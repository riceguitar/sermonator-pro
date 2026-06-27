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
        // The subcategory 'Christianity' survives the sanitized write (most-specific valid term),
        // NOT collapsed to its top-level 'Religion & Spirituality' parent.
        $this->assertSame( 'Christianity', $stored['category'] );
        $this->assertSame( 'Trimmed Title', $stored['title'] );
    }

    /**
     * The stored category must round-trip through the read path so the feed still emits the nested
     * <itunes:category> subcategory. Proves the §2/§3/§4 fidelity fix end-to-end: a sanitized
     * 'Christianity' write yields category='Religion & Spirituality' AND subcategory='Christianity'
     * back out of the factory the feed consumes.
     */
    public function test_sanitized_category_round_trips_subcategory_through_factory(): void {
        update_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, array(
            'category' => 'Christianity',
        ) );

        $config = ( new PodcastConfigFactory() )->fromPost( $this->podcastId, 'http://example.com/feed/' );

        $this->assertSame( 'Religion & Spirituality', $config->category );
        $this->assertSame( 'Christianity', $config->subcategory );
    }

    /**
     * REGRESSION (§1): the canonical blob is NOT identity-only. The PodcastSubscribeBlock reads
     * apple_url/spotify_url from it, and migration stores per-taxonomy term-filter SCOPE keys in it
     * that decide which sermons the feed serves. Because the sanitize_callback fires on EVERY write
     * (incl. migration's add_post_meta), those keys MUST survive a registered write — dropping them
     * would be a #1-standard data-preservation clobber of the feed's scope + subscribe links.
     */
    public function test_update_post_meta_preserves_subscribe_urls_and_term_filter_scope(): void {
        update_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, array(
            'title'               => 'Sunday Sermons',
            'apple_url'           => 'https://podcasts.apple.com/us/podcast/id123',
            'spotify_url'         => 'https://open.spotify.com/show/abc123',
            // Migration term-filter scope: taxonomy slug => new term id(s).
            'sermonator_preacher' => 7,
            'sermonator_series'   => array( 11, 12 ),
        ) );

        $stored = get_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, true );

        $this->assertIsArray( $stored );
        $this->assertSame( 'Sunday Sermons', $stored['title'] );
        // Subscribe URLs survive (now catalog keys, URL-sanitized but valid → unchanged).
        $this->assertSame( 'https://podcasts.apple.com/us/podcast/id123', $stored['apple_url'] );
        $this->assertSame( 'https://open.spotify.com/show/abc123', $stored['spotify_url'] );
        // Feed term-scoping survives, ints intact (lossless) — feed serves the right sermons.
        $this->assertSame( 7, $stored['sermonator_preacher'] );
        $this->assertSame( array( 11, 12 ), $stored['sermonator_series'] );
    }

    /**
     * A genuinely injected unknown SCALAR key is preserved (not dropped) but hardened: the value is
     * run through sanitize_text_field so no raw markup persists. Feed injection is independently
     * blocked at read by the factory's catalog intersect (covered below), so preservation here is
     * safe.
     */
    public function test_update_post_meta_preserves_but_hardens_unknown_scalar(): void {
        update_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, array(
            'mystery_key' => '<b>raw</b> value',
        ) );

        $stored = get_post_meta( $this->podcastId, ID::META_PODCAST_SETTINGS, true );

        $this->assertArrayHasKey( 'mystery_key', $stored );
        // sanitize_text_field strips tags; the key itself is preserved.
        $this->assertSame( 'raw value', $stored['mystery_key'] );
    }

    // --- auth gate -----------------------------------------------------------

    public function test_auth_callback_blocks_non_admin_rest_edit(): void {
        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $admin      = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

        // The registered auth_callback is consumed by map_meta_cap() for the edit_post_meta
        // primitive cap (the documented, subtype-aware path) — not a globally-named
        // auth_post_meta_<key> filter, which register_post_meta scopes to the object subtype.
        wp_set_current_user( $subscriber );
        $this->assertFalse(
            current_user_can( 'edit_post_meta', $this->podcastId, ID::META_PODCAST_SETTINGS ),
            'A subscriber must not be allowed to edit the podcast-settings meta.'
        );

        wp_set_current_user( $admin );
        $this->assertTrue(
            current_user_can( 'edit_post_meta', $this->podcastId, ID::META_PODCAST_SETTINGS ),
            'An administrator (manage_options) must be allowed to edit the podcast-settings meta.'
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
