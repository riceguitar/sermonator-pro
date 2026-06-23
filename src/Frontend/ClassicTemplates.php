<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Classic-theme fallback. Block themes get rich single rendering via BlockTemplates; classic
 * themes get it here, through the single_template filter loading a plugin PHP template (a
 * theme may override by shipping sermonator/single-sermonator-sermon.php).
 *
 * Auto-appending the meta into the_content is an explicit opt-in, DEFAULT OFF: the template
 * is the single emitter, so the default configuration cannot double-render. The opt-in
 * exists only for themes whose single template we cannot influence.
 */
final class ClassicTemplates {
    public function hook(): void {
        add_filter( 'single_template', array( $this, 'singleTemplate' ) );
        add_filter( 'the_content', array( $this, 'maybeAppendMeta' ) );
    }

    public function singleTemplate( string $template ): string {
        if ( ! is_singular( ID::POST_TYPE_SERMON ) ) {
            return $template;
        }

        // Theme override wins: a theme can ship sermonator/single-sermonator-sermon.php.
        $themeTemplate = locate_template( array( 'sermonator/single-sermonator-sermon.php' ) );
        if ( $themeTemplate !== '' ) {
            return $themeTemplate;
        }

        return dirname( SERMONATOR_FILE ) . '/templates/classic/single-sermonator-sermon.php';
    }

    public function maybeAppendMeta( string $content ): string {
        if ( ! is_singular( ID::POST_TYPE_SERMON ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        /**
         * Opt in to auto-append the sermon meta/players to the_content. Default false so the
         * template remains the single emitter (no double render under caches/SEO plugins).
         *
         * @param bool $enabled
         */
        if ( ! apply_filters( 'sermonator_frontend_auto_append_meta', false ) ) {
            return $content;
        }

        $view = ( new TemplateData() )->sermon( (int) get_the_ID() );
        $r    = new Renderer();
        return $r->meta( $view ) . $r->audioPlayer( $view ) . $r->video( $view ) . $content;
    }
}
