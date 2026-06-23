<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

/**
 * Shared registration + post-id resolution for Sermonator dynamic blocks. Each concrete
 * block ships a block.json under blocks/<slug>/ and a render() that delegates to the
 * frontend Renderer. Blocks are server-rendered and read-only.
 */
abstract class AbstractBlock {
    /** Fully-qualified block name, e.g. "sermonator/sermon-meta". */
    abstract public function name(): string;

    /**
     * @param array<string,mixed> $attributes
     */
    abstract public function render( array $attributes, string $content, \WP_Block $block ): string;

    public function register(): void {
        $slug = (string) preg_replace( '#^sermonator/#', '', $this->name() );
        register_block_type(
            dirname( SERMONATOR_FILE ) . '/blocks/' . $slug,
            array( 'render_callback' => array( $this, 'render' ) )
        );
    }

    /**
     * Resolve the sermon post id: explicit attribute → block context (query loop) →
     * the current post in the loop.
     *
     * @param array<string,mixed> $attributes
     */
    protected function resolvePostId( array $attributes, \WP_Block $block ): int {
        if ( ! empty( $attributes['postId'] ) ) {
            return (int) $attributes['postId'];
        }
        if ( isset( $block->context['postId'] ) ) {
            return (int) $block->context['postId'];
        }
        return (int) get_the_ID();
    }
}
