<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Bible;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Bible\BibleWarmer;
use Sermonator\Schema\Identifiers as ID;

/**
 * Phase 3b Task 9 — unit coverage for {@see BibleWarmer}, the cache-warming engine.
 *
 * Proves the spine + bar-clearing guarantees with an INJECTED resolver (no disk/network)
 * and an injected candidate provider (no WP_Query):
 *   - warm primes the cache for a sermon's cited chapters (resolver called with
 *     warmContext:true for each cold chapter);
 *   - FILL-MISSING: a chapter already resolvable off the render path (zero-network get
 *     returns non-null) is SKIPPED — never re-warmed;
 *   - MIGRATION-GATED: warming is inert during an active migration;
 *   - the reverse is a cache-gen bump (a flush), with NO touched-id log;
 *   - the passage/envelope are NEVER mutated (no post-meta write ever occurs);
 *   - the chunked CLI sweep dedups cited chapters across sermons and honours --limit.
 */
final class BibleWarmerTest extends TestCase {
    /** @var list<array{translation:string,book:string,chapter:int,warm:bool}> */
    private array $resolverCalls = array();

    /** @var array<string,bool> "BOOK:chapter" already-present (disk/cache) set. */
    private array $present = array();

    /** @var array<int,string> postId => META_BIBLE_REFS JSON. */
    private array $refsByPost = array();

    /** @var array<int,array<string,mixed>> spy on any update_post_meta call (must stay empty). */
    private array $metaWrites = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->resolverCalls = array();
        $this->present       = array();
        $this->refsByPost    = array();
        $this->metaWrites    = array();

        // Migration phase 'none' = editing allowed (overridden per test for the gate).
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'none' );
            }
            if ( ID::OPTION_BIBLE_INLINE_TRANSLATION === $name ) {
                return 'ENGWEBP';
            }
            if ( ID::OPTION_BIBLE_INLINE_ENABLED === $name ) {
                return true;
            }
            if ( ID::OPTION_BIBLE_CACHE_GEN === $name ) {
                return 4;
            }
            return $default;
        } );
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => $value );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            if ( ID::META_BIBLE_REFS === $key ) {
                return $this->refsByPost[ (int) $id ] ?? '';
            }
            return '';
        } );
        // A post-meta WRITE here would be a data-preservation violation — spy + fail.
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
            $this->metaWrites[] = array( 'id' => $id, 'key' => $key, 'value' => $value );
            return true;
        } );
        Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
            $this->metaWrites[] = array( 'id' => $id, 'key' => $key, 'value' => null );
            return true;
        } );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * The injected resolver: records every call, returns the chapter list when the chapter
     * is "present" (disk/cache hit in render context) OR when warming (a successful fetch),
     * and null otherwise. A chapter in {@see $present} is treated as already-cached.
     */
    private function resolver(): callable {
        return function ( string $translation, string $book, int $chapter, bool $warm ) {
            $this->resolverCalls[] = array(
                'translation' => $translation,
                'book'        => $book,
                'chapter'     => $chapter,
                'warm'        => $warm,
            );

            $key = $book . ':' . $chapter;

            // Render-context (warm=false): only a present chapter resolves; else null.
            if ( ! $warm ) {
                return ( $this->present[ $key ] ?? false ) ? array( array( 'number' => $chapter ) ) : null;
            }

            // Warm-context: simulate a successful fetch+cache → becomes present.
            $this->present[ $key ] = true;
            return array( array( 'number' => $chapter ) );
        };
    }

    private function envelope( array $refs ): string {
        return (string) json_encode( array( 'v' => 1, 'refs' => $refs ) );
    }

    private function ref( string $book, int $chapterStart, ?int $chapterEnd = null ): array {
        return array(
            'bookUSFM'     => $book,
            'chapterStart' => $chapterStart,
            'chapterEnd'   => $chapterEnd,
            'verseStart'   => 1,
            'verseEnd'     => 1,
        );
    }

    // ── warm-on-save primes the cited chapters ──────────────────────────────

    public function test_warm_for_post_primes_cited_chapters_in_warm_context(): void {
        $this->refsByPost[ 7 ] = $this->envelope( array(
            $this->ref( 'JHN', 3 ),
            $this->ref( 'ROM', 5 ),
        ) );

        $warmer = new BibleWarmer( null, $this->resolver() );
        $result = $warmer->warmForPost( 7 );

        $this->assertFalse( $result['gated'] );
        $this->assertSame( 'ENGWEBP', $result['translation'] );
        $this->assertSame( 2, $result['warmed'] );
        $this->assertSame( 0, $result['failed'] );

        // Each cold chapter was warmed once with warmContext:true.
        $warmCalls = array_filter( $this->resolverCalls, static fn( $c ) => $c['warm'] );
        $books     = array_values( array_map( static fn( $c ) => $c['book'] . ':' . $c['chapter'], $warmCalls ) );
        $this->assertTrue( in_array( 'JHN:3', $books, true ), 'JHN:3 should have been warmed.' );
        $this->assertTrue( in_array( 'ROM:5', $books, true ), 'ROM:5 should have been warmed.' );

        // DATA PRESERVATION: warming never writes post meta.
        $this->assertSame( array(), $this->metaWrites, 'Warming must never write post meta.' );
    }

    public function test_warm_for_post_skips_already_present_chapter(): void {
        // JHN 3 already on disk/cache; ROM 5 is cold.
        $this->present[ 'JHN:3' ] = true;
        $this->refsByPost[ 7 ]    = $this->envelope( array(
            $this->ref( 'JHN', 3 ),
            $this->ref( 'ROM', 5 ),
        ) );

        $result = ( new BibleWarmer( null, $this->resolver() ) )->warmForPost( 7 );

        $this->assertSame( 1, $result['warmed'], 'Only the cold chapter is warmed.' );
        $this->assertSame( 1, $result['skipped'], 'The present chapter is fill-missing skipped.' );

        // ROM 5 was warmed; JHN 3 was never warm-fetched (only the render-context probe).
        $warmedKeys = array_map(
            static fn( $c ) => $c['book'] . ':' . $c['chapter'],
            array_filter( $this->resolverCalls, static fn( $c ) => $c['warm'] )
        );
        $this->assertSame( array( 'ROM:5' ), array_values( $warmedKeys ) );
    }

    public function test_cross_chapter_ref_warms_each_chapter_in_range(): void {
        // Matthew 5:1-7:29 cites chapters 5, 6, 7.
        $this->refsByPost[ 7 ] = $this->envelope( array( $this->ref( 'MAT', 5, 7 ) ) );

        $result = ( new BibleWarmer( null, $this->resolver() ) )->warmForPost( 7 );

        $this->assertSame( 3, $result['warmed'] );
        $warmedKeys = array_map(
            static fn( $c ) => $c['book'] . ':' . $c['chapter'],
            array_filter( $this->resolverCalls, static fn( $c ) => $c['warm'] )
        );
        $this->assertSame( array( 'MAT:5', 'MAT:6', 'MAT:7' ), array_values( $warmedKeys ) );
    }

    public function test_no_envelope_is_a_noop(): void {
        $result = ( new BibleWarmer( null, $this->resolver() ) )->warmForPost( 999 );

        $this->assertSame( 0, $result['warmed'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( array(), $this->resolverCalls, 'No refs → nothing to warm.' );
    }

    // ── migration gate ──────────────────────────────────────────────────────

    public function test_warm_for_post_is_inert_during_active_migration(): void {
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'migrating' );
            }
            if ( ID::OPTION_BIBLE_INLINE_TRANSLATION === $name ) {
                return 'ENGWEBP';
            }
            return $default;
        } );

        $this->refsByPost[ 7 ] = $this->envelope( array( $this->ref( 'JHN', 3 ) ) );

        $result = ( new BibleWarmer( null, $this->resolver() ) )->warmForPost( 7 );

        $this->assertTrue( $result['gated'], 'Warming is gated during an active migration.' );
        $this->assertSame( 0, $result['warmed'] );
        $this->assertSame( array(), $this->resolverCalls, 'A gated warm never touches the resolver.' );
    }

    public function test_warm_for_post_is_inert_when_inline_is_disabled(): void {
        // The default install state: inline OFF. Warm-on-save must NOT fire synchronous
        // live fetches (no render consumer until enabled) — the pre-enable bulk warm is
        // the CLI's job.
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'none' );
            }
            if ( ID::OPTION_BIBLE_INLINE_TRANSLATION === $name ) {
                return 'ENGWEBP';
            }
            if ( ID::OPTION_BIBLE_INLINE_ENABLED === $name ) {
                return false;
            }
            return $default;
        } );

        $this->refsByPost[ 7 ] = $this->envelope( array( $this->ref( 'JHN', 3 ) ) );

        $result = ( new BibleWarmer( null, $this->resolver() ) )->warmForPost( 7 );

        $this->assertSame( 0, $result['warmed'] );
        $this->assertSame(
            array(),
            $this->resolverCalls,
            'Inline disabled → warm-on-save never touches the resolver (no synchronous fetch).'
        );
    }

    // ── chunked CLI sweep ───────────────────────────────────────────────────

    public function test_warm_sweep_dedups_cited_chapters_across_sermons(): void {
        $this->refsByPost[ 1 ] = $this->envelope( array( $this->ref( 'JHN', 3 ) ) );
        $this->refsByPost[ 2 ] = $this->envelope( array( $this->ref( 'JHN', 3 ), $this->ref( 'ROM', 5 ) ) );

        $candidates = static fn(): array => array( 1, 2 );
        $result     = ( new BibleWarmer( $candidates, $this->resolver() ) )->warm( 0 );

        $this->assertSame( 2, $result['sermons'] );
        $this->assertSame( 2, $result['cited'], 'JHN:3 is cited twice but counted once.' );
        $this->assertSame( 2, $result['warmed'] );
    }

    public function test_warm_sweep_honours_limit_and_drains_on_rerun(): void {
        $this->refsByPost[ 1 ] = $this->envelope( array(
            $this->ref( 'JHN', 1 ),
            $this->ref( 'JHN', 2 ),
            $this->ref( 'JHN', 3 ),
        ) );
        $candidates = static fn(): array => array( 1 );

        // First run: limit 2 → only 2 of 3 missing chapters warmed.
        $first = ( new BibleWarmer( $candidates, $this->resolver() ) )->warm( 2 );
        $this->assertSame( 2, $first['warmed'] );
        $this->assertSame( 0, $first['skipped'] );

        // Second run: the 2 already-warmed chapters resolve from cache (skipped); the
        // remaining 1 is warmed — the cache state is the resumable progress marker.
        $second = ( new BibleWarmer( $candidates, $this->resolver() ) )->warm( 2 );
        $this->assertSame( 1, $second['warmed'] );
        $this->assertSame( 2, $second['skipped'] );

        // No post meta was ever written across either run.
        $this->assertSame( array(), $this->metaWrites );
    }

    public function test_warm_sweep_is_gated_during_active_migration(): void {
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'verified' );
            }
            if ( ID::OPTION_BIBLE_INLINE_TRANSLATION === $name ) {
                return 'ENGWEBP';
            }
            return $default;
        } );

        $candidates = function (): array {
            $this->fail( 'A gated sweep must not even query candidates.' );
        };

        $result = ( new BibleWarmer( $candidates, $this->resolver() ) )->warm( 0 );

        $this->assertTrue( $result['gated'] );
        $this->assertSame( 0, $result['sermons'] );
        $this->assertSame( 0, $result['warmed'] );
    }

    // ── reversibility: reverse is a cache-gen bump (flush), no touched-id log ─

    public function test_rollback_bumps_cache_generation(): void {
        $newGen = null;
        Functions\when( 'update_option' )->alias( function ( $name, $value ) use ( &$newGen ) {
            if ( ID::OPTION_BIBLE_CACHE_GEN === $name ) {
                $newGen = $value;
            }
            return true;
        } );

        $result = ( new BibleWarmer( null, $this->resolver() ) )->rollback();

        $this->assertFalse( $result['gated'] );
        $this->assertSame( 4, $result['from'] );
        $this->assertSame( 5, $result['to'] );
        $this->assertSame( 5, $newGen, 'Reverse is a cache-gen bump (the flush), not a touched-id replay.' );
    }

    public function test_rollback_is_gated_during_active_migration(): void {
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'migrating' );
            }
            return $default;
        } );
        $bumped = false;
        Functions\when( 'update_option' )->alias( function () use ( &$bumped ) {
            $bumped = true;
            return true;
        } );

        $result = ( new BibleWarmer( null, $this->resolver() ) )->rollback();

        $this->assertTrue( $result['gated'] );
        $this->assertFalse( $bumped, 'A gated rollback never bumps the generation.' );
    }
}
