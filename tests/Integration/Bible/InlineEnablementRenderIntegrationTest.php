<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

/**
 * TASK L — the wp-env INTEGRATION capstone for the whole Bible inline ENABLEMENT, render
 * side (design §3–§5 / spec §8 / T-L). Drives the REAL `get_post_meta` / `get_option` /
 * `sermonator_bible_*` hook stack through the full pipeline
 * {@see BibleResolver::resolve()} → {@see TemplateData::sermon()} → {@see Renderer::scripture()}
 * (no Brain Monkey), proving the end-to-end behavior the unit + per-task integration suites
 * only assert in isolation.
 *
 * !! NOT RUN IN THIS ENVIRONMENT — there is NO Docker / wp-env here. These are AUTHORED to
 *    run later under `npx @wordpress/env run tests-cli … --testsuite integration`. They are
 *    deliberately UNRUN at commit time (see the Task L instructions). !!
 *
 * Covered §8 render-side cases:
 *   (a) a multi-segment passage renders SOME refs inline + SOME as links under
 *       `derived-exact-perseg` (the liturgical carry-over case);
 *   (b) an ATTESTED but versification-DIVERGENT ref STILL falls open to a link — axis-1 is
 *       independent of the axis-2 promotion (SPINE: promotion can never surface a wrong verse);
 *   (d) ROLLBACK — flipping the floor back to `exact` OR disabling inline returns every sermon
 *       to BYTE-IDENTICAL 3a link HTML, with the stored meta byte-immutable (nothing to undo);
 *   (f) NO write-on-GET — the read-only corpus audit + would-promote preview persist nothing.
 *
 * The L8 chapter resolver is injected (a warm stub) so these need no vendored disk snapshot;
 * the inline ENABLEMENT axes (floor/attestation/enable) and the per-ref promotion are what is
 * under test, not the offline-chapter plumbing (covered by the ChapterProvider suites).
 */
final class InlineEnablementRenderIntegrationTest extends WP_UnitTestCase {
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

        // Axis A (link) = ESV; axis B (inline) defaults to ENGWEBP. Inline ON, attested so
        // site-default refs reach L2 — the enablement lever under test is the L2 floor.
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, true );
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );
        // The perseg ack defensively set so a boot-registered sanitize cannot floor the option
        // down to STRICT when this suite drives the option directly (the resolver itself never
        // reads the ack — it gates only the Settings-API sanitize).
        update_option( ID::OPTION_BIBLE_INLINE_PERSEG_ACK, true );
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        delete_option( ID::OPTION_BIBLE_INLINE_ENABLED );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTESTATION );
        delete_option( ID::OPTION_BIBLE_INLINE_PERSEG_ACK );
        delete_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
        delete_option( ID::OPTION_BIBLE_STATS );
        parent::tearDown();
    }

    // --- fixtures ------------------------------------------------------------

    private function sermon(): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'Inline Enablement Sermon',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 3:16, 18' );

        return $id;
    }

    /**
     * @param list<array<string,mixed>> $refs
     *
     * @return string The exact JSON stored (for the byte-immutability assertion).
     */
    private function storeRefs( int $id, array $refs ): string {
        $stored = (string) wp_json_encode( array( 'v' => 1, 'refs' => $refs ) );
        update_post_meta( $id, ID::META_BIBLE_REFS, $stored );

        return $stored;
    }

    /**
     * A lone, clean in-chapter `probable` ref whose own raw re-parses identically — the
     * render-time classifier's re-parse-identity predicate holds, so it PROMOTES.
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

    /** A chapter carrying John 3 verses 15–18 so a promoted ref clears L8/L9. */
    private function johnChapter3(): array {
        return array(
            array( 'number' => 15, 'nodes' => array( array( 'type' => 'text', 'text' => 'whoever believes…' ) ) ),
            array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God so loved the world,' ) ) ),
            array( 'number' => 17, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God did not send…' ) ) ),
            array( 'number' => 18, 'nodes' => array( array( 'type' => 'text', 'text' => 'Whoever believes is not condemned.' ) ) ),
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

    // --- (a) liturgical multi-segment some-inline / some-link -----------------

    public function test_perseg_multisegment_passage_inlines_some_refs_and_links_others(): void {
        // The liturgical carry-over case `John 3:16, 18`: TWO refs in ONE envelope under the
        // widest `derived-exact-perseg` floor —
        //   ref0 "John 3:16" : its own raw re-parses identically → PROMOTES → inline.
        //   ref1 "18"        : a bare carry-over continuation; re-parsed ALONE it has no book
        //                      at its head → fallback → re-parse-identity FAILS → stays
        //                      `probable` → falls open to the 3a link (the pinned per-ref
        //                      carry-over safety — promotion is per-ref by construction).
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );

        $id = $this->sermon();
        $this->storeRefs( $id, array(
            $this->probableRef(),
            $this->probableRef( array( 'verseStart' => 18, 'raw' => '18' ) ),
        ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->johnChapter3(), $calls ) );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $refs = $resolved->refs();
        $this->assertCount( 2, $refs );

        // SOME inline …
        $this->assertIsArray( $refs[0]['inline'], 'the lone clean segment promotes and inlines.' );
        $this->assertSame( array( 16 ), array_column( $refs[0]['inline']['verses'], 'number' ) );

        // … SOME link.
        $this->assertNull( $refs[1]['inline'], 'the carry-over continuation stays a 3a link.' );
        $this->assertContains(
            'low-confidence',
            array_column( $this->fallback, 'reason' ),
            'the un-promoted continuation falls open observably at L2.'
        );

        // End-to-end through the pure Renderer: the single rendered <ul> carries BOTH an
        // inline ref and a plain link ref (the mixed liturgical reading).
        $html = ( new Renderer() )->scripture( ( new TemplateData() )->sermon( $id ), $resolved );
        $this->assertSame( 1, substr_count( $html, 'sermonator-scripture__ref--inline' ) );
        $this->assertStringContainsString( 'sermonator-scripture__link', $html );
    }

    // --- (b) attested-but-divergent ref STILL links (axis-1 independent) ------

    public function test_attested_divergent_ref_still_falls_open_to_a_link_under_perseg(): void {
        // SPINE: Romans 16 is an enumerated English↔English divergent (renumber) zone. Even
        // with attestation ON and the WIDEST floor promoting the ref past L2, the UNCHANGED
        // axis-1 VersificationGate (L7) withholds it AFTER promotion — promotion only lets a
        // ref REACH the gate; it can never surface a wrong verse. The reason is the gate's
        // `versification-divergent`, NOT `low-confidence` (proving L2 promoted, L7 withheld).
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );

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
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->johnChapter3(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'versification-divergent', $this->fallback[0]['reason'] );
        // L7 short-circuits BEFORE any render-time chapter read.
        $this->assertSame( array(), $calls );

        // Rendered: a plain 3a link, never inline.
        $html = ( new Renderer() )->scripture( ( new TemplateData() )->sermon( $id ), $resolved );
        $this->assertStringContainsString( 'sermonator-scripture__link', $html );
        $this->assertStringNotContainsString( 'sermonator-scripture__ref--inline', $html );
    }

    // --- (d) ROLLBACK to byte-identical 3a links -----------------------------

    public function test_rollback_floor_exact_and_disable_are_byte_identical_to_3a_link(): void {
        // A promotable `probable` John 3:16 that DOES inline under perseg. Lowering the lever
        // back to `exact`, OR flipping the master switch off, must each return the sermon to the
        // SAME byte-for-byte 3a link HTML — with NOTHING in the stored envelope to undo.
        $id  = $this->sermon();
        $ref = $this->probableRef();
        $stored = $this->storeRefs( $id, array( $ref ) );

        // ON (perseg): the ref inlines.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );
        $callsOn = array();
        $on      = ( new Renderer() )->scripture(
            ( new TemplateData() )->sermon( $id ),
            BibleResolver::resolve( $id, $this->chapterStub( $this->johnChapter3(), $callsOn ) )
        );
        $this->assertStringContainsString( 'sermonator-scripture__ref--inline', $on );

        // ROLLBACK 1 — floor back to `exact`: promotion is impossible, the ref links.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'exact' );
        $callsFloor = array();
        $floorExact = ( new Renderer() )->scripture(
            ( new TemplateData() )->sermon( $id ),
            BibleResolver::resolve( $id, $this->chapterStub( $this->johnChapter3(), $callsFloor ) )
        );

        // ROLLBACK 2 — master switch OFF: inline is never attempted at all.
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, false );
        $callsOff = array();
        $disabled = ( new Renderer() )->scripture(
            ( new TemplateData() )->sermon( $id ),
            BibleResolver::resolve( $id, $this->chapterStub( $this->johnChapter3(), $callsOff ) )
        );

        // Both rollback paths are byte-identical to each other …
        $this->assertSame( $floorExact, $disabled, 'floor=exact and disabled render identical HTML.' );
        // … differ from the inline render …
        $this->assertNotSame( $on, $disabled );
        // … and are pure 3a links (no inline markup).
        $this->assertStringNotContainsString( 'sermonator-scripture__ref--inline', $disabled );
        $this->assertStringContainsString( 'sermonator-scripture__link', $disabled );

        // Disabling performs NO render-time chapter read (byte-identical, zero I/O).
        $this->assertSame( array(), $callsOff );

        // #1 data preservation: the stored envelope never moved through any of it.
        $this->assertSame( $stored, get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $this->assertSame( 'John 3:16, 18', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }

    // --- (f) no write-on-GET for the read-only audit / preview ---------------

    public function test_corpus_audit_and_promotion_preview_write_nothing_on_get(): void {
        // The read-only instruments an operator runs BEFORE flipping inline on must persist
        // nothing — neither the rollup option nor any reconciliation stamp (no write-on-GET).
        delete_option( ID::OPTION_BIBLE_STATS );

        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact-perseg' );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->probableRef() ) );

        $unusedCalls = array();
        $warm        = $this->chapterStub( $this->johnChapter3(), $unusedCalls );
        $audit = new CoverageAudit( null, $warm );

        // Both the inline corpus-gate report and the three-floor would-promote preview.
        $audit->inlineReport();
        $audit->promotionPreview( true, 5 );

        $this->assertFalse(
            get_option( ID::OPTION_BIBLE_STATS, false ),
            'The read-only audit/preview must not persist the corpus rollup.'
        );
        $this->assertFalse(
            get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, false ),
            'The read-only audit/preview must not stamp the reconciliation generation.'
        );
    }
}
