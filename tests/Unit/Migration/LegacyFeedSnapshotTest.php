<?php
namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Migration\LegacyFeedSnapshot;
use Sermonator\Schema\Identifiers as ID;

final class LegacyFeedSnapshotTest extends TestCase {
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

    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_stores_and_reads_guid_by_legacy_post_id(): void {
        ( new LegacyFeedSnapshot() )->store( array( 42 => 'http://old/feed/?p=42', 7 => 'wpfc-7' ) );

        $this->assertSame( 'http://old/feed/?p=42', ( new LegacyFeedSnapshot() )->guidFor( 42 ) );
        $this->assertSame( 'wpfc-7', ( new LegacyFeedSnapshot() )->guidFor( 7 ) );
        $this->assertNull( ( new LegacyFeedSnapshot() )->guidFor( 999 ) );
    }

    public function test_filters_invalid_entries_during_store(): void {
        ( new LegacyFeedSnapshot() )->store( array(
            -1 => 'negative-id-guid',
            0 => 'zero-id-guid',
            99 => '',
            42 => 'good-guid',
        ) );

        $this->assertSame( 'good-guid', ( new LegacyFeedSnapshot() )->guidFor( 42 ) );
        $this->assertNull( ( new LegacyFeedSnapshot() )->guidFor( -1 ) );
        $this->assertNull( ( new LegacyFeedSnapshot() )->guidFor( 0 ) );
        $this->assertNull( ( new LegacyFeedSnapshot() )->guidFor( 99 ) );
    }

    public function test_make_durable_stamps_legacy_guid_on_new_post(): void {
        ( new LegacyFeedSnapshot() )->store( array( 42 => 'wpfc-legacy-guid' ) );

        $stamped = array();
        Functions\when( 'update_post_meta' )->alias( function ( $postId, $key, $value ) use ( &$stamped ) {
            $stamped[] = array( $postId, $key, $value );
            return true;
        } );

        // new post id 5000 <- legacy id 42.
        ( new LegacyFeedSnapshot() )->makeDurable( 5000, 42 );

        $this->assertSame(
            array( array( 5000, ID::META_LEGACY_GUID, 'wpfc-legacy-guid' ) ),
            $stamped
        );
    }

    public function test_make_durable_is_a_noop_without_a_snapshot_entry(): void {
        ( new LegacyFeedSnapshot() )->store( array( 42 => 'wpfc-legacy-guid' ) );

        $stamped = array();
        Functions\when( 'update_post_meta' )->alias( function ( $postId, $key, $value ) use ( &$stamped ) {
            $stamped[] = array( $postId, $key, $value );
            return true;
        } );

        // Legacy id 7 has no snapshot entry (e.g. a podcast, or never captured).
        ( new LegacyFeedSnapshot() )->makeDurable( 5000, 7 );

        $this->assertSame( array(), $stamped );
    }
}
