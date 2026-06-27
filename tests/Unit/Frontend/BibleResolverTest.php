<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Schema\Identifiers;

/**
 * Unit coverage for the Phase 3a (link-mode) {@see BibleResolver}.
 *
 * The two impure dependencies are stubbed: `get_post_meta` returns a stored
 * envelope, and `get_option` resolves the axis-A link version to a fixed
 * curated code (ESV) so the Bible Gateway URLs are deterministic.
 */
final class BibleResolverTest extends TestCase {
    /** @var list<array{ref:array<string,mixed>,version:string}> */
    private array $resolvedHook = array();

    /** @var list<array{passage:string,reason:string}> */
    private array $fallbackHook = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->resolvedHook = array();
        $this->fallbackHook = array();

        // Deterministic axis-A link version: ESV (a curated link version, so
        // TranslationRegistry returns it without touching the legacy seed).
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( Identifiers::OPTION_BIBLE_LINK_VERSION === $name ) {
                return 'ESV';
            }
            return $default;
        } );

        // apply_filters passes the value through untouched.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        // Capture observability hooks for assertions.
        Functions\when( 'do_action' )->alias( function ( $tag, ...$args ) {
            if ( 'sermonator_bible_resolved' === $tag ) {
                $this->resolvedHook[] = array( 'ref' => $args[0], 'version' => $args[1] );
            } elseif ( 'sermonator_bible_fallback' === $tag ) {
                $this->fallbackHook[] = array( 'passage' => $args[0], 'reason' => $args[1] );
            }
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubMeta( string $stored ): void {
        Functions\when( 'get_post_meta' )->alias( function ( $postId, $key, $single = false ) use ( $stored ) {
            return Identifiers::META_BIBLE_REFS === $key ? $stored : '';
        } );
    }

    /**
     * @param list<array<string,mixed>> $refs
     */
    private function envelope( array $refs ): string {
        return (string) json_encode( array( 'v' => 1, 'refs' => $refs ) );
    }

    public function test_returns_null_when_meta_absent(): void {
        $this->stubMeta( '' );
        $this->assertNull( BibleResolver::resolve( 123 ) );
    }

    public function test_returns_null_when_envelope_has_no_refs(): void {
        $this->stubMeta( $this->envelope( array() ) );
        $this->assertNull( BibleResolver::resolve( 123 ) );
    }

    public function test_returns_null_on_corrupt_json(): void {
        $this->stubMeta( '{not valid json' );
        $this->assertNull( BibleResolver::resolve( 123 ) );
    }

    public function test_resolves_single_verse_label_and_url(): void {
        $this->stubMeta( $this->envelope( array(
            array(
                'bookUSFM'     => 'JHN',
                'chapterStart' => 3,
                'verseStart'   => 16,
                'verseEnd'     => null,
                'chapterEnd'   => null,
                'raw'          => 'John 3:16',
            ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $refs = $resolved->refs();
        $this->assertCount( 1, $refs );
        $this->assertSame( 'John 3:16', $refs[0]['label'] );
        $this->assertSame( 'ESV', $refs[0]['version'] );
        $this->assertTrue( $refs[0]['inlineEligible'] );
        $this->assertSame(
            'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV',
            $refs[0]['linkUrl']
        );
    }

    public function test_label_variants_cover_chapter_range_and_cross_chapter(): void {
        $this->stubMeta( $this->envelope( array(
            // whole chapter
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => null, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3' ),
            // verse range
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 18, 'chapterEnd' => null, 'raw' => 'John 3:16-18' ),
            // chapter range
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 5, 'verseStart' => null, 'verseEnd' => null, 'chapterEnd' => 7, 'raw' => 'Matthew 5-7' ),
            // cross-chapter verse range
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 5, 'verseStart' => 1, 'verseEnd' => 29, 'chapterEnd' => 7, 'raw' => 'Matthew 5:1-7:29' ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );
        $this->assertInstanceOf( ResolvedScripture::class, $resolved );

        $labels = array_column( $resolved->refs(), 'label' );
        $this->assertSame(
            array( 'John 3', 'John 3:16-18', 'Matthew 5-7', 'Matthew 5:1-7:29' ),
            $labels
        );
    }

    public function test_skips_out_of_canon_ref_and_fires_fallback(): void {
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Hesitations 1:1' ),
            array( 'bookUSFM' => 'GEN', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Genesis 1:1' ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );
        $this->assertInstanceOf( ResolvedScripture::class, $resolved );

        // Only the in-canon ref survives.
        $refs = $resolved->refs();
        $this->assertCount( 1, $refs );
        $this->assertSame( 'Genesis 1:1', $refs[0]['label'] );

        // The out-of-canon ref fired a fallback with its raw passage.
        $this->assertCount( 1, $this->fallbackHook );
        $this->assertSame( 'Hesitations 1:1', $this->fallbackHook[0]['passage'] );
        $this->assertSame( 'not-in-canon', $this->fallbackHook[0]['reason'] );
    }

    public function test_returns_null_when_every_ref_is_skipped(): void {
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Nope 1:1' ),
        ) ) );

        $this->assertNull( BibleResolver::resolve( 123 ) );
        $this->assertCount( 1, $this->fallbackHook );
    }

    public function test_divergent_zone_ref_is_link_but_not_inline_eligible(): void {
        // Psalms is wholly versification-divergent -> link-only (inlineEligible false).
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'PSA', 'chapterStart' => 23, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Psalm 23:1' ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );
        $this->assertInstanceOf( ResolvedScripture::class, $resolved );

        $refs = $resolved->refs();
        $this->assertSame( 'Psalms 23:1', $refs[0]['label'] );
        $this->assertFalse( $refs[0]['inlineEligible'] );
        // Still resolved (rendered as a link in 3a).
        $this->assertCount( 1, $this->resolvedHook );
    }

    public function test_fires_resolved_hook_per_ref_with_version(): void {
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
            array( 'bookUSFM' => 'ROM', 'chapterStart' => 8, 'verseStart' => 28, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Romans 8:28' ),
        ) ) );

        BibleResolver::resolve( 123 );

        $this->assertCount( 2, $this->resolvedHook );
        $this->assertSame( 'ESV', $this->resolvedHook[0]['version'] );
        $this->assertSame( 'JHN', $this->resolvedHook[0]['ref']['bookUSFM'] );
    }

    // ---------------------------------------------------------------------------
    // Phase 3b — the inline path (L1–L9, per-ref fail-open to the link).
    // ---------------------------------------------------------------------------

    /**
     * Re-stub get_option to drive the 3b inline options on top of the axis-A link
     * version (still ESV). Any option not in the map returns its caller default — so an
     * absent OPTION_BIBLE_INLINE_ENABLED reads false (the 3a default).
     *
     * @param array<string,mixed> $opts
     */
    private function stubOptions( array $opts ): void {
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $opts ) {
            if ( Identifiers::OPTION_BIBLE_LINK_VERSION === $name ) {
                return 'ESV';
            }
            return array_key_exists( $name, $opts ) ? $opts[ $name ] : $default;
        } );
    }

    /**
     * Turn inline rendering ON with a chosen confidence floor and attestation state.
     *
     * @param array<string,mixed> $extra
     */
    private function enableInline( string $floor = 'exact', bool $attested = false, array $extra = array() ): void {
        $this->stubOptions( array_merge( array(
            Identifiers::OPTION_BIBLE_INLINE_ENABLED          => true,
            Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR => $floor,
            Identifiers::OPTION_BIBLE_INLINE_ATTESTATION      => $attested,
        ), $extra ) );
    }

    /**
     * A render-context chapter resolver spy: records every (translation,book,chapter,
     * warmContext) call and returns a canned chapter. Proves the off-render-path
     * invariant (warmContext is ALWAYS false).
     *
     * @param list<array{number:int,nodes:list<array{type:string,text:string}>}>|null $chapter
     *
     * @return callable(string,string,int,bool):(array<int,mixed>|null)
     */
    private function chapterSpy( ?array $chapter, array &$calls ): callable {
        return function ( $translation, $book, $chapterNum, $warmContext ) use ( $chapter, &$calls ) {
            $calls[] = array( $translation, $book, $chapterNum, $warmContext );
            return $chapter;
        };
    }

    /**
     * A single author-confirmed (exact, authored, ESV-sourced) inline-shaped ref —
     * John 3:16 — that clears L1–L7 so the later layers (or the option gate) are what's
     * under test.
     *
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

    /** A normalized chapter containing John 3:16 with a renderable text node. */
    private function chapterWithVerse16(): array {
        return array(
            array( 'number' => 15, 'nodes' => array( array( 'type' => 'text', 'text' => 'whoever believes…' ) ) ),
            array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God so loved the world,' ) ) ),
            array( 'number' => 17, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God did not send…' ) ) ),
        );
    }

    public function test_inline_disabled_falls_open_to_link_with_no_fallback(): void {
        // OPTION_BIBLE_INLINE_ENABLED unset -> false: pure 3a, byte-identical.
        $this->stubOptions( array() );
        $this->stubMeta( $this->envelope( array( $this->exactRef() ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $refs = $resolved->refs();
        $this->assertNull( $refs[0]['inline'] );
        // No inline attempt at all -> the chapter resolver is never even consulted.
        $this->assertSame( array(), $calls );
        // Disabled is NOT a fall-open: it must not fire the observability hook.
        $this->assertCount( 0, $this->fallbackHook );
        $this->assertCount( 1, $this->resolvedHook );
    }

    public function test_inline_success_builds_typed_payload_off_render_path(): void {
        $this->enableInline();
        $this->stubMeta( $this->envelope( array( $this->exactRef() ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $inline = $resolved->refs()[0]['inline'];

        $this->assertIsArray( $inline );
        $this->assertSame( 'ENGWEBP', $inline['translation'] );
        $this->assertSame( 'World English Bible', $inline['attribution'] );
        // ONLY verse 16 is sliced out of the chapter (not 15 / 17).
        $this->assertCount( 1, $inline['verses'] );
        $this->assertSame( 16, $inline['verses'][0]['number'] );
        $this->assertSame( 'text', $inline['verses'][0]['nodes'][0]['type'] );
        $this->assertSame( 'For God so loved the world,', $inline['verses'][0]['nodes'][0]['text'] );

        // OFF-RENDER-PATH proof: the chapter was read with warmContext === false.
        $this->assertCount( 1, $calls );
        $this->assertSame( array( 'ENGWEBP', 'JHN', 3, false ), $calls[0] );
        // A successful inline still resolves (and fires NO fallback).
        $this->assertCount( 0, $this->fallbackHook );
        $this->assertCount( 1, $this->resolvedHook );
    }

    public function test_inline_verse_range_slices_full_span(): void {
        $this->enableInline();
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'verseStart' => 16, 'verseEnd' => 17, 'raw' => 'John 3:16-17' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $inline = $resolved->refs()[0]['inline'];
        $this->assertIsArray( $inline );
        $this->assertSame( array( 16, 17 ), array_column( $inline['verses'], 'number' ) );
    }

    public function test_inline_low_confidence_falls_open(): void {
        // Floor is exact; a `probable` ref must fall open with reason low-confidence.
        $this->enableInline( 'exact' );
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'confidence' => 'probable' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'low-confidence', $this->fallbackHook[0]['reason'] );
        // L2 fails BEFORE L8 -> the chapter resolver is never reached.
        $this->assertSame( array(), $calls );
    }

    public function test_inline_widened_floor_admits_probable(): void {
        // Admin widened the floor to `probable`: the same ref now inlines.
        $this->enableInline( 'probable' );
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'confidence' => 'probable' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertIsArray( $resolved->refs()[0]['inline'] );
        $this->assertCount( 0, $this->fallbackHook );
    }

    public function test_inline_translation_ineligible_falls_open(): void {
        // Force the inline target to ENGKJV (inline-INELIGIBLE) via the trusted filter.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return 'sermonator_bible_translation' === $tag ? 'ENGKJV' : $value;
        } );
        $this->enableInline();
        $this->stubMeta( $this->envelope( array( $this->exactRef() ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'translation-ineligible', $this->fallbackHook[0]['reason'] );
        $this->assertSame( array(), $calls );
    }

    public function test_inline_src_versification_unsupported_falls_open(): void {
        // A Spanish Reina-Valera source normalizes to NO modeled family (L4).
        $this->enableInline();
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'srcVersification' => 'RVR1960' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );
        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'src-versification-unsupported', $this->fallbackHook[0]['reason'] );
    }

    public function test_inline_unattested_site_default_falls_open(): void {
        // A site-default-provenance ref (no `authored` stamp) with attestation OFF (L6).
        $this->enableInline( 'exact', false );
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'srcVersificationConfidence' => 'site-default' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );
        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'src-versification-unattested', $this->fallbackHook[0]['reason'] );
    }

    public function test_inline_attestation_admits_site_default(): void {
        // Same ref, attestation ON -> L6 passes and it inlines.
        $this->enableInline( 'exact', true );
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'srcVersificationConfidence' => 'site-default' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );
        $this->assertIsArray( $resolved->refs()[0]['inline'] );
        $this->assertCount( 0, $this->fallbackHook );
    }

    public function test_inline_versification_divergent_zone_falls_open(): void {
        // Romans 16 is an enumerated English↔English renumber zone (L7).
        $this->enableInline();
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array(
                'bookUSFM'     => 'ROM',
                'chapterStart' => 16,
                'verseStart'   => 25,
                'raw'          => 'Romans 16:25',
            ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'versification-divergent', $this->fallbackHook[0]['reason'] );
        // L7 fails BEFORE the render-time chapter read.
        $this->assertSame( array(), $calls );
    }

    public function test_inline_chapter_unavailable_falls_open(): void {
        // Cleared L1–L7, but the chapter is not warmed/vendored (L8) -> null chapter.
        $this->enableInline();
        $this->stubMeta( $this->envelope( array( $this->exactRef() ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( null, $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'chapter-unavailable', $this->fallbackHook[0]['reason'] );
        // Still queried OFF the render path (warmContext false), and only once.
        $this->assertSame( array( array( 'ENGWEBP', 'JHN', 3, false ) ), $calls );
    }

    public function test_inline_verse_out_of_range_fails_whole_ref_open(): void {
        // The chapter is present but lacks verse 16 (a critical-text gap) -> L9 fails.
        $this->enableInline();
        $this->stubMeta( $this->envelope( array( $this->exactRef() ) ) );

        $shortChapter = array(
            array( 'number' => 14, 'nodes' => array( array( 'type' => 'text', 'text' => '…' ) ) ),
            array( 'number' => 15, 'nodes' => array( array( 'type' => 'text', 'text' => '…' ) ) ),
        );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $shortChapter, $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'verse-out-of-range', $this->fallbackHook[0]['reason'] );
    }

    public function test_inline_chapter_only_ref_is_not_inline_shaped(): void {
        // L1: a whole-chapter cite (no verseStart) can never inline.
        $this->enableInline();
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => null, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3', 'confidence' => 'exact' ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( $this->chapterWithVerse16(), $calls ) );

        $this->assertNull( $resolved->refs()[0]['inline'] );
        $this->assertSame( 'not-inline-eligible', $this->fallbackHook[0]['reason'] );
        $this->assertSame( array(), $calls );
    }

    public function test_inline_fell_open_ref_still_resolves_as_a_link(): void {
        // A fall-open ref fires BOTH fallback (the reason) AND resolved (it is a link).
        $this->enableInline();
        $this->stubMeta( $this->envelope( array(
            $this->exactRef( array( 'srcVersification' => 'RVR1960' ) ),
        ) ) );

        $calls    = array();
        $resolved = BibleResolver::resolve( 123, $this->chapterSpy( null, $calls ) );
        $ref      = $resolved->refs()[0];

        $this->assertNull( $ref['inline'] );
        $this->assertSame( 'John 3:16', $ref['label'] );
        $this->assertSame( 'ESV', $ref['version'] );
        $this->assertCount( 1, $this->fallbackHook );
        $this->assertCount( 1, $this->resolvedHook );
    }
}
