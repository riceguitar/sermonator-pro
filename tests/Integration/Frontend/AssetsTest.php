<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Assets;
use Sermonator\Schema\Identifiers as ID;

final class AssetsTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        // Reset the style/script registries so enqueue state does not leak between tests.
        $GLOBALS['wp_styles']  = null;
        $GLOBALS['wp_scripts'] = null;
    }

    public function test_handles_registered(): void {
        ( new Assets() )->register();
        $this->assertTrue( wp_style_is( Assets::STYLE_HANDLE, 'registered' ) );
        $this->assertTrue( wp_script_is( Assets::SCRIPT_HANDLE, 'registered' ) );
    }

    public function test_enqueued_on_single_sermon(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        $this->go_to( get_permalink( $id ) );

        $assets = new Assets();
        $assets->register();
        $assets->maybeEnqueue();

        $this->assertTrue( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );
        $this->assertTrue( wp_script_is( Assets::SCRIPT_HANDLE, 'enqueued' ) );
    }

    public function test_not_enqueued_on_unrelated_page(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
        $this->go_to( get_permalink( $id ) );

        $assets = new Assets();
        $assets->register();
        $assets->maybeEnqueue();

        $this->assertFalse( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );
    }
}
