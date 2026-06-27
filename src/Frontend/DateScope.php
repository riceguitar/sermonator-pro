<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * How {@see SermonQuery} treats the preached-date meta ({@see \Sermonator\Schema\Identifiers::META_DATE})
 * when selecting which sermons appear.
 *
 * The three modes exist because the NATIVE Sermonator grid and the legacy Sermon Manager
 * `display_sermons()` listing disagree on date semantics, and the #1 standard (data
 * preservation / never-render-wrong) forbids forcing one onto the other:
 *
 *  - {@see self::INCLUSIVE} — the native default. A LEFT-JOIN-style `OR(EXISTS, NOT EXISTS)`
 *    meta_query: dateless sermons are still listed (sorted last) and FUTURE-dated sermons are
 *    included. This branch is pinned byte-for-byte to the original `SermonQuery` and MUST NOT
 *    be mutated or forked.
 *  - {@see self::PREACHED} — reproduces legacy `display_sermons()`: a single `META_DATE <= now()`
 *    NUMERIC clause (plus an explicit EXISTS) that drops BOTH future-dated AND dateless sermons.
 *    The only branch on which the year/month/before/after date-range bounds may be applied.
 *  - {@see self::NONE} — no date meta_query at all: dateless sermons are included and no future
 *    filter is applied (used by non-date orderings such as title/id/rand).
 */
enum DateScope: string {
    case INCLUSIVE = 'inclusive';
    case PREACHED  = 'preached';
    case NONE      = 'none';

    /** Resolve a raw string (default {@see self::INCLUSIVE}, the native pin) to a case. */
    public static function fromString( ?string $value ): self {
        return self::tryFrom( (string) $value ) ?? self::INCLUSIVE;
    }
}
