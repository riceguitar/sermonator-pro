<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use Sermonator\Frontend\Bible\ChapterProvider;
use Sermonator\Frontend\Bible\ChapterCache;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see ChapterProvider} under a real WordPress object
 * stack (real transients, real `wp_upload_dir()`, real filesystem), exercising
 * the disk → cache → (warm-only) fetch → null read order end to end.
 *
 * NOT run in this environment (no Docker / wp-env). Authored to run later under
 * wp-env. The render-context invariant (zero network I/O) is asserted WITHOUT a
 * live fetch: with nothing on disk or in the transient cache, a render-context
 * read returns null. The warm path's live helloao fetch is intentionally NOT
 * exercised here (it is a network dependency) — that branch is covered by the
 * mocked unit suite; here we drive the warm path via a pre-seeded transient and
 * a pre-seeded disk snapshot so the resolver logic is proven against real WP I/O.
 */
final class ChapterProviderTest extends WP_UnitTestCase {
    /** @var list<string> Snapshot files written during a test, for teardown. */
    private array $written = array();

    public function tear_down(): void {
        foreach ( $this->written as $path ) {
            if ( is_file( $path ) ) {
                unlink( $path );
            }
        }
        $this->written = array();
        parent::tear_down();
    }

    /** A normalized one-verse chapter in the flat render shape. */
    private function normalizedChapter(): array {
        return array(
            array(
                'number' => 16,
                'nodes'  => array( array( 'type' => 'text', 'text' => 'For God so loved the world' ) ),
            ),
        );
    }

    /**
     * Write a SCHEMA-STAMPED envelope snapshot `{schema, chapter}` (the on-disk
     * format the provider's disk tier requires). A null $schema stamps the current
     * {@see ID::BIBLE_CACHE_SCHEMA_VERSION}.
     */
    private function writeSnapshot( string $translation, string $book, int $chapter, array $normalized, ?int $schema = null ): void {
        $uploads = wp_upload_dir();
        $dir     = $uploads['basedir'] . '/' . ID::BIBLE_VENDOR_DIR . '/' . $translation . '/' . $book;
        wp_mkdir_p( $dir );
        $path     = $dir . '/' . $chapter . '.json';
        $envelope = array(
            'schema'  => $schema ?? ID::BIBLE_CACHE_SCHEMA_VERSION,
            'chapter' => $normalized,
        );
        file_put_contents( $path, (string) json_encode( $envelope ) );
        $this->written[] = $path;
    }

    /** The vendored disk snapshot is returned, ahead of the cache. */
    public function test_disk_snapshot_resolves(): void {
        $normalized = $this->normalizedChapter();
        $this->writeSnapshot( 'ENGWEBP', 'JHN', 3, $normalized );

        $this->assertSame( $normalized, ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ) );
    }

    /** With no disk snapshot, a warmed transient is returned in render context. */
    public function test_cache_hit_resolves_in_render_context(): void {
        $normalized = $this->normalizedChapter();
        ChapterCache::set( 'ENGWEBP', 'JHN', 3, $normalized );

        $this->assertSame( $normalized, ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ) );
    }

    /**
     * RENDER CONTEXT cold read (nothing on disk, nothing cached): returns null.
     * Proves the provider falls open offline without attempting a fetch — the
     * resolver turns this into the 3a link.
     */
    public function test_render_context_cold_read_returns_null(): void {
        $this->assertNull( ChapterProvider::get( 'ENGWEBP', 'JHN', 19, false ) );
    }

    /** A gen bump rotates the cache key: a previously warmed chapter misses cold. */
    public function test_cache_gen_bump_invalidates_warm_chapter(): void {
        ChapterCache::set( 'ENGWEBP', 'JHN', 3, $this->normalizedChapter() );
        $this->assertNotNull( ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ) );

        update_option( ID::OPTION_BIBLE_CACHE_GEN, (int) get_option( ID::OPTION_BIBLE_CACHE_GEN, 0 ) + 1 );

        $this->assertNull(
            ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ),
            'A cache-gen bump must rotate the key so the stale chapter misses.'
        );
    }

    /**
     * SCHEMA-INVALIDATION on disk: a snapshot stamped under a STALE schema is not
     * served verbatim. Disk is checked first, so without this guard it would shadow
     * the correctly re-warmed cache; here the stale disk file misses and the warmed
     * transient (current schema) is returned instead.
     */
    public function test_stale_schema_disk_snapshot_falls_through_to_cache(): void {
        $stale = array(
            array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'OLD SHAPE' ) ) ),
        );
        // Stamp a schema that is NOT the current one.
        $this->writeSnapshot( 'ENGWEBP', 'JHN', 3, $stale, ID::BIBLE_CACHE_SCHEMA_VERSION + 1 );

        $fresh = $this->normalizedChapter();
        ChapterCache::set( 'ENGWEBP', 'JHN', 3, $fresh );

        $this->assertSame(
            $fresh,
            ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ),
            'A stale-schema disk snapshot must miss and let the re-warmed cache win.'
        );
    }

    /** A bare/legacy unstamped array on disk falls through (not a schema-stamped envelope). */
    public function test_unstamped_disk_snapshot_falls_through(): void {
        $uploads = wp_upload_dir();
        $dir     = $uploads['basedir'] . '/' . ID::BIBLE_VENDOR_DIR . '/ENGWEBP/JHN';
        wp_mkdir_p( $dir );
        $path = $dir . '/3.json';
        // Bare array, the pre-fix verbatim format — no `schema` member.
        file_put_contents( $path, (string) json_encode( $this->normalizedChapter() ) );
        $this->written[] = $path;

        $this->assertNull(
            ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ),
            'An unstamped (non-envelope) disk body is not a definitive hit.'
        );
    }

    /** Path-traversal identifiers are rejected (never compose a filesystem path). */
    public function test_traversal_inputs_return_null(): void {
        $this->assertNull( ChapterProvider::get( '../etc', 'JHN', 3, false ) );
        $this->assertNull( ChapterProvider::get( 'ENGWEBP', '../JHN', 3, false ) );
    }
}
