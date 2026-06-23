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
 *    steps but STILL re-run term repair — "post exists" must never short-circuit
 *    a now-resolvable term) from a stamped-but-PARTIAL record (resume — re-enter
 *    on the existing post, never insert a second one);
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
        // DateNormalizer is a pure static helper (non-instantiable); the optional
        // instance is accepted for dependency-injection symmetry per the
        // documented interface and is unused while normalize() is called
        // statically (Task 13 wires the date companion rows).
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
                // COMPLETE — a no-op skip for the post/body/meta/comments steps, but
                // term repair STILL runs (self-heal): a record migrated while a term
                // crosswalk was missing must pick the now-available term up on a
                // later pass — "post exists" must never short-circuit term repair.
                // Seed from the persisted flags so an open missing_term_crosswalk
                // flag is re-evaluated (and dropped once it resolves), and so no
                // unrelated flag is lost by writeFlags()'s replace semantics.
                $flags = $this->repairTermsOnExisting( $existing, $legacyId, $this->readFlags( $existing ) );
                return new WriteResult( $existing, false, $flags, false );
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
            $flags               = $this->applyPostInsertSpine( $existing, $legacyId, $legacyForResume, $reconciledForResume, $this->readFlags( $existing ) );
            $this->markComplete( $existing );

            return new WriteResult( $existing, false, $flags, true );
        }

        // --- Insert a fresh post ---
        $legacy = get_post( $legacyId );
        if ( ! $legacy instanceof \WP_Post ) {
            // Nothing to migrate; surface a deterministic, non-crashing result.
            return new WriteResult( 0, false, array( 'legacy_post_missing:' . $legacyId ) );
        }

        $flags = array();

        // Body reconciliation from (post_content, sermon_description, post_content_temp).
        $reconciled = $this->reconcileBody( $legacyId, $legacy );

        // post_parent translation: a non-zero legacy parent is translated through
        // the crosswalk; an untranslatable parent collapses to 0 + a flag (never a
        // dangling legacy id).
        $newParent = 0;
        $legacyParent = (int) $legacy->post_parent;
        if ( 0 !== $legacyParent ) {
            $translated = Crosswalk::findNewByLegacyId( $legacyParent, Identifiers::POST_TYPE_SERMON );
            if ( null !== $translated ) {
                $newParent = $translated;
            } else {
                $flags[] = 'post_parent_unresolved:' . $legacyParent;
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
        );

        $newId = $this->insertKsesSafe( $postarr );
        if ( 0 === $newId ) {
            return new WriteResult( 0, false, array( 'insert_failed:' . $legacyId ) );
        }

        // --- Crash-safety spine: back-ref FIRST, then idempotent self-healing. ---
        $flags = $this->applyPostInsertSpine( $newId, $legacyId, $legacy, $reconciled, $flags );

        // MIGRATION_COMPLETE is written LAST — after meta, terms, and comments —
        // so an abort anywhere before this point leaves a stamped-but-partial post
        // that the idempotency gate resumes rather than skips.
        $this->markComplete( $newId );

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
     * @return list<string>
     */
    private function applyPostInsertSpine( int $newId, int $legacyId, \WP_Post $legacy, array $reconciled, array $flags = array() ): array {
        // Back-ref FIRST, immediately after insert (unique → idempotent on resume).
        Crosswalk::markLegacy( $newId, $legacyId );

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
        $flags = $this->applyTerms( $newId, $legacyId, $flags );

        // Comment copy (Task 14) — depth-first, new ids, remapped parents, stamped
        // with LEGACY_COMMENT_ID so already-copied comments are skipped (idempotent
        // on resume), commentmeta copied with the unserialize discipline.
        $this->copyComments( $newId, $legacyId );

        $this->writeFlags( $newId, $flags );

        return array_values( array_unique( $flags ) );
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
     *    companion (DateNormalizer::normalize, UTC-anchored) is written ALONGSIDE
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
                add_post_meta( $newId, $newKey, $value );
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
            $normalized    = DateNormalizer::normalize( $rawString );
            if ( null === $normalized ) {
                continue; // unparseable — raw is the source of truth; still flagged
            }
            add_post_meta( $newId, Identifiers::META_DATE_NORMALIZED, $normalized );
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
     * @param list<string> $flags
     * @return list<string>
     */
    private function applyTerms( int $newId, int $legacyId, array $flags ): array {
        // Drop stale term flags so this pass re-derives them from scratch — a term
        // that has since been migrated must clear its open missing_term_crosswalk.
        $flags = $this->stripTermFlags( $flags );

        foreach ( MappingContract::taxonomyMap() as $legacyTax => $targetTax ) {
            $legacyTermIds = wp_get_object_terms( $legacyId, $legacyTax, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $legacyTermIds ) ) {
                // Legacy taxonomy unreadable — nothing to assign for it.
                continue;
            }
            $legacyTermIds = array_map( 'intval', (array) $legacyTermIds );

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
     * Re-run ONLY the term-repair step on an already-COMPLETE post, persisting the
     * re-derived flags. "Post exists" must never short-circuit term repair: a post
     * migrated while a term crosswalk was missing picks the now-available term up
     * here. The post/body/meta/comments are intentionally left untouched.
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function repairTermsOnExisting( int $newId, int $legacyId, array $flags ): array {
        $flags = $this->applyTerms( $newId, $legacyId, $flags );
        $this->writeFlags( $newId, $flags );
        return array_values( array_unique( $flags ) );
    }

    /**
     * Drop previously-derived term flags so applyTerms() can re-derive them from
     * the current crosswalk state (a resolved term clears its open flag).
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function stripTermFlags( array $flags ): array {
        return array_values( array_filter(
            $flags,
            static function ( $flag ): bool {
                return ! str_starts_with( (string) $flag, 'missing_term_crosswalk:' )
                    && ! str_starts_with( (string) $flag, 'term_assign_error:' );
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
     *  - the back-ref is stamped on the new comment immediately after insert.
     *
     * Legacy comments are read READ-ONLY.
     */
    private function copyComments( int $newId, int $legacyId ): void {
        $legacyComments = get_comments( array(
            'post_id' => $legacyId,
            'status'  => 'all',
            'orderby' => 'comment_ID',
            'order'   => 'ASC',
            'type'    => '', // every type, including pingbacks/trackbacks
        ) );

        // Rebuild the old→new id map from back-refs already on the new post so a
        // resume remaps parents correctly and skips already-copied comments.
        $oldToNew = $this->existingCommentMap( $newId );

        foreach ( $legacyComments as $legacy ) {
            $legacyCommentId = (int) $legacy->comment_ID;

            // Already copied (idempotent skip).
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
                continue; // do not crash the migration on a single bad comment
            }
            $newCommentId = (int) $newCommentId;

            // Back-ref FIRST (crash-safety + idempotency spine for comments).
            add_comment_meta( $newCommentId, Crosswalk::LEGACY_COMMENT_ID, $legacyCommentId, true );
            $oldToNew[ $legacyCommentId ] = $newCommentId;

            $this->copyCommentMeta( $legacyCommentId, $newCommentId );
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
                add_comment_meta( $newCommentId, $key, $value );
            }
        }
    }

    /** Write the MIGRATION_COMPLETE flag LAST (replace/unique — idempotent). */
    private function markComplete( int $newId ): void {
        update_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, '1' );
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
