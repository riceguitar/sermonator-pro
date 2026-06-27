<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use WP_Error;
use Sermonator\Frontend\Bible\ChapterFetcher;

/**
 * Integration coverage for {@see ChapterFetcher} against the REAL WP HTTP stack,
 * with the network short-circuited by the core `pre_http_request` filter so no
 * actual request to helloao is ever made. This exercises the genuine
 * `wp_remote_get` → `is_wp_error` / `wp_remote_retrieve_*` plumbing (not Brain
 * Monkey stubs) while proving the FAIL-OPEN contract: a transport error, a non-200
 * status, and an unexpected body shape each return null; only a clean 200 with the
 * `{chapter:{content:[…]}}` shape returns the raw chapter array.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Authored to
 * run later under wp-env.
 */
final class ChapterFetcherTest extends WP_UnitTestCase {
    /** @var callable|null */
    private $shortCircuit;

    protected function tearDown(): void {
        if ( null !== $this->shortCircuit ) {
            remove_filter( 'pre_http_request', $this->shortCircuit, 10 );
            $this->shortCircuit = null;
        }
        parent::tearDown();
    }

    /** Install a pre_http_request short-circuit returning the given canned response. */
    private function stubHttp( $response ): void {
        $this->shortCircuit = static function () use ( $response ) {
            return $response;
        };
        add_filter( 'pre_http_request', $this->shortCircuit, 10, 3 );
    }

    public function test_returns_raw_chapter_on_clean_200(): void {
        $chapter = array(
            'number'    => 3,
            'content'   => array(
                array( 'type' => 'verse', 'number' => 16, 'content' => array( 'For God so loved the world' ) ),
            ),
            'footnotes' => array(),
        );

        $this->stubHttp( array(
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'body'     => (string) wp_json_encode( array( 'chapter' => $chapter ) ),
        ) );

        $this->assertSame( $chapter, ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    public function test_returns_null_on_wp_error(): void {
        $this->stubHttp( new WP_Error( 'http_request_failed', 'timed out' ) );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    public function test_returns_null_on_non_200(): void {
        $this->stubHttp( array(
            'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
            'body'     => '{"chapter":{"content":[]}}',
        ) );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }

    public function test_returns_null_on_bad_shape(): void {
        $this->stubHttp( array(
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'body'     => '{"error":"not found"}',
        ) );

        $this->assertNull( ChapterFetcher::fetch( 'ENGWEBP', 'JHN', 3 ) );
    }
}
