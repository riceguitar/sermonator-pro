<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

/**
 * Immutable result of a legacy->new id resolution attempt (shared by
 * LegacyTermResolver and LegacyPostResolver, and consumed by BOTH the
 * `[sermons]` attribute mapper and the per-podcast feed scope path).
 *
 * The binding contract rule is fail-visible / never-fail-WRONG: a resolution
 * is EITHER a hit carrying a NEW-system id, OR a miss carrying a precise,
 * reportable reason — never a legacy id smuggled through as if it were a new id.
 * Callers surface the reason (drop-the-axis + name-it in the editor notice;
 * never silently render a different set).
 */
final class LegacyResolution {
    private function __construct(
        public readonly ?int $newId,
        public readonly ?string $reason
    ) {}

    /** A resolved new-system id (term_id or post_id). */
    public static function hit( int $newId ): self {
        return new self( $newId, null );
    }

    /** A non-resolution carrying a precise, reportable reason code. */
    public static function miss( string $reason ): self {
        return new self( null, $reason );
    }

    public function resolved(): bool {
        return $this->newId !== null;
    }
}
