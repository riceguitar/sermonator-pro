<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Schema;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;
use Sermonator\Schema\PodcastMetaSchema;
use Sermonator\Frontend\Feed\PodcastConfigFactory;

/**
 * Integration coverage for {@see PodcastMetaSchema}, driving the REAL WordPress meta API (no
 * Brain Monkey). NOT run in this environment (no Docker / wp-env) — authored to run under
 * wp-env later.
 *
 * Exercises what the unit test cannot: that `register_post_meta()` actually wires the
 * `sanitize_callback` onto `update_post_meta()` (so a stored value is cleaned by WordPress
 * itself), that the `auth_callback` gates `manage_options`, and that the shared catalog keeps
 * {@see PodcastConfigFactory} and the write path in agreement against real persisted meta.
 */
final class PodcastMetaSchemaTest extends WP_UnitTestCase {
    private int $podcastId;

    protected function setUp(): void {
        parent::setUp();

        PodcastMetaSchema::register();

        $this->podcastId = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_title'  => 'Sunday Sermons',
            'post_status' => 'publish',
        ) );
    }

    // --- sanitize-at-write through the real meta API -------------------------

    public function test_update_post_meta_drops_unknown_keys(): void {
        update_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, array(
            'title'       => 'Sunday Sermons',
            'evil_script' => '<script>alert(1)</script>',
        ) );

        $stored = get_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, true );

        $this->assertIsArray( $stored );
        $this->assertSame( 'Sunday Sermons', $stored['title'] );
        $this->assertArrayNotHasKey( 'evil_script', $stored );
    }

    public function test_update_post_meta_sanitizes_per_key(): void {
        update_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, array(
            'owner_email' => 'pod cast@example.com',
            'image'       => 'http://example.com/art.jpg',
            'explicit'    => 'yes',
            'category'    => 'Christianity',
            'title'       => '  Trimmed Title  ',
        ) );

        $stored = get_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, true );

        $this->assertSame( 'podcast@example.com', $stored['owner_email'] );
        $this->assertSame( 'http://example.com/art.jpg', $stored['image'] );
        $this->assertTrue( $stored['explicit'] );
        $this->assertSame( 'Religion & Spirituality', $stored['category'] );
        $this->assertSame( 'Trimmed Title', $stored['title'] );
    }

    // --- auth gate -----------------------------------------------------------

    public function test_auth_callback_blocks_non_admin_rest_edit(): void {
        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $admin      = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

        wp_set_current_user( $subscriber );
        $this->assertFalse(
            (bool) apply_filters( "auth_post_meta_{$this->metaKey()}", false, ID::META_PODCAST_SETTINGS, $this->podcastId, $subscriber, 'edit_post_meta', array() )
        );

        wp_set_current_user( $admin );
        $this->assertTrue(
            (bool) apply_filters( "auth_post_meta_{$this->metaKey()}", false, ID::META_PODCAST_SETTINGS, $this->podcastId, $admin, 'edit_post_meta', array() )
        );

        wp_set_current_user( 0 );
    }

    // --- shared catalog keeps reader + writer in agreement -------------------

    public function test_factory_reads_only_catalog_keys_from_persisted_meta(): void {
        // Persist directly (bypassing the registered sanitizer via low-level add) so we prove the
        // factory's own catalog intersection drops a stray key even on an unsanitized legacy row.
        update_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
        ) );

        $config = ( new PodcastConfigFactory() )->fromPost( $this->podcastId, 'http://example.com/feed/' );

        $this->assertSame( 'Example Church', $config->author );
        $this->assertSame( 'Weekly teaching.', $config->summary );
        $this->assertSame( 'podcast@example.com', $config->ownerEmail );
    }

    public function test_catalog_keys_match_factory_expectations(): void {
        $keys = PodcastMetaSchema::keys();
        foreach ( array( 'title', 'summary', 'author', 'owner_name', 'owner_email', 'image', 'category', 'explicit', 'copyright', 'language' ) as $factoryKey ) {
            $this->assertContains( $factoryKey, $keys, "Factory reads `{$factoryKey}` but it is not in the shared catalog." );
        }
    }

    private function metaKey(): string {
        return ID::META_PODCAST_SETTINGS;
    }
}
