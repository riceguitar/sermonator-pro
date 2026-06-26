<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Feed\LegacyPodcastId;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the layered legacy podcast-id resolver.
 *
 * NOTE: the Crosswalk::findNewByLegacyId() branch (the pre-Finalize fallback)
 * reads $wpdb directly and is not exercisable under Brain Monkey; it is covered
 * by inspection and by the migration integration suite.
 */
final class LegacyPodcastIdTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_zero_or_negative_legacy_id_returns_default_podcast(): void {
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = false ) => $k === ID::OPTION_DEFAULT_PODCAST ? 11 : $d
        );

        $resolver = new LegacyPodcastId();
        $this->assertSame( 11, $resolver->resolve( 0 ) );
        $this->assertSame( 11, $resolver->resolve( -5 ) );
    }

    public function test_durable_map_hit_returns_mapped_podcast(): void {
        Functions\when( 'get_option' )->alias( function ( $k, $d = false ) {
            if ( $k === ID::OPTION_LEGACY_PODCAST_MAP ) {
                return array( 5 => 22 );
            }
            return $d;
        } );

        $this->assertSame( 22, ( new LegacyPodcastId() )->resolve( 5 ) );
    }

    public function test_unmapped_with_no_map_falls_back_to_default(): void {
        Functions\when( 'get_option' )->alias( function ( $k, $d = false ) {
            if ( $k === ID::OPTION_LEGACY_PODCAST_MAP ) {
                return array(); // no map entries
            }
            if ( $k === ID::OPTION_DEFAULT_PODCAST ) {
                return 7;
            }
            return $d;
        } );

        // The unmapped path falls through to Crosswalk::findNewByLegacyId(), which
        // reads $wpdb directly. A minimal stub returning no rows simulates "no
        // pre-Finalize back-ref" so the resolver lands on the default podcast.
        global $wpdb;
        $wpdb = new class {
            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';
            public function prepare( $query, ...$args ) { return $query; }
            public function get_col( $query ) { return array(); }
        };

        $this->assertSame( 7, ( new LegacyPodcastId() )->resolve( 999 ) );

        $wpdb = null;
    }
}
