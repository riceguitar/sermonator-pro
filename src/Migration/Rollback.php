<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Exact, ordered, idempotent, NON-DESTRUCTIVE reversal of a migration that has not
 * yet been finalized (design-notes item 18).
 *
 * Rollback deletes ONLY the records the migration made — back-ref-tagged posts +
 * swept partial-orphan sermonator posts + comments enumerated via LEGACY_COMMENT_ID
 * + migration-made terms — and restores any native options the migration overwrote
 * from OPTION_PRE_MIGRATION_BACKUP. Legacy wpfc_ / sermonmanager_ data is left
 * byte-for-byte unchanged. After a run the state retreats to detected (from either
 * migrating — a mid-batch crash — or migrated) and ZERO records carry any back-ref.
 *
 * THE HARD CONSTRAINT (B2a fix-10 — shared-taxonomy counts).
 * SermonWriter::mirrorNativeTaxonomies inserts NATIVE (category/post_tag/custom)
 * term_relationships rows DIRECTLY via $wpdb WITHOUT bumping the shared
 * wp_term_taxonomy.count (the bump is deferred, the tt_ids recorded in
 * OPTION_MIGRATION_PROGRESS['sermons']['native_term_recount_tt_ids']). If rollback
 * removed those rows the ordinary way (wp_delete_post / wp_set_object_terms) WP would
 * fire wp_update_term_count and DECREMENT the church's OWN shared term counts below
 * their true value — silent data corruption of native config. So rollback:
 *   1. Deletes the native relationship rows for every migration-made post DIRECTLY
 *      via $wpdb (object_id=newId, term_taxonomy_id=ttId) BEFORE force-deleting the
 *      post, so the wp_delete_post cascade never sees them and never moves the count.
 *   2. Recounts the affected tt_ids exactly ONCE (wp_update_term_count_now) so the
 *      shared count settles to its TRUE current value.
 *
 * Ordering (strict): native-relationship strip → post force-delete → orphan comment
 * sweep → term delete → option restore/delete. Idempotent: a re-run finds nothing
 * left and completes cleanly. Refuses outright when phase()==='finalized' (the only
 * irreversible terminal phase). Read-only on legacy throughout.
 */
final class Rollback {
    private MigrationState $state;

    public function __construct( ?MigrationState $state = null ) {
        $this->state = $state ?? new MigrationState();
    }

    /**
     * Enumerate the id sets a run() would delete, WITHOUT acting — so a CLI/admin
     * surface can print the exact blast radius before confirmation.
     *
     * @return array{posts:list<int>, terms:list<int>, comments:list<int>, options:list<string>}
     */
    public function pendingDeletions(): array {
        LegacySchemaRegistrar::ensureRegistered();

        return array(
            'posts'    => $this->postsToDelete(),
            'terms'    => $this->termsToDelete(),
            'comments' => $this->commentsToDelete(),
            'options'  => $this->optionsToDelete(),
        );
    }

    /**
     * Reverse the migration. Strictly ordered, idempotent, non-destructive on legacy.
     *
     * @return array{deleted:array{posts:list<int>, terms:list<int>, comments:list<int>, options:list<string>}, restored:list<string>, warnings:list<string>}
     */
    public function run(): array {
        LegacySchemaRegistrar::ensureRegistered();

        $deleted = array(
            'posts'    => array(),
            'terms'    => array(),
            'comments' => array(),
            'options'  => array(),
        );
        $restored = array();
        $warnings = array();

        // Refuse outright on the only irreversible terminal phase. Finalize has
        // already deleted legacy counterparts — there is nothing safe to reverse.
        if ( $this->state->phase() === 'finalized' ) {
            $warnings[] = 'Rollback refused: migration is finalized (irreversible); nothing was deleted.';
            return array( 'deleted' => $deleted, 'restored' => $restored, 'warnings' => $warnings );
        }

        // (a) Posts. For each migration-made post: strip its directly-inserted native
        // term_relationships FIRST (so the force-delete cascade never moves the shared
        // count), then force-delete the post (cascading its sermonator-taxonomy
        // relationships — which DID bump counts and so must be decremented — plus its
        // comments). A post carrying a stray back-ref with no LIVE legacy source is
        // NOT deleted (it is not provably migration residue). An admin-edited migrated
        // post is surfaced in warnings, then still deleted.
        $recountTtIds = array();
        foreach ( $this->stampedPostIds() as $newId ) {
            $legacyId    = (int) get_post_meta( $newId, Crosswalk::LEGACY_POST_ID, true );
            $liveLegacy  = $legacyId > 0 ? get_post( $legacyId ) : null;

            if ( ! ( $liveLegacy instanceof \WP_Post ) ) {
                // Stray back-ref, no live legacy source: a cloned/native post we must
                // not delete. Leave it (and its back-ref) for the operator to resolve.
                $warnings[] = sprintf(
                    'Skipped post %d: it carries a back-ref to legacy id %d but no live legacy source exists (not deleted).',
                    $newId,
                    $legacyId
                );
                continue;
            }

            // Edit-guard: migration set the new post's post_modified equal to the
            // legacy source's. A divergence means an admin edited the migrated copy
            // after migration — surface it before deleting.
            $newPost = get_post( $newId );
            if ( $newPost instanceof \WP_Post
                && (string) $newPost->post_modified !== (string) $liveLegacy->post_modified ) {
                $warnings[] = sprintf(
                    'Post %d was modified after migration (post_modified diverged from its legacy source %d); deleting anyway.',
                    $newId,
                    $legacyId
                );
            }

            $recountTtIds = array_merge( $recountTtIds, $this->stripNativeRelationships( $newId ) );

            wp_delete_post( $newId, true );
            $deleted['posts'][] = $newId;
        }

        // Sweep un-stamped partial-orphan sermonator posts (crash residue between the
        // insert and the back-ref stamp). They have NO back-ref, so the loop above
        // never saw them; they are still our own residue and must go.
        foreach ( $this->unstampedOrphanPostIds() as $orphanId ) {
            $recountTtIds = array_merge( $recountTtIds, $this->stripNativeRelationships( $orphanId ) );
            wp_delete_post( $orphanId, true );
            $deleted['posts'][] = $orphanId;
        }

        // Recount the native shared tt_ids exactly once, AFTER all direct strips, so
        // the shared count settles to its true current value (never decremented below
        // the church's own assignments by the cascade).
        $recountTtIds = array_values( array_unique( array_map( 'intval', $recountTtIds ) ) );
        $this->recountNativeTtIds( $recountTtIds );

        // (b) Orphan comments carrying LEGACY_COMMENT_ID that a cascade missed (e.g. a
        // comment re-parented away from its migrated post).
        foreach ( $this->commentsToDelete() as $commentId ) {
            wp_delete_comment( $commentId, true );
            $deleted['comments'][] = $commentId;
        }

        // (c) Migration-made terms. Explicit args (no default-term reassignment side
        // effects). Only terms carrying the LEGACY_TERM_ID back-ref — never a native
        // term.
        foreach ( $this->termsToDeleteInfo() as $termInfo ) {
            wp_delete_term( $termInfo['term_id'], $termInfo['taxonomy'], array( 'default' => 0, 'force_default' => false ) );
            $deleted['terms'][] = $termInfo['term_id'];
        }

        // (d) Options. Delete migration-created sermonator_* options, then restore any
        // native value the migration overwrote from OPTION_PRE_MIGRATION_BACKUP.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $backup = is_array( $backup ) ? $backup : array();
        foreach ( $this->optionsToDelete() as $optionName ) {
            delete_option( $optionName );
            $deleted['options'][] = $optionName;
            if ( array_key_exists( $optionName, $backup ) ) {
                update_option( $optionName, $backup[ $optionName ] );
                $restored[] = $optionName;
            }
        }

        // Clear the migration's bookkeeping options so a re-run is a clean no-op and a
        // fresh migration starts from pristine state.
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );

        // Retreat the lifecycle phase to detected (the only sanctioned backward move)
        // so a corrected re-migration can proceed. This must fire from BOTH:
        //  - 'migrated'  — a complete-but-unverified migration, and
        //  - 'migrating' — a migration that crashed mid-batch (the contract's
        //    unconditional postcondition is "After run, state → detected"; leaving it
        //    stuck at 'migrating' after deleting posts + sweeping orphans would
        //    violate that). A no-op when the phase is already at/below detected (e.g.
        //    an idempotent second run, or a finalized refusal handled above).
        $phase = $this->state->phase();
        if ( $phase === 'migrated' || $phase === 'migrating' ) {
            $this->state->set( 'detected', true );
        }
        // Reset per-record progress so a re-migration starts clean (no stale
        // complete/in_progress markers pointing at now-deleted posts).
        $this->state->resetRecords();

        return array(
            'deleted'  => $deleted,
            'restored' => array_values( array_unique( $restored ) ),
            'warnings' => $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Native shared-taxonomy handling (THE HARD CONSTRAINT)
    // -------------------------------------------------------------------------

    /**
     * Delete the NATIVE (non-sermonator) term_relationships rows on a migrated post
     * DIRECTLY via $wpdb — the rows mirrorNativeTaxonomies inserted without bumping
     * the shared count. Removing them here (before the force-delete) means the
     * wp_delete_post cascade never touches them and so never decrements the shared
     * count. Returns the affected tt_ids for the single deferred recount.
     *
     * The sermonator-taxonomy relationships (which DID go through wp_set_object_terms
     * and bumped per-term counts the migration owns) are intentionally LEFT for the
     * cascade to remove, so their counts decrement back correctly.
     *
     * @return list<int> The native term_taxonomy_ids whose rows were stripped.
     */
    private function stripNativeRelationships( int $newId ): array {
        global $wpdb;

        $sermonatorTaxonomies = Identifiers::sermonTaxonomies();
        $placeholders         = implode( ',', array_fill( 0, count( $sermonatorTaxonomies ), '%s' ) );

        // Every relationship on this post whose taxonomy is NOT a sermonator taxonomy
        // = a native (category/post_tag/custom) relationship the migration mirrored
        // via a direct insert.
        $ttIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " WHERE tr.object_id = %d AND tt.taxonomy NOT IN ( {$placeholders} )",
                $newId,
                ...$sermonatorTaxonomies
            )
        );
        $ttIds = array_values( array_map( 'intval', (array) $ttIds ) );

        foreach ( $ttIds as $ttId ) {
            // DIRECT delete — never wp_set_object_terms / wp_remove_object_terms, which
            // would fire wp_update_term_count and corrupt the shared count.
            $wpdb->delete(
                $wpdb->term_relationships,
                array( 'object_id' => $newId, 'term_taxonomy_id' => $ttId ),
                array( '%d', '%d' )
            );
        }

        if ( $ttIds !== array() ) {
            // Refresh the object's term cache so a subsequent read does not observe the
            // just-stripped relationships. Pass the post's post type as the object type.
            clean_object_term_cache( $newId, (string) get_post_type( $newId ) );
        }

        return $ttIds;
    }

    /**
     * Recount the native shared term_taxonomy_ids exactly once, so the shared count
     * settles to its TRUE current value after the direct relationship strips. Unions
     * the tt_ids actually stripped this run with the deferred recount list recorded by
     * SermonWriter (native_term_recount_tt_ids) so a count that was never bumped is
     * still re-derived authoritatively.
     *
     * @param list<int> $strippedTtIds
     */
    private function recountNativeTtIds( array $strippedTtIds ): void {
        global $wpdb;

        $deferred = array();
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( is_array( $progress )
            && isset( $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] )
            && is_array( $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] ) ) {
            $deferred = array_map( 'intval', $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] );
        }

        $ttIds = array_values( array_unique( array_merge( $strippedTtIds, $deferred ) ) );
        foreach ( $ttIds as $ttId ) {
            // Only recount tt_ids that still exist (a native term could itself have
            // been removed, though the migration never deletes native terms).
            $taxonomy = $wpdb->get_var(
                $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
            );
            if ( $taxonomy === null ) {
                continue;
            }
            wp_update_term_count_now( array( $ttId ), (string) $taxonomy );
        }
    }

    // -------------------------------------------------------------------------
    // Enumeration helpers (read-only)
    // -------------------------------------------------------------------------

    /**
     * The migration-made post ids (carrying LEGACY_POST_ID) plus un-stamped
     * partial-orphan sermonator posts — the full set pendingDeletions reports. Posts
     * with a stray back-ref/no live source are still LISTED here (run() skips the
     * actual delete + warns); the operator sees the candidate set.
     *
     * @return list<int>
     */
    private function postsToDelete(): array {
        $ids = array_merge( $this->stampedPostIds(), $this->unstampedOrphanPostIds() );
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        sort( $ids );
        return $ids;
    }

    /**
     * Every post carrying a LEGACY_POST_ID back-ref, across both sermonator post
     * types. Status-agnostic (a trashed/auto-draft migrated post still resolves).
     *
     * @return list<int>
     */
    private function stampedPostIds(): array {
        return Crosswalk::allMigratedPostIds();
    }

    /**
     * Un-stamped sermonator_sermon / sermonator_podcast posts — suspected
     * partial-migration residue (a crash between wp_insert_post and the back-ref
     * stamp). A NATIVELY-authored post would also have no back-ref, but rollback is
     * only ever run on a not-yet-finalized migration of a church that had no native
     * sermonator content, so any un-stamped sermonator post is residue. (A native
     * post carrying a STRAY back-ref is handled separately by the live-source guard.)
     *
     * @return list<int>
     */
    private function unstampedOrphanPostIds(): array {
        global $wpdb;

        $stamped = $this->stampedPostIds();
        $exclude = $stamped === array() ? '0' : implode( ',', array_map( 'intval', $stamped ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( %s, %s ) AND ID NOT IN ( {$exclude} ) ORDER BY ID ASC",
                Identifiers::POST_TYPE_SERMON,
                Identifiers::POST_TYPE_PODCAST
            )
        );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /**
     * Comments carrying a LEGACY_COMMENT_ID back-ref — the orphan-comment sweep set
     * (a cascade that missed a comment re-parented away from its migrated post).
     *
     * @return list<int>
     */
    private function commentsToDelete(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s ORDER BY comment_id ASC",
                Crosswalk::LEGACY_COMMENT_ID
            )
        );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /**
     * Migration-made terms — those carrying the LEGACY_TERM_ID back-ref. Returned as
     * {term_id, taxonomy} pairs so wp_delete_term can be called taxonomy-correctly.
     * Never includes a native term (a native term never carries the back-ref).
     *
     * @return list<array{term_id:int, taxonomy:string}>
     */
    private function termsToDeleteInfo(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT tm.term_id, tt.taxonomy FROM {$wpdb->termmeta} tm"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id"
                . " WHERE tm.meta_key = %s ORDER BY tm.term_id ASC",
                Crosswalk::LEGACY_TERM_ID
            )
        );

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[] = array( 'term_id' => (int) $row->term_id, 'taxonomy' => (string) $row->taxonomy );
        }
        return $out;
    }

    /**
     * The migration-made term ids (for pendingDeletions's flat id list).
     *
     * @return list<int>
     */
    private function termsToDelete(): array {
        $ids = array();
        foreach ( $this->termsToDeleteInfo() as $info ) {
            $ids[] = $info['term_id'];
        }
        $ids = array_values( array_unique( $ids ) );
        sort( $ids );
        return $ids;
    }

    /**
     * The sermonator_* options the migration created — the union of the written-key
     * sets every option-writing writer recorded under OPTION_MIGRATION_PROGRESS
     * (ArtworkWriter 'artwork', OptionWriter 'options' — the latter includes the
     * remapped default-podcast pointer). These are the ONLY options rollback deletes;
     * a native value among them is restored from the pre-migration backup afterward.
     *
     * @return list<string>
     */
    private function optionsToDelete(): array {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress ) ) {
            return array();
        }

        $names = array();
        foreach ( array( 'artwork', 'options' ) as $writerKey ) {
            if ( isset( $progress[ $writerKey ]['written_keys'] ) && is_array( $progress[ $writerKey ]['written_keys'] ) ) {
                foreach ( $progress[ $writerKey ]['written_keys'] as $name ) {
                    $names[] = (string) $name;
                }
            }
        }

        return array_values( array_unique( $names ) );
    }
}
