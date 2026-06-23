<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;

/**
 * The plugin boots the FrontendServiceProvider, so the three dynamic blocks are registered
 * after init in the integration environment.
 */
final class BootTest extends WP_UnitTestCase {
    public function test_blocks_registered_after_init(): void {
        $reg = \WP_Block_Type_Registry::get_instance();
        $this->assertNotNull( $reg->get_registered( 'sermonator/sermon-meta' ) );
        $this->assertNotNull( $reg->get_registered( 'sermonator/audio-player' ) );
        $this->assertNotNull( $reg->get_registered( 'sermonator/video' ) );
    }
}
