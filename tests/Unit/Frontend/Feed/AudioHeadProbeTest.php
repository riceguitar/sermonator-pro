<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Feed\AudioHeadProbe;

final class AudioHeadProbeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rejects_non_http_scheme(): void {
        Functions\when( 'wp_parse_url' )->justReturn( 'ftp' );
        $this->assertSame( array( 'size' => null, 'mime' => null ), AudioHeadProbe::probe( 'ftp://x/a.mp3' ) );
    }

    public function test_returns_size_and_mime_from_headers(): void {
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );
        Functions\when( 'wp_remote_head' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_header' )->alias( function ( $response, string $header ) {
            return 'content-length' === $header ? '12345' : 'audio/mpeg';
        } );

        $this->assertSame(
            array( 'size' => 12345, 'mime' => 'audio/mpeg' ),
            AudioHeadProbe::probe( 'https://x/a.mp3' )
        );
    }

    public function test_returns_null_when_content_length_missing_or_absurd(): void {
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );
        Functions\when( 'wp_remote_head' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

        $this->assertSame( array( 'size' => null, 'mime' => null ), AudioHeadProbe::probe( 'https://x/a.mp3' ) );
    }

    public function test_rejects_over_max_size(): void {
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );
        Functions\when( 'wp_remote_head' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_header' )->alias( function ( $response, string $header ) {
            $too_big = (string) ( AudioHeadProbe::MAX_SIZE_BYTES + 1 );
            return 'content-length' === $header ? $too_big : 'audio/mpeg';
        } );

        $probed = AudioHeadProbe::probe( 'https://x/a.mp3' );
        $this->assertNull( $probed['size'] );
    }

    public function test_uses_reject_unsafe_urls_and_short_timeout(): void {
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

        $captured_args = null;
        Functions\when( 'wp_remote_head' )->alias( function ( $url, $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return array();
        } );

        AudioHeadProbe::probe( 'https://x/a.mp3' );

        $this->assertNotNull( $captured_args );
        $this->assertTrue( $captured_args['reject_unsafe_urls'] );
        $this->assertSame( 10, $captured_args['timeout'] );
        $this->assertSame( 3, $captured_args['redirection'] );
    }
}
