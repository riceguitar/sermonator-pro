<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\DisplaySettingsRegistrar;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see DisplaySettingsRegistrar}, driving the REAL
 * WordPress options + Settings API (no Brain Monkey). NOT run in this
 * environment (no Docker / wp-env) — authored to run under wp-env later.
 *
 * Exercises what the unit test cannot: the full options.php-style round-trip
 * where `register_setting` wires each sanitize callback onto the
 * `sanitize_option_{$option}` filter, so an `update_option()` actually runs the
 * sanitizer and persists the cleaned value; the real `get_page_by_path`
 * collision check; the real `get_post_type` attachment check; and the
 * distinct-key provenance guarantee (writing a live key never mutates the
 * migration artifact container {@see DisplayDefaults} seeds from).
 */
final class DisplaySettingsRegistrarTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        // Register on admin_init's behalf so the sanitize_option_* filters are live.
        ( new DisplaySettingsRegistrar() )->register();
    }

    // --- archive slug round-trip --------------------------------------------

    public function test_clean_slug_round_trips_through_update_option(): void {
        update_option( ID::OPTION_ARCHIVE_SLUG, 'Sunday Messages' );

        $this->assertSame( 'sunday-messages', get_option( ID::OPTION_ARCHIVE_SLUG ) );
    }

    public function test_reserved_slug_is_rejected_to_stored_value(): void {
        update_option( ID::OPTION_ARCHIVE_SLUG, 'messages' );
        // A reserved core term must not overwrite the good stored value.
        update_option( ID::OPTION_ARCHIVE_SLUG, 'feed' );

        $this->assertSame( 'messages', get_option( ID::OPTION_ARCHIVE_SLUG ) );
    }

    public function test_page_colliding_slug_is_rejected_to_stored_value(): void {
        update_option( ID::OPTION_ARCHIVE_SLUG, 'messages' );

        self::factory()->post->create( array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_name'   => 'sermons',
            'post_title'  => 'Sermons',
        ) );

        update_option( ID::OPTION_ARCHIVE_SLUG, 'sermons' );

        $this->assertSame( 'messages', get_option( ID::OPTION_ARCHIVE_SLUG ) );
    }

    public function test_empty_slug_rejected_to_display_default_when_nothing_stored(): void {
        // Never saved: the rejection falls back through DisplayDefaults to 'sermons'.
        update_option( ID::OPTION_ARCHIVE_SLUG, '!!!' );

        $this->assertSame( DisplayDefaults::HARD_ARCHIVE_SLUG, get_option( ID::OPTION_ARCHIVE_SLUG ) );
    }

    // --- default image id round-trip ----------------------------------------

    public function test_real_attachment_id_round_trips(): void {
        $attachment = (int) self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );

        update_option( ID::OPTION_DEFAULT_IMAGE_ID, $attachment );

        $this->assertSame( $attachment, (int) get_option( ID::OPTION_DEFAULT_IMAGE_ID ) );
    }

    public function test_non_attachment_id_floors_to_zero(): void {
        $page = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

        update_option( ID::OPTION_DEFAULT_IMAGE_ID, $page );

        $this->assertSame( 0, (int) get_option( ID::OPTION_DEFAULT_IMAGE_ID ) );
    }

    // --- preacher label round-trip ------------------------------------------

    public function test_label_round_trips_and_caps_length(): void {
        update_option( ID::OPTION_PREACHER_LABEL, 'Speaker' );
        $this->assertSame( 'Speaker', get_option( ID::OPTION_PREACHER_LABEL ) );

        update_option( ID::OPTION_PREACHER_LABEL, str_repeat( 'x', 300 ) );
        $this->assertSame( 100, strlen( (string) get_option( ID::OPTION_PREACHER_LABEL ) ) );
    }

    // --- distinct-key provenance --------------------------------------------

    public function test_writing_live_key_never_mutates_migration_artifact_container(): void {
        // The migration prefix-swap artifact the seed reads from.
        $artifact = ID::OPTION_PREFIX . 'general';
        update_option( $artifact, array( 'archive_slug' => 'legacy-base', 'preacher_label' => 'Minister' ) );

        // An admin edit of the DISTINCT live keys.
        update_option( ID::OPTION_ARCHIVE_SLUG, 'new-base' );
        update_option( ID::OPTION_PREACHER_LABEL, 'Teacher' );

        // The artifact is untouched — so a (supported, pre-Finalize) migration
        // re-run that overwrites the artifact verbatim can never clobber the edit.
        $this->assertSame(
            array( 'archive_slug' => 'legacy-base', 'preacher_label' => 'Minister' ),
            get_option( $artifact )
        );
        $this->assertSame( 'new-base', get_option( ID::OPTION_ARCHIVE_SLUG ) );
        $this->assertSame( 'Teacher', get_option( ID::OPTION_PREACHER_LABEL ) );
    }

    public function test_register_does_not_register_the_bible_options(): void {
        // Reset all settings globals to neutralise any boot-wired registrations that
        // fired on admin_init during the test bootstrap (SettingsRegistrar is hooked
        // on admin_init, which may run in the wp-env integration context). After the
        // reset, register DisplaySettingsRegistrar once so $before reflects exactly
        // the three Display keys — no Bible keys, no other churn.
        global $wp_registered_settings, $new_allowed_options, $allowed_options;
        $wp_registered_settings = array();
        $new_allowed_options    = array();
        $allowed_options        = array();

        ( new DisplaySettingsRegistrar() )->register();

        // Baseline: only the three Display keys are present (no Bible keys).
        $before = array_keys( (array) $wp_registered_settings );

        // Re-running register() is idempotent for our three keys and must never
        // introduce a Bible key.
        ( new DisplaySettingsRegistrar() )->register();

        $after = array_keys( (array) $wp_registered_settings );

        // The registrar owns exactly the three Display keys.
        $this->assertArrayHasKey( ID::OPTION_ARCHIVE_SLUG, (array) $wp_registered_settings );
        $this->assertArrayHasKey( ID::OPTION_DEFAULT_IMAGE_ID, (array) $wp_registered_settings );
        $this->assertArrayHasKey( ID::OPTION_PREACHER_LABEL, (array) $wp_registered_settings );

        // The headline Bundle-4 invariant: this registrar must NEVER re-register
        // the two Bible options (which SettingsRegistrar owns). They are absent
        // because SettingsRegistrar has not run — so if DisplaySettingsRegistrar
        // touched them they would appear here. Assert directly that it does not.
        $this->assertArrayNotHasKey( ID::OPTION_BIBLE_LINK_VERSION, (array) $wp_registered_settings );
        $this->assertArrayNotHasKey( ID::OPTION_BIBLE_INLINE_TRANSLATION, (array) $wp_registered_settings );

        // And the delta a re-run introduces is empty — it adds neither Bible key
        // nor anything else (idempotent), so the registered set is unchanged.
        $this->assertSame(
            array(),
            array_values( array_diff( $after, $before ) ),
            'Re-running register() must introduce no new registered settings (no Bible keys, no churn).'
        );
    }
}
