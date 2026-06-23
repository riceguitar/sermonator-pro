<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Seo;

use WP_UnitTestCase;
use Sermonator\Frontend\Seo\SeoHead;
use Sermonator\Schema\Identifiers as ID;

final class SeoHeadTest extends WP_UnitTestCase {
    private function sermon(): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'The Light Has Come',
        ) );
        update_post_meta( $id, ID::META_DATE, '1734775200' );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/a.mp3' );
        return $id;
    }

    private function head( int $id ): string {
        $this->go_to( get_permalink( $id ) );
        ob_start();
        ( new SeoHead() )->render();
        return (string) ob_get_clean();
    }

    public function test_emits_jsonld_and_open_graph_on_single_sermon(): void {
        $html = $this->head( $this->sermon() );

        $this->assertStringContainsString( '<script type="application/ld+json">', $html );
        $this->assertStringContainsString( '"@type":"CreativeWork"', $html );
        $this->assertStringContainsString( '"AudioObject"', $html );
        $this->assertStringContainsString( 'property="og:title"', $html );
        $this->assertStringContainsString( 'name="twitter:card"', $html );
    }

    public function test_nothing_on_non_sermon(): void {
        $post = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
        $html = $this->head( $post );
        $this->assertSame( '', trim( $html ) );
    }

    public function test_open_graph_can_be_filtered_off(): void {
        add_filter( 'sermonator_frontend_emit_open_graph', '__return_false' );
        $html = $this->head( $this->sermon() );
        remove_filter( 'sermonator_frontend_emit_open_graph', '__return_false' );

        $this->assertStringContainsString( 'application/ld+json', $html, 'JSON-LD still emitted.' );
        $this->assertStringNotContainsString( 'og:title', $html, 'OG suppressed by the filter.' );
    }

    public function test_jsonld_can_be_filtered_off(): void {
        add_filter( 'sermonator_frontend_emit_json_ld', '__return_false' );
        $html = $this->head( $this->sermon() );
        remove_filter( 'sermonator_frontend_emit_json_ld', '__return_false' );
        $this->assertStringNotContainsString( 'application/ld+json', $html );
        $this->assertStringContainsString( 'og:title', $html, 'OG still emitted.' );
    }

    public function test_jsonld_is_valid_json(): void {
        $html = $this->head( $this->sermon() );
        preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $html, $m );
        $this->assertNotEmpty( $m[1] ?? '' );
        $this->assertNotNull( json_decode( $m[1], true ), 'JSON-LD payload must be valid JSON.' );
    }
}
