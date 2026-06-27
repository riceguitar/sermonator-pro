<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\SettingsRegistrar;
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

    public function test_enable_is_allowed_once_snapshot_is_complete(): void {
        $this->vendorCompleteSnapshot();

        $this->assertTrue(
            BibleChapterVendor::isSnapshotComplete( BibleTranslations::DEFAULT_INLINE ),
            'Precondition: a full vendor pass must have completed.'
        );

        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );

        $this->assertTrue(
            (bool) get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ),
            'Enabling must be honored once the snapshot is complete.'
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
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 );

        // First (create) save flips false→true through add_option_{$option}.
        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, true );
        $this->assertSame( 1, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );

        // A subsequent change flips true→false through update_option_{$option}.
        update_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false );
        $this->assertSame( 2, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );
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

    // --- Helpers -------------------------------------------------------------

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
