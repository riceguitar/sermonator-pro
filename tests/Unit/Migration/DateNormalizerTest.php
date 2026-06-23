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
    public function test_digit_bearing_relative_expressions_rejected(): void {
        // IMPORTANT #6: PHP's DateTimeImmutable accepts digit-bearing RELATIVE
        // expressions ('+1 day', '2 weeks ago') and resolves them against runtime
        // 'now' — producing a plausible-but-wrong companion (e.g. the migration
        // date) presented downstream as authoritative. These are NOT concrete legacy
        // dates and must be rejected (null), not silently anchored to runtime now.
        $this->assertNull(DateNormalizer::normalize('+1 day', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('2 weeks ago', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('+2 weeks', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('1 month ago', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('next year', new \DateTimeZone('UTC')));
    }
    public function test_overflow_date_rejected(): void {
        // 2021-02-30 has no Feb 30 — date_parse flags it (error/warning) and PHP
        // would otherwise roll it over to March. Reject rather than mis-anchor.
        $this->assertNull(DateNormalizer::normalize('2021-02-30', new \DateTimeZone('UTC')));
    }
    public function test_weekday_bearing_concrete_dates_parse(): void {
        // MUST-FIX #5: date_parse classifies the weekday name as a "relative"
        // component, but the y/m/d are all explicitly present — these are concrete
        // calendar dates pervasive in RSS/podcast exports and must normalize to an
        // int, not be rejected by the blanket relative guard.
        $this->assertSame(
            gmmktime(0, 0, 0, 5, 1, 2021),
            DateNormalizer::normalize('Sun, 01 May 2021', new \DateTimeZone('UTC'))
        );
        $this->assertSame(
            gmmktime(0, 0, 0, 5, 1, 2021),
            DateNormalizer::normalize('Sunday May 1 2021', new \DateTimeZone('UTC'))
        );
    }
    public function test_pure_relative_still_rejected_after_weekday_fix(): void {
        // The weekday fix must NOT reopen the digit-bearing relative hole.
        $this->assertNull(DateNormalizer::normalize('+1 day', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('2 weeks ago', new \DateTimeZone('UTC')));
    }
    public function test_real_concrete_dates_still_parse(): void {
        // The reject must NOT regress real legacy dates.
        $this->assertIsInt(DateNormalizer::normalize('2021-01-01', new \DateTimeZone('UTC')));
        $this->assertIsInt(DateNormalizer::normalize('01/05/2021', new \DateTimeZone('UTC')));
        $this->assertIsInt(DateNormalizer::normalize('2021-05-01 09:30:00', new \DateTimeZone('UTC')));
    }
}
