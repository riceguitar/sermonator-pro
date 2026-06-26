<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\PodcastIdentityController;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\Identifiers;
use Sermonator\Schema\PodcastMetaSchema;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Integration coverage for {@see PodcastIdentityController}, driving REAL WordPress posts,
 * options, post meta, and the Detector's WP_Query against seeded legacy data (no Brain
 * Monkey). NOT run in this environment (no Docker / wp-env) — authored to run under wp-env
 * later.
 *
 * Exercises what the unit test cannot: the genuine {@see Detector::hasLegacyData()} probe over
 * real legacy rows, the genuine {@see MigrationState} option-backed phase, real
 * `wp_insert_post` / `update_option` auto-create, and the real {@see PodcastMetaSchema}
 * sanitize-on-merge round-trip into `get_post_meta`. Pins the spec §1.5 phase gate (fresh →
 * auto-create + write; legacy + unfinalized → refuse with NO mutation) and the §1.6
 * sanitize + merge write-through (identity fields cleaned + overlaid WITHOUT dropping the
 * migration's term-filter scope keys, and the feed-injection sanitize actually applied).
 *
 * handle() is driven directly: dispatch() adds only the redirect/exit shim over it, which adds
 * no separately testable data behavior.
 */
final class PodcastIdentityControllerTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;
    private int $adminId;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_DEFAULT_PODCAST );

        $this->adminId = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->adminId );
    }

    /** A request carrying a VALID nonce for the Form-2 action plus the given fields. */
    private function request( array $fields ): array {
        return array_merge(
            array( PodcastIdentityController::NONCE_FIELD => wp_create_nonce( PodcastIdentityController::NONCE_ACTION ) ),
            $fields
        );
    }

    // --- gates ---------------------------------------------------------------

    public function test_invalid_nonce_is_denied_and_writes_nothing(): void {
        $result = ( new PodcastIdentityController() )->handle(
            array(
                PodcastIdentityController::NONCE_FIELD => 'not-a-real-nonce',
                'title'                                => 'Should Not Save',
            )
        );

        $this->assertSame( 'denied', $result['status'] );
        $this->assertSame( 0, (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 ) );
    }

    public function test_missing_capability_is_denied(): void {
        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );

        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array( 'title' => 'Should Not Save' ) )
        );

        $this->assertSame( 'denied', $result['status'] );
    }

    // --- phase gate: legacy data present, not finalized → REFUSE ------------

    public function test_legacy_data_unfinalized_refuses_without_creating_or_writing(): void {
        // Real legacy sermon row → Detector::hasLegacyData() is true.
        $this->fixture->createSermon();
        ( new MigrationState() )->set( 'detected' ); // phase != finalized

        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array( 'title' => 'Premature Podcast' ) )
        );

        $this->assertSame( 'refused', $result['status'] );
        // No podcast was auto-created, and the default-podcast pointer is still unset.
        $this->assertSame( 0, (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 ) );
        $this->assertSame( 0, count( get_posts( array(
            'post_type'      => Identifiers::POST_TYPE_PODCAST,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ) ) ) );
    }

    // --- phase gate: fresh site → auto-create + write-through ---------------

    public function test_fresh_site_auto_creates_default_podcast_and_round_trips_identity(): void {
        // No legacy data seeded → genuinely fresh site.
        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array(
                'title'       => 'Grace Sermons',
                'owner_email' => 'pastor@example.com',
                'explicit'    => 'yes',
            ) )
        );

        $this->assertSame( 'saved', $result['status'] );

        $podcastId = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );
        $this->assertGreaterThan( 0, $podcastId );
        $this->assertSame( Identifiers::POST_TYPE_PODCAST, get_post_type( $podcastId ) );
        $this->assertSame( $podcastId, $result['podcastId'] );

        $settings = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertIsArray( $settings );
        $this->assertSame( 'Grace Sermons', $settings['title'] );
        $this->assertSame( 'pastor@example.com', $settings['owner_email'] );
        // explicit is sanitized to a real bool by the schema.
        $this->assertTrue( $settings['explicit'] );
    }

    public function test_second_save_reuses_the_same_default_podcast(): void {
        $controller = new PodcastIdentityController();
        $controller->handle( $this->request( array( 'title' => 'First' ) ) );
        $first = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );

        $controller->handle( $this->request( array( 'title' => 'Second' ) ) );
        $second = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );

        $this->assertSame( $first, $second, 'A second save must not mint a new default podcast.' );
        $settings = get_post_meta( $first, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( 'Second', $settings['title'] );
    }

    // --- write-through MERGE preserves migration term-filter scope keys -----

    public function test_write_through_merges_preserving_term_filter_scope_keys(): void {
        // A pre-existing default podcast carrying BOTH an identity field and a migration
        // term-filter scope key (the kind PodcastWriter::remapSettingsTerms writes).
        $podcastId = (int) wp_insert_post( array(
            'post_type'   => Identifiers::POST_TYPE_PODCAST,
            'post_status' => 'publish',
            'post_title'  => 'Existing Feed',
        ) );
        update_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, array(
            'title'             => 'Old Title',
            'sermonator_series' => array( 12, 13 ),
        ) );
        update_option( Identifiers::OPTION_DEFAULT_PODCAST, $podcastId );

        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array( 'title' => 'New Title', 'author' => 'Pastor Dave' ) )
        );

        $this->assertSame( 'saved', $result['status'] );

        $settings = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        // Identity fields updated...
        $this->assertSame( 'New Title', $settings['title'] );
        $this->assertSame( 'Pastor Dave', $settings['author'] );
        // ...and the migration's term-filter scope key survives the merge verbatim.
        $this->assertSame( array( 12, 13 ), $settings['sermonator_series'] );
    }

    // --- explicit checkbox toggle (hidden-companion round-trip) -------------

    /**
     * The 'explicit' flag is the only T_BOOL/checkbox field, and it is the value the feed emits as
     * <itunes:explicit> to Apple/Spotify. An unchecked HTML checkbox submits NO key; the SettingsPage
     * hidden companion (value="0") guarantees the key is ALWAYS present so writeThrough collects it.
     * This proves the controller side: once explicit=true is stored, a save carrying explicit='0'
     * (what the unchecked box posts) clears it back to false — the true→false transition the old
     * merge-by-presence design could never express.
     */
    public function test_explicit_can_be_toggled_off_via_hidden_companion_zero(): void {
        $controller = new PodcastIdentityController();

        // First save: church marks the feed explicit (checked box posts '1').
        $controller->handle( $this->request( array( 'title' => 'Grace Sermons', 'explicit' => '1' ) ) );
        $podcastId = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );
        $stored    = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertTrue( $stored['explicit'], 'explicit must store true when the box is checked.' );

        // Second save: box unchecked → the hidden companion posts '0' (key present).
        $controller->handle( $this->request( array( 'title' => 'Grace Sermons', 'explicit' => '0' ) ) );
        $stored = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );

        // PodcastMetaSchema::toBool('0') → false, and the merge overwrites the prior true.
        $this->assertFalse( $stored['explicit'], 'An unchecked box (hidden 0) must clear explicit back to false.' );
    }

    // --- sanitize-at-write (feed-injection hardening) -----------------------

    public function test_owner_email_is_sanitized_on_write(): void {
        ( new PodcastIdentityController() )->handle(
            $this->request( array( 'owner_email' => 'not a valid <b>email</b>' ) )
        );

        $podcastId = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );
        $settings  = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );

        // sanitize_email strips the markup/spaces — the raw injection never reaches the feed.
        $this->assertSame( sanitize_email( 'not a valid <b>email</b>' ), $settings['owner_email'] );
        $this->assertStringNotContainsString( '<b>', (string) $settings['owner_email'] );
    }

    public function test_non_allowlisted_post_fields_are_not_persisted_as_identity(): void {
        ( new PodcastIdentityController() )->handle(
            $this->request( array(
                'title'            => 'Clean',
                'action'           => PodcastIdentityController::ACTION,
                '_wp_http_referer' => '/wp-admin/edit.php',
                'evil'             => 'rm -rf',
            ) )
        );

        $podcastId = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );
        $settings  = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );

        $this->assertArrayHasKey( 'title', $settings );
        $this->assertArrayNotHasKey( 'evil', $settings );
        $this->assertArrayNotHasKey( 'action', $settings );
        $this->assertArrayNotHasKey( '_wp_http_referer', $settings );
        // Only allowlisted identity keys are written; the form's plumbing fields never land.
        foreach ( array_keys( $settings ) as $key ) {
            $this->assertContains( $key, PodcastMetaSchema::keys() );
        }
    }
}
