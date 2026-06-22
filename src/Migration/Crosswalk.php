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

    private function __construct() {}
}
