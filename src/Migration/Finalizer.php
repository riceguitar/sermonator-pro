<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * The sole gated DESTRUCTIVE step — the migration's point of no return
 * (design-notes item 19).
 *
 * Finalize is the ONLY operation in the lifecycle that deletes legacy
 * (wpfc_* / sermonmanager_*) data. Everything before it is non-destructive and
 * reversible by Rollback; once Finalize succeeds the migration is irreversible.
 * Because of that, it is HARD-GATED on three independent conditions, and unless ALL
 * hold it returns a `refused` reason and deletes NOTHING:
 *
 *   1. MigrationState::phase() === 'verified' — a Verifier pass already proved the
 *      migration complete (every manifest legacy id has exactly one clean migrated
 *      counterpart, no drift, no open failure flag).
 *   2. A FRESH Verifier-style drift rescan still reports `complete` — re-running the
 *      Verifier against the detect-time manifest must STILL be clean, so a legacy
 *      edit, a re-opened failure flag (e.g. a human-review post_content_divergence),
 *      or a newly-inserted live legacy id between verification and finalize aborts
 *      the destructive step. This is what makes "verified an hour ago" insufficient.
 *   3. $confirmed === true — an explicit operator confirmation (the CLI passes this
 *      only behind --yes).
 *
 * On success it deletes legacy data PER VERIFIED COUNTERPART ONLY (never on a bare
 * cardinality match):
 *   - for each legacy POST id whose counterpart was field-by-field verified, delete
 *     the legacy post (wp_delete_post(true)) and strip ONLY the pure back-ref
 *     allowlist (Crosswalk::strippableBackRefs()) from the migrated counterpart —
 *     NEVER LEGACY_POST_CONTENT (the preserved divergent body) and never a
 *     MIGRATION_FLAGS row carrying an unresolved divergence flag (defence-in-depth:
 *     the fresh rescan already guarantees none is open). The allowlist includes
 *     LEGACY_COMMENT_ID, which lives on the migrated COMMENTS (comment meta), not on
 *     the post — so the strip reaches into the migrated post's comments and removes
 *     the now-dangling legacy-comment back-ref there too;
 *   - delete the migrated legacy OPTIONS (the live sermonmanager_* set + the legacy
 *     artwork options + the legacy default-podcast pointer), since their target
 *     sermonator_* counterparts were verified present;
 *   - recount the deferred native shared term_taxonomy_ids exactly ONCE
 *     (B2a forward constraint) so the church's shared counts settle to their true
 *     live value at the point of no return.
 *
 * Deleting legacy TERMS is INTENTIONALLY DEFERRED (not done here). A migrated legacy
 * term may still be a native/shared term the church relies on, and the conservative,
 * data-preserving choice is to leave the legacy terms in place — their migrated
 * counterparts are the church's authoritative records and the orphaned legacy terms
 * carry no live posts once the legacy posts are deleted. This is documented as the
 * deliberate conservative choice (see the must_handle).
 *
 * After success, state → 'finalized'; Rollback then refuses outright.
 *
 * CRASH/RESUME (the destructive step is non-atomic). A single run() deletes legacy
 * posts one counterpart at a time; an abort between counterparts would otherwise wedge
 * the migration: the deleted legacy id has no resolvable counterpart on a fresh rescan
 * (its back-ref was already stripped), so the rescan would report it missing → !complete
 * → Finalize would REFUSE forever, with no Rollback recovery for a force-deleted legacy
 * post. To prevent that, each counterpart is recorded in
 * OPTION_MIGRATION_PROGRESS[PROGRESS_KEY]['finalized_legacy_ids'] BEFORE its legacy
 * delete, and:
 *   - the GATE-2 fresh rescan runs against an EFFECTIVE manifest with those finalized
 *     ids subtracted (checksums removed + sermons/podcasts counts decremented), so an
 *     already-finalized id is expected-absent rather than "missing" — the rescan stays
 *     a pure clean-oracle over the REMAINING work;
 *   - the per-counterpart op is fully idempotent (delete is guarded by get_post(); the
 *     meta/comment strips are no-ops when the rows are already gone), so the loop is
 *     re-run for EVERY manifest id on resume — a mark-before-delete abort still gets its
 *     legacy delete completed (no leak), and a completed delete is never repeated.
 * The state advances to 'finalized' only after the whole loop, so a mid-loop abort
 * leaves the phase at 'verified' and GATE 1 still admits the resume.
 */
final class Finalizer {
    /**
     * Sub-key under OPTION_MIGRATION_PROGRESS where the destructive step records its
     * own resume bookkeeping. Holds 'finalized_legacy_ids': the list of legacy POST
     * ids whose destructive finalize was ENTERED (marked BEFORE the legacy delete).
     * On resume these ids are treated as expected-absent by the fresh drift rescan so
     * a mid-loop abort does not wedge the migration into a "missing forever" refusal.
     */
    public const PROGRESS_KEY = 'finalize';

    private MigrationState $state;
    private Verifier $verifier;

    public function __construct( ?MigrationState $state = null, ?Verifier $verifier = null ) {
        $this->state    = $state ?? new MigrationState();
        $this->verifier = $verifier ?? new Verifier( $this->state );
    }

    /**
     * The pure back-ref keys safe to strip from migrated records at finalize. Equal
     * to Crosswalk::strippableBackRefs() — excludes LEGACY_POST_CONTENT (preserved
     * divergent body) and MIGRATION_FLAGS (review data), which must NEVER be stripped.
     *
     * @return list<string>
     */
    public static function stripAllowlist(): array {
        return Crosswalk::strippableBackRefs();
    }

    /**
     * The sole destructive step. Hard-gated; idempotent on the refusal paths.
     *
     * @param bool $confirmed Explicit operator confirmation (CLI: behind --yes).
     * @return array{deleted:array{posts:list<int>, options:list<string>}, stripped:int, refused:?string}
     */
    public function run( bool $confirmed = false ): array {
        // Legacy reads must work with the legacy plugin DEACTIVATED.
        LegacySchemaRegistrar::ensureRegistered();

        $deleted = array(
            'posts'   => array(),
            'options' => array(),
        );

        // GATE 1: state must be 'verified'.
        if ( $this->state->phase() !== 'verified' ) {
            return $this->refuse(
                $deleted,
                sprintf(
                    'Finalize refused: migration phase is "%s", not "verified" (run verify first).',
                    $this->state->phase()
                )
            );
        }

        // GATE 3 (cheap, check before the rescan): explicit confirmation required.
        if ( $confirmed !== true ) {
            return $this->refuse(
                $deleted,
                'Finalize refused: explicit confirmation required (pass confirmed=true / --yes).'
            );
        }

        // GATE 2: a FRESH drift rescan must STILL be clean. The Verifier is read-only
        // and, run from the 'verified' phase, does not advance state (it only ever
        // advances migrated → verified) — so a re-run here is a pure oracle. Any drift,
        // re-opened failure flag, or newly-inserted live legacy id makes this report
        // incomplete and aborts the destructive step.
        $manifest = $this->state->manifest();
        if ( $manifest === null ) {
            return $this->refuse(
                $deleted,
                'Finalize refused: no detect-time manifest is stored (cannot prove fixity).'
            );
        }

        // RESUME DRAIN: a prior run may have aborted mid-loop, leaving counterparts that
        // were MARKED finalized but whose legacy delete + back-ref strip did not finish.
        // Complete those idempotently FIRST so the live DB matches the reduced manifest
        // before the gate — a drained id's legacy post is gone and its back-ref stripped,
        // so the rescan's expected/live/surplus cross-checks are all consistent. (On a
        // first, never-aborted run the finalized set is empty and this is a no-op.)
        $stripped = 0;
        foreach ( $this->finalizedLegacyIds() as $legacyId ) {
            $newType   = $manifest->checksum( $legacyId ) !== null
                ? Identifiers::POST_TYPE_SERMON
                : Identifiers::POST_TYPE_PODCAST;
            $stripped += $this->finalizeCounterpart( $legacyId, $newType, $deleted );
        }

        // Subtract the finalized (now-drained) counterparts from the manifest so the
        // fresh rescan does not report a deliberately-deleted legacy id as missing and
        // wedge the resume. Over REMAINING work it stays a strict clean-oracle.
        $rescanManifest = $this->manifestExcludingFinalized( $manifest );

        $report = $this->verifier->verify( $rescanManifest );
        if ( ! $report->complete ) {
            return $this->refuse(
                $deleted,
                sprintf(
                    'Finalize refused: a fresh rescan is not clean (drift=%d, missing=%d, openFlags=%d) — resolve before finalizing.',
                    count( $report->drift ),
                    count( $report->missing ),
                    count( $report->openFlags )
                )
            );
        }

        // --- All gates passed. Begin the irreversible destructive step. ------------

        // (1) Per verified POST counterpart: delete the legacy post + strip allowlist
        // back-refs from the migrated counterpart. Driven from the manifest's
        // enumerated legacy id set (sermons) plus the live legacy podcast set (the
        // manifest records podcasts by count only). Because the fresh rescan was clean,
        // every enumerated legacy id resolves to EXACTLY ONE clean counterpart — so we
        // never delete a legacy id that lacks a verified counterpart.
        $sermonLegacyIds  = $manifest->checksummedLegacyIds();
        $podcastLegacyIds = $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST );

        foreach ( $sermonLegacyIds as $legacyId ) {
            $stripped += $this->finalizeCounterpart( (int) $legacyId, Identifiers::POST_TYPE_SERMON, $deleted );
        }
        foreach ( $podcastLegacyIds as $legacyId ) {
            $stripped += $this->finalizeCounterpart( (int) $legacyId, Identifiers::POST_TYPE_PODCAST, $deleted );
        }

        // (2) Delete the migrated legacy OPTIONS. Their sermonator_* targets were
        // verified present, so the legacy originals can go. This is the live
        // sermonmanager_* set + the legacy artwork options + the legacy default-podcast
        // pointer. (The migration NEVER wrote these — they are pure legacy.)
        foreach ( $this->legacyOptionsToDelete() as $optionName ) {
            if ( get_option( $optionName, '__sermonator_absent__' ) !== '__sermonator_absent__' ) {
                delete_option( $optionName );
                $deleted['options'][] = $optionName;
            }
        }

        // (3) Recount the deferred native shared tt_ids exactly once so the church's
        // shared counts settle to their TRUE live value at the point of no return.
        $this->recountNativeTtIds();

        // Done — the point of no return.
        $this->state->set( 'finalized' );

        return array(
            'deleted'  => array(
                'posts'   => array_values( array_unique( $deleted['posts'] ) ),
                'options' => array_values( array_unique( $deleted['options'] ) ),
            ),
            'stripped' => $stripped,
            'refused'  => null,
        );
    }

    /**
     * Finalize one verified legacy→target counterpart: delete the legacy post and
     * strip the allowlist back-refs from its migrated counterpart. Returns the number
     * of back-ref rows stripped (so the caller can total `stripped`).
     *
     * Defence-in-depth: even though the fresh rescan guarantees no open failure flag,
     * a migrated record whose MIGRATION_FLAGS row carries an unresolved divergence
     * flag is left fully intact (no strip, legacy NOT deleted) — divergence is only
     * cleared by a human, never silently by Finalize.
     *
     * @param array{posts:list<int>, options:list<string>} $deleted
     */
    private function finalizeCounterpart( int $legacyId, string $newType, array &$deleted ): int {
        $newId = Crosswalk::findNewByLegacyId( $legacyId, $newType );
        if ( $newId === null ) {
            // No counterpart — never delete a legacy id without a verified counterpart.
            return 0;
        }

        // Defence-in-depth: an unresolved divergence flag blocks both the legacy
        // delete and the back-ref strip for this record (human-clear required).
        if ( $this->hasUnresolvedDivergence( $newId ) ) {
            return 0;
        }

        // Resume bookkeeping: record this counterpart as entered BEFORE the irreversible
        // delete, so a mid-loop abort leaves it expected-absent on the next GATE-2
        // rescan (no "missing forever" wedge). The whole op below is idempotent, so a
        // re-run after an abort completes any delete that did not finish (no leak) and
        // never double-deletes one that did.
        $this->markCounterpartFinalized( $legacyId );

        // Delete the verified legacy source (force — past trash). Idempotent: the
        // get_post() guard skips a re-run whose legacy post is already gone.
        if ( get_post( $legacyId ) instanceof \WP_Post ) {
            wp_delete_post( $legacyId, true );
            $deleted['posts'][] = $legacyId;
        }

        // Strip ONLY the allowlist back-refs from the migrated counterpart. Never
        // LEGACY_POST_CONTENT, never the MIGRATION_FLAGS row. The post-meta keys live
        // on the post; LEGACY_COMMENT_ID lives on the migrated COMMENTS, so it is
        // stripped from the post's comments in a second pass below. All strips are
        // idempotent (delete_*_meta is a no-op when the row is already gone, and the
        // existence check means a resumed pass counts 0 for already-stripped rows).
        $stripped = 0;
        foreach ( self::stripAllowlist() as $key ) {
            if ( $key === Crosswalk::LEGACY_COMMENT_ID ) {
                // Comment meta, not post meta — handled in the comment pass below.
                continue;
            }
            // Count the rows that actually existed before deletion (a record may not
            // carry every allowlist key, e.g. a podcast vs a sermon).
            $existing = get_post_meta( $newId, $key, false );
            if ( is_array( $existing ) && $existing !== array() ) {
                $stripped += count( $existing );
            }
            delete_post_meta( $newId, $key );
        }

        // Comment back-refs: LEGACY_COMMENT_ID was written via add_comment_meta onto the
        // migrated post's COPIED comments (SermonWriter), pointing at a legacy comment id
        // that the legacy-post force-delete above has just orphaned. Strip it from every
        // comment of the migrated counterpart so no migrated comment retains a dangling
        // legacy reference. Folded into $stripped.
        if ( in_array( Crosswalk::LEGACY_COMMENT_ID, self::stripAllowlist(), true ) ) {
            $stripped += $this->stripCommentBackRefs( $newId );
        }

        return $stripped;
    }

    /**
     * Strip LEGACY_COMMENT_ID from every comment of a migrated post. Returns the number
     * of comment back-ref rows removed (for the $stripped total). Idempotent: a comment
     * with no back-ref contributes 0 and delete_comment_meta is a harmless no-op.
     */
    private function stripCommentBackRefs( int $newPostId ): int {
        $commentIds = get_comments( array(
            'post_id' => $newPostId,
            'status'  => 'any',
            'fields'  => 'ids',
            'number'  => 0,
        ) );

        $stripped = 0;
        foreach ( (array) $commentIds as $commentId ) {
            $commentId = (int) $commentId;
            $existing  = get_comment_meta( $commentId, Crosswalk::LEGACY_COMMENT_ID, false );
            if ( is_array( $existing ) && $existing !== array() ) {
                $stripped += count( $existing );
            }
            delete_comment_meta( $commentId, Crosswalk::LEGACY_COMMENT_ID );
        }

        return $stripped;
    }

    /**
     * Whether a migrated post's canonical MIGRATION_FLAGS row carries an unresolved
     * post_content_divergence flag — the one flag that must be HUMAN-cleared before
     * its row may be touched.
     */
    private function hasUnresolvedDivergence( int $newId ): bool {
        $flags = get_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, true );
        if ( ! is_array( $flags ) ) {
            return false;
        }
        foreach ( $flags as $flag ) {
            $flag = (string) $flag;
            if ( $flag === 'post_content_divergence' || str_starts_with( $flag, 'post_content_divergence:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a legacy POST id as entered into the destructive finalize, persisted in
     * OPTION_MIGRATION_PROGRESS[PROGRESS_KEY]['finalized_legacy_ids'] BEFORE the legacy
     * delete fires. On a mid-loop abort this is what lets the GATE-2 rescan treat the id
     * as expected-absent on resume (rather than reporting it missing forever).
     */
    private function markCounterpartFinalized( int $legacyId ): void {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress ) ) {
            $progress = array();
        }
        if ( ! isset( $progress[ self::PROGRESS_KEY ] ) || ! is_array( $progress[ self::PROGRESS_KEY ] ) ) {
            $progress[ self::PROGRESS_KEY ] = array();
        }

        $ids = $progress[ self::PROGRESS_KEY ]['finalized_legacy_ids'] ?? array();
        $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
        if ( ! in_array( $legacyId, $ids, true ) ) {
            $ids[] = $legacyId;
        }

        $progress[ self::PROGRESS_KEY ]['finalized_legacy_ids'] = array_values( array_unique( $ids ) );
        update_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress );
    }

    /**
     * The legacy POST ids already entered into the destructive finalize on a prior
     * (possibly aborted) run.
     *
     * @return list<int>
     */
    private function finalizedLegacyIds(): array {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress )
            || ! isset( $progress[ self::PROGRESS_KEY ]['finalized_legacy_ids'] )
            || ! is_array( $progress[ self::PROGRESS_KEY ]['finalized_legacy_ids'] ) ) {
            return array();
        }

        return array_values( array_unique( array_map(
            'intval',
            $progress[ self::PROGRESS_KEY ]['finalized_legacy_ids']
        ) ) );
    }

    /**
     * Build a derived manifest with the already-finalized legacy ids subtracted, so the
     * GATE-2 fresh rescan does not flag a deliberately-deleted legacy id as missing on a
     * resumed run. Removes each finalized id's checksum and decrements the matching
     * sermons/podcasts count (podcasts are count-only in the manifest, so their count
     * guard would otherwise fire on a shrunk live set). On a first, never-aborted run
     * the finalized set is empty and this returns an equivalent manifest unchanged.
     */
    private function manifestExcludingFinalized( Manifest $manifest ): Manifest {
        $finalized = $this->finalizedLegacyIds();
        if ( $finalized === array() ) {
            return $manifest;
        }

        $data      = $manifest->toArray();
        $counts    = $data['counts'];
        $checksums = $data['checksums'];

        foreach ( $finalized as $legacyId ) {
            if ( isset( $checksums[ $legacyId ] ) ) {
                // A checksummed id is a SERMON — drop its checksum and decrement.
                unset( $checksums[ $legacyId ] );
                if ( isset( $counts['sermons'] ) ) {
                    $counts['sermons'] = max( 0, (int) $counts['sermons'] - 1 );
                }
                continue;
            }
            // Not checksummed: a PODCAST (the manifest records podcasts by count only).
            if ( isset( $counts['podcasts'] ) ) {
                $counts['podcasts'] = max( 0, (int) $counts['podcasts'] - 1 );
            }
        }

        return new Manifest( $counts, $checksums );
    }

    /**
     * The legacy option names to delete at finalize — the live sermonmanager_* set,
     * the legacy artwork options, and the legacy default-podcast pointer. These are
     * pure legacy options the migration NEVER wrote; their sermonator_* targets were
     * verified present, so the originals can go.
     *
     * @return list<string>
     */
    private function legacyOptionsToDelete(): array {
        global $wpdb;

        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%'
            )
        );
        $names = array_values( array_map( 'strval', (array) $names ) );

        $names[] = LegacyIdentifiers::OPTION_TERM_IMAGES;
        $names[] = LegacyIdentifiers::OPTION_TERM_IMAGES_SETTINGS;
        $names[] = LegacyIdentifiers::OPTION_DEFAULT_PODCAST;

        return array_values( array_unique( $names ) );
    }

    /**
     * Recount the deferred native shared term_taxonomy_ids exactly once
     * (native_term_recount_tt_ids recorded by SermonWriter::mirrorNativeTaxonomies).
     * mirrorNativeTaxonomies inserted those native relationship rows WITHOUT bumping
     * the shared wp_term_taxonomy.count; at the point of no return we settle the
     * shared count to its TRUE live value so the church's own counts are correct.
     * Only existing tt_ids are recounted.
     */
    private function recountNativeTtIds(): void {
        global $wpdb;

        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress )
            || ! isset( $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] )
            || ! is_array( $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] ) ) {
            return;
        }

        $ttIds = array_values( array_unique( array_map(
            'intval',
            $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids']
        ) ) );

        foreach ( $ttIds as $ttId ) {
            $taxonomy = $wpdb->get_var(
                $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
            );
            if ( $taxonomy === null ) {
                continue;
            }
            wp_update_term_count_now( array( $ttId ), (string) $taxonomy );
        }
    }

    /**
     * All legacy post ids of a type, ascending, status-agnostic. Read-only.
     *
     * @return list<int>
     */
    private function legacyPostIds( string $legacyType ): array {
        $ids = get_posts( array(
            'post_type'      => $legacyType,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ) );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /**
     * Build a refusal result that deletes nothing.
     *
     * @param array{posts:list<int>, options:list<string>} $deleted
     * @return array{deleted:array{posts:list<int>, options:list<string>}, stripped:int, refused:string}
     */
    private function refuse( array $deleted, string $reason ): array {
        return array(
            'deleted'  => $deleted,
            'stripped' => 0,
            'refused'  => $reason,
        );
    }
}
