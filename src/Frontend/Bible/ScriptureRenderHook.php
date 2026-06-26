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
 * Guard is a queried-object IDENTITY check — is_singular( sermon ) +
 * is_main_query() + the post being filtered IS the queried object — NOT
 * in_the_loop(). in_the_loop() is set only by the singular main loop's
 * the_post(), which WordPress runs solely when the active template id starts
 * with the active theme's stylesheet slug (see get_the_block_template_html in
 * wp-includes/block-template.php). Our own registered single template id is
 * `sermonator//single-…` (never `<stylesheet>//…`), so on the DEFAULT
 * block-theme surface core takes the `else` branch and runs do_blocks()
 * WITHOUT the_post() → in_the_loop() is false when core/post-content fires
 * the_content, which would silently ship scripture dark there (spec §5,
 * Risk #4). The identity guard holds on all three surfaces — classic
 * the_post() loop, block core/post-content, theme override — while still
 * excluding archives, secondary loops/excerpts (their post id differs from
 * the queried object), and nested the_content.
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
        if ( ! is_singular( ID::POST_TYPE_SERMON )
            || ! is_main_query()
            || (int) get_the_ID() !== (int) get_queried_object_id() ) {
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
