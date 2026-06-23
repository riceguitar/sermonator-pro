<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Registers plugin-provided block templates for block (FSE) themes. A more-specific
 * single-/archive-/taxonomy- template wins over a theme's generic template with zero config
 * (verified in Phase 0 on TT5). Themes can still override by shipping their own template of
 * the same slug, and users can edit ours in the Site Editor. Single-meta and cards render
 * purely by block PRESENCE in the composition — no request-scoped guard.
 */
final class BlockTemplates {
    public function register(): void {
        if ( ! function_exists( 'register_block_template' ) ) {
            return;
        }

        $this->registerOne( 'single-' . ID::POST_TYPE_SERMON, __( 'Single Sermon', 'sermonator' ), $this->singleContent() );
        $this->registerOne( 'archive-' . ID::POST_TYPE_SERMON, __( 'Sermon Archive', 'sermonator' ), $this->archiveContent( __( 'Sermons', 'sermonator' ) ) );

        foreach ( ID::sermonTaxonomies() as $taxonomy ) {
            $this->registerOne(
                'taxonomy-' . $taxonomy,
                __( 'Sermon Taxonomy', 'sermonator' ),
                $this->archiveContent( '' )
            );
        }
    }

    private function registerOne( string $slug, string $title, string $content ): void {
        $name = 'sermonator//' . $slug;

        // Idempotent: register_block_template() emits a _doing_it_wrong on a duplicate.
        if ( class_exists( '\WP_Block_Templates_Registry' )
            && \WP_Block_Templates_Registry::get_instance()->is_registered( $name ) ) {
            return;
        }

        register_block_template(
            $name,
            array(
                'title'   => $title,
                'content' => $content,
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

    /**
     * Archive/taxonomy layout: a query loop that INHERITS the main query (which
     * ArchiveOrdering has sorted by preached date), rendering one sermon-card per result,
     * plus query pagination. The archive title comes from the queried object.
     */
    private function archiveContent( string $title ): string {
        $heading = $title !== ''
            ? '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html( $title ) . '</h1><!-- /wp:heading -->'
            : '<!-- wp:query-title {"type":"archive","level":1} /-->';

        return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
            . $heading
            . '<!-- wp:query {"queryId":0,"query":{"inherit":true},"className":"sermonator-grid","layout":{"type":"default"}} -->'
            . '<div class="wp-block-query sermonator-grid">'
            . '<!-- wp:post-template -->'
            . '<!-- wp:sermonator/sermon-card /-->'
            . '<!-- /wp:post-template -->'
            . '<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->'
            . '<!-- wp:query-no-results --><!-- wp:paragraph --><p>' . esc_html__( 'No sermons found.', 'sermonator' ) . '</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
            . '</div>'
            . '<!-- /wp:query -->'
            . '</main><!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
    }
}
