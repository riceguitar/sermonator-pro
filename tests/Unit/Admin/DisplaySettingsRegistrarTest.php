<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Admin\DisplaySettingsRegistrar;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;

final class DisplaySettingsRegistrarTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Lean WP shims used across the sanitize callbacks. Each test overrides
        // the ones it actually exercises.
        Functions\when( 'sanitize_title' )->alias(
            static function ( string $value ): string {
                $value = strtolower( $value );
                $value = preg_replace( '/[^a-z0-9]+/', '-', $value );
                return trim( (string) $value, '-' );
            }
        );
        Functions\when( 'sanitize_text_field' )->alias(
            static fn( string $value ): string => trim( preg_replace( '/\s+/', ' ', $value ) )
        );
        Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
        Functions\when( 'apply_filters' )->alias(
            static fn( string $hook, $value = null ) => $value
        );
        // Default: no page collisions, no stored options.
        Functions\when( 'get_page_by_path' )->justReturn( null );
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) => $default
        );
        Functions\when( 'get_post_type' )->justReturn( false );
        // Default stubs for output-only WP functions that the sanitize callbacks
        // call on rejection (individual tests that need to assert call counts /
        // arguments override these with expect() instead).
        Functions\when( '__' )->returnArg();
        Functions\when( 'add_settings_error' )->justReturn( null );
        Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function registrar(): DisplaySettingsRegistrar {
        return new DisplaySettingsRegistrar();
    }

    // --- archive slug sanitize ----------------------------------------------

    public function test_slug_sanitize_keeps_a_clean_value(): void {
        $this->assertSame( 'sunday-sermons', $this->registrar()->sanitizeArchiveSlug( 'Sunday Sermons' ) );
    }

    public function test_slug_sanitize_rejects_empty_to_stored_value(): void {
        // A submission that sanitizes to empty falls back to the CURRENTLY STORED
        // live slug, never a guess.
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_ARCHIVE_SLUG ? 'messages' : $default;
            }
        );

        $this->assertSame( 'messages', $this->registrar()->sanitizeArchiveSlug( '   ' ) );
        $this->assertSame( 'messages', $this->registrar()->sanitizeArchiveSlug( '!!!' ) );
    }

    public function test_slug_sanitize_empty_adds_settings_error(): void {
        // On rejection the admin must receive a settings error so options.php does
        // not display "Settings saved." while silently keeping the old value.
        $errors = array();
        Functions\when( 'add_settings_error' )->alias(
            static function ( string $setting, string $code, string $message, string $type = 'error' ) use ( &$errors ): void {
                $errors[] = array( 'setting' => $setting, 'code' => $code, 'type' => $type );
            }
        );

        $this->registrar()->sanitizeArchiveSlug( '   ' );

        $this->assertNotEmpty( $errors, 'An empty submission must call add_settings_error()' );
        $this->assertSame( Identifiers::OPTION_ARCHIVE_SLUG, $errors[0]['setting'] );
        $this->assertSame( 'sermonator_slug_empty', $errors[0]['code'] );
        $this->assertSame( 'error', $errors[0]['type'] );
    }

    public function test_slug_sanitize_rejects_reserved_term_to_stored_value(): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_ARCHIVE_SLUG ? 'sermons' : $default;
            }
        );

        // 'feed', 'author', 'page' are core query vars / rewrite endpoints.
        foreach ( array( 'feed', 'author', 'page', 'attachment', 'embed' ) as $reserved ) {
            $this->assertSame(
                'sermons',
                $this->registrar()->sanitizeArchiveSlug( $reserved ),
                "reserved slug '{$reserved}' must be rejected"
            );
        }
    }

    public function test_slug_sanitize_reserved_adds_settings_error(): void {
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) => $default
        );

        $codes = array();
        Functions\when( 'add_settings_error' )->alias(
            static function ( string $setting, string $code ) use ( &$codes ): void {
                $codes[] = $code;
            }
        );

        $this->registrar()->sanitizeArchiveSlug( 'feed' );

        $this->assertContains( 'sermonator_slug_reserved', $codes, 'A reserved slug submission must call add_settings_error() with sermonator_slug_reserved code' );
    }

    public function test_slug_sanitize_rejects_page_collision_to_stored_value(): void {
        Functions\when( 'get_page_by_path' )->justReturn( (object) array( 'ID' => 42 ) );
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_ARCHIVE_SLUG ? 'sermons' : $default;
            }
        );

        $this->assertSame( 'sermons', $this->registrar()->sanitizeArchiveSlug( 'about' ) );
    }

    public function test_slug_sanitize_page_collision_adds_settings_error(): void {
        Functions\when( 'get_page_by_path' )->justReturn( (object) array( 'ID' => 42 ) );
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) => $default
        );

        $codes = array();
        Functions\when( 'add_settings_error' )->alias(
            static function ( string $setting, string $code ) use ( &$codes ): void {
                $codes[] = $code;
            }
        );

        $this->registrar()->sanitizeArchiveSlug( 'about' );

        $this->assertContains( 'sermonator_slug_page_collision', $codes, 'A page-colliding slug submission must call add_settings_error() with sermonator_slug_page_collision code' );
    }

    public function test_slug_rejection_falls_back_to_display_default_when_nothing_stored(): void {
        // No stored live option: get_option returns its explicit default, which the
        // registrar passes as DisplayDefaults::defaultArchiveSlug(). With no
        // migrated/legacy containers that resolves to the hard 'sermons'.
        $this->assertSame(
            DisplayDefaults::HARD_ARCHIVE_SLUG,
            $this->registrar()->sanitizeArchiveSlug( 'feed' )
        );
    }

    public function test_slug_sanitize_coerces_non_string_to_stored_value(): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_ARCHIVE_SLUG ? 'sermons' : $default;
            }
        );

        $this->assertSame( 'sermons', $this->registrar()->sanitizeArchiveSlug( array( 'x' ) ) );
        $this->assertSame( 'sermons', $this->registrar()->sanitizeArchiveSlug( null ) );
    }

    // --- default image id sanitize ------------------------------------------

    public function test_image_id_sanitize_keeps_a_real_attachment(): void {
        Functions\when( 'get_post_type' )->alias(
            static fn( $id ): string => $id === 77 ? 'attachment' : 'post'
        );

        $this->assertSame( 77, $this->registrar()->sanitizeImageId( 77 ) );
        $this->assertSame( 77, $this->registrar()->sanitizeImageId( '77' ) );
    }

    public function test_image_id_sanitize_floors_non_attachment_to_zero(): void {
        // A valid post id that is NOT an attachment (e.g. a page) is rejected.
        Functions\when( 'get_post_type' )->justReturn( 'page' );

        $this->assertSame( 0, $this->registrar()->sanitizeImageId( 123 ) );
    }

    public function test_image_id_sanitize_floors_missing_attachment_to_zero(): void {
        // get_post_type returns false for a deleted/absent attachment.
        Functions\when( 'get_post_type' )->justReturn( false );

        $this->assertSame( 0, $this->registrar()->sanitizeImageId( 999 ) );
    }

    public function test_image_id_sanitize_floors_garbage_to_zero(): void {
        $this->assertSame( 0, $this->registrar()->sanitizeImageId( 0 ) );
        $this->assertSame( 0, $this->registrar()->sanitizeImageId( -5 ) );
        $this->assertSame( 0, $this->registrar()->sanitizeImageId( 'not-a-number' ) );
        $this->assertSame( 0, $this->registrar()->sanitizeImageId( null ) );
    }

    // --- preacher label sanitize --------------------------------------------

    public function test_label_sanitize_keeps_a_clean_value(): void {
        $this->assertSame( 'Speaker', $this->registrar()->sanitizePreacherLabel( 'Speaker' ) );
    }

    public function test_label_sanitize_caps_length(): void {
        $label  = str_repeat( 'a', 250 );
        $result = $this->registrar()->sanitizePreacherLabel( $label );

        $this->assertSame( 100, strlen( $result ) );
    }

    public function test_label_sanitize_falls_back_to_stored_when_empty(): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                return $name === Identifiers::OPTION_PREACHER_LABEL ? 'Pastor' : $default;
            }
        );

        $this->assertSame( 'Pastor', $this->registrar()->sanitizePreacherLabel( '   ' ) );
        $this->assertSame( 'Pastor', $this->registrar()->sanitizePreacherLabel( array() ) );
    }

    public function test_label_empty_falls_back_to_display_default_when_nothing_stored(): void {
        $this->assertSame(
            DisplayDefaults::HARD_PREACHER_LABEL,
            $this->registrar()->sanitizePreacherLabel( '' )
        );
    }

    // --- registration wiring: ONLY the 3 display options --------------------

    public function test_register_registers_exactly_the_three_display_options(): void {
        $registered = array();
        Functions\when( 'register_setting' )->alias(
            static function ( string $group, string $name, array $args ) use ( &$registered ): void {
                $registered[ $name ] = array( 'group' => $group, 'args' => $args );
            }
        );

        $this->registrar()->register();

        // Exactly the three live Display keys, on the shared group.
        $this->assertSame(
            array(
                Identifiers::OPTION_ARCHIVE_SLUG,
                Identifiers::OPTION_DEFAULT_IMAGE_ID,
                Identifiers::OPTION_PREACHER_LABEL,
            ),
            array_keys( $registered )
        );

        foreach ( $registered as $entry ) {
            $this->assertSame( Identifiers::OPTION_GROUP_SETTINGS, $entry['group'] );
            $this->assertTrue( $entry['args']['show_in_rest'] );
            $this->assertIsCallable( $entry['args']['sanitize_callback'] );
            $this->assertArrayHasKey( 'default', $entry['args'] );
        }

        $this->assertSame( 'string', $registered[ Identifiers::OPTION_ARCHIVE_SLUG ]['args']['type'] );
        $this->assertSame( 'integer', $registered[ Identifiers::OPTION_DEFAULT_IMAGE_ID ]['args']['type'] );
        $this->assertSame( 'string', $registered[ Identifiers::OPTION_PREACHER_LABEL ]['args']['type'] );
    }

    public function test_register_never_touches_the_bible_options(): void {
        $registered = array();
        Functions\when( 'register_setting' )->alias(
            static function ( string $group, string $name, array $args ) use ( &$registered ): void {
                $registered[ $name ] = true;
            }
        );

        $this->registrar()->register();

        // The Bible options are owned by SettingsRegistrar; re-registering them here
        // would double-attach their sanitize filter. Assert they are absent.
        $this->assertArrayNotHasKey( Identifiers::OPTION_BIBLE_LINK_VERSION, $registered );
        $this->assertArrayNotHasKey( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, $registered );
    }

    public function test_hook_registers_only_admin_init_and_rest_api_init(): void {
        $hooks = array();
        Functions\when( 'add_action' )->alias(
            static function ( string $hook, $cb ) use ( &$hooks ): void {
                $hooks[] = $hook;
            }
        );

        $this->registrar()->hook();

        // Exactly the two registration contexts — and crucially NO cache-gen
        // listener (add_option_* / update_option_*), which is SettingsRegistrar's
        // concern, not this registrar's.
        $this->assertSame( array( 'admin_init', 'rest_api_init' ), $hooks );

        foreach ( $hooks as $hook ) {
            $this->assertStringStartsNotWith( 'add_option_', $hook );
            $this->assertStringStartsNotWith( 'update_option_', $hook );
        }
    }
}
