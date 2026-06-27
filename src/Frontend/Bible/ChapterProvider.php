<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Bible;

use Sermonator\Schema\Identifiers as ID;

/**
 * Single resolver for one NORMALIZED chapter (the flat typed-node render shape
 * `[{number:int, nodes:[{type, text}, …]}, …]`), behind a strict, layered read
 * order — the off-render-path heart of the inline-Bible spine (design §3.6).
 *
 * READ ORDER (first hit wins):
 *   1. UPLOADS-VENDORED DISK SNAPSHOT —
 *      `wp-content/uploads/<BIBLE_VENDOR_DIR>/<translation>/<BOOK>/<chapter>.json`
 *      (read + `json_decode`; the file is ALREADY in the normalized render shape,
 *      so it is returned as-is). Local disk only — never network.
 *   2. {@see ChapterCache} transient (the warmed, gen|schema-keyed cache).
 *   3. ONLY WHEN $warmContext IS true: a live {@see ChapterFetcher} fetch, folded
 *      through {@see ChapterNormalizer}, then written to {@see ChapterCache::set}
 *      and returned. This is the warm-on-save / `bible warm` / vendor path.
 *   4. null — the never-fail-WRONG fall-open. The resolver turns null into the 3a
 *      link for that one ref (L8 `chapter-unavailable` / `cold-unwarmed`).
 *
 * CRITICAL INVARIANT — when $warmContext is FALSE (the RENDER context) the
 * provider is strictly disk + transient and performs ZERO network I/O: it never
 * reaches step 3, so {@see ChapterFetcher} (the only class that can touch
 * `wp_remote_get` here) is never invoked. helloao exposes no verse-range endpoint;
 * a render-time fetch would fan out to N serial 5s calls and hang a scripture-dense
 * page. Warming is a synchronous save-time / CLI concern, never a render concern.
 *
 * NEVER THROWS: every step is guarded; any unexpected condition falls open to the
 * next source and ultimately to null. The cache is disposable, so this layer
 * mutates nothing authoritative (no touched-id log) — a strictly nicer
 * reversibility story than the meta backfill (design §3.6).
 */
final class ChapterProvider {
    /**
     * Resolve the normalized chapter, or null.
     *
     * @param string $translation helloao translation id (e.g. ENGWEBP).
     * @param string $bookUSFM    USFM book code (e.g. JHN, 1JN, PSA).
     * @param int    $chapter     1-based chapter number.
     * @param bool   $warmContext TRUE only in a warm/vendor (non-render) context;
     *                            the ONLY context allowed to fetch over the network.
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>|null
     *         The normalized chapter, or null on any miss/failure (→ 3a link).
     */
    public static function get( string $translation, string $bookUSFM, int $chapter, bool $warmContext ): ?array {
        try {
            $translation = trim( $translation );
            $bookUSFM    = trim( $bookUSFM );

            // Structural guard + path-traversal defense: identifiers are simple
            // alphanumeric codes. Anything else (empty, '../', slashes) is rejected
            // before it can compose a filesystem path or a network URL.
            if (
                $chapter < 1
                || 1 !== preg_match( '/^[A-Za-z0-9]+$/', $translation )
                || 1 !== preg_match( '/^[A-Za-z0-9]+$/', $bookUSFM )
            ) {
                return null;
            }

            // (1) Uploads-vendored disk snapshot (already normalized).
            $fromDisk = self::readDiskSnapshot( $translation, $bookUSFM, $chapter );
            if ( null !== $fromDisk ) {
                return $fromDisk;
            }

            // (2) Warmed transient cache.
            $fromCache = ChapterCache::get( $translation, $bookUSFM, $chapter );
            if ( null !== $fromCache ) {
                return $fromCache;
            }

            // (3) Warm-context-ONLY live fetch. In render context we stop here.
            if ( ! $warmContext ) {
                return null;
            }

            $raw = ChapterFetcher::fetch( $translation, $bookUSFM, $chapter );
            if ( null === $raw ) {
                return null;
            }

            $normalized = ChapterNormalizer::normalize( $raw );
            if ( empty( $normalized ) ) {
                // A fetch that normalizes to nothing usable is not a chapter; do
                // not poison the cache with it — fall open to null.
                return null;
            }

            ChapterCache::set( $translation, $bookUSFM, $chapter, $normalized );

            return $normalized;
        } catch ( \Throwable $e ) {
            // never-fail-WRONG: any unexpected condition falls open to the link.
            return null;
        }
    }

    /**
     * Read and decode the vendored per-chapter snapshot, or null when it is
     * absent, unreadable, undecodable, or not a non-empty list. The file is
     * authored by the vendor step ALREADY in the normalized render shape, so a
     * decoded array is returned verbatim.
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>|null
     */
    private static function readDiskSnapshot( string $translation, string $bookUSFM, int $chapter ): ?array {
        $uploads = wp_upload_dir();
        if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
            return null;
        }

        $path = $uploads['basedir']
            . '/' . ID::BIBLE_VENDOR_DIR
            . '/' . $translation
            . '/' . $bookUSFM
            . '/' . $chapter . '.json';

        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $body = file_get_contents( $path );
        if ( ! is_string( $body ) || '' === $body ) {
            return null;
        }

        $decoded = json_decode( $body, true );

        // A non-empty array is a usable snapshot. An empty array or a non-array
        // (corruption / half-written file) is NOT a definitive hit — fall through
        // to the cache rather than asserting an empty chapter is "available".
        return ( is_array( $decoded ) && array() !== $decoded ) ? $decoded : null;
    }
}
