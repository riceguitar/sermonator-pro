<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Bible;

use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

/**
 * The ONE wiring point that puts resolved scripture on the single-sermon page.
 *
 * Unlike the sermon meta — which each surface (classic template, sermon-meta
 * block, theme override) emits itself, with the_content auto-append only an
 * opt-in fallback — there is NO per-surface emitter for scripture. A per-surface
 * echo would ship the section dark on block themes and zero the observability
 * denominator (spec §5, Risk #4). So the_content is scripture's SINGLE emitter:
 * because classic templates, block single-sermon templates, AND theme overrides
 * all run the post body through `the_content`, hooking here renders the section
 * once on every surface from one place.
 *
 * Guards mirror {@see \Sermonator\Frontend\ClassicTemplates::maybeAppendMeta()}:
 * is_singular( sermon ) + in_the_loop() + is_main_query(), so it never fires on
 * archives, secondary loops, or the_content uses outside the main sermon body.
 *
 * Resolution is impure and happens HERE, at template time
 * ({@see BibleResolver::resolve()} reads meta + options), keeping the
 * {@see Renderer} pure. Fail-open: a null resolution returns the content
 * byte-identical (Renderer::scripture() would also return '' for null — the
 * early return just avoids building a SermonView we won't use).
 */
final class ScriptureRenderHook {
    public function hook(): void {
        add_filter( 'the_content', array( $this, 'appendScripture' ) );
    }

    public function appendScripture( string $content ): string {
        if ( ! is_singular( ID::POST_TYPE_SERMON ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $postId   = (int) get_the_ID();
        $resolved = BibleResolver::resolve( $postId );
        if ( $resolved === null ) {
            // Fail-open: today's plain-text "Scripture" meta row is unchanged.
            return $content;
        }

        $view = ( new TemplateData() )->sermon( $postId );

        return $content . ( new Renderer() )->scripture( $view, $resolved );
    }
}
