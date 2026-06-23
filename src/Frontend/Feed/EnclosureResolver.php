<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Schema\Identifiers as ID;

/**
 * Resolves a sermon's audio enclosure (url, byte size, MIME, duration) by reading PERSISTED
 * meta only — never a network call at render time. The byte size comes from
 * `_sermonator_audio_size` (populated by the AudioBackfillCommand). If there is no audio or
 * the size is unknown, returns null so the feed omits the item (Apple rejects enclosures
 * without a correct length); the caller counts the skip.
 */
final class EnclosureResolver {
    /**
     * @return array{url:string,size:int,type:string,duration:string}|null
     */
    public function resolve( int $sermonId ): ?array {
        $url = (string) get_post_meta( $sermonId, ID::META_AUDIO, true );
        if ( $url === '' ) {
            return null;
        }
        $size = (int) get_post_meta( $sermonId, ID::META_AUDIO_SIZE, true );
        if ( $size <= 0 ) {
            return null; // Size unknown — run the backfill; omit until then.
        }
        return array(
            'url'      => $url,
            'size'     => $size,
            'type'     => $this->mimeFor( $url ),
            'duration' => (string) get_post_meta( $sermonId, ID::META_AUDIO_DURATION, true ),
        );
    }

    private function mimeFor( string $url ): string {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return match ( $ext ) {
            'm4a'        => 'audio/x-m4a',
            'mp4', 'm4b' => 'audio/mp4',
            'ogg', 'oga' => 'audio/ogg',
            'wav'        => 'audio/wav',
            'aac'        => 'audio/aac',
            'flac'       => 'audio/flac',
            default      => 'audio/mpeg',
        };
    }
}
