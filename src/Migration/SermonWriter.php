<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Writes one legacy wpfc_sermon into a new sermonator_sermon, non-destructively,
 * idempotently, and crash-safely.
 *
 * write() is the full pipeline:
 *  - the idempotency gate (resolve via Crosswalk::findNewByLegacyId, status-
 *    agnostic) distinguishes a COMPLETE record (skip the post/body/meta/comments
 *    steps, and re-run term repair ONLY when an OPEN term flag is present so a
 *    now-resolvable term self-heals — and then scoped to ONLY the taxonomies with
 *    an open flag, so a taxonomy a church admin curated is never full-REPLACE
 *    clobbered) from a stamped-but-PARTIAL record (resume — re-enter on the
 *    existing post, never insert a second one). MIGRATION_COMPLETE is WITHHELD
 *    while a comment_copy_failed:* flag is open, so an irreplaceable parishioner
 *    comment that failed to copy keeps the record in the resume leg (copyComments
 *    re-attempts on the next write) instead of being stamped-complete-and-skipped-
 *    forever;
 *  - the post is inserted preserving the legacy post columns, with the body
 *    reconciled from (post_content, sermon_description, post_content_temp);
 *  - the insert is wp_slash'd and run with KSES DISABLED so iframes/shortcodes
 *    in preserved content survive verbatim, then KSES is restored;
 *  - the legacy back-ref is stamped IMMEDIATELY after insert (crash-safety spine);
 *  - meta is applied from the per-key UNSERIALIZED values (arrays round-trip),
 *    with non-numeric sermon_date companions normalized alongside the raw;
 *  - legacy term assignments are translated through the taxonomy-aware crosswalk
 *    and re-assigned per target taxonomy (missing crosswalk → flag, never a
 *    silent drop; WP_Error → flag, never a crash; repair re-appliable later);
 *  - comments are copied depth-first with new ids, remapped parents, preserved
 *    author/email/url/date/approved/type/IP/agent/karma + commentmeta, each
 *    stamped with LEGACY_COMMENT_ID so re-runs copy zero duplicates;
 *  - MIGRATION_COMPLETE is written LAST, after every step, so an abort anywhere
 *    before it leaves a stamped-but-partial post that the gate resumes.
 *
 * Legacy data (posts, meta, terms, comments, commentmeta) is read READ-ONLY;
 * shared attachment posts are referenced by id and never mutated.
 */
final class SermonWriter {
    /**
     * Sub-key under Identifiers::OPTION_MIGRATION_PROGRESS where SermonWriter records
     * deferred-work bookkeeping. Currently holds 'native_term_recount_tt_ids': the
     * term_taxonomy_ids whose SHARED count was intentionally NOT moved during the
     * per-record write (MUST-FIX #3) and which the B2b Finalize step must recount
     * authoritatively via wp_update_term_count_now().
     */
    public const PROGRESS_KEY = 'sermons';

    private ?DateNormalizer $dates;

    public function __construct( ?DateNormalizer $dates = null ) {
        // DateNormalizer is a pure (normally non-instantiable) helper; the optional
        // instance is accepted for dependency-injection symmetry. When provided it is
        // used for date-companion normalization (applyDateNormalization), otherwise
        // the static DateNormalizer::normalize() is called. Either way the site
        // timezone (wp_timezone()) is passed so date-only strings TZ-anchor correctly.
        $this->dates = $dates;
    }

    public function write( int $legacyId ): WriteResult {
        // MUST-FIX #1: the legacy Sermon Manager plugin is normally DEACTIVATED at
        // migration time, which UNREGISTERS the wpfc_* taxonomies/post types — so
        // wp_get_object_terms / get_post for the source would WP_Error/return empty
        // over rows that still exist, silently dropping every primary term assignment.
        // Re-register the legacy schema first (idempotent; a no-op when active) so the
        // legacy reads below are registration-agnostic.
        LegacySchemaRegistrar::ensureRegistered();

        // --- Idempotency gate (status-agnostic, authoritative via back-ref) ---
        //
        // Three observably-distinct outcomes (see WriteResult):
        //   1. no back-ref            -> insert a fresh post (created=true)
        //   2. back-ref + COMPLETE    -> skip (created=false, resumed=false): a
        //                                 no-op here; self-healing steps in later
        //                                 tasks may still re-run, but never a
        //                                 second insert.
        //   3. back-ref but NOT done  -> resume (created=false, resumed=true):
        //                                 re-enter the post-insert / self-healing
        //                                 block on the EXISTING post id; never a
        //                                 second insert.
        $existing = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON );
        if ( null !== $existing ) {
            $legacyForResume = get_post( $legacyId );
            if ( ! $legacyForResume instanceof \WP_Post ) {
                // Legacy source vanished after a partial stamp — surface, do not crash.
                return new WriteResult( $existing, false, $this->readFlags( $existing ), false );
            }

            if ( $this->isComplete( $existing ) ) {
                // COMPLETE — a no-op skip for the post/body/meta/comments steps.
                // Term repair (self-heal) runs ONLY when there is actually an OPEN
                // term flag to clear (missing_term_crosswalk:* / term_assign_error:*),
                // and even then ONLY for the taxonomies that carry that open flag
                // (openFlagTargetTaxonomies). A record migrated while a term
                // crosswalk was missing picks the now-available term up here; but a
                // completed record with NO open term flag must NOT be touched — a
                // full wp_set_object_terms REPLACE on every re-run would silently
                // clobber any term a church admin manually added to the migrated
                // post (data loss on the NEW post). Crucially this is scoped PER
                // TAXONOMY: an open flag in taxonomy A never REPLACEs taxonomy B,
                // so an admin term added to an unflagged taxonomy survives the heal.
                // Seed from the persisted flags so no unrelated flag is lost.
                $persisted = $this->readFlags( $existing );

                $flags    = $persisted;
                $touched  = false;

                // post_parent self-heal (IMPORTANT #5): a record completed while its
                // legacy parent was not yet migrated keeps post_parent=0 and an open
                // post_parent_unresolved flag. Re-translate here so the now-migrated
                // parent is applied and the flag clears — symmetric with the term
                // self-heal — instead of leaving the record never-clean forever. Only
                // runs when the open flag is actually present (no needless update).
                if ( $this->hasOpenPostParentFlag( $flags ) ) {
                    $flags   = $this->reconcilePostParent( $existing, $legacyForResume, $flags );
                    $touched = true;
                }

                // Term repair (self-heal) runs ONLY when there is actually an OPEN
                // term flag, scoped via openFlagTargetTaxonomies() to the flagged
                // taxonomies so an admin-curated unflagged taxonomy is never clobbered
                // by a full-set REPLACE.
                if ( $this->hasOpenTermFlag( $flags ) ) {
                    $flags   = $this->applyTerms( $existing, $legacyId, $flags, $this->openFlagTargetTaxonomies( $flags, $legacyId ) );
                    $touched = true;
                }

                // Comment self-heal: if COMPLETE was stamped while a comment_copy_failed:*
                // flag was open (the flag was somehow injected externally, or the guard
                // failed to withhold COMPLETE), retry copyComments now. Without this, a
                // COMPLETE record with an open comment flag is a dead-end — every write()
                // hits this no-op COMPLETE branch and the irreplaceable parishioner comment
                // is never recovered (skip-forever). Mirrors the term and post_parent heals:
                // strip the stale flag, re-run the step, let markCompleteUnlessCommentFailureOpen
                // decide whether to re-stamp or keep COMPLETE.
                if ( $this->hasOpenCommentFailureFlag( $flags ) ) {
                    $flags   = $this->stripCommentFailureFlags( $flags );
                    $flags   = array_merge( $flags, $this->copyComments( $existing, $legacyId ) );
                    $touched = true;
                }

                if ( $touched ) {
                    $this->writeFlags( $existing, $flags );
                    // Re-evaluate COMPLETE after self-heals: if the comment flag cleared,
                    // stamp COMPLETE; if a comment copy still fails, withhold it (the record
                    // will return to the resume leg on the next write — never skip-forever).
                    $this->markCompleteUnlessCommentFailureOpen( $existing, $flags );
                    return new WriteResult( $existing, false, array_values( array_unique( $flags ) ), false );
                }
                return new WriteResult( $existing, false, $persisted, false );
            }

            // Stamped but PARTIAL — RESUME on the existing post (never insert).
            // Re-enter the same back-ref-first / self-healing block we run after a
            // fresh insert, idempotently re-driving it on $existing (meta/terms/
            // comments), then write MIGRATION_COMPLETE LAST.
            //
            // Seed from the persisted flags so replace-semantics on writeFlags()
            // never drops a prior flag (e.g. post_parent_unresolved) that this
            // resume pass does not re-derive.
            $reconciledForResume = $this->reconcileBody( $legacyId, $legacyForResume );
            $flags               = $this->applyPostInsertSpine( $existing, $legacyId, $legacyForResume, $reconciledForResume, $this->readFlags( $existing ), false );
            // COMPLETE is withheld while a comment copy is still outstanding so the
            // record stays in this resume leg and copyComments() re-runs on the next
            // write (never skip-forever on irreplaceable parishioner-authored data).
            $this->markCompleteUnlessCommentFailureOpen( $existing, $flags );

            return new WriteResult( $existing, false, $flags, true );
        }

        // --- Insert a fresh post ---
        $legacy = get_post( $legacyId );
        if ( ! $legacy instanceof \WP_Post ) {
            // Nothing to migrate; surface a deterministic, non-crashing result.
            return new WriteResult( 0, false, array( 'legacy_post_missing:' . $legacyId ) );
        }

        // Body reconciliation from (post_content, sermon_description, post_content_temp).
        $reconciled = $this->reconcileBody( $legacyId, $legacy );

        // POST-LEVEL CRASH-ORPHAN RECOVERY. The fresh insert below writes the
        // LEGACY_POST_ID back-ref ATOMICALLY via meta_input, so the post-then-
        // back-ref window is closed going forward. But a post left back-ref-less by
        // an OLDER writer (insert succeeded, the process died before the separate
        // markLegacy) is invisible to the authoritative back-ref probe above and
        // would otherwise be DUPLICATED by the fresh insert — a visitor-visible,
        // rollback-invisible orphan. Before inserting, probe for our own back-ref-
        // less orphan matching this legacy identity and ADOPT it: stamp the back-ref
        // and re-enter the spine on it rather than minting a second post.
        $orphanId = $this->findBackRefLessPostByLegacyIdentity( $legacy );
        if ( null !== $orphanId ) {
            Crosswalk::markLegacy( $orphanId, $legacyId );
            // FIX 3 (recommended): before driving the spine, purge any user-meta key
            // on the adopted orphan that is NOT present in the legacy source. An older
            // writer may have written extra keys (stale keys) that the current writer
            // would not write — leaving them causes divergence from source. Migration's
            // own back-ref keys are excluded from deletion so the freshly-stamped
            // LEGACY_POST_ID (and any pre-existing Crosswalk keys) are never removed.
            $this->purgeOrphanMeta( $orphanId, $legacyId );
            $flags = $this->applyPostInsertSpine( $orphanId, $legacyId, $legacy, $reconciled, array(), false );
            $this->markCompleteUnlessCommentFailureOpen( $orphanId, $flags );

            return new WriteResult( $orphanId, false, $flags, true );
        }

        $flags = array();

        // post_parent translation for the initial insert: a non-zero legacy parent
        // is translated through the crosswalk; an untranslatable parent collapses to
        // 0 (never a dangling legacy id). The post_parent_unresolved FLAG and any
        // later self-heal are owned by reconcilePostParent() inside the spine (which
        // runs on both fresh and resume), so the flag is derived in exactly one place.
        $newParent = 0;
        $legacyParent = (int) $legacy->post_parent;
        if ( 0 !== $legacyParent ) {
            $translated = Crosswalk::findNewByLegacyId( $legacyParent, Identifiers::POST_TYPE_SERMON );
            if ( null !== $translated ) {
                $newParent = $translated;
            }
        }

        $postarr = array(
            'post_type'              => Identifiers::POST_TYPE_SERMON,
            'post_title'             => $legacy->post_title,
            'post_author'            => $legacy->post_author,
            'post_date'              => $legacy->post_date,
            'post_date_gmt'          => $legacy->post_date_gmt,
            // Preserve the legacy LAST-MODIFIED timestamps verbatim. Without these,
            // wp_insert_post stamps post_modified[_gmt] (to post_date on insert),
            // silently rewriting every migrated record's edit-history timestamp and
            // corrupting any feed/sitemap/recently-updated ordering keyed on it.
            'post_modified'          => $legacy->post_modified,
            'post_modified_gmt'      => $legacy->post_modified_gmt,
            'post_status'            => $legacy->post_status,
            'post_name'              => $legacy->post_name,
            'comment_status'         => $legacy->comment_status,
            'ping_status'            => $legacy->ping_status,
            'menu_order'             => $legacy->menu_order,
            'post_excerpt'           => $legacy->post_excerpt,
            'post_password'          => $legacy->post_password,
            'post_parent'            => $newParent,
            'post_content'           => $reconciled['content'],
            // Preserve the legacy content_filtered cache column verbatim (a
            // meaningful legacy wp_posts column; dropping it silently is data loss).
            'post_content_filtered'  => $legacy->post_content_filtered,
            // ATOMIC back-ref: write the LEGACY_POST_ID in the SAME insert call so
            // post existence and the back-ref are one operation. A crash can no
            // longer leave a back-ref-less, duplicate-prone orphan (the
            // insert-then-markLegacy window is gone). markLegacy still runs in the
            // spine below (unique=true → a no-op here, idempotent on resume).
            'meta_input'     => array( Crosswalk::LEGACY_POST_ID => $legacyId ),
        );

        $newId = $this->insertKsesSafe( $postarr );
        if ( 0 === $newId ) {
            return new WriteResult( 0, false, array( 'insert_failed:' . $legacyId ) );
        }

        // --- Crash-safety spine: back-ref FIRST, then idempotent self-healing. ---
        $flags = $this->applyPostInsertSpine( $newId, $legacyId, $legacy, $reconciled, $flags, true );

        // MIGRATION_COMPLETE is written LAST — after meta, terms, and comments —
        // so an abort anywhere before this point leaves a stamped-but-partial post
        // that the idempotency gate resumes rather than skips. It is WITHHELD while
        // a comment_copy_failed:* flag is open so the record stays in the resume leg
        // and copyComments() is re-attempted on a later write — irreplaceable
        // parishioner-authored data is never stamped-complete-and-skipped-forever.
        $this->markCompleteUnlessCommentFailureOpen( $newId, $flags );

        return new WriteResult( $newId, true, $flags );
    }

    /**
     * Reconcile the body from (post_content, sermon_description, post_content_temp),
     * reading the two companion meta rows READ-ONLY.
     *
     * @return array{content: string, backup: ?string, flag: bool}
     */
    private function reconcileBody( int $legacyId, \WP_Post $legacy ): array {
        $description     = $this->readSingleMeta( $legacyId, LegacyIdentifiers::META_DESCRIPTION );
        $postContentTemp = $this->readSingleMeta( $legacyId, LegacyIdentifiers::META_POST_CONTENT_TEMP );

        return PostContentReconciler::reconcile(
            (string) $legacy->post_content,
            $description,
            $postContentTemp
        );
    }

    /**
     * The crash-safety spine + idempotent self-healing, applied to a new post id
     * whether it was just inserted OR is being resumed (re-entered on a stamped-
     * but-partial post). Every write here is single-row / unique, so re-driving it
     * on an existing post produces zero duplicate meta rows.
     *
     *  - back-ref FIRST (markLegacy uses unique=true: a no-op if already stamped);
     *  - preserve the reconciler's backup body into LEGACY_POST_CONTENT (unique);
     *  - record LEGACY_SLUG + a slug_changed flag on drift (unique);
     *  - persist the canonical MIGRATION_FLAGS row (replace semantics).
     *
     * Tasks 13/14 extend THIS block (meta/terms/comments) so both the fresh-insert
     * and the resume path drive the same steps forward.
     *
     * @param array{content: string, backup: ?string, flag: bool} $reconciled
     * @param list<string>                                         $flags
     * @param bool                                                 $fresh Whether $newId was just inserted (no prior/admin term assignment) — gates the empty-legacy term REPLACE no-op on resume.
     * @return list<string>
     */
    private function applyPostInsertSpine( int $newId, int $legacyId, \WP_Post $legacy, array $reconciled, array $flags = array(), bool $fresh = false ): array {
        // Back-ref FIRST, immediately after insert (unique → idempotent on resume).
        Crosswalk::markLegacy( $newId, $legacyId );

        // Preserve the legacy last-modified timestamps. wp_insert_post FORCES
        // post_modified[_gmt] to post_date on insert (it only honours them on an
        // update), so the $postarr values are ignored and we must stamp the legacy
        // values directly. Idempotent: only updates when the columns actually differ.
        $this->preserveModifiedTimestamps( $newId, $legacy );

        // post_parent re-translation (IMPORTANT #5): runs on BOTH the fresh-insert
        // and the resume path so a child migrated BEFORE its parent self-heals once
        // the parent is later migrated. The prior post_parent_unresolved flag is
        // stripped and re-derived from scratch (mirroring the term-flag strip), and
        // the resolved parent is applied via wp_update_post when it newly resolves —
        // so COMPLETE clears symmetrically rather than staying never-clean forever.
        // On a fresh insert the parent was already set at insert time, so this is a
        // no-op update (idempotent) that simply re-confirms the flag state.
        $flags = $this->reconcilePostParent( $newId, $legacy, $flags );

        // Preserve any substantive old body the reconciler routed to backup. The
        // unique=true guard means a resume re-entry never adds a duplicate row;
        // re-derive the flag from the persisted row so a resume reports it too.
        if ( null !== $reconciled['backup'] ) {
            add_post_meta( $newId, Crosswalk::LEGACY_POST_CONTENT, $reconciled['backup'], true );
        }
        if ( '' !== (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_CONTENT, true ) ) {
            $flags[] = 'post_content_preserved';
        }

        // Fix 5: PostContentReconciler::reconcile() returns flag=true with backup=null
        // when it PROMOTED the legacy post_content to be the canonical body (i.e. the
        // description was empty but post_content held substantive text). Previously
        // SermonWriter never read the flag in this branch, so the promotion was silent.
        // Surface it so verifiers and operators can see which sermons had their body
        // sourced from post_content rather than sermon_description.
        if ( $reconciled['flag'] && null === $reconciled['backup'] ) {
            $flags[] = 'post_content_promoted';
        }

        // Slug drift: WP may uniquify post_name on insert. Record the original
        // legacy slug and flag the change.
        $insertedSlug = (string) get_post_field( 'post_name', $newId );
        $originalSlug = (string) $legacy->post_name;
        if ( '' !== $originalSlug ) {
            add_post_meta( $newId, Crosswalk::LEGACY_SLUG, $originalSlug, true );
            if ( $insertedSlug !== $originalSlug ) {
                $flags[] = 'slug_changed';
            }
        }

        // Meta application (Task 13) — runs on both the fresh-insert and the
        // resume path, idempotently. Returns any meta-derived flags (e.g.
        // legacy_nonnumeric_date) to fold into the canonical flags row.
        $flags = array_merge( $flags, $this->applyMeta( $newId, $legacyId ) );

        // Term assignment (Task 14) — translate each legacy assignment through the
        // taxonomy-aware crosswalk and re-assign per target taxonomy. A missing
        // crosswalk records a flag (never a silent drop); a wp_set_object_terms
        // WP_Error records a flag (never a crash). The previously-recorded term
        // flags are dropped before re-derivation so a now-resolved term clears its
        // open missing_term_crosswalk flag on this pass.
        $flags = $this->applyTerms( $newId, $legacyId, $flags, null, $fresh );

        // Comment copy (Task 14) — depth-first, new ids, remapped parents, stamped
        // with LEGACY_COMMENT_ID so already-copied comments are skipped (idempotent
        // on resume), commentmeta copied with the unserialize discipline. A comment
        // that fails to insert is NEVER a silent drop — it records a
        // comment_copy_failed:<legacyCommentId> flag (parishioner-authored data is
        // irreplaceable), threaded back into the canonical flags row.
        //
        // Prior comment-copy failure flags are stripped before re-derivation so a
        // comment that copies successfully on this (resume) pass clears its open
        // flag — mirroring the term-flag strip — letting MIGRATION_COMPLETE finally
        // stamp once every comment is recovered.
        $flags = $this->stripCommentFailureFlags( $flags );
        $flags = array_merge( $flags, $this->copyComments( $newId, $legacyId ) );

        $this->writeFlags( $newId, $flags );

        return array_values( array_unique( $flags ) );
    }

    /**
     * Re-translate the legacy post_parent and apply it, self-healing the
     * post_parent_unresolved flag (IMPORTANT #5). Runs on every spine pass (fresh
     * insert AND resume), so a child migrated before its parent picks the parent up
     * once the parent is later migrated:
     *
     *  - the prior post_parent_unresolved:* flag is STRIPPED, then re-derived from
     *    scratch (mirroring the term-flag strip), so a now-resolvable parent clears
     *    its open flag instead of carrying it forever;
     *  - a zero legacy parent is a no-op (parent stays 0, no flag);
     *  - a non-zero legacy parent that resolves is applied via wp_update_post ONLY
     *    when the current post_parent differs (idempotent — a fresh insert already
     *    has it, a resume that already healed is a no-op);
     *  - a non-zero legacy parent that still does NOT resolve re-records the
     *    post_parent_unresolved:<legacyParent> flag (parent stays 0).
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function reconcilePostParent( int $newId, \WP_Post $legacy, array $flags ): array {
        $legacyParent = (int) $legacy->post_parent;

        // Strip any prior post_parent_unresolved flag so this pass re-derives it.
        $flags = array_values( array_filter(
            $flags,
            static function ( $flag ): bool {
                return ! str_starts_with( (string) $flag, 'post_parent_unresolved:' );
            }
        ) );

        if ( 0 === $legacyParent ) {
            return $flags; // no parent — nothing to translate, no flag.
        }

        $translated = Crosswalk::findNewByLegacyId( $legacyParent, Identifiers::POST_TYPE_SERMON );
        if ( null === $translated ) {
            // Still unresolvable — keep parent 0 and re-record the open flag so a
            // LATER pass (once the parent migrates) can self-heal it.
            $flags[] = 'post_parent_unresolved:' . $legacyParent;
            return $flags;
        }

        // Newly (or already) resolved — apply only when it actually differs so a
        // fresh insert / already-healed resume is a no-op (idempotent). KSES is
        // disabled around the update: wp_update_post merges the existing post and
        // would otherwise re-run KSES over the (KSES-off-inserted) body, stripping
        // iframes/shortcodes from an already-clean post on a mere parent re-parent.
        $current = (int) get_post_field( 'post_parent', $newId );
        if ( $current !== (int) $translated ) {
            // Capture the prior KSES filter state and restore it SYMMETRICALLY
            // (mirroring insertKsesSafe). wp_update_post merges the existing post
            // and would otherwise re-run KSES over the (KSES-off-inserted) body,
            // stripping iframes/shortcodes from an already-clean post on a mere
            // re-parent. An unconditional kses_init_filters() in finally would leak
            // KSES ON when a batch orchestrator had disabled it for performance.
            $kses_on = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
            if ( $kses_on ) {
                kses_remove_filters();
            }
            try {
                wp_update_post( array(
                    'ID'          => $newId,
                    'post_parent' => (int) $translated,
                ) );
            } finally {
                if ( $kses_on ) {
                    kses_init_filters();
                }
            }
        }

        return $flags;
    }

    /**
     * Apply the legacy sermon's post meta onto the new post, idempotently.
     *
     * Discipline (the closed adversarial holes):
     *  - the SermonMetaMapper drives WHICH keys are renamed / dropped / verbatim
     *    and which legacy dates are non-numeric (its flags), but the actual WRITE
     *    VALUES are sourced from the per-key UNSERIALIZED get_post_meta($id,$key,
     *    false) form — never the no-key raw-serialized values — so an array meta
     *    value round-trips as an array (core re-serializes) instead of being
     *    double-serialized;
     *  - the denormalized wpfc_service_type meta and the sermon_description (now
     *    post_content) are dropped; unknown keys (e.g. _yoast_wpseo_title,
     *    post_content_temp) pass through verbatim under the same key, so
     *    post_content_temp is its own single canonical row;
     *  - every key is rewritten with delete-then-re-add of the FULL multiset, so a
     *    resume re-run produces zero duplicate / accumulated rows while preserving
     *    multi-value ordering and arity exactly;
     *  - for EVERY non-numeric sermon_date row a sermonator_date_normalized
     *    companion (DateNormalizer::normalize, anchored to wp_timezone()) is written ALONGSIDE
     *    the untouched verbatim raw; numeric dates get no companion. The companion
     *    set is also delete-then-re-added (idempotent).
     *
     * Legacy meta is read READ-ONLY throughout.
     *
     * @return list<string> Meta-derived migration flags (e.g. legacy_nonnumeric_date).
     */
    private function applyMeta( int $newId, int $legacyId ): array {
        // No-key form: drives the mapper's key-mapping / drop / flag decisions.
        // (We deliberately do NOT use its serialized values for writes.)
        $rawByKey = get_post_meta( $legacyId );
        $rawByKey = is_array( $rawByKey ) ? $rawByKey : array();

        $mapped  = SermonMetaMapper::map( $rawByKey );
        $keyMap  = MappingContract::metaKeyMap();
        $dropped = MappingContract::droppedMetaKeys();

        // FIX (IMPORTANT #9): accumulate the full target multiset FIRST so that two
        // distinct legacy source keys that resolve to the SAME target key produce a
        // UNION on the new post rather than silent loss (the old per-legacy-key
        // delete-then-re-add wiped a previous iteration's writes when the target key
        // collided). Group unserialized values by resolved TARGET key, track which
        // target keys are touched by more than one DISTINCT legacy source key so we
        // can raise a meta_key_collision flag for those.
        //
        // array<targetKey, list<value>>
        $targetValues = array();
        // array<targetKey, list<legacyKey>> — which source keys contributed
        $targetSources = array();

        foreach ( array_keys( $rawByKey ) as $legacyKey ) {
            // sermon_description becomes post_content (handled by the reconciler);
            // wpfc_service_type denorm copy is authoritative-via-taxonomy. Never
            // carry either as a meta row.
            if ( in_array( $legacyKey, $dropped, true ) ) {
                continue;
            }

            $newKey = $keyMap[ $legacyKey ] ?? $legacyKey; // known → renamed; unknown → verbatim

            // UNSERIALIZED per-key values — the heart of the serialized-meta hole.
            $values = get_post_meta( $legacyId, $legacyKey, false );
            $values = is_array( $values ) ? array_values( $values ) : array();

            if ( ! isset( $targetValues[ $newKey ] ) ) {
                $targetValues[ $newKey ]  = array();
                $targetSources[ $newKey ] = array();
            }
            foreach ( $values as $v ) {
                $targetValues[ $newKey ][] = $v;
            }
            $targetSources[ $newKey ][] = (string) $legacyKey;
        }

        // Collect collision flags before writing (raised when >1 distinct legacy
        // source keys resolve to the same target key).
        $collisionFlags = array();
        foreach ( $targetSources as $newKey => $sources ) {
            if ( count( array_unique( $sources ) ) > 1 ) {
                $collisionFlags[] = 'meta_key_collision:' . $newKey;
            }
        }

        // Now delete-then-re-add the UNIONED values per target key: idempotent on
        // resume, preserves the full multiset (including values contributed by
        // multiple legacy source keys), replaces single-value rows.
        foreach ( $targetValues as $newKey => $values ) {
            delete_post_meta( $newId, $newKey );
            foreach ( $values as $value ) {
                // get_post_meta(...,false) values are UNSLASHED; add_post_meta()'s
                // add_metadata() wp_unslash()es its input, so we MUST wp_slash()
                // here or a backslash level is stripped (UNC/audio paths, escaped
                // quotes, serialized inner strings). wp_slash() recurses into arrays
                // so array meta round-trips byte-exact.
                add_post_meta( $newId, $newKey, wp_slash( $value ) );
            }
        }

        // Date normalization companions: one per NON-NUMERIC sermon_date row,
        // written alongside the untouched raw (which the loop above already copied
        // verbatim under the new date key). Delete-then-re-add for idempotency.
        //
        // The legacy_nonnumeric_date flag is derived HERE — from the same per-row
        // scan that drives the companions — so the flag set and the companion set
        // can never disagree (e.g. a numeric-first / non-numeric-later multiset
        // gets BOTH the companion for the later row AND the flag). We do not rely
        // on the mapper's flag for this, though the mapper now agrees.
        $flags = array_values( $mapped['flags'] );
        if ( $this->applyDateNormalization( $newId, $legacyId ) ) {
            $flags[] = 'legacy_nonnumeric_date';
        }
        $flags = array_merge( $flags, $collisionFlags );

        return array_values( array_unique( $flags ) );
    }

    /**
     * FIX 3: purge stale user-meta keys from an adopted crash-orphan.
     *
     * When adopting a back-ref-less orphan, it may carry user-meta keys written
     * by an older writer that the current writer would NOT write (e.g. an extra
     * denorm key, a renamed key written under its old name, or a key the writer
     * since dropped). Leaving stale keys causes divergence from the legacy source —
     * the invariant is that the migrated post's meta is derived entirely from the
     * legacy source.
     *
     * Keys to KEEP (excluded from deletion):
     *  - keys whose resolved legacy source is present (applyMeta will re-write them)
     *  - the migration's own back-ref/state keys (Crosswalk::*) which are NEVER
     *    user data and must be preserved for idempotency/rollback
     *
     * We snapshot orphan keys BEFORE applyMeta rewrites them so we delete the
     * stale keys FIRST (this method is called just before applyPostInsertSpine).
     */
    private function purgeOrphanMeta( int $orphanId, int $legacyId ): void {
        // Keys the migration framework owns — never delete these.
        $ownKeys = array(
            Crosswalk::LEGACY_POST_ID,
            Crosswalk::LEGACY_SLUG,
            Crosswalk::MIGRATION_COMPLETE,
            Crosswalk::MIGRATION_FLAGS,
            Crosswalk::LEGACY_POST_CONTENT,
        );

        // Build the set of TARGET keys that applyMeta will write from the legacy
        // source (after rename and excluding dropped keys) — these should NOT be
        // deleted even if present on the orphan already.
        $rawByKey = get_post_meta( $legacyId );
        $rawByKey = is_array( $rawByKey ) ? $rawByKey : array();
        $keyMap   = MappingContract::metaKeyMap();
        $dropped  = MappingContract::droppedMetaKeys();

        $legacyTargetKeys = array();
        foreach ( array_keys( $rawByKey ) as $legacyKey ) {
            if ( in_array( $legacyKey, $dropped, true ) ) {
                continue;
            }
            $legacyTargetKeys[] = $keyMap[ $legacyKey ] ?? $legacyKey;
        }
        $legacyTargetKeys = array_values( array_unique( $legacyTargetKeys ) );

        // Snapshot the orphan's current meta keys.
        $orphanMeta = get_post_meta( $orphanId );
        $orphanMeta = is_array( $orphanMeta ) ? $orphanMeta : array();

        foreach ( array_keys( $orphanMeta ) as $key ) {
            $key = (string) $key;
            // Keep migration's own keys.
            if ( in_array( $key, $ownKeys, true ) ) {
                continue;
            }
            // Keep keys that applyMeta will (re-)write from the legacy source.
            if ( in_array( $key, $legacyTargetKeys, true ) ) {
                continue;
            }
            // Stale key: remove it (all rows for this key).
            delete_post_meta( $orphanId, $key );
        }
    }

    /**
     * Write a sermonator_date_normalized companion for EVERY non-numeric
     * sermon_date row (raw left untouched), delete-then-re-add for idempotency.
     * Numeric dates produce no companion row.
     *
     * Scans EVERY row (not just the first): the flag and the companion set are
     * both driven from this single pass so they cannot disagree.
     *
     * @return bool Whether ANY row (at any position) was non-numeric — the
     *              authoritative legacy_nonnumeric_date signal. True even when a
     *              non-numeric row is unparseable (no companion written), because
     *              the raw is still a non-numeric date that must be flagged.
     */
    private function applyDateNormalization( int $newId, int $legacyId ): bool {
        $rawDates = get_post_meta( $legacyId, LegacyIdentifiers::META_DATE, false );
        $rawDates = is_array( $rawDates ) ? array_values( $rawDates ) : array();

        delete_post_meta( $newId, Identifiers::META_DATE_NORMALIZED );

        $sawNonNumeric = false;
        foreach ( $rawDates as $raw ) {
            $rawString = is_string( $raw ) ? $raw : (string) $raw;
            if ( $this->isUnixTimestamp( $rawString ) ) {
                continue; // numeric date — no companion, no flag
            }
            $sawNonNumeric = true;
            // Anchor date-only strings to the SITE timezone (wp_timezone()), not the
            // DateNormalizer UTC default — otherwise TZ-anchoring is defeated and the
            // companion lands on UTC midnight. Honour the injected DateNormalizer
            // instance when one was provided (DI symmetry); fall back to the static
            // helper otherwise.
            $normalized = null !== $this->dates
                ? $this->dates->normalize( $rawString, wp_timezone() )
                : DateNormalizer::normalize( $rawString, wp_timezone() );
            if ( null === $normalized ) {
                // IMPORTANT #7: positional alignment. Companions are a positional
                // multiset (delete_post_meta + add_post_meta), so a consumer indexes
                // META_DATE_NORMALIZED[i] against the i-th NON-NUMERIC raw row. A bare
                // `continue` here would leave the unparseable row with NO companion,
                // shortening and MISALIGNING the multiset against the raw set. Write a
                // sentinel marker instead so EVERY non-numeric row has a companion at
                // its position; the unparseable case is flagged (raw stays the source
                // of truth) rather than silently dropped.
                add_post_meta( $newId, Identifiers::META_DATE_NORMALIZED, wp_slash( Identifiers::META_DATE_UNPARSEABLE ) );
                continue;
            }
            // wp_slash for consistency with the meta-write discipline (the value is
            // an int here, but add_post_meta() unslashes its input uniformly).
            add_post_meta( $newId, Identifiers::META_DATE_NORMALIZED, wp_slash( $normalized ) );
        }

        return $sawNonNumeric;
    }

    /** Whether a legacy date value is a (signed) unix timestamp. */
    private function isUnixTimestamp( string $value ): bool {
        $stripped = ltrim( $value, '-' );
        return '' !== $stripped && ctype_digit( $stripped );
    }

    /**
     * Translate every legacy term assignment through the taxonomy-aware crosswalk
     * and re-assign per target taxonomy. Idempotent: wp_set_object_terms replaces
     * the full set for each taxonomy, so a resume / repair pass converges without
     * duplicate assignments.
     *
     * Discipline (the closed adversarial holes):
     *  - resolve via Crosswalk::findNewTermByLegacyId keyed by the MAPPED target
     *    taxonomy, so a legacy term resolves only to its correct new taxonomy;
     *  - a legacy term with NO crosswalk is NEVER silently dropped — it records a
     *    missing_term_crosswalk:<legacyTermId> flag and is left unassigned, so a
     *    later pass (once the term is migrated) can self-heal it;
     *  - a wp_set_object_terms WP_Error records a term_assign_error:<taxonomy> flag
     *    rather than crashing the whole migration;
     *  - prior term flags (missing_term_crosswalk:*, term_assign_error:*) are
     *    stripped before re-derivation so a now-resolved term clears its open flag.
     *
     * Legacy term assignments are read READ-ONLY.
     *
     * $onlyTargetTaxonomies scopes the REPLACE to a subset of target taxonomies.
     * On a fresh insert / resume (null) every taxonomy is (re)built from the legacy
     * source — full replace is correct because the post has no admin-curated terms
     * yet. On a COMPLETE-branch repair only the taxonomies that actually carry an
     * open flag are passed, so a taxonomy a church admin manually added a native
     * term to (and which has NO open flag) is left entirely untouched — its
     * admin-curated assignment is never clobbered by a full-set REPLACE. Term flags
     * are stripped ONLY for the taxonomies being re-processed, so an open flag for a
     * taxonomy outside the scope is preserved verbatim rather than silently dropped.
     *
     * Empty-legacy-read guard (IMPORTANT #10): on a NON-fresh pass (resume / repair)
     * a transient empty (non-WP_Error) legacy read must NOT REPLACE-clobber a correct
     * prior/admin assignment already on the new post. An empty legacy read is treated
     * as a NO-OP (not authoritative-empty) whenever the new post already carries a
     * non-empty assignment for that target taxonomy. On a FRESH insert the post has no
     * prior assignment, so an empty read correctly leaves the taxonomy empty.
     *
     * @param list<string>      $flags
     * @param list<string>|null  $onlyTargetTaxonomies Restrict REPLACE to these target taxonomies; null = all.
     * @param bool               $fresh Whether $newId was just inserted (no prior/admin assignment to protect).
     * @return list<string>
     */
    private function applyTerms( int $newId, int $legacyId, array $flags, ?array $onlyTargetTaxonomies = null, bool $fresh = false ): array {
        foreach ( MappingContract::taxonomyMap() as $legacyTax => $targetTax ) {
            if ( null !== $onlyTargetTaxonomies && ! in_array( $targetTax, $onlyTargetTaxonomies, true ) ) {
                // Out of scope (no open flag) — leave its (possibly admin-curated)
                // assignment and any unrelated flag exactly as they are.
                continue;
            }

            // Re-derive this taxonomy's unreadable flag from scratch on every pass:
            // drop any prior legacy_taxonomy_unreadable:<legacyTax> so a taxonomy that
            // became readable on this pass clears its open flag (self-heal), and is
            // re-recorded below only if the read STILL fails.
            $flags = array_values( array_filter(
                $flags,
                static function ( $flag ) use ( $legacyTax ): bool {
                    return (string) $flag !== 'legacy_taxonomy_unreadable:' . $legacyTax;
                }
            ) );

            $legacyTermIds = wp_get_object_terms( $legacyId, $legacyTax, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $legacyTermIds ) ) {
                // MUST-FIX #1: the legacy schema was already re-registered at the
                // write() entry point, so reaching a WP_Error here means the taxonomy
                // is genuinely unreadable and this sermon's primary term assignment
                // could NOT be read. NEVER a silent continue: record a flag that
                // WITHHOLDS MIGRATION_COMPLETE (markCompleteUnlessCommentFailureOpen)
                // and keeps the record in the resume / self-heal leg so a later write
                // re-reads it once the taxonomy is readable again.
                $flags[] = 'legacy_taxonomy_unreadable:' . $legacyTax;
                continue;
            }
            $legacyTermIds = array_map( 'intval', (array) $legacyTermIds );

            // IMPORTANT #10: empty-legacy-read NO-OP on a non-fresh pass. A transient
            // empty (non-WP_Error) legacy read must not REPLACE-clobber a correct
            // prior/admin assignment already on the new post. If the legacy read is
            // empty AND this is NOT a fresh insert AND the new post already carries a
            // non-empty assignment for this taxonomy, treat the empty read as a no-op
            // (not authoritative-empty) and leave the existing assignment intact. On a
            // fresh insert the post has no prior assignment, so an empty read correctly
            // results in an empty taxonomy.
            if ( array() === $legacyTermIds && ! $fresh ) {
                $existingAssigned = wp_get_object_terms( $newId, $targetTax, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $existingAssigned ) && array() !== array_map( 'intval', (array) $existingAssigned ) ) {
                    // Existing assignment present — do not clobber it with an empty
                    // REPLACE. Leave any flags for this taxonomy untouched as well.
                    continue;
                }
            }

            // Drop this taxonomy's stale term flags so this pass re-derives them
            // from scratch — a term that has since been migrated must clear its open
            // missing_term_crosswalk. Scoped per-taxonomy so a flag for a taxonomy
            // outside the (repair) scope is never dropped without re-derivation.
            $flags = $this->stripTermFlagsForTaxonomy( $flags, $legacyTax, $targetTax, $legacyTermIds );

            $newTermIds = array();
            foreach ( $legacyTermIds as $legacyTermId ) {
                $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTax );
                if ( null === $newTermId ) {
                    // Never a silent drop — flag and leave unassigned for self-heal.
                    $flags[] = 'missing_term_crosswalk:' . $legacyTermId;
                    continue;
                }
                $newTermIds[] = (int) $newTermId;
            }

            // Replace the full set for this taxonomy (idempotent). An empty set is
            // still applied so a taxonomy whose terms were all unresolved stays
            // empty rather than retaining a stale assignment from a prior pass.
            $result = wp_set_object_terms( $newId, $newTermIds, $targetTax );
            if ( is_wp_error( $result ) ) {
                $flags[] = 'term_assign_error:' . $targetTax;
            }
        }

        // MUST-FIX #6 — faithful mirror of NATIVE (non-wpfc_) taxonomy assignments.
        // Only the five wpfc_ taxonomies go through the crosswalk above; a legacy
        // sermon assigned to category / post_tag / a site-custom taxonomy would
        // otherwise have those relationships silently dropped. category/post_tag and
        // any global custom taxonomy are GLOBAL (term ids are universal), so the SAME
        // term ids apply to the new post — mirror them verbatim via
        // wp_set_object_terms (idempotent full replace).
        //
        // Skipped on a SCOPED repair pass ($onlyTargetTaxonomies non-null): those
        // passes touch only the flagged wpfc_ taxonomies; re-replacing a native
        // taxonomy there could clobber an admin-curated assignment on the new post.
        if ( null === $onlyTargetTaxonomies ) {
            $flags = $this->mirrorNativeTaxonomies( $newId, $legacyId, $flags, $fresh );
        }

        return array_values( array_unique( $flags ) );
    }

    /**
     * Mirror every NATIVE taxonomy the legacy sermon is assigned in — every
     * taxonomy NOT in MappingContract::taxonomyMap() — onto the new sermon, copying
     * the SAME (global) term ids verbatim. category, post_tag, and site-custom
     * taxonomies are global, so the legacy term id is also valid on the new post.
     *
     * Discovered directly from the term_relationships → term_taxonomy rows (cache-
     * safe $wpdb read) rather than wp_get_object_taxonomies(), so a taxonomy that is
     * not registered for the legacy post type at migration time (e.g. category /
     * post_tag, which core registers only for `post`) is still discovered and never
     * silently dropped.
     *
     * MUST-FIX #3 — SHARED COUNT IMMUTABILITY (Invariant 2). wp_set_object_terms()
     * triggers wp_update_term_count(), which writes wp_term_taxonomy.count on the
     * SHARED term rows — the rows the church's own non-migrated posts are counted
     * against — BEFORE the Finalize point of no return. To honour the invariant
     * "shared rows are never mutated pre-Finalize", we DO NOT call
     * wp_set_object_terms here. Instead we INSERT the term_relationship row DIRECTLY
     * via $wpdb (object_id=$newId, term_taxonomy_id=the term's tt_id, term_order),
     * with INSERT IGNORE-style idempotency (pre-check the existing row), then
     * clean_object_term_cache($newId, $taxonomy) so the relationship is readable
     * through the term API. The affected term_taxonomy_ids are recorded into
     * OPTION_MIGRATION_PROGRESS so the deferred B2b Finalize step recounts them
     * authoritatively (wp_update_term_count_now) once, at the point of no return.
     *
     * Mirrors the wpfc_ loop's empty-read NO-OP guard (IMPORTANT #10): on a non-fresh
     * pass a transient empty legacy read does not clobber an existing assignment.
     *
     * Legacy term assignments are read READ-ONLY.
     *
     * @param list<string> $flags
     * @return list<string>
     */
    /**
     * Mirror "native" (non-wpfc_) taxonomy assignments from the legacy sermon to the
     * new Sermonator sermon via DIRECT $wpdb INSERT into wp_term_relationships.
     *
     * HARD CONSTRAINT for B2b Rollback:
     *   This method MUST NOT call wp_set_object_terms() or wp_update_term_count().
     *   Both of those functions increment wp_term_taxonomy.count immediately, which
     *   is a SHARED counter (not per-site, not per-migration). Any increment before
     *   B2b Finalize fires would corrupt the live site's term count — even if the
     *   migration is later rolled back, the increment cannot be undone retroactively.
     *   Instead, every term_taxonomy_id whose count is now stale-by-design is recorded
     *   in OPTION_MIGRATION_PROGRESS[PROGRESS_KEY]['native_term_recount_tt_ids'].
     *   B2b Finalize runs wp_update_term_count() on that list exactly once after all
     *   B2a writes are confirmed and the shared counter is safe to touch.
     *
     *   B2b Rollback MUST call wp_update_term_count() on the same tt_id list in reverse
     *   (to decrement counts back to their pre-migration value) before removing the
     *   term_relationship rows. Failure to do so leaves the count permanently inflated.
     *
     * Idempotent: pre-checks each (object_id, term_taxonomy_id) pair before inserting;
     * already-linked pairs still contribute to the recount list.
     *
     * @param int         $newId    New Sermonator post ID.
     * @param int         $legacyId Legacy Sermon Manager post ID.
     * @param list<string> $flags   Migration flags accumulated so far.
     * @param bool        $fresh    True when $newId was JUST inserted (gates no-op
     *                              guard for empty legacy reads on resume).
     * @return list<string> Updated $flags.
     */
    private function mirrorNativeTaxonomies( int $newId, int $legacyId, array $flags, bool $fresh ): array {
        global $wpdb;

        $mappedLegacyTaxonomies = array_keys( MappingContract::taxonomyMap() );

        // Every taxonomy the legacy object is actually assigned in.
        $taxonomies = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " WHERE tr.object_id = %d",
                $legacyId
            )
        );

        $recountTtIds = array();

        foreach ( (array) $taxonomies as $taxonomy ) {
            $taxonomy = (string) $taxonomy;
            // The five wpfc_ taxonomies are handled by the crosswalk loop — skip.
            if ( in_array( $taxonomy, $mappedLegacyTaxonomies, true ) ) {
                continue;
            }

            // Read the legacy assignment as (term_id => tt_id) pairs DIRECTLY so we
            // have the term_taxonomy_id needed for the direct relationship insert and
            // for the deferred recount. Read-only on legacy.
            $legacyTtRows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tr.term_taxonomy_id AS tt_id, tr.term_order AS term_order"
                    . " FROM {$wpdb->term_relationships} tr"
                    . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                    . " WHERE tr.object_id = %d AND tt.taxonomy = %s"
                    . " ORDER BY tr.term_order ASC, tr.term_taxonomy_id ASC",
                    $legacyId,
                    $taxonomy
                )
            );
            $legacyTtRows = is_array( $legacyTtRows ) ? $legacyTtRows : array();

            // Empty-read NO-OP on a non-fresh pass (mirrors the wpfc_ loop): never
            // clobber / re-touch an existing native assignment with a transient empty
            // read.
            if ( array() === $legacyTtRows && ! $fresh ) {
                $existing = wp_get_object_terms( $newId, $taxonomy, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $existing ) && array() !== array_map( 'intval', (array) $existing ) ) {
                    continue;
                }
            }

            $taxonomyTouched = false;
            foreach ( $legacyTtRows as $row ) {
                $ttId      = (int) $row->tt_id;
                $termOrder = (int) $row->term_order;

                // Idempotency: skip if the relationship row already exists (a resume /
                // re-run must not duplicate it). $wpdb->insert has no INSERT IGNORE, so
                // we pre-check the (object_id, term_taxonomy_id) pair (the table's PK).
                $alreadyLinked = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->term_relationships}"
                        . " WHERE object_id = %d AND term_taxonomy_id = %d",
                        $newId,
                        $ttId
                    )
                );
                if ( $alreadyLinked > 0 ) {
                    // Still record the tt_id for the deferred recount: the relationship
                    // is present but the shared count has (correctly) not been moved.
                    $recountTtIds[ $ttId ] = true;
                    $taxonomyTouched       = true;
                    continue;
                }

                // DIRECT insert — no wp_set_object_terms, so wp_update_term_count is
                // NEVER invoked and the SHARED count is not moved before Finalize.
                $inserted = $wpdb->insert(
                    $wpdb->term_relationships,
                    array(
                        'object_id'        => $newId,
                        'term_taxonomy_id' => $ttId,
                        'term_order'       => $termOrder,
                    ),
                    array( '%d', '%d', '%d' )
                );

                if ( false === $inserted ) {
                    $flags[] = 'native_term_assign_error:' . $taxonomy;
                    continue;
                }

                // The affected tt_id's shared count is now STALE-by-design — record it
                // for the deferred B2b Finalize recount.
                $recountTtIds[ $ttId ] = true;
                $taxonomyTouched       = true;
            }

            // Refresh the object's term cache for this taxonomy so the directly-inserted
            // relationship is readable through wp_get_object_terms immediately.
            if ( $taxonomyTouched ) {
                clean_object_term_cache( $newId, $taxonomy );
            }
        }

        if ( $recountTtIds !== array() ) {
            $this->recordNativeRecountTtIds( array_keys( $recountTtIds ) );
        }

        return array_values( array_unique( $flags ) );
    }

    /**
     * Record the term_taxonomy_ids whose SHARED wp_term_taxonomy.count was
     * intentionally NOT moved by the direct relationship insert (MUST-FIX #3), so the
     * deferred B2b Finalize step can recount them authoritatively. Persisted under
     * OPTION_MIGRATION_PROGRESS[PROGRESS_KEY]['native_term_recount_tt_ids'] as a
     * de-duplicated list. Idempotent: a re-run unions without duplicating.
     *
     * @param list<int> $ttIds
     */
    private function recordNativeRecountTtIds( array $ttIds ): void {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress ) ) {
            $progress = array();
        }
        if ( ! isset( $progress[ self::PROGRESS_KEY ] ) || ! is_array( $progress[ self::PROGRESS_KEY ] ) ) {
            $progress[ self::PROGRESS_KEY ] = array();
        }

        $existing = array();
        if ( isset( $progress[ self::PROGRESS_KEY ]['native_term_recount_tt_ids'] )
            && is_array( $progress[ self::PROGRESS_KEY ]['native_term_recount_tt_ids'] ) ) {
            $existing = array_map( 'intval', $progress[ self::PROGRESS_KEY ]['native_term_recount_tt_ids'] );
        }

        $merged = array_values( array_unique( array_merge( $existing, array_map( 'intval', $ttIds ) ) ) );
        sort( $merged );

        // No-op if nothing changed (avoid a needless option write on every resume).
        if ( $merged === $existing ) {
            return;
        }

        $progress[ self::PROGRESS_KEY ]['native_term_recount_tt_ids'] = $merged;

        if ( ! add_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress ) ) {
            update_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress );
        }
    }

    /**
     * Whether any OPEN post_parent_unresolved flag is present. Gates the
     * COMPLETE-branch parent self-heal so a completed record whose parent has since
     * been migrated re-translates and clears the flag, while a record with no such
     * open flag is left untouched (no needless wp_update_post).
     *
     * @param list<string> $flags
     */
    private function hasOpenPostParentFlag( array $flags ): bool {
        foreach ( $flags as $flag ) {
            if ( str_starts_with( (string) $flag, 'post_parent_unresolved:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Derive the set of TARGET taxonomies that carry an open term flag, so the
     * COMPLETE-branch repair can REPLACE only those and leave every other
     * (possibly admin-curated) taxonomy untouched.
     *
     *  - term_assign_error:<targetTax> contributes its target taxonomy directly;
     *  - missing_term_crosswalk:<legacyTermId> contributes the target taxonomy of
     *    the legacy taxonomy that the legacy term is assigned to on the (read-only)
     *    legacy post.
     *
     * @param list<string> $flags
     * @return list<string> Distinct target taxonomies with an open flag.
     */
    private function openFlagTargetTaxonomies( array $flags, int $legacyId ): array {
        $taxonomyMap   = MappingContract::taxonomyMap();
        $missingTermIds = array();
        $scope          = array();

        foreach ( $flags as $flag ) {
            $flag = (string) $flag;
            if ( str_starts_with( $flag, 'term_assign_error:' ) ) {
                $scope[ substr( $flag, strlen( 'term_assign_error:' ) ) ] = true;
            } elseif ( str_starts_with( $flag, 'missing_term_crosswalk:' ) ) {
                $missingTermIds[ (int) substr( $flag, strlen( 'missing_term_crosswalk:' ) ) ] = true;
            } elseif ( str_starts_with( $flag, 'legacy_taxonomy_unreadable:' ) ) {
                // MUST-FIX #1: a legacy taxonomy that was unreadable on a prior pass
                // must be re-driven through applyTerms so its primary assignment is
                // re-read once readable. Scope the repair to that legacy tax's target.
                $legacyTax = substr( $flag, strlen( 'legacy_taxonomy_unreadable:' ) );
                if ( isset( $taxonomyMap[ $legacyTax ] ) ) {
                    $scope[ $taxonomyMap[ $legacyTax ] ] = true;
                }
            }
        }

        if ( $missingTermIds !== array() ) {
            // Resolve each missing legacy term id to its target taxonomy via the
            // legacy assignment (read-only). A legacy term may appear in only one
            // legacy taxonomy, so the first match is authoritative.
            //
            // MUST-FIX #1: the legacy schema is re-registered at the write() entry
            // point, so this read is registration-agnostic. A WP_Error here is a
            // genuinely-unreadable taxonomy; widen the repair scope to its target so
            // applyTerms re-records the legacy_taxonomy_unreadable flag (and withholds
            // COMPLETE) instead of silently narrowing the heal scope.
            foreach ( $taxonomyMap as $legacyTax => $targetTax ) {
                $legacyTermIds = wp_get_object_terms( $legacyId, $legacyTax, array( 'fields' => 'ids' ) );
                if ( is_wp_error( $legacyTermIds ) ) {
                    $scope[ $targetTax ] = true;
                    continue;
                }
                foreach ( array_map( 'intval', (array) $legacyTermIds ) as $legacyTermId ) {
                    if ( isset( $missingTermIds[ $legacyTermId ] ) ) {
                        $scope[ $targetTax ] = true;
                    }
                }
            }
        }

        return array_keys( $scope );
    }

    /**
     * Whether any OPEN term flag is present (missing_term_crosswalk:* or
     * term_assign_error:*). Gates the COMPLETE-branch repair so a completed record
     * with no open term flag is left untouched (no full-set REPLACE that would
     * clobber admin-curated terms).
     *
     * @param list<string> $flags
     */
    private function hasOpenTermFlag( array $flags ): bool {
        foreach ( $flags as $flag ) {
            if ( str_starts_with( (string) $flag, 'missing_term_crosswalk:' )
                || str_starts_with( (string) $flag, 'term_assign_error:' )
                // MUST-FIX #1: a legacy_taxonomy_unreadable flag means a primary
                // term assignment could not be read on a prior pass — drive the
                // COMPLETE-branch term repair so it is re-read and the flag clears.
                || str_starts_with( (string) $flag, 'legacy_taxonomy_unreadable:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop the term flags that THIS pass will re-derive for a single taxonomy:
     *  - term_assign_error:<targetTax> for this target taxonomy (re-derived below);
     *  - missing_term_crosswalk:<legacyTermId> for every legacy term currently
     *    assigned to this taxonomy on the (read-only) legacy post.
     *
     * Flags for any OTHER taxonomy are preserved verbatim — essential so a scoped
     * COMPLETE-branch repair never drops an open flag belonging to a taxonomy it is
     * deliberately leaving untouched.
     *
     * @param list<string> $flags
     * @param list<int>    $legacyTermIds Legacy term ids currently assigned in $legacyTax.
     * @return list<string>
     */
    private function stripTermFlagsForTaxonomy( array $flags, string $legacyTax, string $targetTax, array $legacyTermIds ): array {
        $errorFlag = 'term_assign_error:' . $targetTax;
        $missing   = array();
        foreach ( $legacyTermIds as $legacyTermId ) {
            $missing[ 'missing_term_crosswalk:' . (int) $legacyTermId ] = true;
        }

        return array_values( array_filter(
            $flags,
            static function ( $flag ) use ( $errorFlag, $missing ): bool {
                $flag = (string) $flag;
                return $flag !== $errorFlag && ! isset( $missing[ $flag ] );
            }
        ) );
    }

    /**
     * Drop previously-derived comment-copy failure flags so copyComments() can
     * re-derive them from the current copy state (a comment that copies on this
     * pass clears its open comment_copy_failed flag).
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function stripCommentFailureFlags( array $flags ): array {
        return array_values( array_filter(
            $flags,
            static function ( $flag ): bool {
                return ! str_starts_with( (string) $flag, 'comment_copy_failed:' );
            }
        ) );
    }

    /**
     * Copy every legacy comment onto the new post, depth-first, with NEW ids and
     * remapped parents. Idempotent and crash-safe:
     *
     *  - comments are processed parent-before-child (ordered by legacy comment id)
     *    so the new parent id is known before its replies are inserted;
     *  - the old→new id map is rebuilt from the LEGACY_COMMENT_ID back-refs already
     *    present on the new post, so a resume re-uses prior insertions instead of
     *    duplicating them, and comment_parent is remapped to the NEW parent id;
     *  - a comment already carrying its LEGACY_COMMENT_ID back-ref is SKIPPED
     *    (idempotent — a second write copies zero duplicates);
     *  - author/email/url/date/date_gmt/content/approved/type/user_id + IP/agent/
     *    karma are preserved verbatim;
     *  - commentmeta is copied with the unserialize discipline (per-key
     *    get_comment_meta(...,false) values, so arrays round-trip);
     *  - the back-ref is stamped on the new comment immediately after insert;
     *  - a wp_insert_comment failure is NEVER a silent drop — irreplaceable
     *    parishioner-authored data records a comment_copy_failed:<legacyCommentId>
     *    flag (and an error_log entry) so it leaves a forensic trail, rather than
     *    vanishing. The migration is not crashed on a single bad comment.
     *
     * Legacy comments are read READ-ONLY.
     *
     * @return list<string> Comment-copy failure flags (comment_copy_failed:<id>).
     */
    private function copyComments( int $newId, int $legacyId ): array {
        $flags = array();
        // CRITICAL #1: 'status'=>'any' returns EVERY comment, including spam and
        // trash. 'all' silently excludes spam/trash, which would drop irreplaceable
        // parishioner-authored data (and any spam audit trail) before COMPLETE is
        // stamped. We copy every comment and preserve comment_approved verbatim.
        $legacyComments = get_comments( array(
            'post_id' => $legacyId,
            'status'  => 'any',
            'orderby' => 'comment_ID',
            'order'   => 'ASC',
            'type'    => '', // every type, including pingbacks/trackbacks
        ) );

        // Rebuild the old→new id map from back-refs already on the new post so a
        // resume remaps parents correctly and skips already-copied comments.
        $oldToNew = $this->existingCommentMap( $newId );

        // CRITICAL #5: per-comment crash window. wp_insert_comment can succeed and
        // the process abort BEFORE add_comment_meta(LEGACY_COMMENT_ID). On resume
        // that un-back-reffed comment is invisible to existingCommentMap() and would
        // be RE-INSERTED as a duplicate. Before inserting anything, reconcile: probe
        // the new post for an un-back-reffed comment matching each not-yet-mapped
        // legacy comment's identity and ADOPT it (stamp the back-ref, add to the
        // map) so the copy loop skips it instead of duplicating.
        $oldToNew = $this->reconcileOrphanComments( $newId, $legacyComments, $oldToNew );

        foreach ( $legacyComments as $legacy ) {
            $legacyCommentId = (int) $legacy->comment_ID;

            // Already copied / adopted (idempotent skip).
            if ( isset( $oldToNew[ $legacyCommentId ] ) ) {
                continue;
            }

            $legacyParentId = (int) $legacy->comment_parent;
            $newParentId    = 0;
            if ( 0 !== $legacyParentId && isset( $oldToNew[ $legacyParentId ] ) ) {
                $newParentId = $oldToNew[ $legacyParentId ];
            }

            $commentarr = array(
                'comment_post_ID'      => $newId,
                'comment_author'       => $legacy->comment_author,
                'comment_author_email' => $legacy->comment_author_email,
                'comment_author_url'   => $legacy->comment_author_url,
                'comment_author_IP'    => $legacy->comment_author_IP,
                'comment_date'         => $legacy->comment_date,
                'comment_date_gmt'     => $legacy->comment_date_gmt,
                'comment_content'      => $legacy->comment_content,
                'comment_karma'        => $legacy->comment_karma,
                'comment_approved'     => $legacy->comment_approved,
                'comment_agent'        => $legacy->comment_agent,
                'comment_type'         => $legacy->comment_type,
                'comment_parent'       => $newParentId,
                'user_id'              => $legacy->user_id,
            );

            // KSES is irrelevant for comments; preserve content verbatim by
            // slashing so wp_insert_comment's unslash round-trips exactly.
            $newCommentId = wp_insert_comment( wp_slash( $commentarr ) );
            if ( ! $newCommentId ) {
                // Never a silent drop of irreplaceable parishioner-authored data:
                // record a flag + forensic log, do not crash the whole migration.
                $flags[] = 'comment_copy_failed:' . $legacyCommentId;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( sprintf(
                    'SermonWriter: wp_insert_comment failed copying legacy comment %d for legacy post %d (new post %d).',
                    $legacyCommentId,
                    $legacyId,
                    $newId
                ) );
                continue;
            }
            $newCommentId = (int) $newCommentId;

            // Back-ref FIRST (crash-safety + idempotency spine for comments).
            add_comment_meta( $newCommentId, Crosswalk::LEGACY_COMMENT_ID, $legacyCommentId, true );
            $oldToNew[ $legacyCommentId ] = $newCommentId;

            $this->copyCommentMeta( $legacyCommentId, $newCommentId );
        }

        // Thread integrity: a CHILD with a LOWER comment_ID than its PARENT is copied
        // (ascending order) before its parent's new id exists, leaving comment_parent
        // unresolved (0). Now that the FULL oldToNew map is known, re-parent any new
        // comment whose legacy parent has since been mapped. Idempotent: only rows
        // that need it are updated.
        $this->reparentFromMap( $newId, $legacyComments, $oldToNew );

        return array_values( array_unique( $flags ) );
    }

    /**
     * Reconcile the crash window where a comment was inserted on the new post but
     * its LEGACY_COMMENT_ID back-ref was never stamped (abort between the two
     * writes). For each not-yet-mapped legacy comment, probe the new post for an
     * un-back-reffed comment matching its identity (same date_gmt + author_email +
     * a content hash) and ADOPT it: stamp the back-ref and add it to the map so the
     * copy loop skips it rather than re-inserting a duplicate.
     *
     * Identity match is intentionally strict (date_gmt + email + content hash +
     * parent + type + approved + user_id) so a genuinely distinct comment is never
     * mis-adopted. Each new orphan is consumed at most once.
     *
     * MUST-FIX #2: when a signature bucket holds N>1 indistinguishable legacy
     * comments and M>=1 indistinguishable orphans, the orphans are interchangeable
     * copies, so adoption is POSITIONAL — pair K=min(N,M) bucket orphans to K
     * same-signature legacy comments (consuming every orphan); the remaining N-M
     * legacy comments fall through to a fresh insert. Net: exactly N comments, every
     * orphan back-reffed, zero duplicates on resume.
     *
     * @param array<int,\WP_Comment>|list<\WP_Comment> $legacyComments
     * @param array<int,int>                            $oldToNew legacy id → new id
     * @return array<int,int> The map, extended with any adopted comments.
     */
    private function reconcileOrphanComments( int $newId, array $legacyComments, array $oldToNew ): array {
        // Collect new comments on this post that LACK a back-ref (potential orphans),
        // keyed by an identity signature. A back-reffed comment is already mapped.
        $orphansBySig = array();
        $newComments  = get_comments( array( 'post_id' => $newId, 'status' => 'any', 'number' => 0 ) );
        foreach ( $newComments as $newComment ) {
            $newCommentId = (int) $newComment->comment_ID;
            if ( '' !== (string) get_comment_meta( $newCommentId, Crosswalk::LEGACY_COMMENT_ID, true ) ) {
                continue; // already back-reffed → already in the map
            }
            $sig = $this->commentIdentitySignature( $newComment );
            $orphansBySig[ $sig ][] = $newCommentId;
        }

        if ( $orphansBySig === array() ) {
            return $oldToNew;
        }

        foreach ( $legacyComments as $legacy ) {
            $legacyCommentId = (int) $legacy->comment_ID;
            if ( isset( $oldToNew[ $legacyCommentId ] ) ) {
                continue; // already mapped via back-ref
            }
            $sig = $this->commentIdentitySignature( $legacy );
            if ( empty( $orphansBySig[ $sig ] ) ) {
                continue; // no matching orphan to adopt
            }

            // MUST-FIX #2: identical-content positional adoption. When a signature
            // bucket is shared by N>1 indistinguishable legacy comments AND M>=1
            // indistinguishable orphans, those orphans are — by the FULL identity
            // signature (date_gmt + email + content + parent + type + approved +
            // user_id) — interchangeable copies of one another. The OLD ambiguity
            // guard `continue`d past ALL of them, leaving every orphan un-back-reffed
            // AND fresh-inserting a duplicate of each (2 orphans + 2 fresh = 4 where
            // the legacy had 2), un-mappable forever. Instead, adopt POSITIONALLY:
            // because the orphans are interchangeable, pairing the i-th legacy comment
            // in the bucket to ANY remaining bucket orphan is correct — it consumes
            // every orphan (K=min(N,M) adoptions) and yields exactly N comments. The
            // signature already encodes comment_parent, so a DIFFERENT-parent reply is
            // in its OWN bucket and is never cross-adopted here (that distinct-parent
            // case has a single-orphan/single-legacy bucket).
            //
            // The remaining N-M unmatched legacy comments (bucket drained) fall
            // through below to the copy loop's fresh insert — exactly the right count.
            $adoptedId = (int) array_shift( $orphansBySig[ $sig ] );
            add_comment_meta( $adoptedId, Crosswalk::LEGACY_COMMENT_ID, $legacyCommentId, true );
            $oldToNew[ $legacyCommentId ] = $adoptedId;
            // Back-fill commentmeta too (the abort may have predated it).
            $this->copyCommentMeta( $legacyCommentId, $adoptedId );
        }

        return $oldToNew;
    }

    /**
     * A strict identity signature for matching a legacy comment to an un-back-reffed
     * copy on the new post. Includes ALL fields that the copy preserves verbatim:
     * GMT date + author email + content hash + parent + type + approved + user_id.
     *
     * The original 3-field signature (date + email + content) was too weak: two
     * comments authored by the same person at the same second with the same text but
     * different parents (e.g. a top-level and a direct reply to it) produced the same
     * signature, causing array_shift() in reconcileOrphanComments to consume the wrong
     * orphan — swapping parent assignments and mis-threading the reply chain. The
     * tightened signature includes comment_parent so each orphan lands in its own
     * bucket and is adopted by the correct legacy comment. When a bucket is STILL
     * shared (N>1 byte-identical legacy comments + M>1 byte-identical orphans, e.g. a
     * double-submitted 'Amen!'), the orphans are interchangeable copies and
     * reconcileOrphanComments adopts them POSITIONALLY (MUST-FIX #2) rather than
     * leaving them un-back-reffed to be duplicated forever.
     */
    private function commentIdentitySignature( \WP_Comment $comment ): string {
        return implode( "\0", array(
            (string) $comment->comment_date_gmt,
            (string) $comment->comment_author_email,
            md5( (string) $comment->comment_content ),
            (string) $comment->comment_parent,
            (string) $comment->comment_type,
            (string) $comment->comment_approved,
            (string) $comment->user_id,
        ) );
    }

    /**
     * Second-pass thread re-parent: now that the full legacy→new comment map is
     * known, fix any new comment whose comment_parent could not be resolved on the
     * single ascending pass (a child with a lower legacy id than its parent). Only
     * rows whose stored parent differs from the resolved new parent are updated, so
     * this is idempotent and a no-op when the first pass already resolved everything.
     *
     * @param array<int,\WP_Comment>|list<\WP_Comment> $legacyComments
     * @param array<int,int>                            $oldToNew legacy id → new id
     */
    private function reparentFromMap( int $newId, array $legacyComments, array $oldToNew ): void {
        foreach ( $legacyComments as $legacy ) {
            $legacyCommentId = (int) $legacy->comment_ID;
            $legacyParentId  = (int) $legacy->comment_parent;
            if ( 0 === $legacyParentId
                || ! isset( $oldToNew[ $legacyCommentId ] )
                || ! isset( $oldToNew[ $legacyParentId ] ) ) {
                continue;
            }

            $newCommentId    = $oldToNew[ $legacyCommentId ];
            $resolvedParent  = $oldToNew[ $legacyParentId ];
            $current         = get_comment( $newCommentId );
            if ( ! $current instanceof \WP_Comment || (int) $current->comment_parent === $resolvedParent ) {
                continue; // already correct
            }

            global $wpdb;
            $wpdb->update(
                $wpdb->comments,
                array( 'comment_parent' => $resolvedParent ),
                array( 'comment_ID' => $newCommentId )
            );
            clean_comment_cache( $newCommentId );
        }
    }

    /**
     * Rebuild the legacy→new comment id map from LEGACY_COMMENT_ID back-refs on
     * the new post's comments. Reads $wpdb directly so a comment inserted moments
     * earlier on this run is mapped without a stale comment-cache miss.
     *
     * @return array<int,int> legacy comment id → new comment id
     */
    private function existingCommentMap( int $newPostId ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cm.comment_id AS new_id, cm.meta_value AS legacy_id"
                . " FROM {$wpdb->commentmeta} cm"
                . " INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id"
                . " WHERE cm.meta_key = %s AND c.comment_post_ID = %d",
                Crosswalk::LEGACY_COMMENT_ID,
                $newPostId
            )
        );

        $map = array();
        foreach ( (array) $rows as $row ) {
            $map[ (int) $row->legacy_id ] = (int) $row->new_id;
        }
        return $map;
    }

    /**
     * Copy commentmeta from a legacy comment to its new counterpart with the
     * unserialize discipline: per-key UNSERIALIZED values (get_comment_meta(...,
     * false)) so arrays round-trip (core re-serializes) instead of being double-
     * serialized. Delete-then-re-add the full multiset per key → idempotent on
     * resume. The migration's own LEGACY_COMMENT_ID back-ref is never copied.
     */
    private function copyCommentMeta( int $legacyCommentId, int $newCommentId ): void {
        $byKey = get_comment_meta( $legacyCommentId );
        $byKey = is_array( $byKey ) ? $byKey : array();

        foreach ( array_keys( $byKey ) as $key ) {
            if ( Crosswalk::LEGACY_COMMENT_ID === $key ) {
                continue; // never carry our own back-ref across
            }

            $values = get_comment_meta( $legacyCommentId, $key, false );
            $values = is_array( $values ) ? array_values( $values ) : array();

            delete_comment_meta( $newCommentId, $key );
            foreach ( $values as $value ) {
                // get_comment_meta(...,false) values are UNSLASHED; add_comment_meta()'s
                // add_metadata() wp_unslash()es its input, so we MUST wp_slash() here or
                // a backslash level is stripped (UNC/audio paths, escaped quotes,
                // serialized inner strings — a stored serialized string would otherwise
                // un-serialize to false downstream). Mirrors the post-meta path.
                // wp_slash() recurses into arrays so array meta round-trips byte-exact.
                add_comment_meta( $newCommentId, $key, wp_slash( $value ) );
            }
        }
    }

    /** Write the MIGRATION_COMPLETE flag LAST (replace/unique — idempotent). */
    private function markComplete( int $newId ): void {
        update_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, '1' );
    }

    /**
     * Stamp MIGRATION_COMPLETE only when no comment_copy_failed:* flag is open.
     *
     * A failed comment copy is irreplaceable parishioner-authored data: stamping
     * COMPLETE with the flag open would route every subsequent re-run to the
     * COMPLETE branch (a no-op skip whose self-heal is term-only), so the comment
     * would never be recovered — the exact "never skip-forever" violation. Leaving
     * the record stamped-but-PARTIAL keeps it in the resume leg, so copyComments()
     * is re-attempted on the next write and the flag clears once the copy succeeds.
     *
     * @param list<string> $flags
     */
    private function markCompleteUnlessCommentFailureOpen( int $newId, array $flags ): void {
        if ( $this->hasOpenCommentFailureFlag( $flags ) ) {
            return;
        }
        // MUST-FIX #1: a legacy taxonomy that is STILL unreadable after the schema
        // re-registration (e.g. a wpfc_* taxonomy that genuinely could not be made
        // readable) means this record's primary term assignments could not be read.
        // Withhold COMPLETE so the record stays in the resume leg and applyTerms()
        // re-reads on the next write — never stamped-complete-and-skipped-forever
        // with silently-dropped term assignments.
        if ( $this->hasOpenTaxonomyUnreadableFlag( $flags ) ) {
            return;
        }
        $this->markComplete( $newId );
    }

    /**
     * Whether any OPEN comment-copy failure flag is present
     * (comment_copy_failed:*). Gates COMPLETE so an unrecovered, irreplaceable
     * comment never lets the record be stamped-complete-and-skipped-forever.
     *
     * @param list<string> $flags
     */
    private function hasOpenCommentFailureFlag( array $flags ): bool {
        foreach ( $flags as $flag ) {
            if ( str_starts_with( (string) $flag, 'comment_copy_failed:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any OPEN legacy_taxonomy_unreadable:* flag is present (MUST-FIX #1).
     * Recorded when a legacy term read STILL returns WP_Error after the schema
     * re-registration — meaning a primary term assignment could not be read. Gates
     * COMPLETE (the record stays in the resume leg) and drives the COMPLETE-branch
     * term self-heal, so a transiently-unreadable taxonomy is re-read on a later
     * write rather than silently dropped and skipped forever.
     *
     * @param list<string> $flags
     */
    private function hasOpenTaxonomyUnreadableFlag( array $flags ): bool {
        foreach ( $flags as $flag ) {
            if ( str_starts_with( (string) $flag, 'legacy_taxonomy_unreadable:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stamp the legacy post_modified / post_modified_gmt onto the new post.
     * wp_insert_post forces these columns to post_date on INSERT (they are only
     * honoured on an update), so we write them directly via $wpdb and refresh the
     * post cache. Idempotent: a no-op when both columns already match (so resume /
     * orphan-adoption re-entries do not churn the row).
     */
    /**
     * Force-preserve ALL legacy date/status columns that wp_insert_post may silently
     * rewrite, via direct $wpdb->update (which bypasses WP's re-stamp logic).
     *
     * Columns restored:
     *  - post_modified / post_modified_gmt — wp_insert_post forces these to post_date.
     *  - post_date / post_date_gmt       — WP recomputes post_date_gmt from the site
     *                                       timezone when the postarr value differs from
     *                                       get_gmt_from_date(post_date). Preserving
     *                                       them verbatim retains the legacy church's
     *                                       original TZ-aware timestamps.
     *  - post_status                      — A 'future' post whose post_date is already
     *                                       in the past is silently flipped to 'publish'
     *                                       by wp_insert_post (via wp_check_post_lock).
     *                                       Force-restoring 'future' matches the legacy
     *                                       state exactly.
     *
     * Idempotent: reads current columns first and skips the $wpdb->update when all six
     * columns already match (resume path, COMPLETE-branch self-heal).
     */
    private function preserveModifiedTimestamps( int $newId, \WP_Post $legacy ): void {
        $current = get_post( $newId );
        if ( ! $current instanceof \WP_Post ) {
            return;
        }
        if ( $current->post_modified === $legacy->post_modified
            && $current->post_modified_gmt === $legacy->post_modified_gmt
            && $current->post_date === $legacy->post_date
            && $current->post_date_gmt === $legacy->post_date_gmt
            && $current->post_status === $legacy->post_status ) {
            return; // already preserved — idempotent no-op.
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array(
                'post_modified'     => $legacy->post_modified,
                'post_modified_gmt' => $legacy->post_modified_gmt,
                'post_date'         => $legacy->post_date,
                'post_date_gmt'     => $legacy->post_date_gmt,
                'post_status'       => $legacy->post_status,
            ),
            array( 'ID' => $newId )
        );
        clean_post_cache( $newId );
    }

    /**
     * Insert a post with KSES disabled so structural/media HTML (iframes, etc.)
     * survives verbatim, then restore KSES. The post array is wp_slash'd so
     * quotes/backslashes/unicode are not corrupted by wp_insert_post's unslash.
     */
    private function insertKsesSafe( array $postarr ): int {
        $kses_on = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
        if ( $kses_on ) {
            kses_remove_filters();
        }
        try {
            $newId = wp_insert_post( wp_slash( $postarr ), true );
        } finally {
            if ( $kses_on ) {
                kses_init_filters();
            }
        }

        if ( is_wp_error( $newId ) ) {
            return 0;
        }

        return (int) $newId;
    }

    /**
     * Find OUR post-level crash orphan: a sermonator_sermon post that matches the
     * legacy source's identity (same GMT date + title) but carries NO
     * LEGACY_POST_ID back-ref. That signature can only arise from a post WE
     * inserted under an older writer (insert succeeded, the run died before the
     * separate markLegacy stamp) — a native admin-authored post is matched only if
     * it byte-coincides on BOTH identity columns AND lacks the back-ref, which is
     * vanishingly unlikely and, if it occurred, the adopt simply stamps a back-ref
     * onto an otherwise-identical post (no content loss). post_name is deliberately
     * NOT part of the match: WordPress may uniquify the inserted slug, so the
     * orphan's post_name can diverge from the legacy slug. Reads $wpdb directly
     * (cache-safe) and is strict (GMT date + title) to avoid mis-adopting a
     * distinct post.
     *
     * @return int|null The orphan post id to adopt, or null if none.
     */
    private function findBackRefLessPostByLegacyIdentity( \WP_Post $legacy ): ?int {
        global $wpdb;

        // FIX (IMPORTANT #9): match on strong discriminators only — post_date_gmt +
        // post_title + post_type + back-ref-absent. The previous implementation also
        // required post_content byte-equality (bound to the FRESHLY-RECONCILED body),
        // but the probe's purpose is to adopt an orphan left by an OLDER writer —
        // exactly the version most likely to have stored a DIFFERENT body (raw legacy
        // content, or a differently-reconciled body). A one-byte content drift returned
        // zero rows, the code fell through to a fresh wp_insert_post, and an
        // irreplaceable sermon was SILENTLY DUPLICATED (no back-ref → invisible to the
        // Verifier, Rollback, and the >1 guard — reopening the duplicate-orphan hole
        // on the real cross-version crash-resume path).
        //
        // Without the content predicate a title+date collision (two DISTINCT sermons
        // with the same title and exact same GMT date) would produce >1 candidates —
        // the >1 guard below refuses adoption in that case, falling through to a fresh
        // insert and surfacing the ambiguity, which is the correct safe behaviour.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p"
                . " LEFT JOIN {$wpdb->postmeta} backref"
                . "   ON backref.post_id = p.ID AND backref.meta_key = %s"
                . " WHERE p.post_type = %s"
                . "   AND p.post_date_gmt = %s"
                . "   AND p.post_title = %s"
                . "   AND backref.meta_id IS NULL"
                . " ORDER BY p.ID ASC",
                Crosswalk::LEGACY_POST_ID,
                Identifiers::POST_TYPE_SERMON,
                $legacy->post_date_gmt,
                $legacy->post_title
            )
        );

        $ids = array_values( array_map( 'intval', (array) $ids ) );

        // Refuse to adopt if more than one candidate matches: a cross-adoption
        // would swap content between distinct sermons. The caller falls through to
        // a fresh insert, which surfaces the ambiguity without silent data loss.
        if ( count( $ids ) !== 1 ) {
            if ( count( $ids ) > 1 ) {
                error_log( sprintf(
                    'SermonWriter: %d back-ref-less orphan candidates for legacy sermon %d (title=%s, date=%s) — refusing adoption to avoid cross-record content swap.',
                    count( $ids ),
                    $legacy->ID,
                    $legacy->post_title,
                    $legacy->post_date_gmt
                ) );
            }
            return null;
        }

        return $ids[0];
    }

    /** Whether a migrated post has been marked complete. */
    private function isComplete( int $newId ): bool {
        return '' !== (string) get_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, true );
    }

    /** Read a single legacy meta value (first value), READ-ONLY. */
    private function readSingleMeta( int $legacyId, string $key ): ?string {
        $values = get_post_meta( $legacyId, $key, false );
        if ( ! is_array( $values ) || $values === array() ) {
            return null;
        }
        return is_string( $values[0] ) ? $values[0] : (string) $values[0];
    }

    /**
     * Persist the migration flags as a single canonical MIGRATION_FLAGS row
     * (replace semantics — idempotent, never accumulates duplicate rows).
     *
     * @param list<string> $flags
     */
    private function writeFlags( int $newId, array $flags ): void {
        $flags = array_values( array_unique( $flags ) );
        if ( $flags === array() ) {
            // The last open flag just resolved (self-heal): delete the canonical
            // row outright rather than early-returning, which would otherwise leave
            // a stale MIGRATION_FLAGS row persisted forever — a permanent false
            // "unmigrated" signal the self-heal contract promises to clear.
            delete_post_meta( $newId, Crosswalk::MIGRATION_FLAGS );
            return;
        }
        update_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, $flags );
    }

    /** @return list<string> */
    private function readFlags( int $newId ): array {
        $stored = get_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, true );
        return is_array( $stored ) ? array_values( $stored ) : array();
    }
}
