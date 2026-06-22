<?php
declare(strict_types=1);
namespace Sermonator\Migration;
final class DateNormalizer {
    public static function normalize(string $raw, ?\DateTimeZone $tz = null): ?int {
        $raw = trim($raw);
        if ($raw === '') { return null; }
        $tz = $tz ?? new \DateTimeZone('UTC');
        try {
            $dt = new \DateTimeImmutable($raw, $tz);   // anchors date-only strings to $tz
        } catch (\Exception $e) {
            return null;
        }
        // Reject values DateTimeImmutable accepts as relative junk: require it changed from "now" anchor predictably is hard;
        // instead reject if the input had no digit (covers 'not a date').
        if (!preg_match('/\d/', $raw)) { return null; }
        return $dt->getTimestamp();
    }
    private function __construct() {}
}
