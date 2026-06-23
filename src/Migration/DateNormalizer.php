<?php
declare(strict_types=1);
namespace Sermonator\Migration;
final class DateNormalizer {
    public static function normalize(string $raw, ?\DateTimeZone $tz = null): ?int {
        $raw = trim($raw);
        if ($raw === '') { return null; }
        $tz = $tz ?? new \DateTimeZone('UTC');

        // IMPORTANT #6: DateTimeImmutable accepts digit-bearing RELATIVE expressions
        // ('+1 day', '2 weeks ago', '+2 weeks', '1 month ago') and resolves them
        // against runtime 'now' — turning a non-date into a plausible-but-wrong
        // companion (e.g. the migration date) presented downstream as authoritative.
        // The no-digit guard never caught these. Use date_parse() to REQUIRE a
        // concrete calendar date (year+month+day all present, no parse errors, and
        // NO relative component) before we trust DateTimeImmutable. This rejects
        // relative expressions and overflow dates (2021-02-30) while still parsing
        // every real legacy shape (Y-m-d, m/d/Y, full datetimes).
        $parsed = date_parse($raw);
        if (!is_array($parsed)) { return null; }
        if (($parsed['error_count'] ?? 0) > 0 || ($parsed['warning_count'] ?? 0) > 0) {
            return null; // overflow (Feb 30), malformed, ambiguous — reject
        }
        // A concrete date requires all three calendar fields. A relative-only
        // expression ('+1 day') leaves year/month/day as false.
        if (false === $parsed['year'] || false === $parsed['month'] || false === $parsed['day']) {
            return null;
        }
        // Any relative OFFSET ('+1 day', 'ago', 'next year') that synthesised a
        // y/m/d via 'now' taints the result — reject it. But date_parse also files
        // a bare weekday NAME ('Sun, 01 May 2021', 'Sunday May 1 2021') under
        // 'relative' (as 'weekday'), and those carry an explicit y+m+d already
        // established concrete above. Only the numeric offset fields are fatal; a
        // weekday alongside a concrete y/m/d is fine (pervasive in RSS/podcast).
        // NOTE: pure-relative shapes ('+1 day') never reach here — they leave
        // y/m/d false and are rejected by the all-present guard above.
        $rel = $parsed['relative'] ?? array();
        if (is_array($rel)) {
            foreach (array('year', 'month', 'day', 'hour', 'minute', 'second') as $field) {
                if (!empty($rel[$field])) {
                    return null;
                }
            }
        }

        // Build from the EXPLICIT parsed calendar fields rather than re-parsing the
        // raw string. date_parse already established a concrete, non-overflow y/m/d
        // above; constructing from those components anchors to $tz AND neutralises a
        // misleading weekday directive. PHP's DateTimeImmutable would otherwise treat
        // a weekday name ('Sun, 01 May 2021', even when 01 May 2021 is a Saturday) as
        // a "roll forward to next Sunday" instruction, drifting the companion a day
        // off the authoritative explicit date.
        $hour   = false === $parsed['hour'] ? 0 : (int) $parsed['hour'];
        $minute = false === $parsed['minute'] ? 0 : (int) $parsed['minute'];
        $second = false === $parsed['second'] ? 0 : (int) $parsed['second'];
        try {
            $dt = (new \DateTimeImmutable('now', $tz))->setDate(
                (int) $parsed['year'],
                (int) $parsed['month'],
                (int) $parsed['day']
            )->setTime($hour, $minute, $second);
        } catch (\Exception $e) {
            return null;
        }
        return $dt->getTimestamp();
    }
    private function __construct() {}
}
