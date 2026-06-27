<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\BibleInlinePreviewPanel;
use Sermonator\Admin\SettingsRegistrar;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Migration\BibleChapterVendor;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers as ID;

/**
 * TASK L — the wp-env INTEGRATION capstone for the whole Bible inline ENABLEMENT, ADMIN
 * GATE side (design §3–§5 / spec §8 / T-L). Drives the REAL WordPress settings/options stack
 * (the registered `sanitize_callback`s run on `update_option`, exactly as `options.php` would)
 * so the §5 enablement flow is exercised through its genuine gates rather than in isolation.
 *
 * !! NOT RUN IN THIS ENVIRONMENT — there is NO Docker / wp-env here. These are AUTHORED to
 *    run later under `npx @wordpress/env run tests-cli … --testsuite integration`. They are
 *    deliberately UNRUN at commit time (see the Task L instructions). !!
 *
 * Covered §8 gate-side cases:
 *   (c) ENABLE-GATING — enable is refused until the snapshot is vendored+complete (hard gate)
 *       AND a fresh inline audit reconciles (soft gate: eligible>0, zero unmodeled wrong-text,
 *       single tradition); on success the reconciliation GENERATION (a corpus-content
 *       signature) persists;
 *   (e) ATTESTATION is HARD-DISABLED on a seeded HETEROGENEOUS corpus (the single-tradition
 *       premise is provably false);
 *   (f) NO write-on-GET — the read-only live preview panel persists nothing on render.
 *
 * Mirrors the helper shape of {@see SettingsRegistrarTest}: the plugin's boot() already wired
 * SettingsRegistrar->hook(); we call register() (NOT hook()) directly because admin_init/
 * rest_api_init do not fire in the CLI harness, and a second hook() would double-bump the
 * cache generation.
 */
final class InlineEnableGateIntegrationTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        $this->resetOptions();
        ( new SettingsRegistrar() )->register();
    }

    protected function tearDown(): void {
        $this->resetOptions();
        $this->purgeVendorSnapshot();
        parent::tearDown();
    }

    private function resetOptions(): void {
        foreach (
            array(
                ID::OPTION_BIBLE_LINK_VERSION,
                ID::OPTION_BIBLE_INLINE_TRANSLATION,
                ID::OPTION_BIBLE_INLINE_ENABLED,
                ID::OPTION_BIBLE_INLINE_ATTESTATION,
                ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR,
                ID::OPTION_BIBLE_INLINE_PERSEG_ACK,
                ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN,
                ID::OPTION_BIBLE_STATS,
                ID::OPTION_BIBLE_CACHE_GEN,
            ) as $option
        ) {
            delete_option( $option );
        }
    }

    // --- (c) enable-gating: hard gate THEN soft gate, then recon-gen persists -

    public function test_enable_refused_until_vendored_then_honored_and_recon_gen_persists(): void {
        // A single inline-eligible, single-tradition sermon — the soft gate's happy corpus.
        $this->seedEligibleSermon();

        // STEP 1 — hard gate: with NO vendored snapshot, the master switch refuses TRUE so the
        // feature can never ship dark.
        $this->assertFalse(
            BibleChapterVendor::isSnapshotComplete( BibleTranslations::DEFAULT_INLINE ),
            'Precondition: the ENGWEBP snapshot must be absent.'
        );

        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertFalse(
            (bool) get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Enable must be refused while the snapshot is incomplete (hard gate).'
        );
        $this->assertContains(
            'sermonator_bible_inline_not_vendored',
            wp_list_pluck( get_settings_errors( ID::OPTION_BIBLE_INLINE_ENABLED ), 'code' )
        );
        $this->assertSame(
            '',
            (string) get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' ),
            'A hard-gate refusal must not stamp the reconciliation generation.'
        );

        // STEP 2 — vendor the snapshot complete, then the SAME enable now clears both gates.
        $this->vendorCompleteSnapshot();

        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertTrue(
            (bool) get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Enable must be honored once vendored AND the audit reconciles.'
        );

        // The reconciliation GENERATION persists — a CORPUS-CONTENT signature (not a wall-clock
        // generated_at), so a later routine re-audit over the unchanged corpus reproduces it.
        $stamp = get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' );
        $this->assertIsString( $stamp );
        $this->assertNotSame( '', $stamp, 'A successful enable must stamp the reconciliation generation.' );
        $this->assertSame(
            CoverageAudit::inlineSignature( ( new CoverageAudit() )->inlineReport() ),
            $stamp,
            'The stamp must be the corpus-content signature of the reconciling audit.'
        );
    }

    public function test_enable_refused_when_soft_gate_audit_does_not_reconcile(): void {
        // Snapshot COMPLETE (hard gate passes) but the corpus is HETEROGENEOUS → the soft gate's
        // single-tradition condition fails → enable refused with the audit-unreconciled code, and
        // NO reconciliation generation is stamped.
        $this->vendorCompleteSnapshot();
        $this->seedEligibleSermon();                       // ESV (English) bucket.
        $this->seedSermon( 'Psalm 23:1', array(            // a SECOND family bucket.
            'bookUSFM'                   => 'PSA',
            'chapterStart'               => 23,
            'verseStart'                 => 1,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'Psalm 23:1',
            'confidence'                 => 'exact',
            'srcVersification'           => 'RVR1960',
            'srcVersificationConfidence' => 'authored',
        ) );

        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertFalse(
            (bool) get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false ),
            'A heterogeneous corpus must fail the soft gate even when vendored.'
        );
        $this->assertContains(
            'sermonator_bible_inline_audit_unreconciled',
            wp_list_pluck( get_settings_errors( ID::OPTION_BIBLE_INLINE_ENABLED ), 'code' )
        );
        $this->assertSame(
            '',
            (string) get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' )
        );
    }

    public function test_enable_can_always_be_disabled_even_without_a_snapshot(): void {
        // Instant rollback: turning the master switch OFF never depends on the vendor/audit.
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, false );

        $this->assertFalse( (bool) get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false ) );
    }

    // --- (e) attestation hard-disabled on a heterogeneous corpus -------------

    public function test_attestation_refused_on_a_seeded_heterogeneous_corpus(): void {
        // Two render-ready refs in DIFFERENT source-versification family buckets → the live audit
        // reports heterogeneous → attesting the single-tradition premise is HARD-disabled.
        $this->seedEligibleSermon();                       // ESV (English) bucket.
        $this->seedSermon( 'Psalm 23:1', array(
            'bookUSFM'                   => 'PSA',
            'chapterStart'               => 23,
            'verseStart'                 => 1,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'Psalm 23:1',
            'confidence'                 => 'exact',
            'srcVersification'           => 'RVR1960',
            'srcVersificationConfidence' => 'authored',
        ) );

        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $this->assertFalse(
            (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false ),
            'Attesting must be refused while the corpus mixes traditions.'
        );
        $this->assertContains(
            'sermonator_bible_inline_attest_heterogeneous',
            wp_list_pluck( get_settings_errors( ID::OPTION_BIBLE_INLINE_ATTESTATION ), 'code' )
        );
    }

    public function test_attestation_allowed_on_a_homogeneous_corpus(): void {
        $this->seedEligibleSermon();

        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $this->assertTrue( (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false ) );
    }

    // --- (f) no write-on-GET for the read-only live preview panel ------------

    public function test_live_preview_panel_writes_nothing_on_render(): void {
        // The §4 read-only preview an operator consults BEFORE enabling must persist nothing.
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );
        $this->seedEligibleSermon();

        $warm = static function ( $t, $b, $c, $w ): array {
            $verses = array();
            for ( $n = 1; $n <= 40; $n++ ) {
                $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => 'word' ) ) );
            }
            return $verses;
        };
        $panel = new BibleInlinePreviewPanel(
            static fn( bool $assumeAttested ): array => ( new CoverageAudit( null, $warm ) )->promotionPreview( $assumeAttested )
        );

        ob_start();
        $panel->renderPreview();
        $panel->renderAttestationField();
        ob_get_clean();

        $this->assertFalse(
            get_option( ID::OPTION_BIBLE_STATS, false ),
            'The read-only preview must not persist the corpus rollup (no write-on-GET).'
        );
        $this->assertFalse(
            get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, false ),
            'The read-only preview must not stamp the reconciliation generation.'
        );
    }

    // --- helpers (mirror SettingsRegistrarTest) ------------------------------

    /**
     * Seed ONE published sermon whose stored envelope carries a single inline-eligible ref:
     * an `authored` ESV→ENGWEBP Genesis 1:1 — verse 1 is present in the stub-vendored snapshot,
     * so it clears L1–L9 over a single (English) source-versification family.
     */
    private function seedEligibleSermon(): void {
        $this->seedSermon( 'Genesis 1:1', array(
            'bookUSFM'                   => 'GEN',
            'chapterStart'               => 1,
            'verseStart'                 => 1,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'Genesis 1:1',
            'confidence'                 => 'exact',
            'srcVersification'           => 'ESV',
            'srcVersificationConfidence' => 'authored',
        ) );
    }

    /**
     * Seed ONE published sermon with the given passage label and a single stored envelope ref.
     *
     * @param array<string,mixed> $ref
     */
    private function seedSermon( string $passage, array $ref ): void {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'Inline Enable-Gate Sermon',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        update_post_meta(
            $id,
            ID::META_BIBLE_REFS,
            (string) wp_json_encode( array( 'v' => 1, 'refs' => array( $ref ) ) )
        );
    }

    /**
     * Vendor a COMPLETE ENGWEBP snapshot to the uploads dir via a deterministic stub fetcher,
     * so {@see BibleChapterVendor::isSnapshotComplete()} — the real oracle the enable hard-gate
     * consults — returns true. No network. Cleaned in tearDown.
     */
    private function vendorCompleteSnapshot(): void {
        $fetcher = static function ( string $translation, string $book, int $chapter ): array {
            return array(
                'content' => array(
                    array( 'type' => 'verse', 'number' => 1, 'content' => array( 'In the beginning.' ) ),
                ),
            );
        };

        $result = ( new BibleChapterVendor( $fetcher ) )->vendor(
            BibleTranslations::DEFAULT_INLINE,
            false, // real write
            false, // fill-missing
            0      // no limit — full pass
        );

        $this->assertTrue(
            $result['status']['complete'],
            'Test fixture: the stub vendor pass must produce a complete snapshot.'
        );
    }

    /** Recursively remove the vendored snapshot tree from uploads (test cleanup). */
    private function purgeVendorSnapshot(): void {
        $uploads = wp_upload_dir();
        if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
            return;
        }

        $dir = $uploads['basedir'] . '/' . ID::BIBLE_VENDOR_DIR;
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $path ) {
            $path->isDir() ? @rmdir( (string) $path ) : @unlink( (string) $path );
        }
        @rmdir( $dir );
    }
}
