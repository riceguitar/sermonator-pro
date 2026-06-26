<?php
namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Migration\LegacyFeedSnapshot;

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
}
