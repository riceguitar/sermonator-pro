<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Seo;

use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

/**
 * Emits schema.org JSON-LD + Open Graph/Twitter meta into <head> on a single sermon. Pure
 * builders ({@see JsonLd}, {@see OpenGraph}) produce the data; this class adds the WordPress
 * context (site name, featured image, excerpt) and prints, escaped. Read-only. Skips when an
 * SEO plugin is expected to own OG tags via the `sermonator_frontend_emit_open_graph` filter.
 */
final class SeoHead {
    public function hook(): void {
        add_action( 'wp_head', array( $this, 'render' ) );
    }

    public function render(): void {
        if ( ! is_singular( ID::POST_TYPE_SERMON ) || ! is_main_query() ) {
            return;
        }
        $postId = (int) get_queried_object_id();
        if ( $postId <= 0 ) {
            return;
        }

        $view    = ( new TemplateData() )->sermon( $postId );
        $context = array(
            'siteName'    => (string) get_bloginfo( 'name' ),
            'image'       => (string) ( get_the_post_thumbnail_url( $postId, 'full' ) ?: '' ),
            'description' => (string) get_the_excerpt( $postId ),
        );

        // JSON-LD.
        $schema = ( new JsonLd() )->forSermon( $view, $context );
        $json   = wp_json_encode( $schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( is_string( $json ) ) {
            echo "\n" . '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput — JSON_HEX_TAG neutralises </script>
        }

        // Open Graph / Twitter — opt-out for sites whose SEO plugin owns these.
        if ( ! apply_filters( 'sermonator_frontend_emit_open_graph', true, $postId ) ) {
            return;
        }
        foreach ( ( new OpenGraph() )->forSermon( $view, $context ) as $tag ) {
            printf(
                '<meta %s="%s" content="%s" />' . "\n",
                esc_attr( $tag['attr'] ),
                esc_attr( $tag['key'] ),
                esc_attr( $tag['content'] )
            );
        }
    }
}
