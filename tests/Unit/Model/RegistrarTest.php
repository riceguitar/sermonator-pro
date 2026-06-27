<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Model;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Model\Registrar;
use Sermonator\Schema\Identifiers;

/**
 * @covers \Sermonator\Model\Registrar
 */
final class RegistrarTest extends TestCase {
    /** @var array<string,array{name:string,singular_name:string}> */
    private array $taxonomyLabels = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->taxonomyLabels = array();

        Functions\when( '__' )->returnArg();
        Functions\when( 'register_post_type' )->justReturn( null );

        // Capture every register_taxonomy() label array, keyed by taxonomy name.
        Functions\when( 'register_taxonomy' )->alias(
            function ( string $taxonomy, $objectType, array $args ): void {
                $this->taxonomyLabels[ $taxonomy ] = $args['labels'];
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_option so the live preacher-label option returns $liveLabel; every
     * other read (the DisplayDefaults seed containers, the archive slug) falls
     * through to the caller's explicit default.
     */
    private function stubOption( ?string $liveLabel ): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) use ( $liveLabel ) {
                if ( $name === Identifiers::OPTION_PREACHER_LABEL && $liveLabel !== null ) {
                    return $liveLabel;
                }
                return $default;
            }
        );
    }

    public function test_preacher_taxonomy_label_uses_live_option_value(): void {
        $this->stubOption( 'Speaker' );

        ( new Registrar() )->register();

        $labels = $this->taxonomyLabels[ Identifiers::TAX_PREACHER ];
        $this->assertSame( 'Speaker', $labels['singular_name'] );
        $this->assertSame( 'Speakers', $labels['name'] );
    }

    public function test_preacher_taxonomy_label_falls_back_to_hard_default(): void {
        // No live option → DisplayDefaults::preacherLabel() hard fallback 'Preacher'.
        $this->stubOption( null );

        ( new Registrar() )->register();

        $labels = $this->taxonomyLabels[ Identifiers::TAX_PREACHER ];
        $this->assertSame( 'Preacher', $labels['singular_name'] );
        $this->assertSame( 'Preachers', $labels['name'] );
    }
}
