<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Bible;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Bible\ChapterCache;
use Sermonator\Schema\Identifiers as ID;

/**
 * ChapterCache stores NORMALIZED chapters in a transient keyed by
 * `sermonator_bible_` . md5( gen | translation | USFM | chapter | schema ), with a
 * MONTH TTL, invalidated only by a generation/schema bump (never DELETE … LIKE).
 */
final class ChapterCacheTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
            define( 'MONTH_IN_SECONDS', 2592000 );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** The transient key is md5 of gen|translation|USFM|chapter|schema, prefixed. */
    public function test_key_composition_includes_gen_and_schema(): void {
        Functions\when( 'get_option' )->justReturn( 7 ); // cache gen = 7

        $captured = null;
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$captured ) {
            $captured = $key;
            return false;
        } );

        ChapterCache::get( 'ENGWEBP', 'JHN', 3 );

        $expected = 'sermonator_bible_' . md5(
            implode( '|', array( 7, 'ENGWEBP', 'JHN', 3, ID::BIBLE_CACHE_SCHEMA_VERSION ) )
        );
        $this->assertSame( $expected, $captured );
    }

    /** Bumping the cache generation rotates the key (silent invalidation, no delete). */
    public function test_gen_bump_changes_key(): void {
        $keys = array();
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$keys ) {
            $keys[] = $key;
            return false;
        } );

        Functions\when( 'get_option' )->justReturn( 0 );
        ChapterCache::get( 'ENGWEBP', 'JHN', 3 );

        Functions\when( 'get_option' )->justReturn( 1 );
        ChapterCache::get( 'ENGWEBP', 'JHN', 3 );

        $this->assertNotSame( $keys[0], $keys[1] );
    }

    /** set() persists with a MONTH TTL under the same key get() reads. */
    public function test_set_uses_month_ttl_and_matching_key(): void {
        Functions\when( 'get_option' )->justReturn( 0 );

        $captured = array();
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl ) use ( &$captured ) {
            $captured = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
            return true;
        } );

        $normalized = array( array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'x' ) ) ) );
        ChapterCache::set( 'ENGWEBP', 'JHN', 3, $normalized );

        $expected = 'sermonator_bible_' . md5(
            implode( '|', array( 0, 'ENGWEBP', 'JHN', 3, ID::BIBLE_CACHE_SCHEMA_VERSION ) )
        );
        $this->assertSame( $expected, $captured['key'] );
        $this->assertSame( $normalized, $captured['value'] );
        $this->assertSame( MONTH_IN_SECONDS, $captured['ttl'] );
    }

    /** Round-trip: a value set under a key is returned by get() for the same inputs. */
    public function test_get_set_round_trip(): void {
        Functions\when( 'get_option' )->justReturn( 3 );

        $store = array();
        Functions\when( 'set_transient' )->alias( function ( $key, $value ) use ( &$store ) {
            $store[ $key ] = $value;
            return true;
        } );
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$store ) {
            return $store[ $key ] ?? false;
        } );

        $normalized = array(
            array( 'number' => 1, 'nodes' => array( array( 'type' => 'text', 'text' => 'In the beginning' ) ) ),
        );

        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'JHN', 3 ) );
        ChapterCache::set( 'ENGWEBP', 'JHN', 3, $normalized );
        $this->assertSame( $normalized, ChapterCache::get( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** A miss (transient absent → false) returns null, not false. */
    public function test_get_returns_null_on_miss(): void {
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'get_transient' )->justReturn( false );

        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** A non-array cached value (corruption) is treated as a miss → null. */
    public function test_get_returns_null_on_non_array_value(): void {
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'get_transient' )->justReturn( 'corrupt-scalar' );

        $this->assertNull( ChapterCache::get( 'ENGWEBP', 'JHN', 3 ) );
    }
}
