<?php
/**
 * Classic-theme single sermon template. Theme-overridable by shipping
 * sermonator/single-sermonator-sermon.php in the active theme.
 *
 * All dynamic output is already escaped at the Renderer boundary.
 *
 * @package Sermonator
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

get_header();

while ( have_posts() ) {
    the_post();

    $view = ( new TemplateData() )->sermon( (int) get_the_ID() );
    $r    = new Renderer();

    echo '<article class="sermonator-single">';
    echo '<h1 class="sermonator-single__title">' . esc_html( get_the_title() ) . '</h1>';
    echo $r->featuredImage( $view ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Renderer
    echo $r->meta( $view );        // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Renderer
    echo $r->audioPlayer( $view ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Renderer
    echo $r->video( $view );       // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Renderer
    echo $r->bulletin( $view );      // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Renderer
    echo '<div class="sermonator-single__body">';
    the_content();
    echo '</div>';
    echo $r->notes( $view );         // phpcs:ignore WordPress.Security.EscapeOutput — escaped in Renderer
    echo '</article>';
}

get_footer();
