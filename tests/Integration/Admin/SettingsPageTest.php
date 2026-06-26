<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\DisplaySettingsRegistrar;
use Sermonator\Admin\PodcastIdentityController;
use Sermonator\Admin\SettingsPage;
use Sermonator\Admin\SettingsRegistrar;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Bundle 4 Task 8 — the opinionated settings page.
 *
 * NOT run in this environment (no Docker / wp-env) — authored to run under wp-env later, like
 * its sibling Admin integration tests. Exercises what a Brain-Monkey unit cannot: real
 * `add_submenu_page` registration under the Sermons menu, the real Settings-API section/field
 * globals, a genuine options.php-style save round-trip through the registrars' sanitize
 * callbacks, and the spec §1.5 phase-aware read-only podcast section over real legacy data +
 * MigrationState.
 *
 * The Bible options are owned by {@see SettingsRegistrar}; this page MUST surface them WITHOUT
 * re-registering — so the test pins that the page registers no option and that the option group
 * still holds exactly the five registrar-owned options after the page boots.
 */
final class SettingsPageTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;
    private int $adminId;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_DEFAULT_PODCAST );
        delete_option( Identifiers::OPTION_ARCHIVE_SLUG );
        delete_option( Identifiers::OPTION_DEFAULT_IMAGE_ID );
        delete_option( Identifiers::OPTION_PREACHER_LABEL );

        $this->adminId = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->adminId );

        // set_current_screen so any get_current_screen() reads resolve in admin context.
        set_current_screen( 'sermonator_sermon_page_' . SettingsPage::PAGE_SLUG );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_DEFAULT_PODCAST );
        delete_option( Identifiers::OPTION_ARCHIVE_SLUG );
        delete_option( Identifiers::OPTION_DEFAULT_IMAGE_ID );
        delete_option( Identifiers::OPTION_PREACHER_LABEL );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function renderToString( SettingsPage $page ): string {
        ob_start();
        $page->render();
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function test_register_page_adds_settings_submenu_under_sermons(): void {
        global $submenu;
        $submenu = array();

        ( new SettingsPage() )->registerPage();

        $parent = 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON;
        $this->assertArrayHasKey( $parent, $submenu, 'Settings must register under the Sermons menu.' );
        $slugs = array_map( static fn( $item ) => $item[2], $submenu[ $parent ] );
        $this->assertContains( SettingsPage::PAGE_SLUG, $slugs, 'The settings page slug must be registered.' );
    }

    public function test_register_page_is_capability_gated(): void {
        global $submenu;
        $submenu = array();

        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );

        ( new SettingsPage() )->registerPage();

        $parent = 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON;
        $slugs  = isset( $submenu[ $parent ] ) ? array_map( static fn( $item ) => $item[2], $submenu[ $parent ] ) : array();
        $this->assertNotContains( SettingsPage::PAGE_SLUG, $slugs, 'A non-capable user must not get the settings submenu.' );
    }

    public function test_register_sections_registers_three_sections_across_two_page_slugs(): void {
        global $wp_settings_sections;
        $wp_settings_sections = array();

        ( new SettingsPage() )->registerSections();

        // Bible + Display on the Form-1 (options.php) page slug.
        $this->assertArrayHasKey( SettingsPage::PAGE_SLUG, $wp_settings_sections );
        $this->assertArrayHasKey( 'sermonator_bible', $wp_settings_sections[ SettingsPage::PAGE_SLUG ] );
        $this->assertArrayHasKey( 'sermonator_display', $wp_settings_sections[ SettingsPage::PAGE_SLUG ] );

        // Podcast on its own (Form-2) page slug so options.php never emits it.
        $this->assertArrayHasKey( SettingsPage::PAGE_SLUG . '-podcast', $wp_settings_sections );
        $this->assertArrayHasKey( 'sermonator_podcast', $wp_settings_sections[ SettingsPage::PAGE_SLUG . '-podcast' ] );
    }

    /**
     * The page surfaces the Bible options with UI ONLY — it must NOT re-register them. After both
     * the Bible owner (SettingsRegistrar) and the page boot, the option group still holds exactly
     * the five registrar-owned options (2 Bible + 3 Display), proving the page added none.
     */
    public function test_page_does_not_register_any_option(): void {
        global $wp_registered_settings, $new_allowed_options, $allowed_options;
        $wp_registered_settings = array();
        $new_allowed_options    = array();
        $allowed_options        = array();

        ( new SettingsRegistrar() )->register();
        ( new DisplaySettingsRegistrar() )->register();

        $before = array_keys( $wp_registered_settings );

        // Booting the page's registration adds sections/fields but NO setting.
        ( new SettingsPage() )->registerSections();

        $after = array_keys( $wp_registered_settings );
        $this->assertSame( $before, $after, 'SettingsPage must not register (or re-register) any option.' );

        $group = $allowed_options[ Identifiers::OPTION_GROUP_SETTINGS ] ?? array();
        $this->assertContains( Identifiers::OPTION_BIBLE_LINK_VERSION, $group );
        $this->assertContains( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, $group );
        $this->assertContains( Identifiers::OPTION_ARCHIVE_SLUG, $group );
        $this->assertContains( Identifiers::OPTION_DEFAULT_IMAGE_ID, $group );
        $this->assertContains( Identifiers::OPTION_PREACHER_LABEL, $group );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function test_render_emits_both_forms_with_correct_actions(): void {
        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        // Form 1 → options.php (Settings API).
        $this->assertStringContainsString( 'action="options.php"', $html );
        $this->assertStringContainsString( 'name="' . Identifiers::OPTION_GROUP_SETTINGS . '"', $html, 'settings_fields hidden option-group input present.' );

        // Form 2 → admin-post.php with the controller action + nonce.
        $this->assertStringContainsString( 'admin-post.php', $html );
        $this->assertStringContainsString( 'value="' . PodcastIdentityController::ACTION . '"', $html );
        $this->assertStringContainsString( PodcastIdentityController::NONCE_FIELD, $html );

        // Display + Bible field controls render in Form 1.
        $this->assertStringContainsString( 'name="' . Identifiers::OPTION_ARCHIVE_SLUG . '"', $html );
        $this->assertStringContainsString( 'name="' . Identifiers::OPTION_DEFAULT_IMAGE_ID . '"', $html );
        $this->assertStringContainsString( 'name="' . Identifiers::OPTION_BIBLE_LINK_VERSION . '"', $html );
    }

    public function test_render_prefills_current_display_values(): void {
        update_option( Identifiers::OPTION_PREACHER_LABEL, 'Speaker' );
        update_option( Identifiers::OPTION_ARCHIVE_SLUG, 'messages' );

        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        $this->assertStringContainsString( 'value="Speaker"', $html );
        $this->assertStringContainsString( 'value="messages"', $html );
    }

    // -------------------------------------------------------------------------
    // Phase-aware podcast section (spec §1.5)
    // -------------------------------------------------------------------------

    public function test_podcast_section_is_read_only_while_migration_pending(): void {
        $this->fixture->createSermon();              // legacy data present
        ( new MigrationState() )->set( 'detected' ); // phase != finalized

        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        $this->assertStringContainsString( 'after the migration completes', $html );
        // Read-only: podcast inputs are disabled and there is no podcast submit button.
        $this->assertStringContainsString( 'disabled', $html );
        $this->assertStringNotContainsString( 'Save podcast settings', $html );
    }

    public function test_podcast_section_is_editable_on_fresh_site(): void {
        // No legacy data → not read-only.
        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        $this->assertStringContainsString( 'Save podcast settings', $html );
        $this->assertStringContainsString( 'name="title"', $html, 'Editable podcast title field present.' );
        $this->assertStringContainsString( 'name="owner_email"', $html );
    }

    public function test_podcast_section_prefills_from_default_podcast_meta(): void {
        $podcastId = (int) wp_insert_post( array(
            'post_type'   => Identifiers::POST_TYPE_PODCAST,
            'post_status' => 'publish',
            'post_title'  => 'Existing Feed',
        ) );
        update_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, array(
            'title'       => 'Grace Sermons',
            'owner_email' => 'pastor@example.com',
        ) );
        update_option( Identifiers::OPTION_DEFAULT_PODCAST, $podcastId );

        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        $this->assertStringContainsString( 'value="Grace Sermons"', $html );
        $this->assertStringContainsString( 'value="pastor@example.com"', $html );
    }

    /**
     * The 'explicit' checkbox (the only T_BOOL field, emitted as <itunes:explicit> to Apple/Spotify)
     * MUST render a hidden companion input value="0" immediately before it, so an unchecked box still
     * submits the key. Without it, PodcastIdentityController::writeThrough (merge-by-presence) could
     * never clear a stored explicit=true through the UI — a one-way latch. The companion is the
     * standard WP pattern: checked posts "1", unchecked posts the hidden "0" (last value wins).
     */
    public function test_explicit_checkbox_emits_hidden_companion_when_editable(): void {
        // No legacy data → editable (not read-only).
        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        // The hidden companion precedes the visible checkbox, both named "explicit".
        $this->assertStringContainsString(
            '<input type="hidden" name="explicit" value="0">',
            $html,
            'Editable explicit checkbox must have a hidden value="0" companion so unchecking clears the flag.'
        );
        // And the visible checkbox posts "1".
        $this->assertMatchesRegularExpression(
            '/name="explicit" value="0">.*type="checkbox"[^>]*name="explicit" value="1"/s',
            $html,
            'The hidden companion must come BEFORE the checkbox (last value wins).'
        );
    }

    /**
     * In the read-only podcast section (migration mid-flight) the checkbox is disabled and the
     * controller refuses the write regardless, so the hidden companion is suppressed — a mid-migration
     * save must not even appear to submit a value.
     */
    public function test_explicit_checkbox_suppresses_hidden_companion_when_read_only(): void {
        $this->fixture->createSermon();              // legacy data present
        ( new MigrationState() )->set( 'detected' ); // phase != finalized

        ( new SettingsPage() )->registerSections();
        $html = $this->renderToString( new SettingsPage() );

        $this->assertStringNotContainsString(
            '<input type="hidden" name="explicit" value="0">',
            $html,
            'Read-only podcast section must NOT emit the explicit hidden companion.'
        );
    }

    // -------------------------------------------------------------------------
    // Settings-save round-trip (Form 1 / options.php sanitize path)
    // -------------------------------------------------------------------------

    public function test_form_one_save_round_trips_through_registrar_sanitize(): void {
        // register_setting attaches the sanitize_option_{$option} filter that update_option() runs;
        // booting the registrars wires it so a Form-1 save round-trips through the SAME sanitize the
        // page's UI feeds. (The page itself registers nothing — see test_page_does_not_register_any_option.)
        ( new SettingsRegistrar() )->register();
        ( new DisplaySettingsRegistrar() )->register();

        // A valid, non-reserved, non-colliding slug survives the save verbatim.
        update_option( Identifiers::OPTION_ARCHIVE_SLUG, 'preaching' );
        $this->assertSame( 'preaching', get_option( Identifiers::OPTION_ARCHIVE_SLUG ) );

        // A reserved slug is rejected back to the stored value (never silently adopted).
        update_option( Identifiers::OPTION_ARCHIVE_SLUG, 'feed' );
        $this->assertSame( 'preaching', get_option( Identifiers::OPTION_ARCHIVE_SLUG ) );

        // A bogus (non-attachment) image id floors to 0.
        update_option( Identifiers::OPTION_DEFAULT_IMAGE_ID, 999999 );
        $this->assertSame( 0, (int) get_option( Identifiers::OPTION_DEFAULT_IMAGE_ID ) );

        // The preacher label round-trips, sanitized.
        update_option( Identifiers::OPTION_PREACHER_LABEL, '  Speaker  ' );
        $this->assertSame( 'Speaker', get_option( Identifiers::OPTION_PREACHER_LABEL ) );
    }
}
