<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the single wiring point {@see \Sermonator\Frontend\Bible\ScriptureRenderHook}:
 * it must append the resolved scripture section through `the_content` on a single
 * sermon, across every surface that runs the body through that filter (classic,
 * block single-sermon, theme overrides). NOT run in CI here (no Docker / wp-env
 * in this environment) — authored to run under wp-env later.
 *
 * The plugin is already booted by the integration bootstrap, so the hook is live;
 * these tests just drive the main loop and apply the filter.
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
