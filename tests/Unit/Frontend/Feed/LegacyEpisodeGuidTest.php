<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Feed\LegacyEpisodeGuid;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyFeedSnapshot;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the layered, durable episode-GUID resolver — the bridge between
 * the NEW post id the feed holds and the LEGACY post id the snapshot is keyed by.
 *
 * Uses a real LegacyFeedSnapshot (it is final, so it cannot be doubled) backed by an
 * in-memory option store, plus a per-test get_post_meta stub to model the durable meta
 * and the Crosswalk back-ref.
 */
final class LegacyEpisodeGuidTest extends TestCase {
    /** @var array<string,mixed> */
    private array $options = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        Functions\when( 'update_option' )->alias( function ( string $k, $v ) {
            $this->options[ $k ] = $v; return true;
        } );
        Functions\when( 'get_option' )->alias( function ( string $k, $d = false ) {
            return $this->options[ $k ] ?? $d;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_post_meta to return the given durable GUID and legacy back-ref for the
     * new post id, '' otherwise.
     */
    private function stubMeta( ?string $durable, ?string $legacyId ): void {
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) use ( $durable, $legacyId ) {
            if ( $key === ID::META_LEGACY_GUID ) {
                return $durable ?? '';
            }
            if ( $key === Crosswalk::LEGACY_POST_ID ) {
                return $legacyId ?? '';
            }
            return '';
        } );
    }

    /**
     * Layer 1: the durable META_LEGACY_GUID stamped on the new post wins outright —
     * the post-Finalize-safe source, resolved even with the back-ref stripped.
     */
    public function test_durable_meta_is_returned_first(): void {
        $this->stubMeta( 'wpfc-durable-guid', null );
        // Snapshot has a DIFFERENT value to prove layer 1 short-circuits before it.
        ( new LegacyFeedSnapshot() )->store( array( 42 => 'snapshot-guid' ) );

        $this->assertSame( 'wpfc-durable-guid', ( new LegacyEpisodeGuid() )->resolve( 5000 ) );
    }

    /**
     * Layer 2 (pre-Finalize): no durable meta yet, so translate new id -> legacy id
     * via the Crosswalk back-ref and replay the legacy-keyed snapshot GUID. This is
     * exactly the id-space translation production depends on before Finalize.
     */
    public function test_pre_finalize_translates_new_to_legacy_then_replays_snapshot(): void {
        $this->stubMeta( null, '42' ); // new id 5000 -> legacy id 42, no durable meta
        ( new LegacyFeedSnapshot() )->store( array( 42 => 'wpfc-legacy-guid-42' ) );

        $this->assertSame( 'wpfc-legacy-guid-42', ( new LegacyEpisodeGuid() )->resolve( 5000 ) );
    }

    /**
     * Layer 3: a brand-new (never-migrated) episode — no durable meta, no back-ref —
     * keeps its own 'sermonator-<newId>' GUID.
     */
    public function test_unmigrated_episode_uses_sermonator_guid(): void {
        $this->stubMeta( null, null );

        $this->assertSame( 'sermonator-5000', ( new LegacyEpisodeGuid() )->resolve( 5000 ) );
    }

    /**
     * A migrated episode whose legacy id has no snapshot entry falls through to the
     * 'sermonator-<newId>' default rather than emitting an empty/garbage GUID.
     */
    public function test_back_ref_without_snapshot_entry_falls_back_to_default(): void {
        $this->stubMeta( null, '42' );
        // No snapshot stored for legacy id 42.

        $this->assertSame( 'sermonator-5000', ( new LegacyEpisodeGuid() )->resolve( 5000 ) );
    }
}
