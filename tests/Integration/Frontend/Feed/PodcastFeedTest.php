<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\EnclosureResolver;
use Sermonator\Frontend\Feed\PodcastConfigFactory;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Schema\Identifiers as ID;

final class PodcastFeedTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        unset( $_GET['podcast'] );
        parent::tearDown();
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

    private function capture(): string {
        ob_start();
        ( new PodcastFeed() )->render();
        return (string) ob_get_clean();
    }
}
