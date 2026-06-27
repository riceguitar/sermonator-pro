<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier as DEC;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Schema\Identifiers as ID;

/**
 * LOCKSTEP fixture-matrix (design §3.5 / spec line 55): the live
 * {@see BibleResolver} L2 and the {@see CoverageAudit} L2 must reach the SAME
 * promotion decision on every ref, because the audit is the operator's enable
 * soft-gate / live preview — if it lies, the go/no-go decision is made on fiction.
 *
 * Both engines now delegate the `probable → inline` promotion to the ONE shared
 * {@see DEC::promotes()} over the de-stored stored-confidence vocabulary
 * `{exact,probable,ambiguous}` (DISJOINT from the floor vocabulary). This test pins
 * the three cases the lockstep regression broke:
 *   (a) a SMUGGLED `confidence:derived-exact` clears NOTHING under a `derived-exact`
 *       floor (was a FALSE-GREEN in the old rank-2 audit);
 *   (b) `derived-exact-perseg` promotes compound segments per-segment (the old audit
 *       normalized it to `exact` and promoted nothing);
 *   (c) a lone `probable` ref promotes via re-parse-identity (the old audit withheld
 *       it as low-confidence).
 *
 * Every fixture is L1-shaped, ESV (eng-protestant, homogeneous), attested, and present
 * in the injected chapter, so the ONLY gate that can withhold is L2 — making the
 * resolver's per-ref inline presence and the audit's per-ref eligibility directly
 * comparable.
 */
final class BibleL2LockstepTest extends TestCase {
    /** @var array<int,array<string,mixed>> postId => (metaKey => value) */
    private array $meta = array();

    /** @var array<string,mixed> */
    private array $options = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta    = array();
        $this->options = array();

        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'apply_filters' )->alias( static function ( $tag, $value ) {
            return $value;
        } );
        Functions\when( 'do_action' )->justReturn( null );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            return $this->meta[ (int) $id ][ $key ] ?? '';
        } );
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            return $this->options[ $name ] ?? $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ----------------------------------------------------------------------------------
    // Fixtures
    // ----------------------------------------------------------------------------------

    /**
     * An L1-shaped, ESV-sourced JHN 3 ref. `confidence` and `raw` vary per case; every
     * other field is held constant so L1 and L3–L9 always pass and L2 is the sole gate.
     *
     * @return array<string,mixed>
     */
    private function ref( int $verseStart, string $raw, string $confidence ): array {
        return array(
            'bookUSFM'         => 'JHN',
            'chapterStart'     => 3,
            'verseStart'       => $verseStart,
            'verseEnd'         => null,
            'chapterEnd'       => null,
            'raw'              => $raw,
            'confidence'       => $confidence,
            'srcVersification' => 'ESV',
        );
    }

    /** A chapter carrying JHN 3:16–18 so every fixture ref clears L8/L9. */
    private function chapter(): callable {
        return static function ( $translation, $book, $chapterNum, $warmContext ): array {
            $verses = array();
            foreach ( array( 16, 17, 18 ) as $n ) {
                $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => 'word' ) ) );
            }
            return $verses;
        };
    }

    /** @param list<array<string,mixed>> $refs */
    private function seedPost( int $id, array $refs ): void {
        $this->meta[ $id ] = array(
            ID::META_BIBLE_PASSAGE => 'passage',
            ID::META_BIBLE_REFS    => (string) json_encode( array( 'v' => 1, 'refs' => $refs ) ),
        );
    }

    private function setFloor( string $floor ): void {
        $this->options = array(
            ID::OPTION_BIBLE_LINK_VERSION             => 'ESV',
            ID::OPTION_BIBLE_INLINE_ENABLED           => true,
            ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR  => $floor,
            ID::OPTION_BIBLE_INLINE_ATTESTATION       => true,
        );
    }

    /**
     * Drive BOTH engines over a single-post corpus and assert they agree per-ref. Since
     * every non-eligible ref fails ONLY at L2, the audit's `inline_eligible` count equals
     * the number of `true` in $expected and its `low-confidence` withheld count equals the
     * complement — so the aggregate report is a faithful per-ref oracle.
     *
     * Per-ref identity: in addition to the aggregate counts, each ref at position i is
     * isolated in its own single-ref corpus (with the correct compound refCount preserved
     * via non-array junk fillers — byte-lockstep with how the resolver derives refCount),
     * and the audit's per-ref decision is compared with the resolver's per-ref decision.
     * This ensures the audit promotes/withholds the SAME refs as the resolver, not just
     * the same total — a wrong-ref-but-same-count audit cannot pass.
     *
     * @param list<array<string,mixed>> $refs
     * @param list<bool>                $expected
     */
    private function assertLockstep( array $refs, string $floor, array $expected ): void {
        $this->seedPost( 1, $refs );
        $this->setFloor( $floor );

        // Resolver: per-ref inline presence (true == promoted to inline).
        $resolved = BibleResolver::resolve( 1, $this->chapter() );
        $this->assertNotNull( $resolved, "resolver returned null under $floor" );
        $resolverEligible = array_map(
            static function ( array $entry ): bool {
                return null !== $entry['inline'];
            },
            $resolved->refs()
        );

        // Audit: aggregate per-ref eligibility over the same single post.
        $report = ( new CoverageAudit( static fn(): array => array( 1 ), $this->chapter() ) )->inlineReport();

        $expectedCount = count( array_filter( $expected ) );

        $this->assertSame( $expected, $resolverEligible, "resolver per-ref mismatch under $floor" );
        $this->assertSame( $expectedCount, $report['inline_eligible'], "audit inline_eligible mismatch under $floor" );
        $this->assertSame(
            count( $refs ) - $expectedCount,
            $report['withheld']['low-confidence'],
            "audit low-confidence mismatch under $floor"
        );
        // The lockstep claim itself: the two engines admit the same number of refs.
        $this->assertSame(
            $expectedCount,
            count( array_filter( $resolverEligible ) ),
            "resolver/audit eligible-count divergence under $floor"
        );

        // Per-ref identity: verify the audit classifies each individual ref the same way
        // as the resolver — not just the same total. Each ref is tested in an isolated
        // single-ref corpus whose envelope's UNFILTERED refCount equals the compound
        // refCount (matching the STRICT singleton constraint's denominator), achieved by
        // padding with non-array junk entries — byte-lockstep with the resolver's
        // readEnvelopeRefs() + count() pattern.
        $totalRefCount = count( $refs );
        foreach ( $refs as $i => $ref ) {
            // Envelope: the real ref first, then (totalRefCount - 1) non-array junk entries.
            // The audit sees refCount = totalRefCount, postRefs = [ref] (only array entries).
            $perRefEntries = array_merge( array( $ref ), array_fill( 0, $totalRefCount - 1, 5 ) );
            $perRefPostId  = 1000 + $i;
            $this->meta[ $perRefPostId ] = array(
                ID::META_BIBLE_PASSAGE => 'passage',
                ID::META_BIBLE_REFS    => (string) json_encode( array( 'v' => 1, 'refs' => $perRefEntries ) ),
            );

            $pid          = $perRefPostId;
            $perRefReport = ( new CoverageAudit(
                function () use ( $pid ): array { return array( $pid ); },
                $this->chapter()
            ) )->inlineReport();

            $this->assertSame(
                $expected[ $i ] ? 1 : 0,
                $perRefReport['inline_eligible'],
                sprintf(
                    'audit per-ref[%d] eligibility mismatch under %s (resolver classified it as %s)',
                    $i,
                    $floor,
                    $expected[ $i ] ? 'eligible' : 'withheld'
                )
            );
        }
    }

    // ----------------------------------------------------------------------------------
    // The matrix
    // ----------------------------------------------------------------------------------

    public function test_exact_floor_promotes_nothing_below_exact(): void {
        // `exact` clears; a clean `probable` does NOT (the conservative default).
        $this->assertLockstep(
            array(
                $this->ref( 16, 'John 3:16', 'exact' ),
                $this->ref( 17, 'John 3:17', 'probable' ),
            ),
            DEC::FLOOR_EXACT,
            array( true, false )
        );
    }

    public function test_smuggled_derived_exact_is_inert_under_strict_floor(): void {
        // CASE (a) — the FALSE-GREEN the de-store closes: a pre-stamped
        // `confidence:derived-exact`, even with an identically re-parsing raw, is NOT a
        // recognized stored tier → ranks 0 → clears nothing. Both engines withhold.
        $this->assertLockstep(
            array( $this->ref( 16, 'John 3:16', 'derived-exact' ) ),
            DEC::FLOOR_DERIVED_EXACT,
            array( false )
        );
    }

    public function test_lone_probable_promotes_under_strict_floor(): void {
        // CASE (c) — a lone (refCount===1) clean `probable` promotes via re-parse-identity.
        $this->assertLockstep(
            array( $this->ref( 16, 'John 3:16', 'probable' ) ),
            DEC::FLOOR_DERIVED_EXACT,
            array( true )
        );
    }

    public function test_compound_probable_stays_dark_under_strict_floor(): void {
        // STRICT singleton constraint: a 2-ref envelope NEVER promotes, even though each
        // segment is individually clean (the corpus-independent dark guarantee).
        $this->assertLockstep(
            array(
                $this->ref( 16, 'John 3:16', 'probable' ),
                $this->ref( 17, 'John 3:17', 'probable' ),
            ),
            DEC::FLOOR_DERIVED_EXACT,
            array( false, false )
        );
    }

    public function test_compound_probable_promotes_under_perseg_floor(): void {
        // CASE (b) — the SAME 2-ref compound promotes per-segment under perseg (the old
        // audit normalized perseg → exact and promoted nothing: a large recall divergence).
        $this->assertLockstep(
            array(
                $this->ref( 16, 'John 3:16', 'probable' ),
                $this->ref( 17, 'John 3:17', 'probable' ),
            ),
            DEC::FLOOR_DERIVED_EXACT_PERSEG,
            array( true, true )
        );
    }

    public function test_carryover_continuation_never_promotes_under_any_derived_floor(): void {
        // A bookless carry-over (`raw` = bare verse) re-parses in isolation to a fallback →
        // re-parse-identity fails → withheld under BOTH derived floors. Pinned contract.
        $carryover = array( $this->ref( 18, '18', 'probable' ) );

        $this->assertLockstep( $carryover, DEC::FLOOR_DERIVED_EXACT, array( false ) );
        $this->assertLockstep( $carryover, DEC::FLOOR_DERIVED_EXACT_PERSEG, array( false ) );
    }

    public function test_ambiguous_never_promotes_under_perseg_floor(): void {
        $this->assertLockstep(
            array( $this->ref( 16, 'John 3:16', 'ambiguous' ) ),
            DEC::FLOOR_DERIVED_EXACT_PERSEG,
            array( false )
        );
    }

    // ----------------------------------------------------------------------------------
    // Malformed-envelope refCount lockstep (a non-array junk sibling)
    //
    // The STRICT singleton constraint compares the per-post envelope refCount to 1. The
    // resolver derives that count from `readEnvelopeRefs()` — the UNFILTERED list, junk
    // INCLUDED, skipping non-array entries only while iterating AFTER the count. The audit
    // must count the SAME population; if it drops the junk sibling before counting it sees
    // refCount 1 and FALSE-promotes the lone `probable` while the render (refCount 2)
    // withholds it — a false-green on exactly the corpus-independent dark guarantee. These
    // pin both engines to the shared {@see \Sermonator\Bible\RefsEnvelope} count.
    // ----------------------------------------------------------------------------------

    /**
     * Seed a post whose envelope carries the given (possibly non-array) entries verbatim,
     * so a non-array junk sibling survives into the stored bytes both engines read.
     *
     * @param list<mixed> $entries
     */
    private function seedRawEnvelope( int $id, array $entries ): void {
        $this->meta[ $id ] = array(
            ID::META_BIBLE_PASSAGE => 'passage',
            ID::META_BIBLE_REFS    => (string) json_encode( array( 'v' => 1, 'refs' => $entries ) ),
        );
    }

    public function test_non_array_junk_sibling_suppresses_promotion_in_lockstep_under_strict(): void {
        // The concrete review case: {"refs":[{lone clean probable JHN 3:16}, 5]}. The junk
        // sibling makes refCount 2 in BOTH engines → STRICT withholds the probable. Before
        // the shared reader the audit counted 1 and reported it inline-eligible (false-green)
        // while the render withheld it.
        $this->seedRawEnvelope( 1, array( $this->ref( 16, 'John 3:16', 'probable' ), 5 ) );
        $this->options = array(
            ID::OPTION_BIBLE_LINK_VERSION            => 'ESV',
            ID::OPTION_BIBLE_INLINE_ENABLED          => true,
            ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR => DEC::FLOOR_DERIVED_EXACT,
            ID::OPTION_BIBLE_INLINE_ATTESTATION      => true,
        );

        // Resolver: the one render-ready ref resolves but is WITHHELD from inline (refCount 2).
        $resolved = BibleResolver::resolve( 1, $this->chapter() );
        $this->assertNotNull( $resolved );
        $this->assertCount( 1, $resolved->refs() );
        $this->assertNull( $resolved->refs()[0]['inline'], 'render must withhold the probable (refCount 2)' );

        // Audit: identical decision — withheld low-confidence, NOT counted inline-eligible.
        $report = ( new CoverageAudit( static fn(): array => array( 1 ), $this->chapter() ) )->inlineReport();
        $this->assertSame( 1, $report['refs_total'], 'only the array-typed ref is render-ready' );
        $this->assertSame( 0, $report['inline_eligible'], 'audit must NOT over-promote (lockstep refCount 2)' );
        $this->assertSame( 1, $report['withheld']['low-confidence'] );
    }

    public function test_non_array_junk_sibling_does_not_block_perseg_promotion_in_lockstep(): void {
        // Under perseg the singleton constraint does not apply, so the junk sibling is
        // irrelevant: the lone clean `probable` still promotes in BOTH engines (junk is
        // skipped while iterating, never miscounted into the decision).
        $this->seedRawEnvelope( 1, array( $this->ref( 16, 'John 3:16', 'probable' ), 5 ) );
        $this->options = array(
            ID::OPTION_BIBLE_LINK_VERSION            => 'ESV',
            ID::OPTION_BIBLE_INLINE_ENABLED          => true,
            ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR => DEC::FLOOR_DERIVED_EXACT_PERSEG,
            ID::OPTION_BIBLE_INLINE_ATTESTATION      => true,
        );

        $resolved = BibleResolver::resolve( 1, $this->chapter() );
        $this->assertNotNull( $resolved );
        $this->assertCount( 1, $resolved->refs() );
        $this->assertNotNull( $resolved->refs()[0]['inline'], 'render must promote the probable under perseg' );

        $report = ( new CoverageAudit( static fn(): array => array( 1 ), $this->chapter() ) )->inlineReport();
        $this->assertSame( 1, $report['refs_total'] );
        $this->assertSame( 1, $report['inline_eligible'], 'audit must promote in lockstep under perseg' );
        $this->assertSame( 0, $report['withheld']['low-confidence'] );
    }
}
