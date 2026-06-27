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
        // i18n passthrough for the enable-gate error message (not auto-stubbed).
        Functions\when( '__' )->returnArg();
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

    public function test_link_version_default_honors_uncurated_but_valid_legacy_value(): void {
        // Axis A is UNCONSTRAINED (link-only): a real legacy verse_bible_version like
        // 'NCV' (not in the 5-entry dropdown) must be carried VERBATIM onto the link
        // path, never floored to ESV — otherwise a migrated church silently loses its
        // chosen version on the very render path Phase 3a ships.
        Functions\when( 'get_option' )->justReturn( 'NCV' );

        $this->assertSame( 'NCV', SettingsRegistrar::defaultLinkVersion() );
    }

    public function test_link_version_default_floors_only_a_malformed_legacy_value(): void {
        // A structurally-invalid code (spaces/punctuation) is not a usable link
        // version and floors to ESV.
        Functions\when( 'get_option' )->justReturn( 'not a version!' );

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

    // --- Phase 3b: inline enable hard-gate -----------------------------------

    public function test_inline_enable_refused_when_snapshot_not_complete(): void {
        // Snapshot is NOT vendored/complete → enabling must be refused (sanitized back to
        // false) AND surface an add_settings_error, so the feature can never ship dark.
        Functions\when( 'get_option' )->justReturn( BibleTranslations::DEFAULT_INLINE );

        $errors = array();
        Functions\when( 'add_settings_error' )->alias(
            static function ( string $setting, string $code, string $message, string $type = 'error' ) use ( &$errors ): void {
                $errors[] = array( 'setting' => $setting, 'code' => $code, 'type' => $type );
            }
        );

        $registrar = new SettingsRegistrar(
            static fn( string $translation ): bool => false
        );

        $this->assertFalse( $registrar->sanitizeInlineEnabled( '1' ) );
        $this->assertFalse( $registrar->sanitizeInlineEnabled( true ) );
        $this->assertNotEmpty( $errors, 'Refusing to enable must register a settings error.' );
        $this->assertSame( Identifiers::OPTION_BIBLE_INLINE_ENABLED, $errors[0]['setting'] );
        $this->assertSame( 'sermonator_bible_inline_not_vendored', $errors[0]['code'] );
    }

    public function test_inline_enable_allowed_when_snapshot_complete(): void {
        Functions\when( 'get_option' )->justReturn( BibleTranslations::DEFAULT_INLINE );

        // No settings error may be raised on the happy path.
        Functions\expect( 'add_settings_error' )->never();

        $captured = array();
        $registrar = new SettingsRegistrar(
            static function ( string $translation ) use ( &$captured ): bool {
                $captured[] = $translation;
                return true;
            }
        );

        $this->assertTrue( $registrar->sanitizeInlineEnabled( '1' ) );
        $this->assertTrue( $registrar->sanitizeInlineEnabled( true ) );
        // The gate probes the configured inline translation (ENGWEBP by default).
        $this->assertSame( BibleTranslations::DEFAULT_INLINE, $captured[0] );
    }

    public function test_inline_disable_is_always_allowed_without_a_vendor_check(): void {
        // Turning the feature OFF must never consult the snapshot oracle nor error.
        Functions\expect( 'add_settings_error' )->never();
        Functions\expect( 'get_option' )->never();

        $probed = false;
        $registrar = new SettingsRegistrar(
            static function ( string $translation ) use ( &$probed ): bool {
                $probed = true;
                return true;
            }
        );

        $this->assertFalse( $registrar->sanitizeInlineEnabled( '' ) );
        $this->assertFalse( $registrar->sanitizeInlineEnabled( '0' ) );
        $this->assertFalse( $registrar->sanitizeInlineEnabled( false ) );
        $this->assertFalse( $registrar->sanitizeInlineEnabled( null ) );
        $this->assertFalse( $probed, 'Disabling must not probe the snapshot oracle.' );
    }

    public function test_inline_enable_probes_currently_configured_translation(): void {
        // A non-default-but-eligible configured translation is the one the gate checks.
        $eligible = array_keys( BibleTranslations::curatedInline() );
        $configured = end( $eligible );

        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) use ( $configured ) {
                return $name === Identifiers::OPTION_BIBLE_INLINE_TRANSLATION ? $configured : $default;
            }
        );
        Functions\when( 'add_settings_error' )->justReturn( null );

        $captured = array();
        $registrar = new SettingsRegistrar(
            static function ( string $translation ) use ( &$captured ): bool {
                $captured[] = $translation;
                return false;
            }
        );

        $registrar->sanitizeInlineEnabled( '1' );
        $this->assertSame( $configured, $captured[0] );
    }

    // --- Phase 3b: attestation sanitize --------------------------------------

    public function test_attestation_sanitize_coerces_truthy_and_falsy(): void {
        $registrar = new SettingsRegistrar();

        foreach ( array( true, '1', 'true', 'on', 'yes', 1 ) as $truthy ) {
            $this->assertTrue( $registrar->sanitizeAttestation( $truthy ), var_export( $truthy, true ) );
        }
        foreach ( array( false, '', '0', 'false', 'no', 0, null, array( 'x' ) ) as $falsy ) {
            $this->assertFalse( $registrar->sanitizeAttestation( $falsy ), var_export( $falsy, true ) );
        }
    }

    // --- Phase 3b: confidence-floor sanitize ---------------------------------

    public function test_confidence_floor_sanitize_keeps_allowed_values(): void {
        $registrar = new SettingsRegistrar();

        $this->assertSame( 'exact', $registrar->sanitizeConfidenceFloor( 'exact' ) );
        $this->assertSame( 'derived-exact', $registrar->sanitizeConfidenceFloor( 'derived-exact' ) );
    }

    public function test_confidence_floor_sanitize_floors_unknown_and_unofferable(): void {
        $registrar = new SettingsRegistrar();

        // `probable` is deliberately NOT offerable through settings → floored to exact.
        $this->assertSame( SettingsRegistrar::DEFAULT_CONFIDENCE_FLOOR, $registrar->sanitizeConfidenceFloor( 'probable' ) );
        $this->assertSame( SettingsRegistrar::DEFAULT_CONFIDENCE_FLOOR, $registrar->sanitizeConfidenceFloor( 'whatever' ) );
        $this->assertSame( SettingsRegistrar::DEFAULT_CONFIDENCE_FLOOR, $registrar->sanitizeConfidenceFloor( null ) );
        $this->assertSame( SettingsRegistrar::DEFAULT_CONFIDENCE_FLOOR, $registrar->sanitizeConfidenceFloor( 42 ) );
        $this->assertSame( 'exact', SettingsRegistrar::DEFAULT_CONFIDENCE_FLOOR );
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

    public function test_register_registers_three_phase3b_options_in_shared_group(): void {
        $registered = array();
        Functions\when( 'register_setting' )->alias(
            static function ( string $group, string $name, array $args ) use ( &$registered ): void {
                $registered[ $name ] = array( 'group' => $group, 'args' => $args );
            }
        );

        ( new SettingsRegistrar() )->register();

        $enabled = $registered[ Identifiers::OPTION_BIBLE_INLINE_ENABLED ] ?? null;
        $this->assertNotNull( $enabled );
        $this->assertSame( Identifiers::OPTION_GROUP_SETTINGS, $enabled['group'] );
        $this->assertSame( 'boolean', $enabled['args']['type'] );
        $this->assertFalse( $enabled['args']['default'] );
        $this->assertTrue( $enabled['args']['show_in_rest'] );
        $this->assertIsCallable( $enabled['args']['sanitize_callback'] );

        $attest = $registered[ Identifiers::OPTION_BIBLE_INLINE_ATTESTATION ] ?? null;
        $this->assertNotNull( $attest );
        $this->assertSame( Identifiers::OPTION_GROUP_SETTINGS, $attest['group'] );
        $this->assertSame( 'boolean', $attest['args']['type'] );
        $this->assertFalse( $attest['args']['default'] );
        $this->assertTrue( $attest['args']['show_in_rest'] );
        $this->assertIsCallable( $attest['args']['sanitize_callback'] );

        $floor = $registered[ Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR ] ?? null;
        $this->assertNotNull( $floor );
        $this->assertSame( Identifiers::OPTION_GROUP_SETTINGS, $floor['group'] );
        $this->assertSame( 'string', $floor['args']['type'] );
        $this->assertSame( 'exact', $floor['args']['default'] );
        $this->assertTrue( $floor['args']['show_in_rest'] );
        $this->assertIsCallable( $floor['args']['sanitize_callback'] );
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

        // The Phase 3b enable toggle also joins the cache-gen bump on both paths.
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'add_option_' . Identifiers::OPTION_BIBLE_INLINE_ENABLED, \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'update_option_' . Identifiers::OPTION_BIBLE_INLINE_ENABLED, \Mockery::type( 'array' ) );

        ( new SettingsRegistrar() )->hook();
        $this->addToAssertionCount( 1 );
    }
}
