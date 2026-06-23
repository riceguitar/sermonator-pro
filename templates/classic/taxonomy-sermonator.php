<?php
/**
 * Classic-theme sermon taxonomy archive (shared by all 5 sermon taxonomies).
 * Theme-overridable at sermonator/taxonomy-sermonator.php. Main query is preached-date
 * ordered by ArchiveOrdering. Renderer output is escaped.
 *
 * @package Sermonator
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

get_header();

$sermonator_renderer = new Renderer();
$sermonator_data     = new TemplateData();
$sermonator_term     = get_queried_object();

echo '<main class="sermonator-archive">';
echo '<h1 class="sermonator-archive__title">' . esc_html(
    ( $sermonator_term instanceof WP_Term ) ? $sermonator_term->name : single_term_title( '', false )
) . '</h1>';

if ( $sermonator_term instanceof WP_Term && '' !== trim( (string) $sermonator_term->description ) ) {
    echo '<div class="sermonator-archive__description">' . wp_kses_post( $sermonator_term->description ) . '</div>';
}

if ( have_posts() ) {
    echo '<div class="sermonator-grid" data-columns="3">';
    while ( have_posts() ) {
        the_post();
        echo $sermonator_renderer->card( $sermonator_data->sermon( (int) get_the_ID() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
    }
    echo '</div>';

    the_posts_pagination( array(
        'mid_size'  => 2,
        'prev_text' => esc_html__( '« Previous', 'sermonator' ),
        'next_text' => esc_html__( 'Next »', 'sermonator' ),
    ) );
} else {
    echo '<p class="sermonator-grid__empty">' . esc_html__( 'No sermons found.', 'sermonator' ) . '</p>';
}

echo '</main>';

get_footer();
