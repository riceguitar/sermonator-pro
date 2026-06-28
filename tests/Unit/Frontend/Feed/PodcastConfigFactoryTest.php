<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Feed\PodcastConfigFactory;
use Sermonator\Frontend\Feed\ItunesCategory;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for {@see PodcastConfigFactory::fromOptions()} and
 * {@see PodcastConfigFactory::hasOptionPodcast()} — the SM-FREE option-podcast continuity path.
 *
 * Sermon Manager FREE's built-in podcast is OPTION-based (no podcast post). After migration its
 * flat `sermonmanager_<id>` settings become `sermonator_<id>` options. These tests pin that the
 * factory reads each migrated option into the right {@see \Sermonator\Frontend\Feed\PodcastConfig}
 * field, that blog-info fallbacks fire when an option is absent, and that the hasOptionPodcast GATE
 * reports presence iff at least one SM-Free podcast option is a non-empty string.
 *
 * WP is mocked with an in-memory option store plus stubbed get_bloginfo/home_url, so the logic is
 * exercised with no WordPress runtime.
 */
final class PodcastConfigFactoryTest extends TestCase {
    private const FEED_URL = 'http://example.com/feed/sermonator-podcast/';

    /** @var array<string,mixed> */
    private array $options = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();

        Functions\when( 'get_option' )->alias( function ( string $k, $d = false ) {
            return $this->options[ $k ] ?? $d;
        } );
        Functions\when( 'get_bloginfo' )->alias( static function ( string $show = '' ): string {
            return array(
                'name'        => 'Blog Name',
                'description' => 'Blog Tagline',
                'language'    => 'en-US',
            )[ $show ] ?? '';
        } );
        Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'http://example.com' . $path );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Seed every SM-Free podcast option with a distinct, recognizable value. */
    private function seedAllOptions(): void {
        $this->options = array(
            ID::OPTION_PODCAST_TITLE              => 'My Sermons',
            ID::OPTION_PODCAST_DESCRIPTION        => 'A description option',
            ID::OPTION_PODCAST_WEBSITE_LINK       => 'http://church.example/',
            ID::OPTION_PODCAST_LANGUAGE           => 'es-ES',
            ID::OPTION_PODCAST_COPYRIGHT          => '(c) 2026 Church',
            ID::OPTION_PODCAST_ITUNES_AUTHOR      => 'Pastor Jane',
            ID::OPTION_PODCAST_ITUNES_SUBTITLE    => 'Weekly subtitle',
            ID::OPTION_PODCAST_ITUNES_SUMMARY     => 'The iTunes summary',
            ID::OPTION_PODCAST_ITUNES_OWNER_NAME  => 'Owner Name',
            ID::OPTION_PODCAST_ITUNES_OWNER_EMAIL => 'owner@church.example',
            ID::OPTION_PODCAST_ITUNES_COVER_IMAGE => 'http://church.example/cover.jpg',
            ID::OPTION_PODCAST_ITUNES_SUB_CATEGORY => 'Christianity',
        );
    }

    // ---- fromOptions(): each option lands in the right PodcastConfig field --------------------

    public function test_fromOptions_reads_each_option_into_its_field(): void {
        $this->seedAllOptions();

        $config = ( new PodcastConfigFactory() )->fromOptions( self::FEED_URL );

        $this->assertSame( 'My Sermons', $config->title );
        $this->assertSame( 'http://church.example/', $config->link );
        $this->assertSame( 'The iTunes summary', $config->summary );
        // description prefers the iTunes summary (mirrors fromPost).
        $this->assertSame( 'The iTunes summary', $config->description );
        $this->assertSame( 'es-ES', $config->language );
        $this->assertSame( 'Pastor Jane', $config->author );
        $this->assertSame( 'Owner Name', $config->ownerName );
        $this->assertSame( 'owner@church.example', $config->ownerEmail );
        $this->assertSame( 'http://church.example/cover.jpg', $config->imageUrl );
        $this->assertSame( ItunesCategory::DEFAULT_CATEGORY, $config->category );
        $this->assertSame( 'Christianity', $config->subcategory );
        $this->assertSame( '(c) 2026 Church', $config->copyright );
        $this->assertFalse( $config->explicit, 'SM-Free has no explicit field — always false.' );
        $this->assertSame( self::FEED_URL, $config->feedUrl );
    }

    public function test_fromOptions_description_falls_back_to_description_option_then_tagline(): void {
        // No iTunes summary → description uses the description option.
        $this->options = array( ID::OPTION_PODCAST_DESCRIPTION => 'Plain description' );
        $config = ( new PodcastConfigFactory() )->fromOptions( self::FEED_URL );
        $this->assertSame( '', $config->summary );
        $this->assertSame( 'Plain description', $config->description );

        // No summary AND no description option → blog tagline.
        $this->options = array();
        $config = ( new PodcastConfigFactory() )->fromOptions( self::FEED_URL );
        $this->assertSame( 'Blog Tagline', $config->description );
    }

    public function test_fromOptions_blog_info_fallbacks_fire_when_options_absent(): void {
        $this->options = array(); // nothing migrated

        $config = ( new PodcastConfigFactory() )->fromOptions( self::FEED_URL );

        $this->assertSame( 'Blog Name', $config->title, 'title → bloginfo name' );
        $this->assertSame( 'Blog Name', $config->author, 'author → bloginfo name' );
        $this->assertSame( 'Blog Name', $config->ownerName, 'ownerName → author (→ bloginfo name)' );
        $this->assertSame( 'en-US', $config->language, 'language → bloginfo language' );
        $this->assertSame( 'http://example.com/', $config->link, 'link → home_url(/)' );
        $this->assertSame( ItunesCategory::DEFAULT_CATEGORY, $config->category );
        $this->assertNull( $config->subcategory );
        $this->assertSame( '', $config->ownerEmail );
        $this->assertSame( '', $config->imageUrl );
        $this->assertSame( '', $config->copyright );
    }

    public function test_fromOptions_owner_name_falls_back_to_author(): void {
        $this->options = array( ID::OPTION_PODCAST_ITUNES_AUTHOR => 'Pastor Jane' );

        $config = ( new PodcastConfigFactory() )->fromOptions( self::FEED_URL );

        $this->assertSame( 'Pastor Jane', $config->author );
        $this->assertSame( 'Pastor Jane', $config->ownerName, 'ownerName falls back to author when unset.' );
    }

    // ---- hasOptionPodcast(): the GATE --------------------------------------------------------

    public function test_hasOptionPodcast_false_when_no_options_set(): void {
        $this->options = array();
        $this->assertFalse( ( new PodcastConfigFactory() )->hasOptionPodcast() );
    }

    public function test_hasOptionPodcast_true_when_all_options_set(): void {
        $this->seedAllOptions();
        $this->assertTrue( ( new PodcastConfigFactory() )->hasOptionPodcast() );
    }

    /**
     * Each SM-Free podcast option, alone, is enough to signal a migrated option-podcast.
     *
     * @dataProvider provideSingleOptionKeys
     */
    public function test_hasOptionPodcast_true_when_any_single_option_set( string $key ): void {
        $this->options = array( $key => 'something' );
        $this->assertTrue(
            ( new PodcastConfigFactory() )->hasOptionPodcast(),
            "A non-empty {$key} must signal a migrated SM-Free podcast."
        );
    }

    /** @return list<array{string}> */
    public static function provideSingleOptionKeys(): array {
        return array(
            array( ID::OPTION_PODCAST_TITLE ),
            array( ID::OPTION_PODCAST_DESCRIPTION ),
            array( ID::OPTION_PODCAST_WEBSITE_LINK ),
            array( ID::OPTION_PODCAST_LANGUAGE ),
            array( ID::OPTION_PODCAST_COPYRIGHT ),
            array( ID::OPTION_PODCAST_ITUNES_AUTHOR ),
            array( ID::OPTION_PODCAST_ITUNES_SUBTITLE ),
            array( ID::OPTION_PODCAST_ITUNES_SUMMARY ),
            array( ID::OPTION_PODCAST_ITUNES_OWNER_NAME ),
            array( ID::OPTION_PODCAST_ITUNES_OWNER_EMAIL ),
            array( ID::OPTION_PODCAST_ITUNES_COVER_IMAGE ),
            array( ID::OPTION_PODCAST_ITUNES_SUB_CATEGORY ),
        );
    }

    public function test_hasOptionPodcast_false_for_empty_string_and_non_scalar_values(): void {
        // An empty string or a non-scalar (e.g. a leftover array) is NOT a configured podcast.
        $this->options = array(
            ID::OPTION_PODCAST_TITLE          => '',
            ID::OPTION_PODCAST_ITUNES_SUMMARY => array( 'unexpected' ),
        );
        $this->assertFalse( ( new PodcastConfigFactory() )->hasOptionPodcast() );
    }
}
