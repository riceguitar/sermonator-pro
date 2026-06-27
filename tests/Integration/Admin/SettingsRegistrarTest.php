<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\SettingsRegistrar;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier;
use Sermonator\Migration\BibleChapterVendor;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers;

/**
 * Integration coverage for the two-axis Bible settings against a real WordPress
 * options/settings stack. NOT run in this environment (no Docker) — written per
 * the Bundle 3 task instructions.
 */
final class SettingsRegistrarTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        delete_option( Identifiers::OPTION_BIBLE_LINK_VERSION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN );
        delete_option( Identifiers::OPTION_BIBLE_STATS );
        delete_option( Identifiers::OPTION_BIBLE_CACHE_GEN );
        delete_option( 'sermonmanager_verse_bible_version' );
        delete_option( Identifiers::OPTION_PREFIX . 'verse_bible_version' );

        // The plugin's boot() already wired SettingsRegistrar->hook() (the add_option_/
        // update_option_ cache-gen listeners + the admin_init/rest_api_init register hooks).
        // Do NOT call hook() again — a second instance would DUPLICATE those listeners and
        // double-bump the cache generation. admin_init/rest_api_init don't fire in this CLI
        // harness, so call register() directly to populate register_setting/allowed_options.
        ( new SettingsRegistrar() )->register();
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_BIBLE_LINK_VERSION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN );
        delete_option( Identifiers::OPTION_BIBLE_STATS );
        delete_option( Identifiers::OPTION_BIBLE_CACHE_GEN );
        delete_option( 'sermonmanager_verse_bible_version' );
        delete_option( Identifiers::OPTION_PREFIX . 'verse_bible_version' );
        $this->purgeVendorSnapshot();
        parent::tearDown();
    }

    public function test_registered_settings_appear_in_allowed_options(): void {
        global $new_allowed_options, $allowed_options;
        $registry = $new_allowed_options ?? $allowed_options ?? array();

        $this->assertArrayHasKey( Identifiers::OPTION_GROUP_SETTINGS, $registry );
        $this->assertContains(
            Identifiers::OPTION_BIBLE_LINK_VERSION,
            $registry[ Identifiers::OPTION_GROUP_SETTINGS ]
        );
        $this->assertContains(
            Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
            $registry[ Identifiers::OPTION_GROUP_SETTINGS ]
        );
    }

    public function test_invalid_link_version_sanitizes_to_default_on_update(): void {
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'NOT_A_VERSION' );

        $this->assertSame(
            BibleTranslations::DEFAULT_LINK_VERSION,
            get_option( Identifiers::OPTION_BIBLE_LINK_VERSION )
        );
    }

    public function test_valid_link_version_is_persisted(): void {
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'NIV' );

        $this->assertSame( 'NIV', get_option( Identifiers::OPTION_BIBLE_LINK_VERSION ) );
    }

    public function test_ineligible_inline_translation_sanitizes_to_engwebp(): void {
        update_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, 'BSB' );

        $this->assertSame(
            BibleTranslations::DEFAULT_INLINE,
            get_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION )
        );
    }

    public function test_setting_link_version_bumps_cache_generation_on_create_and_update(): void {
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 );

        // setUp() deleted the option, so this FIRST save routes through WordPress's
        // add_option() path (add_option_{$option}), the common first-config case.
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'NIV' );
        $this->assertSame(
            1,
            (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ),
            'First save (add_option path) must bump the cache generation.'
        );

        // A subsequent real change routes through update_option_{$option}.
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'KJV' );
        $this->assertSame(
            2,
            (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ),
            'Subsequent save (update_option path) must also bump.'
        );
    }

    public function test_inline_translation_option_is_wired_to_the_cache_generation_bump(): void {
        // Phase 3b makes ENGWEBP the SOLE inline-eligible translation, so the inline
        // translation can no longer be toggled to a second value to drive a real
        // update_option_ change (any ineligible code sanitizes back to ENGWEBP — a no-op
        // write that fires no hook). Assert instead that the cache-gen bump LISTENERS are
        // wired for the option, so when a second public-domain inline target is added a
        // switch invalidates every warmed chapter. The end-to-end add/update bump is proven
        // via the link-version option in the test above.
        $this->assertNotFalse(
            has_action( 'add_option_' . Identifiers::OPTION_BIBLE_INLINE_TRANSLATION ),
            'A cache-gen bump must be wired on the inline-translation create path.'
        );
        $this->assertNotFalse(
            has_action( 'update_option_' . Identifiers::OPTION_BIBLE_INLINE_TRANSLATION ),
            'A cache-gen bump must be wired on the inline-translation update path.'
        );
    }

    public function test_default_link_version_seeds_from_legacy_when_curated(): void {
        update_option( 'sermonmanager_verse_bible_version', 'KJV' );

        $this->assertSame( 'KJV', SettingsRegistrar::defaultLinkVersion() );
    }

    public function test_default_link_version_prefers_migrated_option(): void {
        // Post-finalize the sermonmanager_ row is gone; the value lives at the
        // prefix-swapped sermonator_verse_bible_version row.
        update_option( Identifiers::OPTION_PREFIX . 'verse_bible_version', 'NASB' );

        $this->assertSame( 'NASB', SettingsRegistrar::defaultLinkVersion() );
    }

    public function test_frontend_link_version_resolves_migrated_legacy_without_a_saved_option(): void {
        // The new axis-A option is never explicitly saved (deleted in setUp), but a
        // migrated church carries sermonator_verse_bible_version=KJV. The render
        // path must resolve KJV, not ESV.
        update_option( Identifiers::OPTION_PREFIX . 'verse_bible_version', 'KJV' );

        $this->assertSame( 'KJV', \Sermonator\Bible\TranslationRegistry::current()->linkVersion() );
    }

    // --- Phase 3b: registration ----------------------------------------------

    public function test_phase3b_options_appear_in_allowed_options(): void {
        global $new_allowed_options, $allowed_options;
        $registry = $new_allowed_options ?? $allowed_options ?? array();

        $group = $registry[ Identifiers::OPTION_GROUP_SETTINGS ] ?? array();

        $this->assertContains( Identifiers::OPTION_BIBLE_INLINE_ENABLED, $group );
        $this->assertContains( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, $group );
        $this->assertContains( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, $group );
    }

    // --- Phase 3b: enable hard-gate (un-enableable until vendored+warmed) -----

    public function test_enable_is_refused_until_snapshot_vendored(): void {
        // No vendored snapshot exists → the master switch must refuse to store TRUE,
        // so the feature can never ship dark.
        $this->assertFalse(
            BibleChapterVendor::isSnapshotComplete( BibleTranslations::DEFAULT_INLINE ),
            'Precondition: the ENGWEBP snapshot must be absent.'
        );

        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertFalse(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Enabling must be refused while the snapshot is incomplete.'
        );

        $codes = wp_list_pluck( get_settings_errors( Identifiers::OPTION_BIBLE_INLINE_ENABLED ), 'code' );
        $this->assertContains(
            'sermonator_bible_inline_not_vendored',
            $codes,
            'A refused enable must surface the not-vendored settings error.'
        );
    }

    public function test_enable_is_allowed_once_snapshot_is_complete_and_audit_reconciles(): void {
        // T-G soft-gate: the snapshot must be complete (hard gate) AND a fresh inline audit
        // must reconcile (eligible > 0, no unmodeled wrong-text, single tradition).
        $this->vendorCompleteSnapshot();
        $this->seedEligibleSermon();

        $this->assertTrue(
            BibleChapterVendor::isSnapshotComplete( BibleTranslations::DEFAULT_INLINE ),
            'Precondition: a full vendor pass must have completed.'
        );

        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertTrue(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Enabling must be honored once the snapshot is complete and the audit reconciles.'
        );

        // The reconciliation signature is stamped against the corpus content it reconciled
        // with (a CORPUS-CONTENT fingerprint, NOT a wall-clock generated_at — the T-K fix).
        $stamp = get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' );
        $this->assertIsString( $stamp );
        $this->assertNotSame( '', $stamp, 'A successful enable must stamp the reconciliation signature.' );
        // Over the unchanged corpus, a freshly recomputed signature equals the stamp (the
        // equal/silent steady state the timestamp proxy could never reach).
        $this->assertSame(
            CoverageAudit::inlineSignature( ( new CoverageAudit() )->inlineReport() ),
            $stamp,
            'The stamp must be the corpus-content signature of the reconciling audit.'
        );
    }

    public function test_enable_is_refused_when_corpus_has_no_inline_eligible_ref(): void {
        // Snapshot complete but the corpus is EMPTY → inline_eligible == 0 → the soft-gate
        // refuses enable ("enabled but dark = looks like a bug"), with its own error code.
        $this->vendorCompleteSnapshot();

        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertFalse(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Enabling must be refused when no reference is inline-eligible.'
        );
        $codes = wp_list_pluck( get_settings_errors( Identifiers::OPTION_BIBLE_INLINE_ENABLED ), 'code' );
        $this->assertContains( 'sermonator_bible_inline_audit_unreconciled', $codes );
        $this->assertSame(
            '',
            (string) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' ),
            'A refused enable must not stamp the reconciliation signature.'
        );
    }

    public function test_enable_can_always_be_turned_off_even_without_a_snapshot(): void {
        // Disabling never depends on the snapshot.
        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false );

        $this->assertFalse( (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ) );
    }

    // --- Phase 3b: cache-gen bump on enable change ---------------------------

    public function test_enable_change_bumps_cache_generation(): void {
        $this->vendorCompleteSnapshot();
        $this->seedEligibleSermon();
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 );

        // First (create) save flips false→true. A SUCCESSFUL enable bumps TWICE: once in the
        // sanitize success path (the explicit reconciliation cache invalidation, which must
        // not silently depend on the separate add/update_option hook wiring) and once via the
        // add_option_{$option} listener → generation 2.
        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );
        $this->assertSame( 2, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );

        // A subsequent change flips true→false through update_option_{$option}. Disabling does
        // NOT run the soft-gate (so no sanitize bump) — only the listener fires → generation 3.
        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false );
        $this->assertSame( 3, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );
    }

    // --- Task G fix: no-op re-save while ALREADY enabled ----------------------

    public function test_resave_while_enabled_does_not_restamp_or_rebump_or_disable(): void {
        // Adversarial-review fix: WordPress re-submits the checked enable box on EVERY save of the
        // shared settings group and runs the sanitize_callback before update_option()'s old==new
        // short-circuit. A re-save while inline is ALREADY enabled must be inert: it must NOT
        // re-stamp the reconciliation generation, NOT re-bump the cache generation, and NOT
        // silently disable inline even when the corpus has since drifted heterogeneous.
        $this->vendorCompleteSnapshot();
        $this->seedEligibleSermon();

        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );
        $this->assertTrue(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Precondition: the genuine false->true enable must be honored.'
        );

        $stampAtEnable = (string) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' );
        $genAtEnable   = (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 );
        $this->assertNotSame( '', $stampAtEnable, 'Precondition: the enable stamped a recon signature.' );

        // Drift the corpus AFTER enable: add a second source-versification family bucket so a
        // fresh audit would now report heterogeneous (and would refuse a first-time enable).
        $this->seedSermon( 'Psalm 23:1', array(
            'bookUSFM' => 'PSA', 'chapterStart' => 23, 'verseStart' => 1,
            'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Psalm 23:1',
            'confidence' => 'exact', 'srcVersificationConfidence' => 'authored',
        ) );

        // Re-submit the (unchanged) enabled=true value, simulating an unrelated group save.
        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertTrue(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ),
            'A re-save while enabled must NOT silently disable inline on post-enable corpus drift.'
        );
        $this->assertSame(
            $stampAtEnable,
            (string) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' ),
            'A re-save while enabled must NOT re-stamp the reconciliation signature (the T-K drift baseline).'
        );
        $this->assertSame(
            $genAtEnable,
            (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 ),
            'A re-save while enabled must NOT re-bump the cache generation (no warm-cache thrash).'
        );
        $this->assertEmpty(
            get_settings_errors( Identifiers::OPTION_BIBLE_INLINE_ENABLED ),
            'A no-op re-save while enabled must surface no settings error.'
        );
    }

    public function test_resave_while_attested_does_not_withdraw_on_drift(): void {
        // Mirror of the enable guard for attestation: a re-save while attestation is ALREADY true
        // must not re-run the audit and must not silently withdraw the attestation when the corpus
        // has since drifted heterogeneous.
        $this->seedEligibleSermon();

        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, true );
        $this->assertTrue(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false ),
            'Precondition: the genuine false->true attestation must be honored on a homogeneous corpus.'
        );

        // Drift heterogeneous after attesting.
        $this->seedSermon( 'Psalm 23:1', array(
            'bookUSFM' => 'PSA', 'chapterStart' => 23, 'verseStart' => 1,
            'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Psalm 23:1',
            'confidence' => 'exact', 'srcVersificationConfidence' => 'authored',
        ) );

        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $this->assertTrue(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false ),
            'A re-save while attested must NOT silently withdraw attestation on post-attest drift.'
        );
    }

    // --- Phase 3b: attestation + confidence-floor sanitize -------------------

    public function test_attestation_persists_as_boolean(): void {
        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, '1' );
        $this->assertTrue( (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION ) );

        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, '0' );
        $this->assertFalse( (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION ) );
    }

    public function test_confidence_floor_keeps_allowed_and_floors_unknown(): void {
        update_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );
        $this->assertSame( 'derived-exact', get_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR ) );

        update_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'probable' );
        $this->assertSame(
            SettingsRegistrar::DEFAULT_CONFIDENCE_FLOOR,
            get_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR ),
            'The un-offerable `probable` floor must be rejected to `exact`.'
        );
    }

    // --- Task G: perseg floor gated behind the axis-2 spot-check ack ----------

    public function test_perseg_floor_floored_to_strict_without_ack(): void {
        // The ack option is unset (deleted in setUp) → the widest floor is refused down to
        // the STRICT single-segment `derived-exact`, with a perseg-unacked settings error.
        update_option(
            Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG
        );

        $this->assertSame(
            DerivedExactClassifier::FLOOR_DERIVED_EXACT,
            get_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR )
        );
        $codes = wp_list_pluck(
            get_settings_errors( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR ),
            'code'
        );
        $this->assertContains( 'sermonator_bible_inline_perseg_unacked', $codes );
    }

    public function test_perseg_floor_persists_once_ack_is_set(): void {
        // The logged CLI ack step ("wp sermonator bible ack-perseg --confirm",
        // {@see \Sermonator\Cli\BibleCommand::ackPerseg()}) sets this option — the only
        // production setter. With it set, the per-ref floor is now selectable. (An end-to-end
        // assertion that the CLI command itself unlocks the floor lives in the Cli\BibleCommandTest.)
        update_option( Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK, true );

        update_option(
            Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG
        );

        $this->assertSame(
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
            get_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR )
        );
    }

    // --- Task G: attestation hard-disabled on a heterogeneous corpus ----------

    public function test_attestation_refused_on_a_heterogeneous_corpus(): void {
        // Two render-ready refs in DIFFERENT source-versification family buckets → the live
        // audit reports heterogeneous → attesting the single-tradition premise is refused.
        $this->seedEligibleSermon();                 // ESV (English family) bucket.
        $this->seedSermon( 'Psalm 23:1', array(
            'bookUSFM'                   => 'PSA',
            'chapterStart'               => 23,
            'verseStart'                 => 1,
            'verseEnd'                   => null,
            'chapterEnd'                 => null,
            'raw'                        => 'Psalm 23:1',
            'confidence'                 => 'exact',
            // No srcVersification → buckets under `unknown`, a SECOND family bucket.
            'srcVersificationConfidence' => 'authored',
        ) );

        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $this->assertFalse(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false ),
            'Attesting must be refused while the corpus mixes traditions.'
        );
        $codes = wp_list_pluck(
            get_settings_errors( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION ),
            'code'
        );
        $this->assertContains( 'sermonator_bible_inline_attest_heterogeneous', $codes );
    }

    public function test_attestation_allowed_on_a_homogeneous_corpus(): void {
        // A single-tradition corpus → attesting is honored.
        $this->seedEligibleSermon();

        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $this->assertTrue( (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false ) );
    }

    public function test_attestation_can_always_be_withdrawn_on_a_heterogeneous_corpus(): void {
        // Withdrawing is never gated — even on a heterogeneous corpus it returns to false.
        $this->seedSermon( 'Psalm 23:1', array(
            'bookUSFM' => 'PSA', 'chapterStart' => 23, 'verseStart' => 1,
            'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Psalm 23:1',
            'confidence' => 'exact', 'srcVersificationConfidence' => 'authored',
        ) );
        $this->seedEligibleSermon();

        update_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false );

        $this->assertFalse( (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, true ) );
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Seed ONE published sermon whose stored envelope carries a single inline-eligible ref:
     * an `authored` (attestation-skipping) ESV→ENGWEBP Genesis 1:1 — verse 1 is present in
     * the stub-vendored snapshot, so it clears L1–L9. Makes the live audit report
     * inline_eligible >= 1 over a single (English) source-versification family.
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
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'Inline Settings Sermon',
        ) );
        update_post_meta( $id, Identifiers::META_BIBLE_PASSAGE, $passage );
        update_post_meta(
            $id,
            Identifiers::META_BIBLE_REFS,
            (string) wp_json_encode( array( 'v' => 1, 'refs' => array( $ref ) ) )
        );
    }

    /**
     * Vendor a COMPLETE ENGWEBP snapshot (all 1189 chapters) to the uploads dir using a
     * deterministic stub fetcher, so {@see BibleChapterVendor::isSnapshotComplete()} — the
     * real oracle the enable gate consults — returns true. No network. Cleaned in tearDown.
     */
    private function vendorCompleteSnapshot(): void {
        $fetcher = static function ( string $translation, string $book, int $chapter ): array {
            // Minimal valid helloao chapter object → one present verse after normalization.
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

        $dir = $uploads['basedir'] . '/' . Identifiers::BIBLE_VENDOR_DIR;
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
