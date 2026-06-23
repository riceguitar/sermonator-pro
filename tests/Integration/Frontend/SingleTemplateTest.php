<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\BlockTemplates;
use Sermonator\Frontend\ClassicTemplates;
use Sermonator\Schema\Identifiers as ID;

/**
 * The single-sermon block template registers for FSE themes and wires the Sermonator blocks;
 * the classic fallback resolves a plugin PHP template; and the_content auto-append is OFF by
 * default so the template is the single meta emitter (no double render).
 */
final class SingleTemplateTest extends WP_UnitTestCase {
    public function test_block_template_registered_for_single_sermon(): void {
        ( new BlockTemplates() )->register();

        $all   = get_block_templates( array(), 'wp_template' );
        $slugs = array_map( static fn( $t ) => $t->slug, $all );
        $this->assertContains(
            'single-' . ID::POST_TYPE_SERMON,
            $slugs,
            'Plugin must register a single-sermonator_sermon block template.'
        );
    }

    public function test_block_template_content_wires_sermon_blocks(): void {
        ( new BlockTemplates() )->register();

        $tpl = get_block_template( 'sermonator//single-' . ID::POST_TYPE_SERMON, 'wp_template' );
        $this->assertNotNull( $tpl );
        $this->assertStringContainsString( 'wp:sermonator/sermon-meta', $tpl->content );
        $this->assertStringContainsString( 'wp:sermonator/audio-player', $tpl->content );
        $this->assertStringContainsString( 'wp:sermonator/video', $tpl->content );
    }

    public function test_classic_single_template_points_at_plugin_file(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        $this->go_to( get_permalink( $id ) );

        $resolved = ( new ClassicTemplates() )->singleTemplate( '/themes/x/single.php' );
        $this->assertStringContainsString( 'single-sermonator-sermon.php', $resolved );
    }

    public function test_classic_single_template_untouched_for_other_types(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
        $this->go_to( get_permalink( $id ) );

        $resolved = ( new ClassicTemplates() )->singleTemplate( '/themes/x/single.php' );
        $this->assertSame( '/themes/x/single.php', $resolved );
    }

    public function test_auto_append_meta_is_off_by_default(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
        $this->go_to( get_permalink( $id ) );
        $this->assertTrue( have_posts() );
        the_post();

        $out = (string) apply_filters( 'the_content', 'BODY' );
        // wpautop wraps the body; what matters is that NO sermon meta was appended.
        $this->assertStringContainsString( 'BODY', $out );
        $this->assertStringNotContainsString( 'sermonator-meta', $out, 'Auto-append must be OFF by default (single emitter).' );
        wp_reset_postdata();
    }

    public function test_auto_append_meta_when_opted_in(): void {
        // ClassicTemplates is already hooked by the booted plugin; just flip the opt-in.
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
        add_filter( 'sermonator_frontend_auto_append_meta', '__return_true' );
        $this->go_to( get_permalink( $id ) );
        $this->assertTrue( have_posts() );
        the_post();

        $out = apply_filters( 'the_content', 'BODY' );
        $this->assertStringContainsString( 'John 1:1-14', (string) $out );
        $this->assertStringContainsString( 'BODY', (string) $out );

        remove_filter( 'sermonator_frontend_auto_append_meta', '__return_true' );
        wp_reset_postdata();
    }
}
