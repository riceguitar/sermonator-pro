<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Seo;

use PHPUnit\Framework\TestCase;
use Sermonator\Frontend\Seo\OpenGraph;
use Sermonator\Frontend\SermonView;

final class OpenGraphTest extends TestCase {
    private function view( array $over = array() ): SermonView {
        return new SermonView(
            id: 5,
            title: 'The Light Has Come',
            permalink: 'http://x/sermons/light/',
            preachedTimestamp: 1734775200,
            biblePassage: 'John 1:1-14',
            audioUrl: $over['audio'] ?? '',
            audioDuration: '',
            audioSize: 0,
            videoEmbed: '',
            videoUrl: $over['vurl'] ?? '',
            views: 0
        );
    }

    /** @param list<array{attr:string,key:string,content:string}> $tags */
    private function find( array $tags, string $key ): ?string {
        foreach ( $tags as $t ) {
            if ( $t['key'] === $key ) {
                return $t['content'];
            }
        }
        return null;
    }

    public function test_core_og_tags(): void {
        $tags = ( new OpenGraph() )->forSermon( $this->view(), array( 'siteName' => 'Example Church', 'description' => 'A sermon.' ) );
        $this->assertSame( 'article', $this->find( $tags, 'og:type' ) );
        $this->assertSame( 'The Light Has Come', $this->find( $tags, 'og:title' ) );
        $this->assertSame( 'http://x/sermons/light/', $this->find( $tags, 'og:url' ) );
        $this->assertSame( 'Example Church', $this->find( $tags, 'og:site_name' ) );
        $this->assertSame( 'A sermon.', $this->find( $tags, 'og:description' ) );
    }

    public function test_twitter_card_summary_without_image(): void {
        $tags = ( new OpenGraph() )->forSermon( $this->view() );
        $this->assertSame( 'summary', $this->find( $tags, 'twitter:card' ) );
    }

    public function test_twitter_card_large_image_with_image(): void {
        $tags = ( new OpenGraph() )->forSermon( $this->view(), array( 'image' => 'http://x/img.jpg' ) );
        $this->assertSame( 'summary_large_image', $this->find( $tags, 'twitter:card' ) );
        $this->assertSame( 'http://x/img.jpg', $this->find( $tags, 'og:image' ) );
    }

    public function test_audio_and_video_tags(): void {
        $tags = ( new OpenGraph() )->forSermon( $this->view( array( 'audio' => 'http://x/a.mp3', 'vurl' => 'http://x/v' ) ) );
        $this->assertSame( 'http://x/a.mp3', $this->find( $tags, 'og:audio' ) );
        $this->assertSame( 'http://x/v', $this->find( $tags, 'og:video' ) );
    }

    public function test_description_falls_back_to_passage(): void {
        $tags = ( new OpenGraph() )->forSermon( $this->view() );
        $this->assertSame( 'John 1:1-14', $this->find( $tags, 'og:description' ) );
    }
}
