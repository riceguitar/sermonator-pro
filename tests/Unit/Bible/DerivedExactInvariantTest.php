<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Bible\DerivedExactClassifier;
use Sermonator\Bible\ReferenceParser;

/**
 * PINNED re-parse-identity invariant over a representative LITURGICAL corpus (the kind
 * of multi-segment lectionary citation the real epiclesis data is full of). This is a
 * test-only TRIPWIRE: it adds NO production code. It converts the carry-over safety —
 * today an emergent property of {@see ReferenceParser} segment-splitting + the
 * {@see DerivedExactClassifier} re-parse-identity predicate — into a HARD contract that
 * fails loudly the moment either of those behaviors drifts.
 *
 * It pins two load-bearing guarantees (design §2, §3.5 T-F):
 *
 *   (A) NO carry-over continuation ref is EVER promoted. A continuation (the bare "18"
 *       trailing "John 3:16, 18") is stored carrying the book/chapter it inherited in
 *       context, but its OWN raw ("18") has no book at its head; re-parsed in isolation it
 *       degrades to a fallback, so {@see DerivedExactClassifier::isDerivedExact()} — and
 *       therefore {@see DerivedExactClassifier::promotes()} under EVERY floor, perseg
 *       included — returns false. Such a ref stays `probable` and falls open to a 3a link.
 *
 *   (B) EVERY promoted ref is structurally identical re-parsed IN ISOLATION vs IN CONTEXT.
 *       For each ref the classifier promotes, re-parsing its own raw alone yields exactly
 *       one matched segment carrying exactly one ref whose 5-tuple
 *       (book/chapterStart/verseStart/verseEnd/chapterEnd) equals the in-context tuple.
 *
 * The corpus is also PINNED structurally: each fixture declares the exact segments the
 * parser must produce. If segment-splitting or carry-over ever changes (e.g. "18" stops
 * inheriting JHN, or "Luke 5:1-11" stops being its own segment), the structural assertion
 * trips FIRST — making "the parser changed" a loud, attributable failure rather than a
 * silent change in what renders inline.
 *
 * Pure: no WordPress, no I/O, no Brain Monkey — {@see ReferenceParser} and
 * {@see DerivedExactClassifier} are both pure.
 */
final class DerivedExactInvariantTest extends TestCase {
    /**
     * The pinned liturgical corpus. Each entry is a real-shaped passage label plus the
     * EXACT segment structure the parser must yield, annotated per matched segment with:
     *   - `tuple`        : the structural 5-tuple [bookUSFM, cs, vs, ve, ce] (ints/null);
     *   - `continuation` : true when the book was CARRIED OVER from a prior segment (the
     *                      segment's own raw has no book at its head) — the refs the
     *                      invariant forbids from ever promoting.
     * Fallback segments declare only `status` + `raw` (they carry no ref).
     *
     * @return array<string,array{raw:string,segments:list<array<string,mixed>>}>
     */
    private function corpus(): array {
        return array(
            // Single in-chapter range — the canonical promotable shape (own book in own raw).
            'single in-chapter range' => array(
                'raw'      => 'Mark 9:2-9',
                'segments' => array(
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Mark 9:2-9',
                        'continuation' => false,
                        'tuple'        => array( 'MRK', 9, 2, 9, null ),
                    ),
                ),
            ),

            // Chapter-only — never inline-eligible (no verseStart); fails L1 by shape.
            'chapter-only' => array(
                'raw'      => 'John 9',
                'segments' => array(
                    array(
                        'status'       => 'matched',
                        'raw'          => 'John 9',
                        'continuation' => false,
                        'tuple'        => array( 'JHN', 9, null, null, null ),
                    ),
                ),
            ),

            // Two-segment lectionary bundle — BOTH segments keep their own book (the ';'
            // split does not produce a bookless continuation here), so both are clean,
            // isolated-identical in-chapter ranges.
            'two-segment lectionary bundle' => array(
                'raw'      => 'Isaiah 6:1-13; Luke 5:1-11',
                'segments' => array(
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Isaiah 6:1-13',
                        'continuation' => false,
                        'tuple'        => array( 'ISA', 6, 1, 13, null ),
                    ),
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Luke 5:1-11',
                        'continuation' => false,
                        'tuple'        => array( 'LUK', 5, 1, 11, null ),
                    ),
                ),
            ),

            // Three-segment lectionary bundle (the epiclesis shape) — comma-split, each
            // segment self-contained with its own book.
            'three-segment lectionary bundle' => array(
                'raw'      => 'Zeph 3:14-20, Isaiah 12:2-6, Phil 4:4-7',
                'segments' => array(
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Zeph 3:14-20',
                        'continuation' => false,
                        'tuple'        => array( 'ZEP', 3, 14, 20, null ),
                    ),
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Isaiah 12:2-6',
                        'continuation' => false,
                        'tuple'        => array( 'ISA', 12, 2, 6, null ),
                    ),
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Phil 4:4-7',
                        'continuation' => false,
                        'tuple'        => array( 'PHP', 4, 4, 7, null ),
                    ),
                ),
            ),

            // Cross-chapter range — chapterEnd set; never inline-eligible (fails L1 shape).
            'cross-chapter range' => array(
                'raw'      => 'Ephesians 4:25-5:2',
                'segments' => array(
                    array(
                        'status'       => 'matched',
                        'raw'          => 'Ephesians 4:25-5:2',
                        'continuation' => false,
                        'tuple'        => array( 'EPH', 4, 25, 2, 5 ),
                    ),
                ),
            ),

            // THE carry-over continuation: the bare "18" inherits JHN 3 in context, but its
            // own raw "18" is bookless → must NEVER promote (the load-bearing guarantee).
            'carry-over continuation' => array(
                'raw'      => 'John 3:16, 18',
                'segments' => array(
                    array(
                        'status'       => 'matched',
                        'raw'          => 'John 3:16',
                        'continuation' => false,
                        'tuple'        => array( 'JHN', 3, 16, null, null ),
                    ),
                    array(
                        'status'       => 'matched',
                        'raw'          => '18',
                        'continuation' => true,
                        'tuple'        => array( 'JHN', 3, 18, null, null ),
                    ),
                ),
            ),

            // Verse-letter tail — "5a" breaks the numeric grammar, so the WHOLE segment is a
            // fallback (no ref). Pinned so a future "letter-tail" parser feature can't start
            // silently emitting a ref that would then become inline-eligible.
            'verse-letter tail' => array(
                'raw'      => 'Micah 5:2-5a',
                'segments' => array(
                    array(
                        'status' => 'fallback',
                        'raw'    => 'Micah 5:2-5a',
                    ),
                ),
            ),
        );
    }

    /**
     * The parser must yield EXACTLY the pinned segment structure for every fixture. This is
     * the drift tripwire: any change to segment-splitting or carry-over attribution fails
     * HERE first, attributing the regression to the parser rather than to a quiet shift in
     * what renders inline.
     */
    public function test_parser_yields_the_pinned_segment_structure(): void {
        foreach ( $this->corpus() as $name => $fixture ) {
            $segments = ReferenceParser::parse( $fixture['raw'] )['segments'];

            $this->assertCount(
                count( $fixture['segments'] ),
                $segments,
                "[$name] segment count drifted for '{$fixture['raw']}'."
            );

            foreach ( $fixture['segments'] as $i => $expected ) {
                $actual = $segments[ $i ];

                $this->assertSame( $expected['status'], $actual['status'], "[$name] seg[$i] status drifted." );
                $this->assertSame( $expected['raw'], $actual['raw'], "[$name] seg[$i] raw drifted." );

                if ( 'matched' === $expected['status'] ) {
                    $this->assertCount( 1, $actual['refs'], "[$name] seg[$i] must carry exactly one ref." );
                    $this->assertSame(
                        $expected['tuple'],
                        $this->tuple( $actual['refs'][0] ),
                        "[$name] seg[$i] ref shape drifted."
                    );
                } else {
                    $this->assertSame( array(), $actual['refs'], "[$name] fallback seg[$i] must carry no ref." );
                }
            }
        }
    }

    /**
     * (A) NO carry-over continuation ref is EVER promoted — under STRICT, under PER-SEG, or
     * by the bare predicate. Its own raw, re-parsed alone, has no book → fallback → withheld.
     */
    public function test_no_carryover_continuation_is_ever_promoted(): void {
        $continuationCount = 0;

        foreach ( $this->corpus() as $name => $fixture ) {
            $segments = ReferenceParser::parse( $fixture['raw'] )['segments'];

            foreach ( $fixture['segments'] as $i => $expected ) {
                if ( 'matched' !== $expected['status'] || true !== ( $expected['continuation'] ?? false ) ) {
                    continue;
                }

                ++$continuationCount;
                $ref = $segments[ $i ]['refs'][0];

                $this->assertFalse(
                    DerivedExactClassifier::isDerivedExact( $ref ),
                    "[$name] seg[$i] is a carry-over continuation and must NOT be derived-exact."
                );

                // …and therefore promotes under NO floor, including the most permissive per-seg.
                foreach ( $this->floors() as $floor ) {
                    $this->assertFalse(
                        DerivedExactClassifier::promotes( $ref, $floor, count( $segments ) ),
                        "[$name] seg[$i] continuation must never promote under floor '$floor'."
                    );
                }

                // Pin the MECHANISM: re-parsing the continuation's own raw alone degrades to a
                // structure that is NOT a single matched single-ref identity equal to the
                // in-context shape (a fallback, or anything non-identical). This is the exact
                // behavior the safety rides on.
                $this->assertFalse(
                    $this->reparsesIdentically( $ref ),
                    "[$name] seg[$i] continuation must NOT re-parse identically in isolation."
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $continuationCount,
            'The corpus must contain at least one carry-over continuation, or it guards nothing.'
        );
    }

    /**
     * (B) EVERY promoted ref is structurally identical re-parsed IN ISOLATION vs IN CONTEXT,
     * and the corpus actually exercises promotion (a non-zero count) so the invariant is not
     * vacuously satisfied.
     */
    public function test_every_promoted_ref_is_isolated_identical_to_in_context(): void {
        $promotedCount = 0;

        foreach ( $this->corpus() as $name => $fixture ) {
            $segments = ReferenceParser::parse( $fixture['raw'] )['segments'];

            foreach ( $segments as $i => $segment ) {
                if ( 'matched' !== $segment['status'] ) {
                    continue;
                }

                $ref = $segment['refs'][0];
                if ( ! DerivedExactClassifier::isDerivedExact( $ref ) ) {
                    continue;
                }

                ++$promotedCount;

                // Re-parse the promoted ref's OWN raw in isolation and demand an exact
                // single-matched-single-ref structural identity to the in-context shape.
                $isolated = ReferenceParser::parse( (string) $ref['raw'] )['segments'];

                $this->assertCount( 1, $isolated, "[$name] seg[$i] promoted raw must isolate to one segment." );
                $this->assertSame( 'matched', $isolated[0]['status'], "[$name] seg[$i] promoted raw must isolate as matched." );
                $this->assertCount( 1, $isolated[0]['refs'], "[$name] seg[$i] promoted raw must isolate to one ref." );
                $this->assertSame(
                    $this->tuple( $ref ),
                    $this->tuple( $isolated[0]['refs'][0] ),
                    "[$name] seg[$i] promoted ref shape must be identical in isolation and in context."
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $promotedCount,
            'The corpus must promote at least one ref, or the invariant is vacuous.'
        );
    }

    /**
     * The three L2 floors, used to prove a continuation is withheld under EVERY one of them.
     *
     * @return list<string>
     */
    private function floors(): array {
        return array(
            DerivedExactClassifier::FLOOR_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
        );
    }

    /**
     * True only when the ref's own raw, re-parsed ALONE, is exactly one matched single ref
     * structurally identical to the ref itself — i.e. the classifier's re-parse-identity
     * mechanism, recomputed independently here so the test does not merely echo the
     * classifier's own answer.
     *
     * @param array<string,mixed> $ref
     */
    private function reparsesIdentically( array $ref ): bool {
        $raw = isset( $ref['raw'] ) && is_string( $ref['raw'] ) ? $ref['raw'] : '';
        if ( '' === $raw ) {
            return false;
        }

        $segments = ReferenceParser::parse( $raw )['segments'];
        if ( 1 !== count( $segments ) || 'matched' !== $segments[0]['status'] ) {
            return false;
        }

        if ( 1 !== count( $segments[0]['refs'] ) ) {
            return false;
        }

        return $this->tuple( $ref ) === $this->tuple( $segments[0]['refs'][0] );
    }

    /**
     * The structural 5-tuple [bookUSFM, chapterStart, verseStart, verseEnd, chapterEnd] of a
     * ref — the identity the invariant compares (raw/confidence/extra keys ignored).
     *
     * @param array<string,mixed> $ref
     *
     * @return array{0:string,1:?int,2:?int,3:?int,4:?int}
     */
    private function tuple( array $ref ): array {
        return array(
            isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '',
            $this->intOrNull( $ref['chapterStart'] ?? null ),
            $this->intOrNull( $ref['verseStart'] ?? null ),
            $this->intOrNull( $ref['verseEnd'] ?? null ),
            $this->intOrNull( $ref['chapterEnd'] ?? null ),
        );
    }

    /**
     * @param mixed $value
     */
    private function intOrNull( $value ): ?int {
        return is_int( $value ) ? $value : null;
    }
}
