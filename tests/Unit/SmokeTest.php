<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
    public function test_autoloader_resolves_plugin_class(): void {
        $this->assertTrue( class_exists( \Sermonator\Plugin::class ) );
    }
}
