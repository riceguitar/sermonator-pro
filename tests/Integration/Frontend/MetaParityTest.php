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
}
