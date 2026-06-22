<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Back-reference meta keys recorded on migrated records, linking each new
 * record to its legacy source. The migration's spine: makes rollback exact,
 * re-runs idempotent, and verification pairing possible. (Query helpers are
 * added in Plan B2 where records are written.)
 */
final class Crosswalk {
    /** On migrated sermon AND podcast posts (legacy post IDs are unique across post types). */
    public const LEGACY_POST_ID = '_sermonator_legacy_id';
    /** On migrated taxonomy terms. */
    public const LEGACY_TERM_ID = '_sermonator_legacy_term_id';
    /** On migrated taxonomy terms (term_taxonomy ID from legacy). */
    public const LEGACY_TERM_TT_ID = '_sermonator_legacy_term_tt_id';
    /** On migrated comments. */
    public const LEGACY_COMMENT_ID = '_sermonator_legacy_comment_id';
    /** Set to true once migration is marked complete for a record. */
    public const MIGRATION_COMPLETE = '_sermonator_migration_complete';
    /** Legacy term slug preserved for verification. */
    public const LEGACY_SLUG = '_sermonator_legacy_slug';
    /** Migration flags for metadata about the migration state. */
    public const MIGRATION_FLAGS = '_sermonator_migration_flags';
    /** Legacy post content preserved for rollback/verification (safe from stripping). */
    public const LEGACY_POST_CONTENT = '_sermonator_legacy_post_content';

    private function __construct() {}

    /**
     * Back-references safe for the Finalizer to strip.
     * Excludes LEGACY_POST_CONTENT and MIGRATION_FLAGS which must be preserved.
     *
     * @return list<string> Pure back-ref keys to strip during finalization.
     */
    public static function strippableBackRefs(): array {
        return [
            self::LEGACY_POST_ID,
            self::LEGACY_TERM_ID,
            self::LEGACY_TERM_TT_ID,
            self::LEGACY_COMMENT_ID,
            self::MIGRATION_COMPLETE,
        ];
    }

    /**
     * Stamp a migrated post with its legacy source id — the crash-safety
     * back-ref, written immediately after the post is inserted. A single row
     * (unique=true) so re-stamping never accumulates duplicates.
     */
    public static function markLegacy( int $newPostId, int $legacyPostId ): void {
        add_post_meta( $newPostId, self::LEGACY_POST_ID, $legacyPostId, true );
    }

    /**
     * Resolve the migrated post for a legacy source id, scoped to a post type.
     *
     * Resolution is authoritative (the LEGACY_POST_ID back-ref meta) and
     * STATUS-AGNOSTIC: a stamped post that has been trashed or left as an
     * auto-draft must still be found, so a resumed migration re-uses it instead
     * of inserting a duplicate. The post-type filter (joined on $wpdb->posts)
     * keeps a sermon legacy id from resolving against the podcast type.
     *
     * At-most-one is asserted: on a corrupt >1 state we are loud (error_log)
     * but deterministic, returning the lowest new post id.
     *
     * @return int|null The new post id, or null if no migrated post carries it.
     */
    public static function findNewByLegacyId( int $legacyPostId, string $postType = Identifiers::POST_TYPE_SERMON ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm"
                . " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
                . " WHERE pm.meta_key = %s AND pm.meta_value = %d AND p.post_type = %s"
                . " ORDER BY pm.post_id ASC",
                self::LEGACY_POST_ID,
                $legacyPostId,
                $postType
            )
        );

        $ids = array_map( 'intval', (array) $ids );

        if ( $ids === [] ) {
            return null;
        }

        if ( count( $ids ) > 1 ) {
            error_log( sprintf(
                'Sermonator Crosswalk: legacy post id %d maps to %d new %s posts (%s); using lowest id.',
                $legacyPostId,
                count( $ids ),
                $postType,
                implode( ',', $ids )
            ) );
        }

        return $ids[0];
    }

    /**
     * All migrated post ids of a given post type — those carrying the
     * LEGACY_POST_ID back-ref. Status-agnostic; excludes natively-authored
     * posts (which have no back-ref).
     *
     * @return list<int>
     */
    public static function migratedPostIds( string $postType = Identifiers::POST_TYPE_SERMON ): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm"
                . " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
                . " WHERE pm.meta_key = %s AND p.post_type = %s"
                . " ORDER BY pm.post_id ASC",
                self::LEGACY_POST_ID,
                $postType
            )
        );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /**
     * Every migrated post id across BOTH the sermon and podcast post types, so
     * rollback / allMigrated callers cover podcasts as well as sermons.
     *
     * @return list<int>
     */
    public static function allMigratedPostIds(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm"
                . " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
                . " WHERE pm.meta_key = %s AND p.post_type IN ( %s, %s )"
                . " ORDER BY pm.post_id ASC",
                self::LEGACY_POST_ID,
                Identifiers::POST_TYPE_SERMON,
                Identifiers::POST_TYPE_PODCAST
            )
        );

        return array_values( array_map( 'intval', (array) $ids ) );
    }
}
