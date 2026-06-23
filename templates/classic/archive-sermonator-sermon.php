<?php
/**
 * Classic-theme sermon archive. Theme-overridable at sermonator/archive-sermonator-sermon.php.
 * The main query is preached-date ordered by ArchiveOrdering. Renderer output is escaped.
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

echo '<main class="sermonator-archive">';
echo '<h1 class="sermonator-archive__title">' . esc_html( post_type_archive_title( '', false ) ) . '</h1>';

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
