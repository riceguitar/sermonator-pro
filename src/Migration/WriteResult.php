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
 *  - $resumed : true when this call re-entered a stamped-but-PARTIAL post (back-ref
 *               present, MIGRATION_COMPLETE absent) to drive the remaining /
 *               self-healing steps forward; false on a fresh insert and false when
 *               the post was already COMPLETE (a no-op skip). Together with
 *               $created this makes the three idempotency-gate outcomes observable
 *               and testable — created (fresh), resumed (partial re-entry),
 *               neither (already complete) — so Tasks 13/14 hook the resume path
 *               rather than retrofitting the gate.
 *  - $flags   : human-readable migration flags recorded for this record (e.g.
 *               'slug_changed', 'post_content_preserved', 'post_parent_unresolved:<id>').
 *               Mirrors the Crosswalk::MIGRATION_FLAGS row persisted on the post.
 */
final class WriteResult {
    /** @param list<string> $flags */
    public function __construct(
        public readonly int $newId,
        public readonly bool $created,
        public readonly array $flags = array(),
        public readonly bool $resumed = false
    ) {}
}
