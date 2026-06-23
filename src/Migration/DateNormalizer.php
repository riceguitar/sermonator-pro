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
        // Any relative component ('+1 day', 'ago', 'next year' that still produced
        // a y/m/d via 'now') taints the result — reject if date_parse recorded one.
        if (!empty($parsed['relative'])) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($raw, $tz);   // anchors date-only strings to $tz
        } catch (\Exception $e) {
            return null;
        }
        return $dt->getTimestamp();
    }
    private function __construct() {}
}
