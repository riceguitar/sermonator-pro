<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Seo;

use Sermonator\Frontend\SermonView;

/**
 * Builds schema.org JSON-LD for a sermon. PURE: a {@see SermonView} (+ optional context) in,
 * a plain associative array out (ready for wp_json_encode). A sermon is modelled as a
 * CreativeWork with the preacher as author, the series as isPartOf, and nested AudioObject /
 * VideoObject for the media so search engines can surface the audio/video.
 *
 * @phpstan-type Schema array<string,mixed>
 */
final class JsonLd {
    /**
     * @param array{siteName?:string,image?:string} $context
     * @return array<string,mixed>
     */
    public function forSermon( SermonView $view, array $context = array() ): array {
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'CreativeWork',
            'name'     => $view->title,
            'url'      => $view->permalink,
        );

        if ( $view->preachedTimestamp !== null ) {
            $data['datePublished'] = gmdate( 'c', $view->preachedTimestamp );
        }
        if ( $view->biblePassage !== '' ) {
            $data['description'] = $view->biblePassage;
        }
        if ( isset( $context['image'] ) && $context['image'] !== '' ) {
            $data['image'] = $context['image'];
        }
        if ( $view->preachers !== array() ) {
            $data['author'] = array_map(
                static fn( $p ) => array( '@type' => 'Person', 'name' => $p['name'] ),
                $view->preachers
            );
        }
        if ( $view->series !== array() ) {
            $data['isPartOf'] = array(
                '@type' => 'CreativeWorkSeries',
                'name'  => $view->series[0]['name'],
            );
        }
        if ( $view->topics !== array() ) {
            $data['keywords'] = implode( ', ', array_map( static fn( $t ) => $t['name'], $view->topics ) );
        }

        $publisher = isset( $context['siteName'] ) ? (string) $context['siteName'] : '';
        if ( $publisher !== '' ) {
            $data['publisher'] = array( '@type' => 'Organization', 'name' => $publisher );
        }

        if ( $view->audioUrl !== '' ) {
            $audio = array(
                '@type'      => 'AudioObject',
                'contentUrl' => $view->audioUrl,
                'name'       => $view->title,
            );
            if ( $view->audioDuration !== '' ) {
                $audio['duration'] = $this->isoDuration( $view->audioDuration );
            }
            $data['audio'] = $audio;
        }

        if ( $view->videoUrl !== '' || $view->videoEmbed !== '' ) {
            $video = array(
                '@type' => 'VideoObject',
                'name'  => $view->title,
            );
            if ( $view->videoUrl !== '' ) {
                $video['url'] = $view->videoUrl;
            }
            if ( $view->preachedTimestamp !== null ) {
                $video['uploadDate'] = gmdate( 'c', $view->preachedTimestamp );
            }
            $data['video'] = $video;
        }

        return $data;
    }

    /** Convert "hh:mm:ss" / "mm:ss" to an ISO-8601 duration (PT#H#M#S). */
    private function isoDuration( string $clock ): string {
        $parts = array_map( 'intval', explode( ':', $clock ) );
        if ( count( $parts ) === 3 ) {
            [ $h, $m, $s ] = $parts;
        } elseif ( count( $parts ) === 2 ) {
            [ $m, $s ] = $parts;
            $h         = 0;
        } else {
            return '';
        }
        $iso = 'PT';
        if ( $h > 0 ) {
            $iso .= $h . 'H';
        }
        if ( $m > 0 ) {
            $iso .= $m . 'M';
        }
        $iso .= $s . 'S';
        return $iso;
    }
}
