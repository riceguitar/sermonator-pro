<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * The outcome of writing one legacy record into the new namespace.
 *
 *  - $newId   : the new sermonator_* post id (created or resumed).
 *  - $created : true when this call inserted the post; false when it resolved an
 *               already-stamped post (a completed record re-run, or a partial
 *               record resumed) — lets callers distinguish a fresh insert from a
 *               self-healing re-entry.
 *  - $flags   : human-readable migration flags recorded for this record (e.g.
 *               'slug_changed', 'post_content_preserved', 'post_parent_unresolved:<id>').
 *               Mirrors the Crosswalk::MIGRATION_FLAGS row persisted on the post.
 */
final class WriteResult {
    /** @param list<string> $flags */
    public function __construct(
        public readonly int $newId,
        public readonly bool $created,
        public readonly array $flags = array()
    ) {}
}
