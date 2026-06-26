<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Compat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Compat\LegacyShortcodes;

final class LegacyShortcodesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( '__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_notice_renders_only_for_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertStringContainsString( 'sermonator-compat-notice', LegacyShortcodes::needsReviewNotice() );
    }

    public function test_notice_is_empty_for_visitors(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertSame( '', LegacyShortcodes::needsReviewNotice() );
    }
}
