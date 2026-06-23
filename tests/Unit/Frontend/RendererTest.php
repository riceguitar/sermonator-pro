<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\SermonView;

final class RendererTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'wp_kses_allowed_html' )->justReturn( array() );
        Functions\when( 'wp_oembed_get' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( '__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string,mixed> $over */
    private function view( array $over = array() ): SermonView {
        return new SermonView(
            id: 5,
            title: 'T',
            permalink: 'http://x/s/',
            preachedTimestamp: $over['ts'] ?? null,
            biblePassage: $over['passage'] ?? 'John 1:1-14',
            audioUrl: $over['audio'] ?? '',
            audioDuration: $over['dur'] ?? '',
            audioSize: 0,
            videoEmbed: $over['embed'] ?? '',
            videoUrl: $over['vurl'] ?? '',
            views: 0,
            preachers: $over['preachers'] ?? array( array( 'name' => 'Pastor John', 'url' => 'http://x/p' ) ),
        );
    }

    public function test_meta_includes_passage_and_preacher(): void {
        $html = ( new Renderer() )->meta( $this->view() );
        $this->assertStringContainsString( 'John 1:1-14', $html );
        $this->assertStringContainsString( 'Pastor John', $html );
        $this->assertStringContainsString( 'sermonator-meta', $html );
        $this->assertStringContainsString( '<a href="http://x/p">Pastor John</a>', $html );
    }

    public function test_meta_omits_absent_fields(): void {
        $html = ( new Renderer() )->meta( $this->view( array( 'passage' => '', 'preachers' => array() ) ) );
        $this->assertStringNotContainsString( 'sermonator-meta__passage', $html );
        $this->assertStringNotContainsString( 'sermonator-meta__preacher', $html );
        $this->assertStringNotContainsString( '>0<', $html ); // never the literal "0"
    }

    public function test_meta_empty_when_nothing_to_show(): void {
        $empty = new SermonView(
            id: 1, title: 'T', permalink: 'http://x/', preachedTimestamp: null,
            biblePassage: '', audioUrl: '', audioDuration: '', audioSize: 0,
            videoEmbed: '', videoUrl: '', views: 0
        );
        $this->assertSame( '', ( new Renderer() )->meta( $empty ) );
    }

    public function test_audio_player_empty_when_no_audio(): void {
        $this->assertSame( '', ( new Renderer() )->audioPlayer( $this->view() ) );
    }

    public function test_audio_player_renders_audio_tag_and_download(): void {
        $html = ( new Renderer() )->audioPlayer( $this->view( array( 'audio' => 'http://x/a.mp3', 'dur' => '00:10:00' ) ) );
        $this->assertStringContainsString( '<audio', $html );
        $this->assertStringContainsString( 'http://x/a.mp3', $html );
        $this->assertStringContainsString( 'download', $html );
        $this->assertStringContainsString( 'data-duration="00:10:00"', $html );
    }

    public function test_video_prefers_embed_then_url_then_empty(): void {
        $r = new Renderer();
        $this->assertStringContainsString( '<iframe', $r->video( $this->view( array( 'embed' => '<iframe src="x"></iframe>' ) ) ) );
        // wp_oembed_get stubbed to false → falls back to a plain link.
        $this->assertStringContainsString( 'http://x/v', $r->video( $this->view( array( 'vurl' => 'http://x/v' ) ) ) );
        $this->assertSame( '', $r->video( $this->view() ) );
    }

    public function test_video_url_uses_oembed_when_available(): void {
        Functions\when( 'wp_oembed_get' )->justReturn( '<iframe class="oembed" src="http://x/player"></iframe>' );
        $html = ( new Renderer() )->video( $this->view( array( 'vurl' => 'http://youtube.com/watch?v=x' ) ) );
        $this->assertStringContainsString( 'oembed', $html );
        $this->assertStringContainsString( 'sermonator-video', $html );
    }

    public function test_date_label_uses_wp_date(): void {
        Functions\when( 'get_option' )->justReturn( 'F j, Y' );
        Functions\when( 'wp_date' )->justReturn( 'December 21, 2025' );
        $html = ( new Renderer() )->meta( $this->view( array( 'ts' => 1734775200 ) ) );
        $this->assertStringContainsString( 'December 21, 2025', $html );
        $this->assertStringContainsString( 'sermonator-meta__date', $html );
    }
}
