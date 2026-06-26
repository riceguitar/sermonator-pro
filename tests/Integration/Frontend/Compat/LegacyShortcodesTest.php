<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * Legacy tags are registered by FrontendServiceProvider on init (booted by the plugin), so
 * do_shortcode() exercises the end-to-end shim. The Compatibility Contract's load-bearing rule
 * is fail-visible: a migrated page must never print raw "[sermons]" text, and the editor notice
 * must be invisible to visitors.
 */
final class LegacyShortcodesTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function makeSermon(): int {
        return (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'The Light Has Come',
        ) );
    }

    public function test_sermons_tag_resolves_to_the_sermon_list_not_raw_text(): void {
        $this->makeSermon();

        $html = do_shortcode( '[sermons]' );

        $this->assertStringContainsString( 'sermonator-grid', $html );
        $this->assertStringNotContainsString( '[sermons]', $html );
    }

    public function test_every_sermon_tag_resolves(): void {
        $this->makeSermon();

        foreach ( array( '[sermons]', '[sermons_sm]', '[list_sermons]', '[latest_series]', '[sermon_images]' ) as $tag ) {
            $html = do_shortcode( $tag );
            $this->assertStringNotContainsString( $tag, $html, "$tag rendered as raw text" );
            $this->assertStringContainsString( 'sermonator-grid', $html, "$tag did not render the sermon list" );
        }
    }

    public function test_notice_absent_for_visitors(): void {
        wp_set_current_user( 0 );
        $this->makeSermon();

        $html = do_shortcode( '[sermons]' );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_notice_present_for_editors(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon();

        $html = do_shortcode( '[sermons]' );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_list_podcasts_renders_subscribe_links_not_the_sermon_list(): void {
        $podcast = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_status' => 'publish',
        ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $podcast );

        $html = do_shortcode( '[list_podcasts]' );

        $this->assertStringContainsString( 'sermonator-subscribe', $html );
        $this->assertStringNotContainsString( 'sermonator-grid', $html );
        $this->assertStringNotContainsString( '[list_podcasts]', $html );
        // Subscribe surface is faithful at Tier A, so it carries no review notice.
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }
}
