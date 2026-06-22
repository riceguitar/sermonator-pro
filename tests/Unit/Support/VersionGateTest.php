<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sermonator\Support\VersionGate;

final class VersionGateTest extends TestCase {
    public function test_satisfied_when_both_versions_meet_floor(): void {
        $gate = new VersionGate( '8.1.0', '6.0' );
        $this->assertTrue( $gate->isSatisfied() );
    }

    public function test_satisfied_on_newer_versions(): void {
        $gate = new VersionGate( '8.3.2', '6.5' );
        $this->assertTrue( $gate->isSatisfied() );
    }

    public function test_not_satisfied_when_php_too_old(): void {
        $gate = new VersionGate( '8.0.30', '6.5' );
        $this->assertFalse( $gate->isSatisfied() );
        $this->assertStringContainsString( 'PHP 8.1+', $gate->failureMessage() );
        $this->assertStringContainsString( '8.0.30', $gate->failureMessage() );
    }

    public function test_not_satisfied_when_wp_too_old(): void {
        $gate = new VersionGate( '8.1.0', '5.9' );
        $this->assertFalse( $gate->isSatisfied() );
        $this->assertStringContainsString( 'WordPress 6.0+', $gate->failureMessage() );
    }

    public function test_failure_message_reassures_about_data(): void {
        $gate = new VersionGate( '8.0', '5.9' );
        $this->assertStringContainsString( 'data is untouched', $gate->failureMessage() );
    }
}
