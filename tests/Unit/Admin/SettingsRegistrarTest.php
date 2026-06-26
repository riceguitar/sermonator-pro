<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Admin\SettingsRegistrar;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers;

final class SettingsRegistrarTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Default: no legacy verse version set, so the axis-A default is ESV.
        Functions\when( 'get_option' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- Axis A: link version sanitize ---------------------------------------

    public function test_link_version_sanitize_keeps_curated_value(): void {
        $registrar = new SettingsRegistrar();

        foreach ( array_keys( BibleTranslations::curatedLinkVersions() ) as $code ) {
            $this->assertSame( $code, $registrar->sanitizeLinkVersion( $code ) );
        }
    }

    public function test_link_version_sanitize_rejects_unknown_to_default(): void {
        $registrar = new SettingsRegistrar();

        $this->assertSame(
            BibleTranslations::DEFAULT_LINK_VERSION,
            $registrar->sanitizeLinkVersion( 'NOT_A_VERSION' )
        );
    }

    public function test_link_version_sanitize_coerces_non_string_to_default(): void {
        $registrar = new SettingsRegistrar();

        $this->assertSame( BibleTranslations::DEFAULT_LINK_VERSION, $registrar->sanitizeLinkVersion( null ) );
        $this->assertSame( BibleTranslations::DEFAULT_LINK_VERSION, $registrar->sanitizeLinkVersion( array( 'x' ) ) );
    }

    public function test_link_version_default_prefers_curated_legacy_value(): void {
        Functions\when( 'get_option' )->justReturn( 'KJV' );

        $this->assertSame( 'KJV', SettingsRegistrar::defaultLinkVersion() );

        // And the sanitize fallback follows that legacy-seeded default.
        $registrar = new SettingsRegistrar();
        $this->assertSame( 'KJV', $registrar->sanitizeLinkVersion( 'NOT_A_VERSION' ) );
    }

    public function test_link_version_default_ignores_uncurated_legacy_value(): void {
        // Legacy 'NCV' is a real Sermon Manager option value but not in our curated
        // link list, so the default must fall back to ESV rather than carry it.
        Functions\when( 'get_option' )->justReturn( 'NCV' );

        $this->assertSame( BibleTranslations::DEFAULT_LINK_VERSION, SettingsRegistrar::defaultLinkVersion() );
    }

    public function test_link_version_default_prefers_migrated_option(): void {
        // Post-finalize the sermonmanager_ row is gone; the church's choice lives
        // at sermonator_verse_bible_version. defaultLinkVersion() must read it.
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_PREFIX . 'verse_bible_version' ? 'KJV' : $default;
            }
        );

        $this->assertSame( 'KJV', SettingsRegistrar::defaultLinkVersion() );
    }

    public function test_link_version_default_prefers_migrated_over_pre_finalize_legacy(): void {
        // During the migration window both rows can coexist; the migrated row wins.
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                if ( $name === Identifiers::OPTION_PREFIX . 'verse_bible_version' ) {
                    return 'NASB';
                }
                if ( $name === 'sermonmanager_verse_bible_version' ) {
                    return 'KJV';
                }
                return $default;
            }
        );

        $this->assertSame( 'NASB', SettingsRegistrar::defaultLinkVersion() );
    }

    public function test_link_version_default_falls_back_to_pre_finalize_legacy(): void {
        // Before the migrated row exists, the pre-finalize sermonmanager_ row still
        // seeds the default.
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === 'sermonmanager_verse_bible_version' ? 'NKJV' : $default;
            }
        );

        $this->assertSame( 'NKJV', SettingsRegistrar::defaultLinkVersion() );
    }

    // --- Axis B: inline translation sanitize ---------------------------------

    public function test_inline_translation_sanitize_keeps_eligible_value(): void {
        $registrar = new SettingsRegistrar();

        foreach ( array_keys( BibleTranslations::curatedInline() ) as $id ) {
            $this->assertSame( $id, $registrar->sanitizeInlineTranslation( $id ) );
        }
    }

    public function test_inline_translation_sanitize_rejects_ineligible_slug(): void {
        $registrar = new SettingsRegistrar();

        // BSB is in BibleTranslations::all() but inline-INELIGIBLE, so it is absent
        // from curatedInline() and must be rejected to the ENGWEBP default.
        $this->assertArrayNotHasKey( 'BSB', BibleTranslations::curatedInline() );
        $this->assertSame(
            BibleTranslations::DEFAULT_INLINE,
            $registrar->sanitizeInlineTranslation( 'BSB' )
        );
    }

    public function test_inline_translation_sanitize_rejects_unknown_to_default(): void {
        $registrar = new SettingsRegistrar();

        $this->assertSame(
            BibleTranslations::DEFAULT_INLINE,
            $registrar->sanitizeInlineTranslation( 'ZZZ' )
        );
        $this->assertSame(
            BibleTranslations::DEFAULT_INLINE,
            $registrar->sanitizeInlineTranslation( 12345 )
        );
    }

    // --- Cache-generation bump ----------------------------------------------

    public function test_bump_cache_gen_increments_stored_generation(): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_BIBLE_CACHE_GEN ? 4 : $default;
            }
        );

        Functions\expect( 'update_option' )
            ->once()
            ->with( Identifiers::OPTION_BIBLE_CACHE_GEN, 5 );

        ( new SettingsRegistrar() )->bumpCacheGen();
        $this->addToAssertionCount( 1 );
    }

    public function test_bump_cache_gen_starts_from_zero_when_unset(): void {
        Functions\when( 'get_option' )->justReturn( false );

        Functions\expect( 'update_option' )
            ->once()
            ->with( Identifiers::OPTION_BIBLE_CACHE_GEN, 1 );

        ( new SettingsRegistrar() )->bumpCacheGen();
        $this->addToAssertionCount( 1 );
    }

    // --- Registration wiring -------------------------------------------------

    public function test_register_registers_both_axes_in_shared_group(): void {
        $registered = array();
        Functions\when( 'register_setting' )->alias(
            static function ( string $group, string $name, array $args ) use ( &$registered ): void {
                $registered[ $name ] = array( 'group' => $group, 'args' => $args );
            }
        );

        ( new SettingsRegistrar() )->register();

        $this->assertArrayHasKey( Identifiers::OPTION_BIBLE_LINK_VERSION, $registered );
        $this->assertArrayHasKey( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, $registered );

        $link = $registered[ Identifiers::OPTION_BIBLE_LINK_VERSION ];
        $this->assertSame( Identifiers::OPTION_GROUP_SETTINGS, $link['group'] );
        $this->assertSame( 'string', $link['args']['type'] );
        $this->assertTrue( $link['args']['show_in_rest'] );
        $this->assertSame( BibleTranslations::DEFAULT_LINK_VERSION, $link['args']['default'] );
        $this->assertIsCallable( $link['args']['sanitize_callback'] );

        $inline = $registered[ Identifiers::OPTION_BIBLE_INLINE_TRANSLATION ];
        $this->assertSame( Identifiers::OPTION_GROUP_SETTINGS, $inline['group'] );
        $this->assertSame( BibleTranslations::DEFAULT_INLINE, $inline['args']['default'] );
        $this->assertTrue( $inline['args']['show_in_rest'] );
        $this->assertIsCallable( $inline['args']['sanitize_callback'] );
    }

    public function test_hook_registers_init_create_and_update_listeners(): void {
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_init', \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'rest_api_init', \Mockery::type( 'array' ) );

        // Both the create AND update paths must be wired for each axis, so the
        // first-time save (add_option) is not missed (see hook() docblock).
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'add_option_' . Identifiers::OPTION_BIBLE_LINK_VERSION, \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'update_option_' . Identifiers::OPTION_BIBLE_LINK_VERSION, \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'add_option_' . Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'update_option_' . Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, \Mockery::type( 'array' ) );

        ( new SettingsRegistrar() )->hook();
        $this->addToAssertionCount( 1 );
    }
}
