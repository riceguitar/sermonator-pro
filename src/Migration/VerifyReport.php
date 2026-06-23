<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Immutable result of a Verifier pass.
 *
 *  - $complete  : true ONLY when drift, missing, and openFlags are ALL empty — the
 *                 single gate the Verifier uses before advancing state to 'verified'
 *                 and the Finalizer re-checks before its destructive step.
 *  - $drift     : legacy ids whose recomputed LegacyChecksum::forPost no longer
 *                 matches the detect-time manifest checksum (source edited between
 *                 detect and migrate) — PLUS sentinel markers for drifted terms /
 *                 options (negative pseudo-ids; see Verifier::TERM_DRIFT_BASE).
 *  - $missing   : legacy ids in the manifest with NO clean migrated counterpart —
 *                 either no counterpart at all, or a counterpart carrying an open
 *                 failure flag. Proves the legacy→target direction so an offsetting
 *                 skip+duplicate cannot satisfy a bare count match.
 *  - $openFlags : the distinct open FAILURE flags found across migrated records
 *                 (advisory flags are excluded — they never block verification).
 *  - $counts    : per-key summary {sermons, podcasts, terms, options, ...} of the
 *                 verified-clean counterparts, for the CLI/status surface.
 */
final class VerifyReport {
    /**
     * @param list<int>             $drift
     * @param list<int>             $missing
     * @param list<string>          $openFlags
     * @param array<string,int>     $counts
     */
    public function __construct(
        public readonly bool $complete,
        public readonly array $drift,
        public readonly array $missing,
        public readonly array $openFlags,
        public readonly array $counts
    ) {}
}
