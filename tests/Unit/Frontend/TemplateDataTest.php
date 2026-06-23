<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

final class TemplateDataTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'is_wp_error' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_maps_meta_and_terms_into_view(): void {
        Functions\when( 'get_the_title' )->justReturn( 'The Light Has Come' );
        Functions\when( 'get_permalink' )->justReturn( 'http://x/sermons/the-light-has-come/' );

        $meta = array(
            ID::META_DATE           => array( '1734775200' ),
            ID::META_BIBLE_PASSAGE  => array( 'John 1:1-14' ),
            ID::META_AUDIO          => array( 'http://x/a.mp3' ),
            ID::META_AUDIO_DURATION => array( '00:34:12' ),
            ID::META_AUDIO_SIZE     => array( '32871234' ),
            ID::META_VIDEO_URL      => array( 'http://x/v' ),
            ID::META_VIEWS          => array( '142' ),
        );
        Functions\when( 'get_post_meta' )->alias(
            static fn( $id, $key, $single ) => $single ? ( $meta[ $key ][0] ?? '' ) : ( $meta[ $key ] ?? array() )
        );
        Functions\when( 'get_the_terms' )->justReturn( array() );

        $view = ( new TemplateData() )->sermon( 5 );

        $this->assertSame( 5, $view->id );
        $this->assertSame( 'The Light Has Come', $view->title );
        $this->assertSame( 'John 1:1-14', $view->biblePassage );
        $this->assertSame( 'http://x/a.mp3', $view->audioUrl );
        $this->assertSame( '00:34:12', $view->audioDuration );
        $this->assertSame( 32871234, $view->audioSize );
        $this->assertSame( 142, $view->views );
        $this->assertSame( 1734775200, $view->preachedTimestamp );
        $this->assertSame( '', $view->videoEmbed ); // missing → empty, never "0"
        $this->assertSame( array(), $view->preachers );
    }

    public function test_non_numeric_date_becomes_null_timestamp(): void {
        Functions\when( 'get_the_title' )->justReturn( 'T' );
        Functions\when( 'get_permalink' )->justReturn( 'http://x/' );
        Functions\when( 'get_the_terms' )->justReturn( array() );
        $meta = array( ID::META_DATE => array( 'next Sunday' ) );
        Functions\when( 'get_post_meta' )->alias(
            static fn( $id, $key, $single ) => $single ? ( $meta[ $key ][0] ?? '' ) : ( $meta[ $key ] ?? array() )
        );

        $view = ( new TemplateData() )->sermon( 7 );

        $this->assertNull( $view->preachedTimestamp );
    }

    public function test_negative_pre_1970_timestamp_is_preserved(): void {
        Functions\when( 'get_the_title' )->justReturn( 'T' );
        Functions\when( 'get_permalink' )->justReturn( 'http://x/' );
        Functions\when( 'get_the_terms' )->justReturn( array() );
        // A sermon preached before 1970 has a negative Unix timestamp; it must NOT be dropped.
        $meta = array( ID::META_DATE => array( '-1734775200' ) );
        Functions\when( 'get_post_meta' )->alias(
            static fn( $id, $key, $single ) => $single ? ( $meta[ $key ][0] ?? '' ) : ( $meta[ $key ] ?? array() )
        );

        $view = ( new TemplateData() )->sermon( 8 );

        $this->assertSame( -1734775200, $view->preachedTimestamp );
    }

    public function test_lone_dash_date_is_null(): void {
        Functions\when( 'get_the_title' )->justReturn( 'T' );
        Functions\when( 'get_permalink' )->justReturn( 'http://x/' );
        Functions\when( 'get_the_terms' )->justReturn( array() );
        $meta = array( ID::META_DATE => array( '-' ) );
        Functions\when( 'get_post_meta' )->alias(
            static fn( $id, $key, $single ) => $single ? ( $meta[ $key ][0] ?? '' ) : ( $meta[ $key ] ?? array() )
        );

        $this->assertNull( ( new TemplateData() )->sermon( 9 )->preachedTimestamp );
    }

    public function test_term_link_wp_error_yields_unlinked_term(): void {
        Functions\when( 'get_the_title' )->justReturn( 'T' );
        Functions\when( 'get_permalink' )->justReturn( 'http://x/' );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_term_link' )->justReturn( 'WPERR' );
        // Override the blanket is_wp_error stub: treat the sentinel as an error.
        Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v === 'WPERR' );
        Functions\when( 'get_the_terms' )->alias( static function ( $id, $tax ) {
            if ( $tax === ID::TAX_PREACHER ) {
                $t       = new \stdClass();
                $t->name = 'Pastor John';
                return array( $t );
            }
            return array();
        } );

        $view = ( new TemplateData() )->sermon( 10 );

        $this->assertSame( array( array( 'name' => 'Pastor John', 'url' => '' ) ), $view->preachers );
    }

    public function test_maps_terms_with_links(): void {
        Functions\when( 'get_the_title' )->justReturn( 'T' );
        Functions\when( 'get_permalink' )->justReturn( 'http://x/' );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_term_link' )->justReturn( 'http://x/preacher/john' );
        Functions\when( 'get_the_terms' )->alias( static function ( $id, $tax ) {
            if ( $tax === ID::TAX_PREACHER ) {
                $t       = new \stdClass();
                $t->name = 'Pastor John';
                return array( $t );
            }
            return array();
        } );

        $view = ( new TemplateData() )->sermon( 9 );

        $this->assertSame( array( array( 'name' => 'Pastor John', 'url' => 'http://x/preacher/john' ) ), $view->preachers );
        $this->assertSame( array(), $view->topics );
    }
}
