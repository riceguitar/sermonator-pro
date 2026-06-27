<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Schema\Identifiers as ID;

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
        // Idempotent: init can fire more than once (e.g. a re-dispatch in tests, or another
        // plugin re-running it), and a second register_block_type() for an already-registered
        // name triggers a _doing_it_wrong() notice. Guard so re-registration is a safe no-op.
        if ( \WP_Block_Type_Registry::get_instance()->is_registered( $this->name() ) ) {
            return;
        }

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

    /**
     * Resolve a post id that is SAFE to render on the front end, or 0. A block may carry an
     * explicit postId, so we cannot trust the post is the (already-vetted) main-query post:
     * we must verify it is a sermon AND publicly viewable (published, not private/draft/
     * future/trashed/password-locked). Editors keep their own preview via read_post. Without
     * this, a placed block could disclose an unpublished sermon's audio URL / scripture.
     *
     * @param array<string,mixed> $attributes
     */
    protected function renderablePostId( array $attributes, \WP_Block $block ): int {
        $postId = $this->resolvePostId( $attributes, $block );
        if ( $postId <= 0 || get_post_type( $postId ) !== ID::POST_TYPE_SERMON ) {
            return 0;
        }
        if ( ! is_post_publicly_viewable( $postId ) && ! current_user_can( 'read_post', $postId ) ) {
            return 0;
        }
        return $postId;
    }
}
