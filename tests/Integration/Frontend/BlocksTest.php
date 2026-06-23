<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * The three Phase-1 dynamic blocks render through the shared Renderer. Registration happens
 * via FrontendServiceProvider on init (booted by the plugin), so these assert end-to-end
 * block output.
 */
final class BlocksTest extends WP_UnitTestCase {
    private function makeSermon(): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'The Light Has Come',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/a.mp3' );
        return $id;
    }

    public function test_sermon_meta_block_renders_passage(): void {
        $id   = $this->makeSermon();
        $html = do_blocks( '<!-- wp:sermonator/sermon-meta {"postId":' . $id . '} /-->' );
        $this->assertStringContainsString( 'John 1:1-14', $html );
        $this->assertStringContainsString( 'sermonator-meta', $html );
    }

    public function test_audio_player_block_renders_audio_tag(): void {
        $id   = $this->makeSermon();
        $html = do_blocks( '<!-- wp:sermonator/audio-player {"postId":' . $id . '} /-->' );
        $this->assertStringContainsString( '<audio', $html );
        $this->assertStringContainsString( 'http://example.com/a.mp3', $html );
    }

    public function test_video_block_empty_without_video(): void {
        $id   = $this->makeSermon();
        $html = trim( do_blocks( '<!-- wp:sermonator/video {"postId":' . $id . '} /-->' ) );
        $this->assertSame( '', $html );
    }

    public function test_video_block_renders_embed(): void {
        $id = $this->makeSermon();
        update_post_meta( $id, ID::META_VIDEO_EMBED, '<iframe src="http://example.com/v"></iframe>' );
        $html = do_blocks( '<!-- wp:sermonator/video {"postId":' . $id . '} /-->' );
        $this->assertStringContainsString( '<iframe', $html );
    }
}
