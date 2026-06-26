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

        ( new SettingsRegistrar() )->hook();
        do_action( 'admin_init' );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_BIBLE_LINK_VERSION );
        delete_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION );
        delete_option( Identifiers::OPTION_BIBLE_CACHE_GEN );
        delete_option( 'sermonmanager_verse_bible_version' );
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

    public function test_updating_link_version_bumps_cache_generation(): void {
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 );
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'NIV' );

        $this->assertSame( 1, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );
    }

    public function test_updating_inline_translation_bumps_cache_generation(): void {
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 7 );
        update_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, 'ENGKJV' );

        $this->assertSame( 8, (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN ) );
    }

    public function test_default_link_version_seeds_from_legacy_when_curated(): void {
        update_option( 'sermonmanager_verse_bible_version', 'KJV' );

        $this->assertSame( 'KJV', SettingsRegistrar::defaultLinkVersion() );
    }
}
