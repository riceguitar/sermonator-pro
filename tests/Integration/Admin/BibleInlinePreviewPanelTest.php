<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\BibleInlinePreviewPanel;
use Sermonator\Admin\SettingsPage;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier as DEC;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the READ-ONLY live inline audit-preview panel
 * ({@see BibleInlinePreviewPanel}, design §4 / spec T-H). NOT run in this environment
 * (no Docker / wp-env) — authored to run under wp-env later, like its sibling Admin/Bible
 * integration tests.
 *
 * Drives the real `get_post_meta` / `get_option` / `WP_Query` stack (no Brain Monkey) to
 * prove the panel: renders the three-floor would-promote + inline-eligible numbers over the
 * operator's own corpus, surfaces the explicit "0% until you attest" state when attestation is
 * off under a pending derived floor, hard-disables the attestation checkbox on a heterogeneous
 * corpus, carries the verbatim theological claim, and — the #1 standard — WRITES NOTHING on
 * render (no write-on-GET).
 *
 * ## The chapter oracle
 *
 * Chapters are NOT vendored under wp-env, so the L8 offline read misses and an L2-cleared ref
 * lands in `cold-unwarmed` rather than becoming inline-eligible. To exercise the eligible%
 * numbers the panel displays, the injected preview provider wraps a {@see CoverageAudit} given a
 * WARM chapter resolver (its 2nd constructor arg), so a promoted ref clears L8/L9 and counts as
 * inline-eligible — the same trick {@see \Sermonator\Tests\Integration\Bible\CoveragePromotionPreviewTest}
 * uses.
 */
final class BibleInlinePreviewPanelTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_BIBLE_STATS );
        delete_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTESTATION );
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_BIBLE_STATS );
        delete_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTESTATION );
        parent::tearDown();
    }

    /** @param array<string,mixed> $overrides */
    private function ref( int $verseStart, string $raw, string $confidence, array $overrides = array() ): array {
        return array_merge( array(
            'bookUSFM'         => 'JHN',
            'chapterStart'     => 3,
            'verseStart'       => $verseStart,
            'verseEnd'         => null,
            'chapterEnd'       => null,
            'raw'              => $raw,
            'confidence'       => $confidence,
            'srcVersification' => 'ESV',
        ), $overrides );
    }

    /** @param list<array<string,mixed>> $refs */
    private function sermonWithRefs( array $refs ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'preview sermon',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'passage' );
        update_post_meta( $id, ID::META_BIBLE_REFS, (string) wp_json_encode( array( 'v' => 1, 'refs' => $refs ) ) );

        return $id;
    }

    /**
     * A chapter resolver reporting every requested chapter as carrying verses 1..40, so a
     * promoted ref clears L8/L9 and becomes inline-eligible.
     *
     * @return callable(string,string,int,bool):array<int,mixed>
     */
    private function warmChapter(): callable {
        return static function ( $t, $b, $c, $w ): array {
            $verses = array();
            for ( $n = 1; $n <= 40; $n++ ) {
                $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => 'word' ) ) );
            }
            return $verses;
        };
    }

    /** A panel whose preview oracle wraps a warm-chapter CoverageAudit (eligible numbers visible). */
    private function warmPanel(): BibleInlinePreviewPanel {
        $warm = $this->warmChapter();

        return new BibleInlinePreviewPanel(
            static fn( bool $assumeAttested ): array => ( new CoverageAudit( null, $warm ) )->promotionPreview( $assumeAttested )
        );
    }

    private function renderPreview( BibleInlinePreviewPanel $panel ): string {
        ob_start();
        $panel->renderPreview();
        return (string) ob_get_clean();
    }

    private function renderAttestationField( BibleInlinePreviewPanel $panel ): string {
        ob_start();
        $panel->renderAttestationField();
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Three-floor numbers
    // -------------------------------------------------------------------------

    public function test_preview_renders_the_three_floor_numbers(): void {
        // Attestation ON so the table is the live recall (no "0% until attest" overlay).
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        // Lone clean probable (promotes strict + perseg) + a compound (promotes perseg only).
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );
        $this->sermonWithRefs( array(
            $this->ref( 16, 'John 3:16', 'probable' ),
            $this->ref( 17, 'John 3:17', 'probable' ),
        ) );

        $html = $this->renderPreview( $this->warmPanel() );

        // All three floor labels present.
        $this->assertStringContainsString( 'Exact (author-confirmed only)', $html );
        $this->assertStringContainsString( 'Derived-exact (strict, single-segment)', $html );
        $this->assertStringContainsString( 'Derived-exact per-reference', $html );

        // would-promote: exact 0, strict 1, perseg 3 — and the eligible% extremes (0% / 100%).
        $this->assertMatchesRegularExpression( '/Exact \(author-confirmed only\).*?<td>0<\/td>/s', $html );
        $this->assertStringContainsString( '3 / 3', $html, 'perseg eligible count over the 3-ref corpus.' );
        $this->assertStringContainsString( '100%', $html, 'perseg reaches 100% inline-eligible.' );
        $this->assertStringContainsString( '0%', $html, 'exact promotes nothing — 0% inline-eligible.' );
    }

    // -------------------------------------------------------------------------
    // The "0% until you attest" state
    // -------------------------------------------------------------------------

    public function test_preview_shows_zero_until_attest_when_off_and_derived_floor_pending(): void {
        // Attestation OFF (default) + a pending DERIVED floor → the explicit state.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, DEC::FLOOR_DERIVED_EXACT );

        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $html = $this->renderPreview( $this->warmPanel() );

        $this->assertStringContainsString( '0% inline until you attest.', $html );
        $this->assertStringContainsString( 'withheld at the single-tradition gate', $html );
    }

    public function test_preview_omits_zero_until_attest_when_attested(): void {
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, DEC::FLOOR_DERIVED_EXACT );

        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $html = $this->renderPreview( $this->warmPanel() );

        $this->assertStringNotContainsString( '0% inline until you attest.', $html );
    }

    public function test_preview_handles_empty_corpus_without_table(): void {
        $html = $this->renderPreview( new BibleInlinePreviewPanel() );

        $this->assertStringContainsString( 'nothing to preview yet', $html );
        $this->assertStringNotContainsString( 'sermonator-bible-inline-floors', $html );
    }

    // -------------------------------------------------------------------------
    // Canaries
    // -------------------------------------------------------------------------

    public function test_preview_fires_heterogeneous_canary_on_mixed_corpus(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'ESV' ) ) ) );
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'RVR1960' ) ) ) );

        $html = $this->renderPreview( $this->warmPanel() );

        $this->assertStringContainsString( 'mixes more than one source-versification tradition', $html );
    }

    // -------------------------------------------------------------------------
    // Attestation checkbox (verbatim copy + heterogeneity hard-disable)
    // -------------------------------------------------------------------------

    public function test_attestation_field_carries_verbatim_claim(): void {
        $html = $this->renderAttestationField( new BibleInlinePreviewPanel() );

        $this->assertStringContainsString( 'ESV/NIV/NASB/NKJV/KJV/WEB number identically', $html );
        $this->assertStringContainsString( 'do NOT attest', $html );
        $this->assertStringContainsString( 'Septuagint/Vulgate/Catholic-canon-Psalm-numbered', $html );

        // The control posts to the SettingsRegistrar-owned option with a hidden companion.
        $this->assertStringContainsString( 'name="' . ID::OPTION_BIBLE_INLINE_ATTESTATION . '" value="1"', $html );
        $this->assertStringContainsString( '<input type="hidden" name="' . ID::OPTION_BIBLE_INLINE_ATTESTATION . '" value="0">', $html );
    }

    public function test_attestation_checkbox_reflects_stored_state(): void {
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $html = $this->renderAttestationField( new BibleInlinePreviewPanel() );

        $this->assertMatchesRegularExpression( '/name="' . preg_quote( ID::OPTION_BIBLE_INLINE_ATTESTATION, '/' ) . '" value="1"[^>]*checked/', $html );
    }

    public function test_attestation_checkbox_hard_disabled_on_heterogeneous_corpus(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'ESV' ) ) ) );
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'RVR1960' ) ) ) );

        $html = $this->renderAttestationField( $this->warmPanel() );

        $this->assertMatchesRegularExpression( '/type="checkbox"[^>]*disabled/', $html );
        // Hidden companion suppressed when disabled, so an unrelated save can't auto-clear.
        $this->assertStringNotContainsString( '<input type="hidden" name="' . ID::OPTION_BIBLE_INLINE_ATTESTATION . '" value="0">', $html );
        $this->assertStringContainsString( 'Attestation disabled:', $html );
    }

    // -------------------------------------------------------------------------
    // READ-ONLY (no write-on-GET / no write-on-render)
    // -------------------------------------------------------------------------

    public function test_render_writes_nothing(): void {
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, DEC::FLOOR_DERIVED_EXACT_PERSEG );
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $panel = $this->warmPanel();
        $this->renderPreview( $panel );
        $this->renderAttestationField( $panel );

        // No write-on-GET: the read-only panel must not persist the corpus rollup.
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
        // And it must not have stamped the reconciliation generation (an enable-time write).
        $this->assertFalse( get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, false ) );
    }

    public function test_render_through_settings_page_emits_preview_and_attestation(): void {
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $adminId = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $adminId );

        // Inject the warm-chapter panel so the page's preview field shows eligible numbers.
        $page = new SettingsPage( null, null, $this->warmPanel() );
        $page->registerSections();

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        wp_set_current_user( 0 );

        $this->assertStringContainsString( 'Inline scripture — live coverage preview', $html );
        $this->assertStringContainsString( 'ESV/NIV/NASB/NKJV/KJV/WEB number identically', $html );
        // No write-on-GET through the full page render either.
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
    }
}
