<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Migration;
use PHPUnit\Framework\TestCase;
use Sermonator\Migration\DateNormalizer;
final class DateNormalizerTest extends TestCase {
    public function test_parses_iso_date_in_given_tz(): void {
        $utc = DateNormalizer::normalize('2021-01-01', new \DateTimeZone('UTC'));
        $this->assertSame(gmmktime(0,0,0,1,1,2021), $utc);
    }
    public function test_timezone_anchoring_changes_result(): void {
        $utc = DateNormalizer::normalize('2021-01-01', new \DateTimeZone('UTC'));
        $est = DateNormalizer::normalize('2021-01-01', new \DateTimeZone('America/New_York'));
        $this->assertNotSame($utc, $est);            // proves TZ is honored, no server-TZ leak
        $this->assertSame(5 * 3600, $est - $utc);    // EST is UTC-5 for a date-only midnight
    }
    public function test_parses_slash_date(): void {
        $this->assertIsInt(DateNormalizer::normalize('01/05/2021', new \DateTimeZone('UTC')));
    }
    public function test_garbage_returns_null(): void {
        $this->assertNull(DateNormalizer::normalize('not a date', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('', new \DateTimeZone('UTC')));
    }
}
