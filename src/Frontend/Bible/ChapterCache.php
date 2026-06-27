<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Bible;

use Sermonator\Schema\Identifiers as ID;

/**
 * Transient cache for NORMALIZED chapters (the flat typed-node render shape from
 * {@see ChapterNormalizer}, NOT the raw helloao body).
 *
 * Key = `sermonator_bible_` . md5( cacheGen | translation | USFM | chapter | schema ),
 * where:
 *   - `cacheGen`  = {@see ID::OPTION_BIBLE_CACHE_GEN} (operator-owned generation
 *     counter; bumped by the settings save and `wp sermonator bible flush`), and
 *   - `schema`    = {@see ID::BIBLE_CACHE_SCHEMA_VERSION} (code-owned structural
 *     version of the normalized node shape).
 *
 * Folding BOTH into the key makes invalidation a pure key-rotation: a gen bump or a
 * schema bump simply changes every key, so the next read misses and re-warms. We
 * NEVER run a `DELETE … LIKE 'sermonator_bible_%'` sweep — that is a destructive,
 * non-atomic, autoload-thrashing anti-pattern; the cache is disposable and stale
 * entries age out on their {@see MONTH_IN_SECONDS} TTL. This is a strictly nicer
 * reversibility story than the meta backfill: no touched-id log is needed because
 * nothing authoritative is mutated (design §3.6).
 *
 * Impure only via `get_option` / `get_transient` / `set_transient`. No network or
 * disk I/O; the fetch lives in {@see ChapterFetcher}, called from a WARM/VENDOR
 * context — never from the render path.
 */
final class ChapterCache {
    /** Transient key prefix (kept short so the md5 key stays well under WP's limit). */
    private const KEY_PREFIX = 'sermonator_bible_';

    /**
     * Read the cached normalized chapter, or null on a miss (or a non-array value).
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>|null
     */
    public static function get( string $translation, string $bookUsfm, int $chapter ): ?array {
        $value = get_transient( self::key( $translation, $bookUsfm, $chapter ) );

        // get_transient() returns false on a miss; any non-array is treated as a miss.
        return is_array( $value ) ? $value : null;
    }

    /**
     * Cache a normalized chapter for {@see MONTH_IN_SECONDS}.
     *
     * @param list<array{number:int,nodes:list<array{type:string,text:string}>}> $normalized
     */
    public static function set( string $translation, string $bookUsfm, int $chapter, array $normalized ): void {
        set_transient( self::key( $translation, $bookUsfm, $chapter ), $normalized, MONTH_IN_SECONDS );
    }

    /**
     * Compose the gen+schema-keyed transient key. Identical inputs at the same
     * generation and schema version always map to the same key; bumping either the
     * generation or the schema version rotates every key (silent invalidation).
     */
    private static function key( string $translation, string $bookUsfm, int $chapter ): string {
        $gen = (int) get_option( ID::OPTION_BIBLE_CACHE_GEN, 0 );

        $material = implode(
            '|',
            array(
                $gen,
                $translation,
                $bookUsfm,
                $chapter,
                ID::BIBLE_CACHE_SCHEMA_VERSION,
            )
        );

        return self::KEY_PREFIX . md5( $material );
    }
}
