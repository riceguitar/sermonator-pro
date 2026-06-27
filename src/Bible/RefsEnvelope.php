<?php

declare(strict_types=1);

namespace Sermonator\Bible;

/**
 * The ONE shared reader of the stored {@see \Sermonator\Schema\Identifiers::META_BIBLE_REFS}
 * envelope `{"v":1,"refs":[Ref,…]}`, used by BOTH the live render path
 * ({@see \Sermonator\Frontend\BibleResolver::readEnvelopeRefs()}) and the corpus audit
 * ({@see CoverageAudit::refsForPost()} / {@see CoverageAudit::corpusRefs()}).
 *
 * ## Why this exists (lockstep refCount)
 *
 * The STRICT `derived-exact` floor's singleton constraint compares the per-post envelope
 * refCount to 1 ({@see DerivedExactClassifier::promotes()}). The audit is the operator's
 * enable soft-gate / live preview, so its refCount MUST be derived from the IDENTICAL
 * population the resolver counts — otherwise a malformed/smuggled envelope (a non-array
 * junk sibling next to a lone clean `probable`) makes the audit count 1 while the render
 * counts 2, promoting in the audit but withholding at render: a false-green on exactly the
 * corpus-independent dark guarantee the refCount thread exists to protect.
 *
 * This reader returns the ref list **UNFILTERED** — `array_values()` only, non-array junk
 * entries PRESERVED — so `count()` over it is the singleton-constraint refCount both engines
 * use. Each consumer guards non-array entries with `if ( ! is_array( $ref ) ) { continue; }`
 * while ITERATING (exactly as the resolver's `resolve()` loop does AFTER taking the count),
 * never by dropping them before the count.
 *
 * Pure: it reads no options/DB/network and never throws. It is given the already-read raw
 * meta value so the two callers count provably-identical bytes.
 */
final class RefsEnvelope {
    /**
     * Decode a stored META_BIBLE_REFS meta value into its UNFILTERED ref list, or null when
     * the value is absent, empty, or not a well-formed `{refs:[…]}` with a non-empty list.
     *
     * Mirrors the resolver's historical `readEnvelopeRefs()` byte-for-byte: non-array
     * elements are KEPT (so the count matches the render); the caller skips them while
     * iterating.
     *
     * @param mixed $stored Raw value from `get_post_meta( $id, META_BIBLE_REFS, true )`.
     *
     * @return list<mixed>|null
     */
    public static function decode( $stored ): ?array {
        if ( ! is_string( $stored ) || '' === $stored ) {
            return null;
        }

        $decoded = json_decode( $stored, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $refs = $decoded['refs'] ?? null;
        if ( ! is_array( $refs ) || array() === $refs ) {
            return null;
        }

        return array_values( $refs );
    }
}
