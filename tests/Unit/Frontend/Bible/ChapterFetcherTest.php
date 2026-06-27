<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Bible;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Bible\ChapterFetcher;

/**
 * ChapterFetcher is the hardened, FAIL-OPEN helloao chapter read. It returns the
 * raw chapter array on a clean 200-with-shape response and null on ANY error, and
 * it NEVER throws — every null path is a fall-open to the 3a link.
 */
final class ChapterFetcherTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Silence error_log() so the failure-path assertions stay quiet.
        Functions\when( 'wp_parse_url' )->justReturn( 'https' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** A clean 200 with the expected `{chapter:{content:[…]}}` shape returns the raw chapter array. */
    public function test_returns_raw_chapter_on_success(): void {
        $chapter = array(
            'number'    => 3,
            'content'   => array(
                array( 'type' => 'verse', 'number' => 16, 'content' => array( 'For God so loved…' ) ),
            ),
            'footnotes' => array(),
        );

        Functions\when( 'wp_remote_get' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            (string) json_encode( array( 'chapter' => $chapter ) )
        );

        $this->assertSame( $chapter, ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** A WP_Error transport failure returns null (and never throws). */
    public function test_returns_null_on_wp_error(): void {
        $error = new class {
            public function get_error_message(): string {
                return 'cURL error 28: timed out';
            }
        };

        Functions\when( 'wp_remote_get' )->justReturn( $error );
        Functions\when( 'is_wp_error' )->justReturn( true );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** A non-200 status returns null. */
    public function test_returns_null_on_non_200(): void {
        Functions\when( 'wp_remote_get' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"chapter":{"content":[]}}' );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** A 200 whose body is undecodable JSON returns null. */
    public function test_returns_null_on_undecodable_body(): void {
        Functions\when( 'wp_remote_get' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '<html>not json</html>' );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** A 200 whose JSON lacks the expected `chapter.content` shape returns null. */
    public function test_returns_null_on_bad_shape(): void {
        Functions\when( 'wp_remote_get' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        // Valid JSON, but no `chapter` key (e.g. an error envelope).
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"not found"}' );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** `chapter` present but `content` is not an array → null (never a partial shape). */
    public function test_returns_null_when_content_not_array(): void {
        Functions\when( 'wp_remote_get' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"chapter":{"content":"oops"}}' );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** Empty/blank inputs short-circuit to null without any HTTP call. */
    public function test_returns_null_on_blank_inputs(): void {
        $called = false;
        Functions\when( 'wp_remote_get' )->alias( function () use ( &$called ) {
            $called = true;
            return array();
        } );

        $this->assertNull( ChapterFetcher::fetch( '', 'JHN', 3 ) );
        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', '', 3 ) );
        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 0 ) );
        $this->assertFalse( $called, 'No HTTP call should be made for blank/invalid inputs.' );
    }

    /** NEVER throws: an exception from the HTTP layer is caught and falls open to null. */
    public function test_never_throws_swallows_unexpected_exception(): void {
        Functions\when( 'wp_remote_get' )->alias( function () {
            throw new \RuntimeException( 'boom' );
        } );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    /** Reuses the AudioHeadProbe hardening: timeout 5, redirection 2, reject_unsafe_urls true. */
    public function test_uses_hardened_request_args(): void {
        $captured = null;
        Functions\when( 'wp_remote_get' )->alias( function ( $url, $args ) use ( &$captured ) {
            $captured = $args;
            return array();
        } );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"chapter":{"content":[]}}' );

        ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 );

        $this->assertNotNull( $captured );
        $this->assertSame( 5, $captured['timeout'] );
        $this->assertSame( 2, $captured['redirection'] );
        $this->assertTrue( $captured['reject_unsafe_urls'] );
    }
}
