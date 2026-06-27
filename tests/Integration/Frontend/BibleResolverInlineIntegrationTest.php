<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the Phase 3b (INLINE-mode) {@see BibleResolver}, driving
 * REAL `get_post_meta` + `get_option` + the `sermonator_bible_*` action hooks (no
 * Brain Monkey). NOT run in CI here (no Docker / wp-env in this environment) —
 * authored to run under wp-env later.
 *
 * The L8 chapter resolver is injected so these tests need NO vendored disk snapshot
 * (T8) nor warmed transient (T9) — the inline gating (L1–L7) and the typed-payload
 * slice are exercised against the real option/meta surface. ONE test deliberately
 * uses the REAL {@see \Sermonator\Frontend\Bible\ChapterProvider} (no injection) to
 * prove the off-render-path invariant end-to-end: a cold render falls open to the
 * link with ZERO network I/O.
 */
final class BibleResolverInlineIntegrationTest extends WP_UnitTestCase {
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

        // Axis A (link) = ESV; axis B (inline) defaults to ENGWEBP. Inline ON.
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, true );
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'exact' );
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false );
    }

    private function sermon(): int {
        return (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'Inline Scripture Sermon',
        ) );
    }

    /**
     * @param list<array<string,mixed>> $refs
     */
    private function storeRefs( int $id, array $refs ): void {
        update_post_meta( $id, ID::META_BIBLE_REFS, wp_json_encode( array( 'v' => 1, 'refs' => $refs ) ) );
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function exactRef( array $overrides = array() ): array {
        return array_merge( array(
            'bookUSFM'                   => 'JHN',
            'chapterStart'               => 3,
            'verseStart'                 => 16,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'John 3:16',
            'confidence'                 => 'exact',
            'srcVersification'           => 'ESV',
            'srcVersificationConfidence' => 'authored',
        ), $overrides );
    }

    /** A normalized chapter carrying John 3:16 with a renderable text node. */
    private function chapterWithVerse16(): array {
        return array(
            array( 'number' => 15, 'nodes' => array( array( 'type' => 'text', 'text' => 'whoever believes…' ) ) ),
            array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God so loved the world,' ) ) ),
            array( 'number' => 17, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God did not send…' ) ) ),
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

    public function test_exact_authored_ref_inlines_the_sliced_typed_payload(): void {
        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->exactRef() ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapterWithVerse16(), $calls ) );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $inline = $resolved->refs()[0]['inline'];

        $this->assertIsArray( $inline );
        $this->assertSame( 'ENGWEBP', $inline['translation'] );
        $this->assertSame( 'World English Bible', $inline['attribution'] );
        $this->assertSame( array( 16 ), array_column( $inline['verses'], 'number' ) );
        $this->assertSame( 'For God so loved the world,', $inline['verses'][0]['nodes'][0]['text'] );

        // Off-render-path: queried with warmContext === false.
        $this->assertSame( array( array( 'ENGWEBP', 'JHN', 3, false ) ), $calls );
        $this->assertSame( array(), $this->fallback );
        $this->assertSame( array( 'ESV' ), $this->resolved );
    }

    public function test_inline_disabled_is_byte_identical_3a_link(): void {
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, false );

        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->exactRef() ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapterWithVerse16(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        // No inline attempt: the chapter resolver is never consulted, no fallback fires.
        $this->assertSame( array(), $calls );
        $this->assertSame( array(), $this->fallback );
    }

    public function test_divergent_zone_ref_falls_open_observably(): void {
        $id = $this->sermon();
        $this->storeRefs( $id, array(
            $this->exactRef( array( 'bookUSFM' => 'ROM', 'chapterStart' => 16, 'verseStart' => 25, 'raw' => 'Romans 16:25' ) ),
        ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( $id, $this->chapterStub( $this->chapterWithVerse16(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'versification-divergent', $this->fallback[0]['reason'] );
        // L7 short-circuits before the render-time chapter read.
        $this->assertSame( array(), $calls );
    }

    public function test_site_default_ref_blocked_without_attestation_then_admitted(): void {
        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->exactRef( array( 'srcVersificationConfidence' => 'site-default' ) ) ) );

        $calls    = array();
        $blocked  = BibleResolver::resolve( $id, $this->chapterStub( $this->chapterWithVerse16(), $calls ) );
        $this->assertNull( $blocked->refs()[0]['inline'] );
        $this->assertSame( 'src-versification-unattested', $this->fallback[0]['reason'] );

        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $calls2   = array();
        $admitted = BibleResolver::resolve( $id, $this->chapterStub( $this->chapterWithVerse16(), $calls2 ) );
        $this->assertIsArray( $admitted->refs()[0]['inline'] );
    }

    public function test_cold_render_falls_open_with_zero_network_via_real_provider(): void {
        // No injected resolver -> the REAL ChapterProvider runs. Nothing is vendored or
        // warmed, so render context (warmContext false) reads disk + transient ONLY,
        // finds nothing, performs NO network call, and falls open to chapter-unavailable.
        $id = $this->sermon();
        $this->storeRefs( $id, array( $this->exactRef() ) );

        $resolved = BibleResolver::resolve( $id );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'chapter-unavailable', $this->fallback[0]['reason'] );
        // It still resolves as a 3a link.
        $this->assertSame( array( 'ESV' ), $this->resolved );
    }

    public function test_passage_meta_is_never_mutated_by_resolution(): void {
        $id = $this->sermon();
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 3:16' );
        $stored = wp_json_encode( array( 'v' => 1, 'refs' => array( $this->exactRef() ) ) );
        update_post_meta( $id, ID::META_BIBLE_REFS, $stored );

        $calls = array();
        BibleResolver::resolve( $id, $this->chapterStub( $this->chapterWithVerse16(), $calls ) );

        // #1 data preservation: neither the preserved passage label nor the envelope moved.
        $this->assertSame( 'John 3:16', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
        $this->assertSame( $stored, get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
    }
}
