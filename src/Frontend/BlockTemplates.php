<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Registers plugin-provided block templates for block (FSE) themes. A more-specific
 * single-sermonator_sermon template wins over a theme's generic `single` with zero config
 * (verified in Phase 0 on TT5). Themes can still override by shipping their own template of
 * the same slug, and users can edit ours in the Site Editor. Single-meta renders purely by
 * the block's PRESENCE in this composition — no request-scoped guard.
 */
final class BlockTemplates {
    public function register(): void {
        if ( ! function_exists( 'register_block_template' ) ) {
            return;
        }

        register_block_template(
            'sermonator//single-' . ID::POST_TYPE_SERMON,
            array(
                'title'       => __( 'Single Sermon', 'sermonator' ),
                'description' => __( 'Default Sermonator single-sermon layout.', 'sermonator' ),
                'content'     => $this->singleContent(),
            )
        );
    }

    private function singleContent(): string {
        return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
            . '<!-- wp:post-title {"level":1} /-->'
            . '<!-- wp:sermonator/sermon-meta /-->'
            . '<!-- wp:sermonator/audio-player /-->'
            . '<!-- wp:sermonator/video /-->'
            . '<!-- wp:post-content /-->'
            . '</main><!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
    }
}
