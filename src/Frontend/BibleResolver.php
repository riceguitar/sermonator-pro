<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Bible\RefValidator;
use Sermonator\Bible\TranslationRegistry;
use Sermonator\Bible\VersificationGate;
use Sermonator\Frontend\Bible\ChapterProvider;
use Sermonator\Schema\BibleBookMap;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers;

/**
 * Impure orchestrator that turns a post's stored {@see Identifiers::META_BIBLE_REFS}
 * envelope into a {@see ResolvedScripture} value object for the pure Renderer to
 * escape and emit. Carries BOTH the Phase 3a LINK path and the Phase 3b INLINE path.
 *
 * Contract:
 *   resolve(int $postId, ?callable $chapterResolver = null): ?ResolvedScripture
 *
 * It NEVER throws and NEVER mutates meta (#1 data preservation — `META_BIBLE_PASSAGE`
 * and the stored envelope are read-only). It reads the versioned JSON envelope
 * `{"v":1,"refs":[Ref,…]}` and, for each Ref:
 *   - validates via {@see RefValidator} (skips any ref that is not in-canon or not
 *     structurally valid — never fail-*wrong*),
 *   - builds a human label from {@see BibleBookMap} (USFM → display name) plus a
 *     chapter:verse(-verse)(+cross-chapter) suffix,
 *   - builds a Bible Gateway lookup URL against the configured axis-A link version
 *     ({@see TranslationRegistry::linkVersion()}) — the always-available 3a LINK,
 *   - when inline is enabled, ATTEMPTS the 3b inline payload through the full L1–L9
 *     predicate (below); on any failure it falls OPEN to the 3a link for THAT one ref,
 *     observably (`sermonator_bible_fallback` with the layer reason).
 *
 * ## The never-fail-WRONG inline predicate (design §2 L1–L9)
 *
 * A ref renders inline verse text ONLY when inline is globally enabled
 * ({@see Identifiers::OPTION_BIBLE_INLINE_ENABLED}) AND EVERY layer passes; otherwise
 * the ref stays a 3a link and a fallback fires with the FIRST failing layer's reason:
 *
 *   PURE STATIC PRE-FILTER (no chapter data):
 *     L1  structural inline-shape: in-canon && structurally-valid && a specific verse
 *         (`verseStart !== null`) && NOT cross-chapter (`chapterEnd === null`).
 *         reason `not-inline-eligible`.
 *     L2  confidence floor: the ref's `confidence` clears
 *         {@see Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR} (default `exact`).
 *         reason `low-confidence`.
 *     L3  the inline TARGET translation is itself inline-eligible (license-clean &
 *         audited — ENGWEBP yes; ENGKJV/BSB no). reason `translation-ineligible`.
 *
 *   VERSIFICATION RELATION ({@see VersificationGate::eligible()} — L4–L7):
 *     L4  source `srcVersification` normalizes to a modeled family
 *         (reason `src-versification-unsupported`),
 *     L5  the ordered (source-family → target) pair is modeled
 *         (reason `unmodeled-versification-pair`),
 *     L6  site-default-provenance refs need the admin attestation
 *         ({@see Identifiers::OPTION_BIBLE_INLINE_ATTESTATION}); `authored` refs skip it
 *         (reason `src-versification-unattested`),
 *     L7  the (book, chapter) sits OUTSIDE the pair's divergent zones
 *         (reason `versification-divergent`).
 *
 *   RENDER-TIME CONFIRMATION (chapter in hand; Renderer stays pure):
 *     L8  the chapter is available OFFLINE — {@see ChapterProvider::get()} with
 *         `warmContext: FALSE`, so the render context performs ZERO network I/O (disk
 *         + transient only). reason `chapter-unavailable`.
 *     L9  {@see RefValidator::rangeWithinChapter()} — EVERY verse `verseStart..verseEnd`
 *         is physically present in the fetched chapter (a critical-text gap fails the
 *         WHOLE ref open; never render a partial range). reason `verse-out-of-range`.
 *
 * On a full pass the resolver slices `verseStart..verseEnd` out of the chapter into the
 * per-ref inline payload `{translation, attribution, verses:[{number, nodes:[…]}]}` of
 * TYPED nodes (NOT raw HTML — the pure Renderer escapes every leaf).
 *
 * ## Off-render-path invariant
 *
 * The render context NEVER fetches: every chapter read goes through the injected
 * resolver with `warmContext: false`, so {@see ChapterProvider} stays disk/transient
 * only and {@see \Sermonator\Frontend\Bible\ChapterFetcher} is never reached. Warming is
 * a save-time / CLI concern. The chapter resolver is injectable purely so this can be
 * proven in a unit test; production passes the default {@see ChapterProvider::get}.
 *
 * Fail-open at two granularities: a missing/empty/corrupt envelope, or a post where no
 * ref resolves, yields `null` (the Renderer then emits today's byte-identical plain-text
 * meta row). Observability hooks fire per outcome:
 *   - `sermonator_bible_resolved` ( array $ref, string $version )   — a ref produced an entry
 *   - `sermonator_bible_fallback` ( string $passage, string $reason ) — a ref fell open
 * A ref that falls open from inline to link fires BOTH (it resolved to a link, observably).
 *
 * Impure via `get_post_meta`, the option reads (inline enable/attestation/floor + the
 * two translation axes inside {@see TranslationRegistry}), and the disk/cache reads
 * inside {@see ChapterProvider}. No network I/O on the render path.
 */
final class BibleResolver {
    private const BIBLEGATEWAY_BASE = 'https://www.biblegateway.com/passage/?search=';

    /**
     * Confidence tiers an inline ref may carry, ranked HIGH → LOW. A ref clears the L2
     * floor only when its tier rank is >= the configured floor's rank (and is a known,
     * non-zero tier). `ambiguous` (and any unknown/absent value) ranks 0 — never inline.
     *
     * @var array<string,int>
     */
    private const CONFIDENCE_RANK = array(
        'exact'         => 3,
        'derived-exact' => 2,
        'probable'      => 1,
    );

    /**
     * Resolve a post's stored references into a ResolvedScripture, or null.
     *
     * @param int                                                          $postId
     * @param callable(string,string,int,bool):(array<int,mixed>|null)|null $chapterResolver
     *        The L8 chapter resolver (tests inject a spy to prove the off-render-path
     *        invariant); defaults to the disk/cache-only {@see ChapterProvider::get}.
     */
    public static function resolve( int $postId, ?callable $chapterResolver = null ): ?ResolvedScripture {
        $refs = self::readEnvelopeRefs( $postId );
        if ( null === $refs ) {
            return null;
        }

        $registry          = TranslationRegistry::current();
        $version           = $registry->linkVersion();
        $inlineTranslation = $registry->inlineTranslation();

        $inlineEnabled   = self::inlineEnabled();
        $attested        = (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false );
        $floor           = self::confidenceFloor();
        $chapterResolver = $chapterResolver ?? array( ChapterProvider::class, 'get' );

        $resolved = array();

        foreach ( $refs as $ref ) {
            if ( ! is_array( $ref ) ) {
                continue;
            }

            $passage = isset( $ref['raw'] ) && is_string( $ref['raw'] ) ? $ref['raw'] : '';

            $flags = RefValidator::validate( $ref );

            if ( ! $flags['inCanon'] || ! $flags['structurallyValid'] ) {
                /** This single ref can't resolve; the rest of the passage still can. */
                do_action(
                    'sermonator_bible_fallback',
                    $passage,
                    ! $flags['inCanon'] ? 'not-in-canon' : 'structurally-invalid'
                );
                continue;
            }

            $label = self::label( $ref );
            if ( '' === $label ) {
                do_action( 'sermonator_bible_fallback', $passage, 'unlabelable' );
                continue;
            }

            $entry = array(
                'label'          => $label,
                'linkUrl'        => self::linkUrl( $label, $version ),
                'version'        => $version,
                'inlineEligible' => $flags['inlineEligible'],
                'inline'         => null,
            );

            // 3b inline path: attempt the L1–L9 predicate ONLY when globally enabled.
            // When disabled the ref stays a pure 3a link (inline null) with NO fallback —
            // byte-identical to 3a, including observability and zero chapter I/O.
            if ( $inlineEnabled ) {
                $entry['inline'] = self::resolveInline(
                    $ref,
                    $passage,
                    $flags,
                    $inlineTranslation,
                    $attested,
                    $floor,
                    $chapterResolver
                );
            }

            $resolved[] = $entry;

            /** Ground-truth observability: a real ref resolved (to inline text or a link). */
            do_action( 'sermonator_bible_resolved', $ref, $version );
        }

        if ( array() === $resolved ) {
            return null;
        }

        return new ResolvedScripture( $resolved );
    }

    /**
     * Run the full never-fail-WRONG L1–L9 predicate for ONE ref and return its inline
     * payload, or null (falling OPEN to the 3a link for that ref). On every fall-open it
     * fires `sermonator_bible_fallback` with the FIRST failing layer's reason, so a
     * mis-versification can never hide behind a green inline.
     *
     * @param array<string,mixed>                                      $ref
     * @param string                                                   $passage           Raw label for the fallback hook.
     * @param array{inCanon:bool,structurallyValid:bool,inlineEligible:bool} $flags        Pre-computed RefValidator flags.
     * @param string                                                   $inlineTranslation Inline target id (e.g. ENGWEBP).
     * @param bool                                                     $attested          L6 admin attestation state.
     * @param string                                                   $floor             L2 confidence floor.
     * @param callable(string,string,int,bool):(array<int,mixed>|null) $chapterResolver   L8 disk/cache-only chapter reader.
     *
     * @return array{translation:string,attribution:string,verses:list<array{number:int,nodes:list<array{type:string,text:string}>}>}|null
     */
    private static function resolveInline(
        array $ref,
        string $passage,
        array $flags,
        string $inlineTranslation,
        bool $attested,
        string $floor,
        callable $chapterResolver
    ): ?array {
        // L1 — pure structural inline-shape (NOT the 3a unary divergent table; the
        // versification relation is owned by L4–L7's VersificationGate, which is why the
        // Psalter is inline-eligible for the English↔English pair — the headline fix).
        if ( ! self::structurallyInlineShaped( $ref, $flags ) ) {
            return self::fallOpen( $passage, 'not-inline-eligible' );
        }

        // L2 — confidence floor (default `exact`; an author-confirmed chip).
        if ( ! self::confidenceClears( $ref, $floor ) ) {
            return self::fallOpen( $passage, 'low-confidence' );
        }

        // L3 — the inline TARGET translation must itself be inline-eligible.
        if ( ! self::translationInlineEligible( $inlineTranslation ) ) {
            return self::fallOpen( $passage, 'translation-ineligible' );
        }

        // L4–L7 — the (source-family → target) versification relation (the gate owns
        // the reason codes: src-versification-unsupported / unmodeled-versification-pair
        // / src-versification-unattested / versification-divergent). The resolver
        // propagates whichever reason the gate reports verbatim.
        $gate = VersificationGate::eligible( $ref, $inlineTranslation, $attested );
        if ( ! $gate['eligible'] ) {
            return self::fallOpen( $passage, (string) $gate['reason'] );
        }

        // L8 — RENDER CONTEXT: disk/cache ONLY, zero network (warmContext FALSE).
        $book       = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
        $chapterNum = isset( $ref['chapterStart'] ) ? (int) $ref['chapterStart'] : 0;
        $chapter    = $chapterResolver( $inlineTranslation, $book, $chapterNum, false );
        if ( ! is_array( $chapter ) || array() === $chapter ) {
            return self::fallOpen( $passage, 'chapter-unavailable' );
        }

        // L9 — every verse verseStart..verseEnd is physically present in the chapter; a
        // gap fails the WHOLE ref open (never render a partial range).
        if ( ! RefValidator::rangeWithinChapter( $ref, $chapter ) ) {
            return self::fallOpen( $passage, 'verse-out-of-range' );
        }

        // Slice the confirmed range into the typed-node inline payload.
        $verses = self::sliceVerses( $ref, $chapter );
        if ( array() === $verses ) {
            // Defensive: L9 already proved presence, so this is unreachable in practice —
            // but never render an empty inline section; fall open if it ever happens.
            return self::fallOpen( $passage, 'verse-out-of-range' );
        }

        return array(
            'translation' => $inlineTranslation,
            'attribution' => self::attribution( $inlineTranslation ),
            'verses'      => $verses,
        );
    }

    /**
     * Fire the observable per-ref fall-open and return null (the inline payload absence
     * the caller turns into the 3a link for that ref).
     */
    private static function fallOpen( string $passage, string $reason ): ?array {
        do_action( 'sermonator_bible_fallback', $passage, $reason );

        return null;
    }

    /**
     * L1 — the pure structural inline-shape gate: a single concrete verse (or in-chapter
     * verse range) of a canonical, structurally-valid book. Chapter-only refs (no
     * verseStart) and cross-chapter ranges (chapterEnd set) can never inline.
     *
     * @param array<string,mixed>                                          $ref
     * @param array{inCanon:bool,structurallyValid:bool,inlineEligible:bool} $flags
     */
    private static function structurallyInlineShaped( array $ref, array $flags ): bool {
        if ( ! $flags['inCanon'] || ! $flags['structurallyValid'] ) {
            return false;
        }

        $verseStart = $ref['verseStart'] ?? null;
        $chapterEnd = $ref['chapterEnd'] ?? null;

        return null !== $verseStart && null === $chapterEnd;
    }

    /**
     * L2 — does the ref's `confidence` tier rank at or above the configured floor?
     * Unknown/absent tiers rank 0 and never clear (never-fail-WRONG).
     *
     * @param array<string,mixed> $ref
     */
    private static function confidenceClears( array $ref, string $floor ): bool {
        $floorRank = self::CONFIDENCE_RANK[ $floor ] ?? self::CONFIDENCE_RANK['exact'];
        $refConf   = isset( $ref['confidence'] ) && is_string( $ref['confidence'] ) ? $ref['confidence'] : '';
        $refRank   = self::CONFIDENCE_RANK[ $refConf ] ?? 0;

        return $refRank > 0 && $refRank >= $floorRank;
    }

    /**
     * L3 — is the inline target translation itself inline-eligible (license-clean &
     * audited)? Reads the single curated allowlist so a `sermonator_bible_translation`
     * filter override to an ineligible id (e.g. ENGKJV) still falls open to the link.
     */
    private static function translationInlineEligible( string $translation ): bool {
        return array_key_exists( $translation, BibleTranslations::curatedInline() );
    }

    /** Master inline kill-switch (default OFF; physically un-enableable until vendored+warmed). */
    private static function inlineEnabled(): bool {
        return (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false );
    }

    /**
     * The configured L2 confidence floor, validated to a known tier (default + fallback
     * `exact`, the most conservative).
     */
    private static function confidenceFloor(): string {
        $stored = get_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'exact' );
        $stored = is_string( $stored ) ? $stored : 'exact';

        return isset( self::CONFIDENCE_RANK[ $stored ] ) ? $stored : 'exact';
    }

    /**
     * Human attribution for an inline translation id (e.g. ENGWEBP → "World English
     * Bible"), read from the curated allowlist; falls back to the id itself.
     */
    private static function attribution( string $translation ): string {
        foreach ( BibleTranslations::all() as $entry ) {
            if ( isset( $entry['id'], $entry['label'] ) && $entry['id'] === $translation ) {
                return (string) $entry['label'];
            }
        }

        return $translation;
    }

    /**
     * Slice the verseStart..verseEnd span out of a normalized chapter into the inline
     * payload's verse list — typed nodes only, in chapter order. Defensive: re-sanitizes
     * each node to `{type:string,text:string}` so no raw HTML (and no malformed node) can
     * reach the payload.
     *
     * @param array<string,mixed>                                                    $ref
     * @param list<array{number?:int,nodes?:list<array{type?:string,text?:string}>}> $chapter
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>
     */
    private static function sliceVerses( array $ref, array $chapter ): array {
        $verseStart = isset( $ref['verseStart'] ) && null !== $ref['verseStart'] ? (int) $ref['verseStart'] : null;
        if ( null === $verseStart ) {
            return array();
        }

        $verseEnd = isset( $ref['verseEnd'] ) && null !== $ref['verseEnd'] ? (int) $ref['verseEnd'] : $verseStart;
        if ( $verseEnd < $verseStart ) {
            return array();
        }

        $verses = array();
        foreach ( $chapter as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['number'] ) || ! is_int( $entry['number'] ) ) {
                continue;
            }

            $number = $entry['number'];
            if ( $number < $verseStart || $number > $verseEnd ) {
                continue;
            }

            $verses[] = array(
                'number' => $number,
                'nodes'  => self::sanitizeNodes( $entry['nodes'] ?? array() ),
            );
        }

        return $verses;
    }

    /**
     * Keep only well-formed typed nodes `{type:string,text:string}`; drop anything else.
     * The payload carries NO raw HTML — the pure Renderer escapes every leaf.
     *
     * @param mixed $nodes
     *
     * @return list<array{type:string,text:string}>
     */
    private static function sanitizeNodes( $nodes ): array {
        if ( ! is_array( $nodes ) ) {
            return array();
        }

        $out = array();
        foreach ( $nodes as $node ) {
            if (
                is_array( $node )
                && isset( $node['type'], $node['text'] )
                && is_string( $node['type'] )
                && is_string( $node['text'] )
            ) {
                $out[] = array( 'type' => $node['type'], 'text' => $node['text'] );
            }
        }

        return $out;
    }

    /**
     * Read + decode the META_BIBLE_REFS envelope into its raw ref list, or null when the
     * envelope is absent, empty, or not a well-formed `{refs:[…]}`.
     *
     * @return list<mixed>|null
     */
    private static function readEnvelopeRefs( int $postId ): ?array {
        $stored = get_post_meta( $postId, Identifiers::META_BIBLE_REFS, true );

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

    /**
     * Build the human-readable reference label from a validated Ref.
     *
     * Mirrors the way a reader writes a citation:
     *   whole chapter            -> "John 3"
     *   chapter range            -> "Matthew 5-7"
     *   single verse             -> "John 3:16"
     *   verse range              -> "John 3:16-18"
     *   cross-chapter range      -> "Matthew 5:1-7:29"
     *
     * Returns '' when the book code is unknown (caller treats as fallback).
     *
     * @param array<string,mixed> $ref
     */
    private static function label( array $ref ): string {
        $book = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
        $name = self::bookName( $book );
        if ( '' === $name ) {
            return '';
        }

        $chapterStart = (int) ( $ref['chapterStart'] ?? 0 );
        $verseStart   = isset( $ref['verseStart'] ) && null !== $ref['verseStart'] ? (int) $ref['verseStart'] : null;
        $verseEnd     = isset( $ref['verseEnd'] ) && null !== $ref['verseEnd'] ? (int) $ref['verseEnd'] : null;
        $chapterEnd   = isset( $ref['chapterEnd'] ) && null !== $ref['chapterEnd'] ? (int) $ref['chapterEnd'] : null;

        if ( null === $verseStart ) {
            // Whole-chapter or chapter-range reference (no specific verse).
            $suffix = (string) $chapterStart;
            if ( null !== $chapterEnd ) {
                $suffix .= '-' . $chapterEnd;
            }

            return $name . ' ' . $suffix;
        }

        if ( null !== $chapterEnd ) {
            // Cross-chapter range: chapterStart:verseStart-chapterEnd:verseEnd.
            $endVerse = null !== $verseEnd ? $verseEnd : $verseStart;

            return sprintf( '%s %d:%d-%d:%d', $name, $chapterStart, $verseStart, $chapterEnd, $endVerse );
        }

        if ( null !== $verseEnd ) {
            return sprintf( '%s %d:%d-%d', $name, $chapterStart, $verseStart, $verseEnd );
        }

        return sprintf( '%s %d:%d', $name, $chapterStart, $verseStart );
    }

    /**
     * USFM code -> canonical display name (e.g. JHN -> "John"), or '' if unknown.
     */
    private static function bookName( string $usfm ): string {
        $byCode = array_flip( BibleBookMap::usfm() );

        return $byCode[ $usfm ] ?? '';
    }

    /**
     * Build the external Bible Gateway lookup URL for a label + link version.
     */
    private static function linkUrl( string $label, string $version ): string {
        return self::BIBLEGATEWAY_BASE
            . rawurlencode( $label )
            . '&version='
            . rawurlencode( $version );
    }
}
