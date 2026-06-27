<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use Sermonator\Frontend\Bible\ChapterCache;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see ChapterCache} against the REAL WordPress
 * transient + options API. Proves the gen-keyed invalidation contract end-to-end:
 * a normalized chapter round-trips through `set`/`get`, a generation bump rotates
 * the key so the next read MISSES (without deleting anything), and the prior
 * transient still physically exists in the options table — i.e. invalidation is a
 * pure key rotation, never a `DELETE … LIKE` sweep (design §3.6).
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Authored to
 * run later under wp-env.
 */
final class ChapterCacheTest extends WP_UnitTestCase {
    /** @var list<array{number:int,nodes:list<array{type:string,text:string}>}> */
    private const NORMALIZED = array(
        array(
            'number' => 1,
            'nodes'  => array( array( 'type' => 'text', 'text' => 'In the beginning was the Word' ) ),
        ),
    );

    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_BIBLE_CACHE_GEN );
    }

    public function test_set_then_get_round_trips_normalized_chapter(): void {
        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'JHN', 1 ) );

        ChapterCache::set( 'ENGWEBP', 'JHN', 1, self::NORMALIZED );

        $this->assertSame( self::NORMALIZED, ChapterCache::get( 'ENGWEBP', 'JHN', 1 ) );
    }

    public function test_distinct_coordinates_do_not_collide(): void {
        ChapterCache::set( 'ENGWEBP', 'JHN', 1, self::NORMALIZED );

        // Different chapter / book / translation each miss.
        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'JHN', 2 ) );
        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'GEN', 1 ) );
        $this->assertNull( ChapterCache::get( 'ENGKJV', 'JHN', 1 ) );
    }

    public function test_gen_bump_invalidates_without_deleting(): void {
        ChapterCache::set( 'ENGWEBP', 'JHN', 1, self::NORMALIZED );
        $this->assertSame( self::NORMALIZED, ChapterCache::get( 'ENGWEBP', 'JHN', 1 ) );

        // The exact transient row that backed the gen-0 entry.
        $genZeroKey = '_transient_' . self::transientKey( 0, 'ENGWEBP', 'JHN', 1 );
        $this->assertNotFalse( get_option( $genZeroKey ), 'gen-0 transient should exist' );

        // Operator bumps the generation (the flush mechanism).
        update_option( ID::OPTION_BIBLE_CACHE_GEN, 1 );

        // Next read at gen 1 MISSES — key rotated.
        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'JHN', 1 ) );

        // …but the old row was NOT deleted (no DELETE … LIKE); it ages out on TTL.
        $this->assertNotFalse(
            get_option( $genZeroKey ),
            'gen-0 transient must still exist after a gen bump (invalidation is key rotation, not deletion)'
        );
    }

    /** Mirror of ChapterCache's private key composition, for the existence assertion. */
    private static function transientKey( int $gen, string $translation, string $book, int $chapter ): string {
        return 'sermonator_bible_' . md5(
            implode( '|', array( $gen, $translation, $book, $chapter, ID::BIBLE_CACHE_SCHEMA_VERSION ) )
        );
    }
}
