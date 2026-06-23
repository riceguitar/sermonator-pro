<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Sermonator\Frontend\Feed\FeedBuilder;
use Sermonator\Frontend\Feed\PodcastConfig;
use Sermonator\Frontend\Feed\FeedItem;

final class FeedBuilderTest extends TestCase {
    private function config(): PodcastConfig {
        return new PodcastConfig(
            title: 'Sunday Sermons & More',
            link: 'http://example.com/',
            description: 'Weekly teaching',
            language: 'en-US',
            author: 'Example Church',
            summary: 'Sermons preached on Sundays.',
            ownerName: 'Example Church',
            ownerEmail: 'podcast@example.com',
            imageUrl: 'http://example.com/art.jpg',
            category: 'Religion & Spirituality',
            subcategory: 'Christianity',
            explicit: false,
            copyright: '© Example Church',
            feedUrl: 'http://example.com/feed/sermonator-podcast/'
        );
    }

    private function item(): FeedItem {
        return new FeedItem(
            title: 'The Light & The Dark',
            link: 'http://example.com/sermons/light/',
            guid: 'sermonator-5',
            description: 'A sermon on John 1.',
            pubTimestamp: 1734775200,
            audioUrl: 'http://example.com/a.mp3',
            audioType: 'audio/mpeg',
            audioSize: 32871234,
            duration: '00:34:12',
            explicit: false
        );
    }

    public function test_produces_well_formed_xml(): void {
        $xml = ( new FeedBuilder() )->build( $this->config(), array( $this->item() ) );
        $sx  = simplexml_load_string( $xml );
        $this->assertNotFalse( $sx, 'Feed must be well-formed XML.' );
    }

    public function test_channel_has_required_itunes_tags(): void {
        $xml = ( new FeedBuilder() )->build( $this->config(), array() );

        $this->assertStringContainsString( '<rss version="2.0"', $xml );
        $this->assertStringContainsString( 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"', $xml );
        $this->assertStringContainsString( '<itunes:author>Example Church</itunes:author>', $xml );
        $this->assertStringContainsString( '<itunes:owner>', $xml );
        $this->assertStringContainsString( '<itunes:email>podcast@example.com</itunes:email>', $xml );
        $this->assertStringContainsString( '<itunes:image href="http://example.com/art.jpg"/>', $xml );
        $this->assertStringContainsString( '<itunes:category text="Religion &amp; Spirituality">', $xml );
        $this->assertStringContainsString( '<itunes:category text="Christianity"/>', $xml );
        $this->assertStringContainsString( '<itunes:explicit>false</itunes:explicit>', $xml );
    }

    public function test_item_has_enclosure_with_length_and_stable_guid(): void {
        $xml = ( new FeedBuilder() )->build( $this->config(), array( $this->item() ) );

        $this->assertStringContainsString( '<enclosure url="http://example.com/a.mp3" length="32871234" type="audio/mpeg"/>', $xml );
        $this->assertStringContainsString( '<guid isPermaLink="false">sermonator-5</guid>', $xml );
        $this->assertStringContainsString( '<itunes:duration>00:34:12</itunes:duration>', $xml );
        $this->assertStringContainsString( 'Sat, 21 Dec 2024 10:00:00 +0000', $xml );
    }

    public function test_xml_escapes_special_characters_in_text(): void {
        $xml = ( new FeedBuilder() )->build( $this->config(), array( $this->item() ) );
        // Channel title with & is escaped in element text.
        $this->assertStringContainsString( '<title>Sunday Sermons &amp; More</title>', $xml );
        // Item title with & ends up inside the title element, escaped.
        $this->assertStringContainsString( 'The Light &amp; The Dark', $xml );
        $this->assertStringNotContainsString( 'The Light & The Dark', $xml );
    }

    public function test_description_uses_cdata(): void {
        $xml = ( new FeedBuilder() )->build( $this->config(), array( $this->item() ) );
        $this->assertStringContainsString( '<description><![CDATA[A sermon on John 1.]]></description>', $xml );
    }

    public function test_strips_invalid_xml_control_characters(): void {
        $item = new FeedItem(
            title: "Bad\x0CTitle\x0B",      // form-feed + vertical tab (illegal in XML 1.0)
            link: 'http://example.com/x/',
            guid: 'sermonator-9',
            description: "Body with a \x1B escape byte.",
            pubTimestamp: 1734775200,
            audioUrl: 'http://example.com/x.mp3',
            audioType: 'audio/mpeg',
            audioSize: 100,
            duration: '',
            explicit: false
        );

        $xml = ( new FeedBuilder() )->build( $this->config(), array( $item ) );

        $this->assertNotFalse( simplexml_load_string( $xml ), 'A control char must NOT break the whole feed.' );
        $this->assertStringContainsString( 'BadTitle', $xml );
        $this->assertStringNotContainsString( "\x0C", $xml );
        $this->assertStringNotContainsString( "\x1B", $xml );
    }
}
