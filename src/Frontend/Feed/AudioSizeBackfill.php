<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Schema\Identifiers as ID;

/**
 * Populates the persisted `_sermonator_audio_size` meta so the podcast feed can emit a
 * correct enclosure length without a render-time network call. This is the ONLY component
 * in the front-end layer that writes to the database, so it observes strict
 * data-preservation guardrails (see the rollback story in the design spec):
 *
 *  - NATIVE-ONLY: writes `_sermonator_audio_size` on `sermonator_sermon` posts. It never
 *    touches the legacy `_wpfc_sermon_size` key or any legacy row, so byte-immutability of
 *    legacy data until the migration's Finalize is unaffected.
 *  - FILL-MISSING-ONLY: candidates are sermons whose size is missing/zero; an existing size
 *    is never overwritten.
 *  - EXACTLY REVERSIBLE: every touched post id is recorded in {@see self::LOG_OPTION}; a
 *    rollback deletes exactly those rows, restoring the pre-backfill (missing) state.
 *  - IDEMPOTENT + CHUNKABLE: re-running re-queries only still-missing sizes, so a repeated
 *    `--limit=N` drains the backlog safely; crashes leave a consistent partial state.
 */
final class AudioSizeBackfill {
    public const LOG_OPTION = 'sermonator_enclosure_backfill_log';

    /** Reject an absurd Content-Length (> 4 GB) — a sermon audio file is never this large,
     *  and a bogus huge size would advertise a broken enclosure AND be unrepairable (the
     *  candidate query would never re-select a "real" positive size). */
    private const MAX_SIZE_BYTES = 4_294_967_296;

    /** @var callable(string):?int */
    private $sizeFetcher;

    /** @param callable(string):?int|null $sizeFetcher Resolve a URL to a byte size (null = unresolved). */
    public function __construct( ?callable $sizeFetcher = null ) {
        $this->sizeFetcher = $sizeFetcher ?? array( $this, 'fetchViaHead' );
    }

    /**
     * @return array{candidates:int,written:int,failed:int,ids:list<int>,dryRun:bool}
     */
    public function run( int $limit = 0, bool $dryRun = false ): array {
        $ids        = $this->candidates( $limit );
        $written    = 0;
        $failed     = 0;
        $writtenIds = array();

        foreach ( $ids as $id ) {
            $url = (string) get_post_meta( $id, ID::META_AUDIO, true );
            if ( $url === '' ) {
                continue;
            }
            // Race guard: re-read the size immediately before fetching so a value written
            // since the query (concurrent run / migration) is never overwritten.
            if ( (int) get_post_meta( $id, ID::META_AUDIO_SIZE, true ) > 0 ) {
                continue;
            }
            $size = ( $this->sizeFetcher )( $url );
            if ( ! is_int( $size ) || $size <= 0 || $size > self::MAX_SIZE_BYTES ) {
                ++$failed;
                continue;
            }
            if ( ! $dryRun ) {
                update_post_meta( $id, ID::META_AUDIO_SIZE, (string) $size );
                $this->logId( $id );
            }
            ++$written;
            $writtenIds[] = $id;
        }

        return array(
            'candidates' => count( $ids ),
            'written'    => $written,
            'failed'     => $failed,
            'ids'        => $writtenIds,
            'dryRun'     => $dryRun,
        );
    }

    /**
     * Reverse the backfill: delete the `_sermonator_audio_size` rows this command created
     * (tracked in the log) and clear the log. Restores the exact pre-backfill state.
     *
     * @return array{removed:int,ids:list<int>}
     */
    public function rollback(): array {
        $log     = $this->log();
        $removed = 0;
        foreach ( $log as $id ) {
            if ( get_post_type( $id ) === ID::POST_TYPE_SERMON ) {
                delete_post_meta( $id, ID::META_AUDIO_SIZE );
                ++$removed;
            }
        }
        delete_option( self::LOG_OPTION );
        return array( 'removed' => $removed, 'ids' => $log );
    }

    /**
     * Published-or-editable sermons that have an audio URL but no usable size yet.
     *
     * @return list<int>
     */
    private function candidates( int $limit ): array {
        $query = new \WP_Query( array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
            'posts_per_page'         => $limit > 0 ? $limit : -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array( 'key' => ID::META_AUDIO, 'compare' => 'EXISTS' ),
                array(
                    'relation' => 'OR',
                    array( 'key' => ID::META_AUDIO_SIZE, 'compare' => 'NOT EXISTS' ),
                    array( 'key' => ID::META_AUDIO_SIZE, 'value' => '0', 'compare' => '=' ),
                    array( 'key' => ID::META_AUDIO_SIZE, 'value' => '', 'compare' => '=' ),
                ),
            ),
        ) );
        return array_map( 'intval', $query->posts );
    }

    /** @return list<int> */
    private function log(): array {
        $log = get_option( self::LOG_OPTION, array() );
        return is_array( $log ) ? array_values( array_map( 'intval', $log ) ) : array();
    }

    private function logId( int $id ): void {
        $log = $this->log();
        if ( ! in_array( $id, $log, true ) ) {
            $log[] = $id;
            update_option( self::LOG_OPTION, $log, false );
        }
    }

    private function fetchViaHead( string $url ): ?int {
        // Only fetch http(s); reject_unsafe_urls engages core's internal-host/redirect guard
        // (SSRF defence — the audio URL is admin-entered but not fully trusted on a migrated
        // multi-author site).
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            return null;
        }
        $response = wp_remote_head( $url, array(
            'timeout'            => 10,
            'redirection'        => 3,
            'reject_unsafe_urls' => true,
        ) );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        $length = wp_remote_retrieve_header( $response, 'content-length' );
        if ( is_array( $length ) ) {
            $length = end( $length );
        }
        $length = (int) $length;
        return ( $length > 0 && $length <= self::MAX_SIZE_BYTES ) ? $length : null;
    }
}
