<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\DerivedExactClassifier;
use Sermonator\Bible\ReferenceParser;

/**
 * End-to-end pinning for {@see DerivedExactClassifier} driving the REAL
 * {@see ReferenceParser} over real liturgical passages — the faithful parse→classify
 * flow the render path runs (the resolver's L2 and the audit's L2 both delegate here).
 * NOT run in this environment (no Docker / wp-env) — authored to run under wp-env later.
 *
 * Unlike the pure unit test (which hand-builds ref fixtures), this exercises the actual
 * envelope shape: it parses a compound passage exactly as the capture pipeline does —
 * each ref carrying the parser's own per-segment `raw` — then asserts the §2 invariant
 * holds over real carry-over data:
 *
 *   - a continuation segment (book carried from a SIBLING) is NEVER promoted, because
 *     its own raw re-parsed in isolation has no book → fallback → not derived-exact;
 *   - a self-contained leading segment IS derived-exact and promotes under per-seg;
 *   - STRICT promotes NOTHING from any multi-ref passage (the singleton constraint).
 *
 * If a future parser change ever made a bookless continuation re-parse to a real ref,
 * this fails loudly — promotion drift can never silently surface a wrong verse.
 */
final class DerivedExactClassifierTest extends WP_UnitTestCase {
    /**
     * Flatten the real parser's refs for a passage, preserving each ref's own `raw`.
     *
     * @return list<array<string,mixed>>
     */
    private function refsFor( string $passage ): array {
        $refs = array();
        foreach ( ReferenceParser::parse( $passage )['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    public function test_verse_list_continuation_never_promotes_but_lead_does(): void {
        // "John 3:16, 18": two refs — "John 3:16" (self-contained) and the bare "18"
        // continuation that inherits book+chapter from its sibling.
        $refs = $this->refsFor( 'John 3:16, 18' );
        $this->assertCount( 2, $refs );

        $lead         = $refs[0];
        $continuation = $refs[1];

        $this->assertSame( '18', $continuation['raw'], 'continuation keeps its own bare raw' );
        $this->assertSame( 18, $continuation['verseStart'], 'continuation resolved to verse 18 via carry-over' );

        // Identity predicate: lead is derived-exact, continuation is not.
        $this->assertTrue( DerivedExactClassifier::isDerivedExact( $lead ) );
        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $continuation ) );

        // Per-seg promotes the self-contained lead but still withholds the continuation.
        $count = count( $refs );
        $this->assertTrue( DerivedExactClassifier::promotes( $lead, DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG, $count ) );
        $this->assertFalse( DerivedExactClassifier::promotes( $continuation, DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG, $count ) );

        // STRICT promotes neither (multi-ref envelope fails the singleton constraint).
        $this->assertFalse( DerivedExactClassifier::promotes( $lead, DerivedExactClassifier::FLOOR_DERIVED_EXACT, $count ) );
        $this->assertFalse( DerivedExactClassifier::promotes( $continuation, DerivedExactClassifier::FLOOR_DERIVED_EXACT, $count ) );
    }

    public function test_psalm_verse_continuation_mirrors_isolated_reparse_and_strict_stays_dark(): void {
        // The Psalm-bearing lectionary shape whose safety the per-seg floor rides: the
        // leading "Psalm 23:1-3" is self-contained; the trailing bare "6" inherits Psalm 23
        // from its sibling and, re-parsed alone, has no book → fallback → not derived-exact.
        $refs = $this->refsFor( 'Psalm 23:1-3, 6' );
        $this->assertCount( 2, $refs );
        $this->assertSame( '6', $refs[1]['raw'], 'continuation keeps its own bare raw' );
        $this->assertSame( 6, $refs[1]['verseStart'], 'continuation resolved to verse 6 via carry-over' );

        $count = count( $refs );
        foreach ( $refs as $ref ) {
            // The pinned invariant: isDerivedExact mirrors the ref's OWN isolated re-parse.
            $reparsedAlone = ReferenceParser::parse( (string) $ref['raw'] )['segments'][0]['status'];
            $expected      = ( 'matched' === $reparsedAlone );

            $this->assertSame(
                $expected,
                DerivedExactClassifier::isDerivedExact( $ref ),
                "isDerivedExact must mirror isolated re-parse identity for raw '{$ref['raw']}'"
            );
            // STRICT promotes nothing from a multi-ref bundle (the singleton constraint).
            $this->assertFalse(
                DerivedExactClassifier::promotes( $ref, DerivedExactClassifier::FLOOR_DERIVED_EXACT, $count )
            );
        }

        // The continuation is specifically NOT derived-exact (the safety the floor rides on).
        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $refs[1] ) );
    }

    public function test_lone_clean_passage_promotes_under_strict(): void {
        $refs = $this->refsFor( 'Romans 8:28' );
        $this->assertCount( 1, $refs );

        $this->assertTrue( DerivedExactClassifier::isDerivedExact( $refs[0] ) );
        $this->assertTrue(
            DerivedExactClassifier::promotes( $refs[0], DerivedExactClassifier::FLOOR_DERIVED_EXACT, 1 )
        );
        // `exact` still promotes nothing.
        $this->assertFalse(
            DerivedExactClassifier::promotes( $refs[0], DerivedExactClassifier::FLOOR_EXACT, 1 )
        );
    }
}
