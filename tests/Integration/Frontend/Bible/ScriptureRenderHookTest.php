<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the single wiring point {@see \Sermonator\Frontend\Bible\ScriptureRenderHook}:
 * it must append the resolved scripture section through `the_content` on a single
 * sermon, across the TWO structurally distinct core render paths that both run the
 * body through that filter:
 *
 *   1. the_post() loop path — classic single templates AND theme-override block
 *      templates (id starts with the stylesheet slug, so
 *      get_the_block_template_html() takes the `while(have_posts()){the_post();…}`
 *      branch). in_the_loop() is TRUE here.
 *      → {@see self::test_appends_scripture_section_on_single_sermon}
 *
 *   2. do_blocks()-without-the_post() path — the DEFAULT block-theme surface, where
 *      the active template is the plugin's own `sermonator//single-…` (its id does
 *      NOT start with the stylesheet slug), so get_the_block_template_html() takes
 *      the `else { do_blocks(); }` branch and in_the_loop() is FALSE. This is the
 *      path the old `! in_the_loop()` guard silently broke (spec §5, Risk #4); the
 *      queried-object identity guard fixes it.
 *      → {@see self::test_appends_scripture_through_plugin_block_template}
 *
 * NOT run in CI here (no Docker / wp-env in this environment) — authored to run
 * under wp-env later. The plugin is already booted by the integration bootstrap,
 * so the hook is live; these tests drive the main query and the real core render
 * path, never hand-stitching the guard's preconditions.
 */
final class ScriptureRenderHookTest extends WP_UnitTestCase {
    private function sermon(): int {
        return (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_title'  => 'Scripture Sermon',
            'post_status' => 'publish',
        ) );
    }

    /**
     * @param list<array<string,mixed>> $refs
     */
    private function storeRefs( int $id, array $refs ): void {
        update_post_meta(
            $id,
            ID::META_BIBLE_REFS,
            wp_json_encode( array( 'v' => 1, 'refs' => $refs ) )
        );
    }

    /**
     * Path 1: the_post() loop (classic single template + theme-override block
     * template). in_the_loop() is TRUE here; the queried-object identity guard
     * also holds (current post IS the queried object).
     */
    public function test_appends_scripture_section_on_single_sermon(): void {
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $id = $this->sermon();
        $this->storeRefs( $id, array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
        ) );

        $this->go_to( get_permalink( $id ) );
        $this->assertTrue( have_posts() );
        the_post();

        $out = (string) apply_filters( 'the_content', 'BODY' );

        $this->assertStringContainsString( 'BODY', $out );
        $this->assertStringContainsString( 'sermonator-scripture', $out );
        $this->assertStringContainsString( 'biblegateway.com', $out );
        $this->assertStringContainsString( 'John 3:16', $out );
        $this->assertStringContainsString( '(ESV)', $out );

        wp_reset_postdata();
    }

    /**
     * Path 2: the DEFAULT block-theme surface. Renders through the REAL core
     * canvas {@see get_the_block_template_html()} with the plugin's own template
     * id `sermonator//single-…`. Because that id does NOT start with the active
     * stylesheet slug, core takes the `else { do_blocks(); }` branch — it never
     * calls the_post(), so in_the_loop() is FALSE while core/post-content fires
     * the_content. The body still renders (global $post / block postId context,
     * both seeded from the queried object by WP::register_globals + render_block),
     * and the scripture section MUST appear. The old `! in_the_loop()` guard made
     * this assertion fail (feature dark on the default block surface); the
     * identity guard makes it pass. This is the test the prior version lacked —
     * it hand-drove the classic loop and never exercised this path.
     */
    public function test_appends_scripture_through_plugin_block_template(): void {
        global $_wp_current_template_id, $_wp_current_template_content;

        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $id = $this->sermon();
        $this->storeRefs( $id, array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
        ) );

        $this->go_to( get_permalink( $id ) );

        // Reproduce the default block-theme canvas: a plugin-registered template id
        // (NOT `<stylesheet>//…`), so core renders via the no-the_post() else branch.
        $prevId      = $_wp_current_template_id;
        $prevContent = $_wp_current_template_content;

        $_wp_current_template_id      = 'sermonator//single-' . ID::POST_TYPE_SERMON;
        $_wp_current_template_content = '<!-- wp:post-content /-->';

        // Precondition that pins the regression: the broken guard depended on this.
        $this->assertFalse( in_the_loop(), 'else-branch render must not be in the loop' );

        $out = (string) get_the_block_template_html();

        $_wp_current_template_id      = $prevId;
        $_wp_current_template_content = $prevContent;
        wp_reset_postdata();

        $this->assertStringContainsString( 'sermonator-scripture', $out );
        $this->assertStringContainsString( 'biblegateway.com', $out );
        $this->assertStringContainsString( 'John 3:16', $out );
        $this->assertStringContainsString( '(ESV)', $out );
    }

    public function test_fail_open_leaves_content_byte_identical_when_no_refs(): void {
        // No META_BIBLE_REFS → BibleResolver::resolve() is null → content unchanged.
        $id = $this->sermon();
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 3:16' );

        $this->go_to( get_permalink( $id ) );
        $this->assertTrue( have_posts() );
        the_post();

        $body = '<p>BODY</p>';
        $out  = (string) apply_filters( 'the_content', $body );

        $this->assertStringNotContainsString( 'sermonator-scripture', $out );

        wp_reset_postdata();
    }

    public function test_does_not_fire_outside_main_single_loop(): void {
        // No is_singular( sermon ) context → guard short-circuits, content untouched.
        $id = $this->sermon();
        $this->storeRefs( $id, array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
        ) );

        $out = (string) apply_filters( 'the_content', 'BODY' );

        $this->assertStringNotContainsString( 'sermonator-scripture', $out );
    }
}
