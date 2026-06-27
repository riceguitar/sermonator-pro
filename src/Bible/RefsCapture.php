<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Schema\BibleBookMap;
use Sermonator\Schema\Identifiers as ID;

/**
 * The SINGLE structured-reference producer (design §3, "one schema, multiple
 * producers"): the one place that turns a preserved {@see ID::META_BIBLE_PASSAGE}
 * free-text label into the versioned {@see ID::META_BIBLE_REFS} envelope, the
 * {@see ID::META_BIBLE_REFS_UNPARSEABLE} sentinel, and the dual-written
 * {@see ID::TAX_BOOK} terms.
 *
 * Both producers delegate here so their output is byte-identical for the same
 * passage (modulo the per-ref `source` tag):
 *   - {@see \Sermonator\Migration\BibleRefsBackfill} (source: `backfill`) — the
 *     migration-gated bulk pass over legacy sermons.
 *   - {@see \Sermonator\Admin\Authoring\SermonRefsCapture} (source: `authoring`) —
 *     the save-time auto-capture so newly authored sermons gain structured refs.
 *
 * Data-preservation invariants this class enforces (the callers add gating on top):
 *  - NEVER mutates {@see ID::META_BIBLE_PASSAGE} — it is read-only input, the
 *    preserved human display label and the fail-open output (#1 data preservation).
 *  - FILL-MISSING-ONLY: if a {@see ID::META_BIBLE_REFS} envelope already exists it is
 *    NEVER overwritten — in particular a `source:'authoring'` envelope is sacrosanct.
 *    A previously stamped sentinel likewise short-circuits (idempotent).
 *  - NEVER fail-*wrong*: only in-canon, structurally-valid refs are emitted; anything
 *    the validator rejects is dropped. A non-empty passage that parses to zero refs
 *    stamps the measurable sentinel rather than silently doing nothing.
 *
 * Gating (MigrationGuard phase + capability) is the CALLER's responsibility — this
 * producer is deliberately ungated so it can be reused by both the migration shell
 * and the save hook, each of which wraps it with the gate appropriate to its surface.
 */
final class RefsCapture {
    /** Schema version stamped on every written envelope. */
    public const ENVELOPE_VERSION = 1;

    /** Sentinel value stamped when a non-empty passage parses to zero refs. */
    public const UNPARSEABLE_SENTINEL = '1';

    /**
     * The ONLY confidence tiers a producer may ever PERSIST (design §3.4, "de-store the
     * tier"). The stored-confidence vocabulary is DISJOINT from the render-time floor
     * vocabulary `{derived-exact, derived-exact-perseg}` owned by
     * {@see DerivedExactClassifier}:
     *  - `exact`     — the author confirmed the structured chips (the confirm-chip REST
     *                  path is the ONLY producer of this tier).
     *  - `probable`  — a heuristic free-text parse the validator fully cleared.
     *  - `ambiguous` — a ref the validator could not fully clear.
     *
     * "derived-exact" is a RENDER-TIME promotion computed by {@see DerivedExactClassifier},
     * NEVER persisted. A pre-stamped `derived-exact*` confidence would smuggle past the
     * classifier and clear the inline floor without the re-parse-identity check — so no
     * producer may ever emit it. {@see self::normalizeStoredConfidence()} is the single
     * enforcement point both producers route through.
     */
    public const STORED_CONFIDENCE_EXACT     = 'exact';
    public const STORED_CONFIDENCE_PROBABLE  = 'probable';
    public const STORED_CONFIDENCE_AMBIGUOUS = 'ambiguous';

    /**
     * The closed set of persistable stored-confidence tiers (an allow-list; anything
     * outside it — a floor-only `derived-exact*` tier, a smuggled client value, garbage —
     * is rejected by {@see self::normalizeStoredConfidence()}).
     *
     * @var list<string>
     */
    private const STORED_CONFIDENCE_TIERS = array(
        self::STORED_CONFIDENCE_EXACT,
        self::STORED_CONFIDENCE_PROBABLE,
        self::STORED_CONFIDENCE_AMBIGUOUS,
    );

    /**
     * Optional per-ref provenance field (design §3.2) describing HOW the ref's
     * `srcVersification` was established:
     *  - `authored`     — stamped contemporaneously at an authoring save with the
     *                     live link version (the confirm-chip path); gates directly.
     *  - `site-default` — the conservative default: a backfill/auto-parse ref whose
     *                     versification is the site-wide stamp, OR ANY v1 envelope ref
     *                     that predates this field. `site-default` additionally needs
     *                     the admin attestation (L6) before it can render inline.
     *
     * BACKWARD-COMPATIBLE: the field is OPTIONAL. A v1 envelope ref lacking it MUST
     * read as `site-default` (the conservative default) everywhere consumed — read
     * the value ONLY through {@see self::srcVersificationConfidence()}, never a bare
     * array access. We do NOT bump {@see self::ENVELOPE_VERSION} or rewrite existing
     * envelopes to add it.
     */
    public const SRC_VERSIFICATION_CONFIDENCE_AUTHORED     = 'authored';
    public const SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT = 'site-default';

    /**
     * Read a ref's `srcVersificationConfidence` with back-compat: an absent or
     * unrecognized value reads as the conservative `site-default`. This is the SINGLE
     * accessor every consumer (VersificationGate, resolver, audit) must use so the
     * default is enforced in exactly one place.
     *
     * @param array<string,mixed> $ref
     */
    public static function srcVersificationConfidence( array $ref ): string {
        $value = $ref['srcVersificationConfidence'] ?? null;

        return self::SRC_VERSIFICATION_CONFIDENCE_AUTHORED === $value
            ? self::SRC_VERSIFICATION_CONFIDENCE_AUTHORED
            : self::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT;
    }

    /**
     * De-store enforcement (design §3.4): coerce any candidate confidence to a PERSISTABLE
     * stored tier, ACTIVELY rejecting a de-stored floor-only tier (`derived-exact` /
     * `derived-exact-perseg`). A recognized stored tier
     * ({@see self::STORED_CONFIDENCE_TIERS}) passes through verbatim; ANYTHING else — a
     * floor-only tier, a smuggled client value, a non-string, garbage — normalizes to the
     * conservative {@see self::STORED_CONFIDENCE_PROBABLE}.
     *
     * This is the SINGLE place the producers guarantee that no `derived-exact*` value is
     * ever written, closing the bypass where a pre-stamped tier would clear the inline floor
     * without running the {@see DerivedExactClassifier}. The classifier stays the ONLY
     * promotion path.
     *
     * @param mixed $confidence A computed or client-supplied confidence value.
     */
    public static function normalizeStoredConfidence( $confidence ): string {
        return in_array( $confidence, self::STORED_CONFIDENCE_TIERS, true )
            ? (string) $confidence
            : self::STORED_CONFIDENCE_PROBABLE;
    }

    /**
     * Produce structured refs for one post and persist them, fill-missing only.
     *
     * @param int    $postId Target sermon post id.
     * @param string $source Provenance tag stamped on every emitted ref
     *                       (`authoring` | `backfill` | `import`).
     *
     * @return array{wrote:bool,refs:bool,sentinel:bool,terms:list<int>} What was
     *         written, so callers can log it for exact reversibility: `wrote` is
     *         false for a no-op (empty passage / pre-existing envelope or sentinel);
     *         exactly one of `refs`/`sentinel` is true otherwise; `terms` are the
     *         TAX_BOOK term ids NEWLY added by this call.
     */
    public function captureForPost( int $postId, string $source ): array {
        $plan = $this->plan( $postId, $source );

        return $this->apply( $postId, $plan );
    }

    /**
     * Resolve — WITHOUT writing — what {@see self::captureForPost()} would do for a
     * post. Exposed so the backfill's dry-run report can count outcomes without
     * touching the database (the producer stays the single source of truth for the
     * skip checks and the parse).
     *
     * @return array{outcome:'noop'|'refs'|'sentinel',refs:list<array<string,mixed>>}
     */
    public function plan( int $postId, string $source ): array {
        $noop = array( 'outcome' => 'noop', 'refs' => array() );

        $passage = (string) get_post_meta( $postId, ID::META_BIBLE_PASSAGE, true );
        if ( '' === trim( $passage ) ) {
            return $noop;
        }

        // FILL-MISSING: an existing envelope (esp. source:'authoring') is sacrosanct.
        if ( '' !== (string) get_post_meta( $postId, ID::META_BIBLE_REFS, true ) ) {
            return $noop;
        }

        // Already stamped unparseable on a prior pass: leave it (idempotent).
        if ( '' !== (string) get_post_meta( $postId, ID::META_BIBLE_REFS_UNPARSEABLE, true ) ) {
            return $noop;
        }

        // Resolve the church's source versification once (the same legacy
        // verse_bible_version the backfill uses); it tags every ref so the 3b
        // inline-eligibility gate can later detect ESV-vs-WEB divergence.
        $srcVersification = TranslationRegistry::current()->linkVersion();
        $refs             = $this->buildRefs( $passage, $source, $srcVersification );

        if ( array() === $refs ) {
            return array( 'outcome' => 'sentinel', 'refs' => array() );
        }

        return array( 'outcome' => 'refs', 'refs' => $refs );
    }

    /**
     * Persist a resolved plan and report exactly what was written.
     *
     * @param array{outcome:'noop'|'refs'|'sentinel',refs:list<array<string,mixed>>} $plan
     *
     * @return array{wrote:bool,refs:bool,sentinel:bool,terms:list<int>}
     */
    private function apply( int $postId, array $plan ): array {
        if ( 'sentinel' === $plan['outcome'] ) {
            update_post_meta( $postId, ID::META_BIBLE_REFS_UNPARSEABLE, self::UNPARSEABLE_SENTINEL );

            return array( 'wrote' => true, 'refs' => false, 'sentinel' => true, 'terms' => array() );
        }

        if ( 'refs' === $plan['outcome'] ) {
            update_post_meta( $postId, ID::META_BIBLE_REFS, $this->envelope( $plan['refs'] ) );
            $addedTermIds = $this->assignBookTerms( $postId, $plan['refs'] );

            return array( 'wrote' => true, 'refs' => true, 'sentinel' => false, 'terms' => $addedTermIds );
        }

        return array( 'wrote' => false, 'refs' => false, 'sentinel' => false, 'terms' => array() );
    }

    /**
     * Parse a raw passage into a list of enriched Ref rows (source/confidence/
     * srcVersification stamped). In-canon, structurally-valid refs only — anything
     * the validator rejects is dropped (never fail-*wrong*).
     *
     * @return list<array<string,mixed>>
     */
    private function buildRefs( string $passage, string $source, string $srcVersification ): array {
        $parsed = ReferenceParser::parse( $passage );
        $refs   = array();

        foreach ( $parsed['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                if ( ! is_array( $ref ) ) {
                    continue;
                }

                $flags = RefValidator::validate( $ref );
                if ( ! $flags['inCanon'] || ! $flags['structurallyValid'] ) {
                    continue;
                }

                $ref['source']           = $source;
                $ref['confidence']       = $this->confidence( $flags );
                $ref['srcVersification'] = $srcVersification;
                // This producer (backfill / save-time auto-parse) never authors the
                // versification contemporaneously — its stamp is the site-wide
                // default. Only the confirm-chip REST path (T12) promotes a ref to
                // `authored`. Conservative by construction (design §3.2).
                $ref['srcVersificationConfidence'] = self::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT;
                $refs[]                  = $ref;
            }
        }

        return $refs;
    }

    /**
     * Confidence is honest about its provenance: a heuristic parse of free text —
     * whether legacy backfill OR a save-time auto-parse of the authored passage — is
     * at best `probable`, never `exact`. `exact` is reserved for an author who
     * confirmed the structured chips (Phase 3b). A ref the validator could not fully
     * clear is `ambiguous`. The confidence is a function of the validator flags, NOT
     * the source, so both producers emit identical refs for the same passage.
     *
     * @param array{inCanon:bool,structurallyValid:bool,inlineEligible:bool} $flags
     */
    private function confidence( array $flags ): string {
        $confidence = ( $flags['inCanon'] && $flags['structurallyValid'] )
            ? self::STORED_CONFIDENCE_PROBABLE
            : self::STORED_CONFIDENCE_AMBIGUOUS;

        // De-store enforcement (design §3.4): this producer may persist ONLY a stored tier;
        // never a floor-only `derived-exact*` tier (which is computed at render time by the
        // classifier). Belt-and-suspenders — the literals above are already in-set — but it
        // pins the guarantee at the single emit point so the producer can never regress.
        return self::normalizeStoredConfidence( $confidence );
    }

    /**
     * Serialize the versioned envelope written to META_BIBLE_REFS.
     *
     * @param list<array<string,mixed>> $refs
     */
    private function envelope( array $refs ): string {
        return (string) wp_json_encode( array( 'v' => self::ENVELOPE_VERSION, 'refs' => $refs ) );
    }

    /**
     * Append-assign the TAX_BOOK terms named by the refs and return the ids that were
     * NEWLY added by this call (so a caller's reverse can detach exactly those, leaving
     * any term the post already carried untouched). Idempotent: re-adding a present
     * term is a no-op.
     *
     * @param list<array<string,mixed>> $refs
     *
     * @return list<int>
     */
    private function assignBookTerms( int $id, array $refs ): array {
        $names = $this->bookNames( $refs );
        if ( array() === $names ) {
            return array();
        }

        $before = $this->bookTermIds( $id );
        wp_set_object_terms( $id, $names, ID::TAX_BOOK, true );
        $after = $this->bookTermIds( $id );

        return array_values( array_diff( $after, $before ) );
    }

    /**
     * Unique canonical book display names for the refs (USFM -> BibleCanon name).
     *
     * @param list<array<string,mixed>> $refs
     *
     * @return list<string>
     */
    private function bookNames( array $refs ): array {
        $byCode = array_flip( BibleBookMap::usfm() );
        $names  = array();

        foreach ( $refs as $ref ) {
            $usfm = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
            if ( isset( $byCode[ $usfm ] ) && ! in_array( $byCode[ $usfm ], $names, true ) ) {
                $names[] = $byCode[ $usfm ];
            }
        }

        return $names;
    }

    /** @return list<int> The TAX_BOOK term ids currently attached to the post. */
    private function bookTermIds( int $id ): array {
        $terms = wp_get_object_terms( $id, ID::TAX_BOOK, array( 'fields' => 'ids' ) );
        if ( ! is_array( $terms ) ) {
            return array();
        }

        return array_values( array_map( 'intval', $terms ) );
    }
}
