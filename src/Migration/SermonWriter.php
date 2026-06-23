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

                if ( $touched ) {
                    $this->writeFlags( $existing, $flags );
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
            $reconciledForOrphan = $this->reconcileBody( $legacyId, $legacy );
            $flags = $this->applyPostInsertSpine( $orphanId, $legacyId, $legacy, $reconciledForOrphan, array(), false );
            $this->markCompleteUnlessCommentFailureOpen( $orphanId, $flags );

            return new WriteResult( $orphanId, false, $flags, true );
        }

        $flags = array();

        // Body reconciliation from (post_content, sermon_description, post_content_temp).
        $reconciled = $this->reconcileBody( $legacyId, $legacy );

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
            'post_type'      => Identifiers::POST_TYPE_SERMON,
            'post_title'     => $legacy->post_title,
            'post_author'    => $legacy->post_author,
            'post_date'      => $legacy->post_date,
            'post_date_gmt'  => $legacy->post_date_gmt,
            'post_status'    => $legacy->post_status,
            'post_name'      => $legacy->post_name,
            'comment_status' => $legacy->comment_status,
            'ping_status'    => $legacy->ping_status,
            'menu_order'     => $legacy->menu_order,
            'post_excerpt'   => $legacy->post_excerpt,
            'post_password'  => $legacy->post_password,
            'post_parent'    => $newParent,
            'post_content'   => $reconciled['content'],
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
            kses_remove_filters();
            try {
                wp_update_post( array(
                    'ID'          => $newId,
                    'post_parent' => (int) $translated,
                ) );
            } finally {
                kses_init_filters();
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

        $mapped = SermonMetaMapper::map( $rawByKey );
        $keyMap = MappingContract::metaKeyMap();
        $dropped = MappingContract::droppedMetaKeys();

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

            // Delete-then-re-add the full multiset: idempotent on resume, preserves
            // multi-value ordering/arity, replaces single-value rows.
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

        return array_values( array_unique( $flags ) );
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

            $legacyTermIds = wp_get_object_terms( $legacyId, $legacyTax, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $legacyTermIds ) ) {
                // Legacy taxonomy unreadable — nothing to assign for it.
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

        return array_values( array_unique( $flags ) );
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
            }
        }

        if ( $missingTermIds !== array() ) {
            // Resolve each missing legacy term id to its target taxonomy via the
            // legacy assignment (read-only). A legacy term may appear in only one
            // legacy taxonomy, so the first match is authoritative.
            foreach ( $taxonomyMap as $legacyTax => $targetTax ) {
                $legacyTermIds = wp_get_object_terms( $legacyId, $legacyTax, array( 'fields' => 'ids' ) );
                if ( is_wp_error( $legacyTermIds ) ) {
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
                || str_starts_with( (string) $flag, 'term_assign_error:' ) ) {
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
     * Identity match is intentionally strict (date_gmt + email + content hash) so a
     * genuinely distinct comment is never mis-adopted. Each new orphan is consumed
     * at most once.
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

            // Adopt the orphan: stamp the back-ref FIRST (idempotency spine) and add
            // to the map. Consume it so it is not adopted by two legacy comments.
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
     * copy on the new post: GMT date + author email + a hash of the content. These
     * are preserved verbatim by the copy, so an adopted orphan is the same comment.
     */
    private function commentIdentitySignature( \WP_Comment $comment ): string {
        return implode( "\0", array(
            (string) $comment->comment_date_gmt,
            (string) $comment->comment_author_email,
            md5( (string) $comment->comment_content ),
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
     * Insert a post with KSES disabled so structural/media HTML (iframes, etc.)
     * survives verbatim, then restore KSES. The post array is wp_slash'd so
     * quotes/backslashes/unicode are not corrupted by wp_insert_post's unslash.
     */
    private function insertKsesSafe( array $postarr ): int {
        kses_remove_filters();
        try {
            $newId = wp_insert_post( wp_slash( $postarr ), true );
        } finally {
            kses_init_filters();
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

        return $ids === array() ? null : $ids[0];
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
