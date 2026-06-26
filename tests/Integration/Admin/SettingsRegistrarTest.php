<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\SettingsRegistrar;
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
        delete_option( Identifiers::OPTION_BIBLE_CACHE_GEN );
        delete_option( 'sermonmanager_verse_bible_version' );
        delete_option( Identifiers::OPTION_PREFIX . 'verse_bible_version' );

        ( new SettingsRegistrar() )->hook();
        do_action( 'admin_init' );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_BIBLE_LINK_VERSION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION );
        delete_option( Identifiers::OPTION_BIBLE_CACHE_GEN );
        delete_option( 'sermonmanager_verse_bible_version' );
        delete_option( Identifiers::OPTION_PREFIX . 'verse_bible_version' );
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

    public function test_setting_inline_translation_bumps_cache_generation_on_create_and_update(): void {
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 7 );

        update_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, 'ENGKJV' );
        $this->assertSame( 8, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );

        update_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, 'ENGWEBP' );
        $this->assertSame( 9, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );
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
}
