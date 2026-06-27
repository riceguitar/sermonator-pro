<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Bible;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Bible\ChapterProvider;

/**
 * ChapterProvider resolves one NORMALIZED chapter through a strict read order:
 *   (1) uploads-vendored disk snapshot → (2) ChapterCache transient →
 *   (3) ONLY when $warmContext is true: ChapterFetcher live fetch +
 *       ChapterNormalizer + ChapterCache::set → (4) null.
 *
 * CRITICAL INVARIANT (design §3.6 / spine never-fail-WRONG, off-render-path):
 * when $warmContext is FALSE (the render context) the provider is strictly
 * disk + transient and performs ZERO network I/O — it never reaches the
 * fetch branch. The proof here is a spy on `wp_remote_get` (the sole network
 * primitive ChapterFetcher can touch): in render context it is NEVER called.
 *
 * The class never throws — every failure path falls open to null, which the
 * resolver turns into the 3a link for that one ref.
 */
final class ChapterProviderTest extends TestCase {
    /** @var string Temp uploads basedir created per test. */
    private string $basedir;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
            define( 'MONTH_IN_SECONDS', 2592000 );
        }

        $this->basedir = sys_get_temp_dir() . '/sermonator-chapterprovider-' . uniqid( '', true );
        mkdir( $this->basedir, 0777, true );

        // Default upload dir → our temp basedir (overridable per test).
        Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $this->basedir ) );
        // ChapterCache key composition reads the cache-gen option.
        Functions\when( 'get_option' )->justReturn( 0 );
    }

    protected function tearDown(): void {
        $this->rrmdir( $this->basedir );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Write a vendored, already-normalized chapter JSON to the disk snapshot path. */
    private function writeSnapshot( string $translation, string $book, int $chapter, string $json ): void {
        $dir = $this->basedir . '/sermonator-bible/' . $translation . '/' . $book;
        mkdir( $dir, 0777, true );
        file_put_contents( $dir . '/' . $chapter . '.json', $json );
    }

    private function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
        }
        rmdir( $dir );
    }

    /** A normalized one-verse chapter in the flat render shape. */
    private function normalizedJohn3(): array {
        return array(
            array(
                'number' => 16,
                'nodes'  => array( array( 'type' => 'text', 'text' => 'For God so loved the world' ) ),
            ),
        );
    }

    // ── (1) Disk snapshot is the first source ───────────────────────────────

    /** A vendored disk snapshot is read+decoded and returned BEFORE the cache. */
    public function test_disk_snapshot_is_read_first_without_touching_cache(): void {
        $normalized = $this->normalizedJohn3();
        $this->writeSnapshot( 'ENGWEBP', 'JHN', 3, (string) json_encode( $normalized ) );

        $transientCalled = false;
        Functions\when( 'get_transient' )->alias( function () use ( &$transientCalled ) {
            $transientCalled = true;
            return false;
        } );

        $result = ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false );

        $this->assertSame( $normalized, $result );
        $this->assertFalse( $transientCalled, 'Disk hit must short-circuit before the transient cache.' );
    }

    /** A corrupt (undecodable) disk file is ignored and the read falls through. */
    public function test_corrupt_disk_file_falls_through_to_cache(): void {
        $this->writeSnapshot( 'ENGWEBP', 'JHN', 3, '<<not json>>' );

        $normalized = $this->normalizedJohn3();
        Functions\when( 'get_transient' )->justReturn( $normalized );

        $this->assertSame( $normalized, ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ) );
    }

    /** An empty-array disk file is NOT treated as a definitive hit; it falls through. */
    public function test_empty_disk_array_falls_through(): void {
        $this->writeSnapshot( 'ENGWEBP', 'JHN', 3, '[]' );
        Functions\when( 'get_transient' )->justReturn( false );

        $this->assertNull( ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ) );
    }

    // ── (2) Transient cache is the second source ────────────────────────────

    /** With no disk snapshot, a cache hit is returned (still zero network). */
    public function test_cache_hit_when_no_disk(): void {
        $normalized = $this->normalizedJohn3();
        Functions\when( 'get_transient' )->justReturn( $normalized );

        $networked = false;
        Functions\when( 'wp_remote_get' )->alias( function () use ( &$networked ) {
            $networked = true;
            return array();
        } );

        $result = ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false );

        $this->assertSame( $normalized, $result );
        $this->assertFalse( $networked, 'A cache hit must never reach the network.' );
    }

    // ── (3) THE off-render-path proof ───────────────────────────────────────

    /**
     * RENDER CONTEXT ($warmContext = false), nothing on disk or in cache:
     * returns null AND makes ZERO network calls. ChapterFetcher's only
     * observable primitive is `wp_remote_get`; spying it proves the fetch
     * branch is never entered off the warm path.
     */
    public function test_render_context_returns_null_and_makes_no_network_call(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $networked = false;
        Functions\when( 'wp_remote_get' )->alias( function () use ( &$networked ) {
            $networked = true;
            return array();
        } );

        $result = ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false );

        $this->assertNull( $result, 'Cold render-context read falls open to null (→ 3a link).' );
        $this->assertFalse( $networked, 'Render context MUST perform zero network I/O.' );
    }

    // ── (3) Warm context DOES fetch + normalize + cache ─────────────────────

    /**
     * WARM CONTEXT ($warmContext = true) on a cold miss: fetches live,
     * normalizes the raw helloao body, writes the normalized chapter to the
     * cache, and returns it.
     */
    public function test_warm_context_fetches_normalizes_and_caches_on_miss(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        // helloao raw chapter body the fetcher will receive.
        $rawBody = (string) json_encode(
            array(
                'chapter' => array(
                    'number'    => 3,
                    'content'   => array(
                        array(
                            'type'    => 'verse',
                            'number'  => 16,
                            'content' => array( 'For God so loved the world' ),
                        ),
                    ),
                    'footnotes' => array(),
                ),
            )
        );

        $networked = false;
        Functions\when( 'wp_remote_get' )->alias( function () use ( &$networked ) {
            $networked = true;
            return array();
        } );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $rawBody );
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );

        $cacheWrite = null;
        Functions\when( 'set_transient' )->alias( function ( $key, $value ) use ( &$cacheWrite ) {
            $cacheWrite = $value;
            return true;
        } );

        $result = ChapterProvider::get( 'ENGWEBP', 'JHN', 3, true );

        $this->assertTrue( $networked, 'Warm-context cold miss must fetch.' );
        $this->assertSame( $this->normalizedJohn3(), $result, 'Returns the normalized chapter.' );
        $this->assertSame( $this->normalizedJohn3(), $cacheWrite, 'Caches the normalized chapter.' );
    }

    /** Warm context but the fetch fails (transport error): returns null, no cache write. */
    public function test_warm_context_fetch_failure_returns_null_no_cache_write(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $error = new class {
            public function get_error_message(): string {
                return 'cURL error 28: timed out';
            }
        };
        Functions\when( 'wp_remote_get' )->justReturn( $error );
        Functions\when( 'is_wp_error' )->justReturn( true );
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );

        $cacheWritten = false;
        Functions\when( 'set_transient' )->alias( function () use ( &$cacheWritten ) {
            $cacheWritten = true;
            return true;
        } );

        $this->assertNull( ChapterProvider::get( 'ENGWEBP', 'JHN', 3, true ) );
        $this->assertFalse( $cacheWritten, 'A failed fetch must not write the cache.' );
    }

    /** Warm context where the fetch succeeds but normalizes to empty: null, no cache write. */
    public function test_warm_context_empty_normalization_returns_null(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        // Valid shape but a non-verse content payload → normalizer yields [].
        $rawBody = (string) json_encode(
            array( 'chapter' => array( 'content' => array( array( 'type' => 'heading' ) ), 'footnotes' => array() ) )
        );

        Functions\when( 'wp_remote_get' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $rawBody );
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );

        $cacheWritten = false;
        Functions\when( 'set_transient' )->alias( function () use ( &$cacheWritten ) {
            $cacheWritten = true;
            return true;
        } );

        $this->assertNull( ChapterProvider::get( 'ENGWEBP', 'JHN', 3, true ) );
        $this->assertFalse( $cacheWritten, 'An empty normalization is not a usable chapter.' );
    }

    // ── Defensive guards (never-throws) ─────────────────────────────────────

    /** Blank/invalid identifiers short-circuit to null and never network. */
    public function test_blank_or_invalid_inputs_return_null(): void {
        $networked = false;
        Functions\when( 'wp_remote_get' )->alias( function () use ( &$networked ) {
            $networked = true;
            return array();
        } );
        Functions\when( 'get_transient' )->justReturn( false );

        $this->assertNull( ChapterProvider::get( '', 'JHN', 3, true ) );
        $this->assertNull( ChapterProvider::get( 'ENGWEBP', '', 3, true ) );
        $this->assertNull( ChapterProvider::get( 'ENGWEBP', 'JHN', 0, true ) );
        // Path-traversal attempt is rejected structurally.
        $this->assertNull( ChapterProvider::get( '../etc', 'JHN', 3, true ) );
        $this->assertNull( ChapterProvider::get( 'ENGWEBP', '../JHN', 3, true ) );

        $this->assertFalse( $networked, 'Invalid inputs must not reach the network.' );
    }

    /** An upload dir without a basedir simply skips disk and uses the cache. */
    public function test_missing_basedir_skips_disk(): void {
        Functions\when( 'wp_upload_dir' )->justReturn( array( 'error' => 'no uploads' ) );

        $normalized = $this->normalizedJohn3();
        Functions\when( 'get_transient' )->justReturn( $normalized );

        $this->assertSame( $normalized, ChapterProvider::get( 'ENGWEBP', 'JHN', 3, false ) );
    }
}
