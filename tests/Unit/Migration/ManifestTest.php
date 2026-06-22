<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\Manifest;

final class ManifestTest extends TestCase {
    public function test_counts_and_default_zero(): void {
        $m = new Manifest( array( 'sermons' => 1240, 'podcasts' => 6 ) );
        $this->assertSame( 1240, $m->count( 'sermons' ) );
        $this->assertSame( 6, $m->count( 'podcasts' ) );
        $this->assertSame( 0, $m->count( 'missing' ) );
    }

    public function test_checksums(): void {
        $m = new Manifest( array( 'sermons' => 2 ), array( 10 => 'abc', 11 => 'def' ) );
        $this->assertSame( 'abc', $m->checksum( 10 ) );
        $this->assertNull( $m->checksum( 99 ) );
    }

    public function test_round_trips_through_array(): void {
        $m = new Manifest( array( 'sermons' => 3 ), array( 1 => 'h1' ) );
        $restored = Manifest::fromArray( $m->toArray() );
        $this->assertSame( 3, $restored->count( 'sermons' ) );
        $this->assertSame( 'h1', $restored->checksum( 1 ) );
    }
}
