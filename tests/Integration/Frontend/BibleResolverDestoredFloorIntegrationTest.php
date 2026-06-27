<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for TASK B — the **de-stored** L2 confidence tier + render-time
 * promotion in {@see BibleResolver}, driving REAL `get_post_meta` + `get_option` + the
 * `sermonator_bible_*` hooks (no Brain Monkey). NOT run in CI here (no Docker / wp-env
 * in this environment) — authored to run under wp-env later.
 *
 * What it pins (design §1–§3.4):
 *   - the stored-confidence vocabulary `{exact,probable,ambiguous}` and the floor
 *     vocabulary `{exact,derived-exact,derived-exact-perseg}` are DISJOINT;
 *   - promotion is computed at RENDER TIME by the shared classifier — NEVER a stored
 *     re-stamp — so a stored `probable` ref whose raw re-parses identically is PROMOTED
 *     and inlines under a `derived-exact*` floor, but a SMUGGLED pre-stamped
 *     `confidence:derived-exact` clears NOTHING;
 *   - the per-post envelope ref count threads correctly — STRICT keeps a compound
 *     passage's clean segments DARK; `derived-exact-perseg` promotes them;
 *   - NO meta is written by any of it (#1 data preservation);
 *   - the axis-1 VersificationGate (L4–L7) still withholds a promoted-but-divergent ref.
 *
 * The L8 chapter resolver is injected so these need no vendored/warmed snapshot — the L2
 * de-store + the singleton constraint are what is under test.
 */
final class BibleResolverDestoredFloorIntegrationTest extends WP_UnitTestCase {
    /** @var list<array{passage:string,reason:string}> */
    private array $fallback = array();

    /** @var list<string> */
    private array $resolved = array();

    protected function setUp(): void {
        parent::setUp();

        $this->fallback = array();
        $this->resolved = array();

        add_action( 'sermonator_bible_fallback', function ( $passage, $reason ): void {
            $this->fallback[] = array( 'passage' => (string) $passage, 'reason' => (string) $reason );
        }, 10, 2 );

        add_action( 'sermonator_bible_resolved', function ( $ref, $version ): void {
            $this->resolved[] = (string) $version;
        }, 10, 2 );

        // Axis A (link) = ESV; axis B (inline) defaults to ENGWEBP. Inline ON, attested
        // (the de-store gate is the focus; L6 is satisfied so authored refs reach L2).
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, true );
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );
    }

    private function sermon(): int {
        return (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'De-stored Floor Sermon',
        ) );
    }

    /**
     * @param list<array<string,mixed>> $refs
     */
    private function storeRefs( int $id, array $refs ): string {
        $stored = wp_json_encode( array( 'v' => 1, 'refs' => $refs ) );
        update_post_meta( $id, ID::META_BIBLE_REFS, $stored );

        return (string) $stored;
    }

    /**
     * A lone in-chapter ref whose raw re-parses identically (so the classifier's
     * re-parse-identity predicate holds). Defaults to `probable` — the only promotable
     * stored tier.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function probableRef( array $overrides = array() ): array {
        return array_merge( array(
            'bookUSFM'                   => 'JHN',
            'chapterStart'               => 3,
            'verseStart'                 => 16,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'John 3:16',
            'confidence'                 => 'probable',
            'srcVersification'           => 'ESV',
            'srcVersificationConfidence' => 'authored',
        ), $overrides );
    }

    private function romansRef( array $overrides = array() ): array {
        return array_merge( array(
            'bookUSFM'                   => 'ROM',
            'chapterStart'               => 8,
            'verseStart'                 => 28,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'Romans 8:28',
            'confidence'                 => 'probable',
            'srcVersification'           => 'ESV',
            'srcVersificationConfidence' => 'authored',
        ), $overrides );
    }

    /** Chapter carrying both John 3:16 and Romans 8:28 (same stub for every book/chapter). */
    private function chapter(): array {
        return array(
            array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God so loved the world,' ) ) ),
            array( 'number' => 28, 'nodes' => array( array( 'type' => 'text', 'text' => 'And we know…' ) ) ),
        );
    }

    /**
     * @param list<array{number:int,nodes:list<array{type:string,text:string}>}>|null $chapter
     */
    private function chapterStub( ?array $chapter, array &$calls ): callable {
        return function ( $translation, $book, $chapterNum, $warmContext ) use ( $chapter, &$calls ) {
            $calls[] = array( $translation, $book, $chapterNum, $warmContext );
            return $chapter;
        };
    }

    public function test_lone_probable_is_promoted_and_inlines_under_strict_floor(): void {
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef() ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $this->assertIsArray( $resolved->refs()[0]['inline'] );
        $this->assertSame( array( 16 ), array_column( $resolved->refs()[0]['inline']['verses'], 'number' ) );
        $this->assertSame( array(), $this->fallback );
    }

    public function test_prestamped_derived_exact_confidence_clears_nothing(): void {
        // De-store enforcement: a SMUGGLED stored `derived-exact` is not a recognized tier.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef( array( 'confidence' => 'derived-exact' ) ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'low-confidence', $this->fallback[0]['reason'] );
        // L2 fails before the render-time chapter read.
        $this->assertSame( array(), $calls );
    }

    public function test_strict_floor_keeps_compound_segments_dark(): void {
        // Two individually-clean `probable` segments: refCount===2, so STRICT promotes
        // NEITHER (the corpus-independent compound-stays-dark guarantee).
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef(), $this->romansRef() ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        foreach ( $resolved->refs() as $ref ) {
            $this->assertNull( $ref['inline'] );
        }
        $this->assertSame( array(), $calls );
    }

    public function test_perseg_floor_promotes_the_same_compound_segments(): void {
        // The SAME 2-ref compound, now under `derived-exact-perseg`: both promote per
        // segment (sibling count is ignored by perseg) and inline.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef(), $this->romansRef() ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        foreach ( $resolved->refs() as $ref ) {
            $this->assertIsArray( $ref['inline'] );
        }
        $this->assertSame( array(), $this->fallback );
    }

    public function test_exact_stored_ref_clears_every_floor(): void {
        // `exact` is the top stored tier — it clears even the perseg floor outright,
        // independent of the classifier.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef( array( 'confidence' => 'exact' ) ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        $this->assertIsArray( $resolved->refs()[0]['inline'] );
        $this->assertSame( array(), $this->fallback );
    }

    public function test_promotion_does_not_let_a_divergent_ref_render(): void {
        // SPINE: a promoted `probable` Romans 16:25 (an enumerated English↔English renumber
        // zone) is still WITHHELD by the unchanged axis-1 VersificationGate (L7) AFTER
        // promotion — promotion can never surface a wrong verse.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );

        $id = $this->sermon();
        $this->storeRefs( $id, array(
            $this->probableRef( array(
                'bookUSFM'     => 'ROM',
                'chapterStart' => 16,
                'verseStart'   => 25,
                'verseEnd'     => null,
                'raw'          => 'Romans 16:25',
            ) ),
        ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        // The gate's reason — NOT low-confidence: L2 promoted, L7 then withheld.
        $this->assertSame( 'versification-divergent', $this->fallback[0]['reason'] );
        $this->assertSame( array(), $calls );
    }

    public function test_ambiguous_stored_ref_never_clears(): void {
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef( array( 'confidence' => 'ambiguous' ) ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'low-confidence', $this->fallback[0]['reason'] );
        $this->assertSame( array(), $calls );
    }

    public function test_render_time_promotion_writes_no_meta(): void {
        // #1 data preservation: promotion is render-time only — the stored envelope is
        // byte-immutable across resolution (no re-stamp to `derived-exact`).
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );

        $id     = $this->sermon();
        $stored = $this->storeRefs( $id, array( $this->probableRef() ) );

        $calls = array();
        BibleResolver::resolve( $id, $this->chapterStub( $this->chapter(), $calls ) );

        $this->assertSame( $stored, get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
    }
}
