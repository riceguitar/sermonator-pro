<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\EnclosureResolver;
use Sermonator\Frontend\Feed\PodcastConfigFactory;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

final class PodcastFeedTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        foreach ( array(
            ID::OPTION_PODCAST_TITLE,
            ID::OPTION_PODCAST_ITUNES_AUTHOR,
            ID::OPTION_PODCAST_ITUNES_SUMMARY,
            ID::OPTION_PODCAST_ITUNES_OWNER_EMAIL,
            ID::OPTION_PODCAST_ITUNES_COVER_IMAGE,
        ) as $k ) {
            delete_option( $k );
        }
        unset( $_GET['podcast'] );
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        parent::tearDown();
    }

    /** Seed a migrated SM-Free option-podcast config (no podcast post). */
    private function seedSmFreeOptions(): void {
        update_option( ID::OPTION_PODCAST_TITLE, 'My SM-Free Podcast' );
        update_option( ID::OPTION_PODCAST_ITUNES_AUTHOR, 'Pastor Jane' );
        update_option( ID::OPTION_PODCAST_ITUNES_SUMMARY, 'Weekly sermons from our church.' );
        update_option( ID::OPTION_PODCAST_ITUNES_OWNER_EMAIL, 'podcast@church.example' );
        update_option( ID::OPTION_PODCAST_ITUNES_COVER_IMAGE, 'http://church.example/cover.jpg' );
    }

    private function podcast(): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_title'  => 'Sunday Sermons',
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
            'category'    => 'Christianity',
            'explicit'    => 'no',
        ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );
        return $id;
    }

    private function sermonWithAudio( int $size = 1000, string $title = 'Ep' ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON, 'post_title' => $title ) );
        update_post_meta( $id, ID::META_DATE, '1700000000' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/' . $id . '.mp3' );
        if ( $size > 0 ) {
            update_post_meta( $id, ID::META_AUDIO_SIZE, (string) $size );
        }
        return $id;
    }

    public function test_enclosure_resolver_reads_persisted_size(): void {
        $id  = $this->sermonWithAudio( 2048 );
        $enc = ( new EnclosureResolver() )->resolve( $id );
        $this->assertIsArray( $enc );
        $this->assertSame( 2048, $enc['size'] );
        $this->assertSame( 'audio/mpeg', $enc['type'] );
    }

    public function test_enclosure_resolver_null_without_size(): void {
        $id = $this->sermonWithAudio( 0 ); // audio set, no size
        $this->assertNull( ( new EnclosureResolver() )->resolve( $id ) );
    }

    public function test_enclosure_resolver_null_without_audio(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        $this->assertNull( ( new EnclosureResolver() )->resolve( $id ) );
    }

    public function test_feed_renders_valid_xml_with_items(): void {
        $this->podcast();
        $this->sermonWithAudio( 1000, 'First' );
        $this->sermonWithAudio( 2000, 'Second' );

        $xml = $this->capture();

        $sx = simplexml_load_string( $xml );
        $this->assertNotFalse( $sx, 'Feed must be well-formed XML.' );
        $this->assertStringContainsString( '<itunes:author>Example Church</itunes:author>', $xml );
        $this->assertStringContainsString( '<itunes:category text="Religion &amp; Spirituality">', $xml );
        $this->assertSame( 2, substr_count( $xml, '<item>' ) );
        $this->assertSame( 2, substr_count( $xml, '<enclosure ' ) );
    }

    public function test_feed_omits_sermon_without_size_and_drafts(): void {
        $this->podcast();
        $this->sermonWithAudio( 1000, 'Included' );
        $this->sermonWithAudio( 0, 'NoSize' ); // omitted (unknown size)
        $draft = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON, 'post_status' => 'draft' ) );
        update_post_meta( $draft, ID::META_AUDIO, 'http://example.com/d.mp3' );
        update_post_meta( $draft, ID::META_AUDIO_SIZE, '500' );

        $xml = $this->capture();

        $this->assertSame( 1, substr_count( $xml, '<item>' ) );
        $this->assertStringContainsString( 'Included', $xml );
        $this->assertStringNotContainsString( 'NoSize', $xml );
    }

    /**
     * Regression (Bundle 2 / T5 review): SermonQuery::run() defaults a missing `page` to the
     * registered PUBLIC `sermon_page` query var. The feed MUST be immune to that var — a stray or
     * hostile `?sermon_page=N` on the feed URL must NOT serve a different (or empty) episode set.
     * Proves never-fail-WRONG on the flagship read-only surface.
     */
    public function test_feed_ignores_sermon_page_query_var(): void {
        $this->podcast();
        $this->sermonWithAudio( 1000, 'First' );
        $this->sermonWithAudio( 2000, 'Second' );

        // sermon_page=2 would, if the feed honored it, push the offset past the data (MAX_ITEMS=300,
        // total<300 → totalPages=1) and serve an EMPTY feed.
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '2' );
        $withVar = $this->capture();

        $this->assertSame( 2, substr_count( $withVar, '<item>' ), 'sermon_page=2 must NOT empty the feed.' );
        $this->assertStringContainsString( 'First', $withVar );
        $this->assertStringContainsString( 'Second', $withVar );
    }

    /**
     * Stronger pin: the rendered feed is BYTE-IDENTICAL with and without `?sermon_page=2`, so a
     * cached/poisoned pagination param cannot alter the feed's content at all.
     */
    public function test_feed_is_byte_identical_regardless_of_sermon_page(): void {
        $this->podcast();
        $this->sermonWithAudio( 1000, 'First' );
        $this->sermonWithAudio( 2000, 'Second' );

        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        $baseline = $this->capture();

        set_query_var( SermonQuery::PAGE_QUERY_VAR, '2' );
        $withVar = $this->capture();

        $this->assertSame( $baseline, $withVar, 'sermon_page must not change a single byte of the feed.' );
    }

    public function test_feed_without_default_podcast_is_valid_empty_channel(): void {
        $xml = $this->capture();
        $sx  = simplexml_load_string( $xml );
        $this->assertNotFalse( $sx );
        $this->assertSame( 0, substr_count( $xml, '<item>' ) );
    }

    public function test_factory_falls_back_to_post_and_blog_fields(): void {
        $id     = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_PODCAST, 'post_title' => 'Bare Cast' ) );
        $config = ( new PodcastConfigFactory() )->fromPost( $id, 'http://example.com/feed/' );
        $this->assertSame( 'Bare Cast', $config->title );
        $this->assertSame( 'Religion & Spirituality', $config->category );
    }

    // ---- SM-Free option-podcast continuity (no podcast post; backward-compat) ----------------

    public function test_smfree_option_podcast_feed_serves_all_sermons_from_options(): void {
        // SM-Free: NO podcast post, NO default podcast — only the migrated option-podcast config.
        // Its single iTunes feed served ALL sermons; that must continue, sourced from the options.
        $this->seedSmFreeOptions();
        $this->sermonWithAudio( 1000, 'First' );
        $this->sermonWithAudio( 2000, 'Second' );

        $xml = $this->capture();

        $this->assertNotFalse( simplexml_load_string( $xml ), 'option-podcast feed must be well-formed XML' );
        $this->assertSame( 2, substr_count( $xml, '<item>' ), 'all sermons must serve (SM-Free implicit single podcast)' );
        $this->assertStringContainsString( '<title>My SM-Free Podcast</title>', $xml );
        $this->assertStringContainsString( '<itunes:author>Pastor Jane</itunes:author>', $xml );
        $this->assertStringContainsString( 'Weekly sermons from our church.', $xml );
        $this->assertStringContainsString( 'http://church.example/cover.jpg', $xml );
    }

    public function test_smfree_option_podcast_feed_preserves_legacy_guid(): void {
        // GUID continuity: a migrated episode stamped with its durable legacy GUID must emit THAT
        // guid, so already-subscribed apps never re-download the back catalogue after the switch.
        $this->seedSmFreeOptions();
        $id = $this->sermonWithAudio( 1500, 'Migrated Ep' );
        update_post_meta( $id, ID::META_LEGACY_GUID, 'legacy-guid-xyz' );

        $xml = $this->capture();

        $this->assertStringContainsString( 'legacy-guid-xyz', $xml, 'feed must reuse the durable legacy GUID' );
    }

    public function test_no_option_podcast_and_no_post_does_not_flood_items(): void {
        // Regression guard: a fresh sermonator site (no option-podcast, no podcast post) must serve a
        // valid EMPTY channel even when sermons exist — the all-sermons fallback is SM-Free-only.
        $this->sermonWithAudio( 1000, 'Orphan' );

        $xml = $this->capture();

        $this->assertNotFalse( simplexml_load_string( $xml ) );
        $this->assertSame( 0, substr_count( $xml, '<item>' ), 'no option-podcast → no default all-sermons feed' );
    }

    private function capture(): string {
        ob_start();
        ( new PodcastFeed() )->render();
        return (string) ob_get_clean();
    }
}
