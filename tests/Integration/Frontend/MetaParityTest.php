<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

/**
 * Composition-drift control: the sermon-meta block render and a direct Renderer call must
 * produce identical meta markup for the same sermon. If they diverge, the block wrapper
 * added markup the classic template / shortcode would not — the drift the spec warns about.
 */
final class MetaParityTest extends WP_UnitTestCase {
    public function test_block_and_direct_renderer_meta_match(): void {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'Parity',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );

        $viaBlock  = do_blocks( '<!-- wp:sermonator/sermon-meta {"postId":' . $id . '} /-->' );
        $viaDirect = ( new Renderer() )->meta( ( new TemplateData() )->sermon( $id ) );

        $this->assertSame(
            trim( $viaDirect ),
            trim( $viaBlock ),
            'Block render and direct Renderer output must match — no composition drift.'
        );
    }

    /**
     * Cross-path drift control: the block sequence used by the block template
     * (meta → audio → video) must match the classic PHP template's composition (which
     * concatenates the same three Renderer calls in the same order). A divergence in either
     * site is the drift the spec warns about.
     */
    public function test_block_sequence_matches_classic_composition(): void {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'Composed',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/a.mp3' );
        // Use embed (not URL) so neither path makes an oEmbed network call.
        update_post_meta( $id, ID::META_VIDEO_EMBED, '<iframe src="http://example.com/v"></iframe>' );

        $viaBlocks = do_blocks(
            '<!-- wp:sermonator/sermon-meta {"postId":' . $id . '} /-->'
            . '<!-- wp:sermonator/audio-player {"postId":' . $id . '} /-->'
            . '<!-- wp:sermonator/video {"postId":' . $id . '} /-->'
        );

        $view        = ( new TemplateData() )->sermon( $id );
        $r           = new Renderer();
        $viaClassic  = $r->meta( $view ) . $r->audioPlayer( $view ) . $r->video( $view );

        $this->assertSame(
            trim( $viaClassic ),
            trim( $viaBlocks ),
            'Classic-template composition and block sequence must match — no cross-path drift.'
        );
    }
}
