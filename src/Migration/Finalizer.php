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
 * CRASH/RESUME (the destructive step is non-atomic). The three gates are enforced ONCE,
 * on the first run, against the fully-intact (pre-destruction) database. The instant
 * they pass, the destructive step is COMMITTED: we advance the phase to 'finalized'
 * BEFORE deleting anything, then run an idempotent destructive drain (delete legacy
 * posts per verified counterpart, delete legacy options, recount native tt_ids). A crash
 * anywhere in the (non-atomic) drain leaves the phase at 'finalized'; the next run()
 * re-enters the drain DIRECTLY and completes it — it does NOT re-run the gates, because
 * GATE 2's fresh rescan is a PRE-destruction oracle that would falsely refuse over the
 * half-deleted DB (a deleted-and-stripped counterpart reads as "missing"). The drain is
 * fully idempotent: deletes are guarded by get_post()/findNewByLegacyId(), and posts are
 * enumerated from the IMMUTABLE manifest (sermons AND podcasts are checksummed there), so
 * a counterpart deleted-but-not-yet-stripped is still re-enumerated and its dangling
 * back-ref stripped (no leak). Advancing the phase first also means Rollback (which
 * refuses from 'finalized') can never interleave with the in-progress destruction.
 */
final class Finalizer {
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
     * Enumerate the blast radius a successful run() would delete, WITHOUT acting — so a
     * CLI/admin surface can print the exact id set before the operator confirms the
     * irreversible step (mirroring Rollback::pendingDeletions). Read-only.
     *
     * 'posts' are the legacy ids that resolve to a clean, deletable counterpart: every
     * manifest-checksummed sermon id + every live legacy podcast id whose counterpart
     * exists and carries no unresolved divergence flag (the exact records run() would
     * force-delete). 'options' are the pure-legacy option names run() would delete that
     * are currently present. This is a PREVIEW: run() still re-enforces all three gates,
     * so a preview at a not-yet-verified phase shows what WOULD be deleted once verified.
     *
     * @return array{posts:list<int>, options:list<string>}
     */
    public function pendingDeletions(): array {
        LegacySchemaRegistrar::ensureRegistered();

        $posts    = array();
        $manifest = $this->state->manifest();
        if ( $manifest !== null ) {
            foreach ( $manifest->checksummedLegacyIds() as $legacyId ) {
                if ( $this->isDeletableCounterpart( (int) $legacyId, Identifiers::POST_TYPE_SERMON ) ) {
                    $posts[] = (int) $legacyId;
                }
            }
        }
        foreach ( $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST ) as $legacyId ) {
            if ( $this->isDeletableCounterpart( $legacyId, Identifiers::POST_TYPE_PODCAST ) ) {
                $posts[] = $legacyId;
            }
        }
        $posts = array_values( array_unique( $posts ) );
        sort( $posts );

        $options = array();
        foreach ( $this->legacyOptionsToDelete() as $name ) {
            if ( get_option( $name, '__sermonator_absent__' ) !== '__sermonator_absent__' ) {
                $options[] = $name;
            }
        }

        return array( 'posts' => $posts, 'options' => array_values( array_unique( $options ) ) );
    }

    /**
     * Whether a legacy id has a clean, deletable migrated counterpart — it resolves via
     * the crosswalk AND carries no unresolved divergence flag (the same two conditions
     * finalizeCounterpart requires before it would force-delete the legacy source).
     */
    private function isDeletableCounterpart( int $legacyId, string $newType ): bool {
        $newId = Crosswalk::findNewByLegacyId( $legacyId, $newType );
        if ( $newId === null ) {
            return false;
        }
        return ! $this->hasUnresolvedDivergence( $newId );
    }

    /**
     * The sole destructive step. Hard-gated on the first run; idempotent on the refusal
     * paths AND on a committed-but-incomplete resume.
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

        $phase = $this->state->phase();

        // RESUME of a committed-but-incomplete finalize. Once the three gates passed on
        // the first run we advanced the phase to 'finalized' BEFORE any deletion (the
        // destructive step is committed at that point). A crash mid-destruction therefore
        // leaves the phase at 'finalized'; re-entering here runs the IDEMPOTENT drain
        // directly. We must NOT re-run the gates: GATE 2's fresh rescan is a
        // PRE-destruction oracle and would falsely refuse over the half-deleted DB (a
        // legacy post already deleted with its back-ref already stripped reads as
        // "missing"). A redundant re-run on a fully-completed finalize is a harmless
        // no-op drain. This is what closes the wedge where a half-deleted DB could fail
        // GATE 2 forever (B2b review finalize-correctness-0 / data-loss-0).
        if ( $phase === 'finalized' ) {
            return $this->drainDestructive( $deleted );
        }

        // ---- FIRST run: enforce all three gates on the fully-intact (pre-destruction)
        //      database, cheapest first. ----

        // GATE 1: state must be 'verified'.
        if ( $phase !== 'verified' ) {
            return $this->refuse(
                $deleted,
                sprintf(
                    'Finalize refused: migration phase is "%s", not "verified" (run verify first).',
                    $phase
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

        // GATE 2: a FRESH drift rescan must STILL be clean over the INTACT DB. Run from
        // the 'verified' phase the Verifier is a pure read-only oracle (it only ever
        // advances migrated → verified, so it does not move the phase here). Any drift, a
        // re-opened failure flag, or a newly-inserted live legacy id makes this report
        // incomplete and aborts the destructive step — leaving the phase at 'verified'
        // for a corrected retry (nothing has been deleted yet).
        $manifest = $this->state->manifest();
        if ( $manifest === null ) {
            return $this->refuse(
                $deleted,
                'Finalize refused: no detect-time manifest is stored (cannot prove fixity).'
            );
        }
        $report = $this->verifier->verify( $manifest );
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

        // COMMIT POINT. All gates passed → the destructive step is committed. Advance the
        // phase to 'finalized' BEFORE touching any data, so a crash anywhere in the
        // (non-atomic) destruction re-enters the idempotent drain on the next run()
        // WITHOUT re-gating. Rollback also refuses from 'finalized', so no rollback can
        // interleave with the in-progress destruction.
        $this->state->set( 'finalized' );

        return $this->drainDestructive( $deleted );
    }

    /**
     * The idempotent destructive drain — the actual deletion of legacy data, run ONLY
     * after the phase is 'finalized' (committed). Safe to re-run after a crash: every
     * step is a no-op once already applied.
     *
     *  (1) Per verified POST counterpart, enumerated from the IMMUTABLE manifest (both
     *      sermons AND podcasts are checksummed there) — NOT the live DB — so a
     *      counterpart deleted-but-not-yet-stripped by a prior aborted drain is still
     *      re-enumerated and its dangling back-ref stripped (no leak). finalizeCounterpart
     *      is idempotent (get_post() / findNewByLegacyId() guards skip already-done work)
     *      and never deletes a legacy id lacking a clean counterpart.
     *  (2) Delete the migrated legacy OPTIONS (skip already-absent — idempotent).
     *  (3) Recount the deferred native shared tt_ids exactly once (idempotent recompute).
     *
     * @param array{posts:list<int>, options:list<string>} $deleted
     * @return array{deleted:array{posts:list<int>, options:list<string>}, stripped:int, refused:null}
     */
    private function drainDestructive( array $deleted ): array {
        $manifest = $this->state->manifest();
        $stripped = 0;

        if ( $manifest !== null ) {
            foreach ( $manifest->checksummedLegacyIds() as $legacyId ) {
                $stripped += $this->finalizeCounterpart( (int) $legacyId, Identifiers::POST_TYPE_SERMON, $deleted );
            }
            foreach ( $manifest->checksummedPodcastLegacyIds() as $legacyId ) {
                $stripped += $this->finalizeCounterpart( (int) $legacyId, Identifiers::POST_TYPE_PODCAST, $deleted );
            }
        }

        foreach ( $this->legacyOptionsToDelete() as $optionName ) {
            if ( get_option( $optionName, '__sermonator_absent__' ) !== '__sermonator_absent__' ) {
                delete_option( $optionName );
                $deleted['options'][] = $optionName;
            }
        }

        // Recount the deferred native shared tt_ids exactly once so the church's shared
        // counts settle to their TRUE live value at the point of no return.
        $this->recountNativeTtIds();

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

        // The whole op below is idempotent (the get_post()/findNewByLegacyId() guards),
        // so a re-run after a crashed drain completes any delete/strip that did not
        // finish (no leak) and never double-deletes one that did. The 'finalized' phase
        // gate in run() is the resume signal — no per-id bookkeeping is needed.

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
