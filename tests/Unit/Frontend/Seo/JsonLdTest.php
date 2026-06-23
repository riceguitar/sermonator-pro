<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Seo;

use PHPUnit\Framework\TestCase;
use Sermonator\Frontend\Seo\JsonLd;
use Sermonator\Frontend\SermonView;

final class JsonLdTest extends TestCase {
    private function view( array $over = array() ): SermonView {
        return new SermonView(
            id: 5,
            title: $over['title'] ?? 'The Light Has Come',
            permalink: 'http://x/sermons/light/',
            preachedTimestamp: array_key_exists( 'ts', $over ) ? $over['ts'] : 1734775200,
            biblePassage: $over['passage'] ?? 'John 1:1-14',
            audioUrl: $over['audio'] ?? '',
            audioDuration: $over['dur'] ?? '',
            audioSize: 0,
            videoEmbed: '',
            videoUrl: $over['vurl'] ?? '',
            views: 0,
            preachers: $over['preachers'] ?? array( array( 'name' => 'Pastor John', 'url' => '' ) ),
            series: $over['series'] ?? array( array( 'name' => 'Advent', 'url' => '' ) ),
            topics: $over['topics'] ?? array( array( 'name' => 'Hope', 'url' => '' ) )
        );
    }

    public function test_basic_creativework(): void {
        $data = ( new JsonLd() )->forSermon( $this->view(), array( 'siteName' => 'Example Church' ) );
        $this->assertSame( 'https://schema.org', $data['@context'] );
        $this->assertSame( 'CreativeWork', $data['@type'] );
        $this->assertSame( 'The Light Has Come', $data['name'] );
        $this->assertSame( '2024-12-21T10:00:00+00:00', $data['datePublished'] );
        $this->assertSame( 'Pastor John', $data['author'][0]['name'] );
        $this->assertSame( 'Advent', $data['isPartOf']['name'] );
        $this->assertSame( 'Example Church', $data['publisher']['name'] );
        $this->assertArrayNotHasKey( 'audio', $data );
    }

    public function test_audio_object_with_iso_duration(): void {
        $data = ( new JsonLd() )->forSermon( $this->view( array( 'audio' => 'http://x/a.mp3', 'dur' => '00:34:12' ) ) );
        $this->assertSame( 'AudioObject', $data['audio']['@type'] );
        $this->assertSame( 'http://x/a.mp3', $data['audio']['contentUrl'] );
        $this->assertSame( 'PT34M12S', $data['audio']['duration'] );
    }

    public function test_video_object(): void {
        $data = ( new JsonLd() )->forSermon( $this->view( array( 'vurl' => 'http://x/v' ) ) );
        $this->assertSame( 'VideoObject', $data['video']['@type'] );
        $this->assertSame( 'http://x/v', $data['video']['url'] );
        $this->assertSame( '2024-12-21T10:00:00+00:00', $data['video']['uploadDate'] );
    }

    public function test_no_date_omits_datepublished(): void {
        $data = ( new JsonLd() )->forSermon( $this->view( array( 'ts' => null ) ) );
        $this->assertArrayNotHasKey( 'datePublished', $data );
    }
}
