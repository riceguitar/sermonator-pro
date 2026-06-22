<?php

declare(strict_types=1);

namespace Sermonator\Migration;

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
}
