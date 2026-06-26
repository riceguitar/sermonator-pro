<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin\Authoring;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Admin\Authoring\MigrationGuard;
use Sermonator\Schema\Identifiers;

final class MigrationGuardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
            if ( $option === Identifiers::OPTION_MIGRATION_STATE ) {
                return $GLOBALS['__sermonator_test_phase'] ?? array();
            }
            return $default;
        } );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['__sermonator_test_phase'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @dataProvider phaseProvider
     * @param array<string,mixed>|string $phase
     */
    public function test_editing_allowed_only_in_none_or_finalized( $phase, bool $expected ): void {
        $GLOBALS['__sermonator_test_phase'] = is_array( $phase ) ? $phase : array( 'phase' => $phase );
        $this->assertSame( $expected, MigrationGuard::editingAllowed() );
    }

    /**
     * @return list<array{0:array<string,mixed>|string,1:bool}>
     */
    public function phaseProvider(): array {
        return array(
            array( 'none', true ),
            array( 'detected', false ),
            array( 'migrating', false ),
            array( 'migrated', false ),
            array( 'verified', false ),
            array( 'finalized', true ),
            array( array(), true ), // empty option normalizes to 'none'
            array( array( 'phase' => 'none' ), true ),
        );
    }
}
