<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Admin\PodcastIdentityController;
use Sermonator\Schema\Identifiers;

/**
 * Unit coverage for {@see PodcastIdentityController} with Brain Monkey stubs.
 *
 * Drives {@see PodcastIdentityController::handle()} — the testable, gated core — directly,
 * asserting the spec §1.5 phase-gate DECISION (fresh → auto-create + save; legacy + unfinalized
 * → refuse with no write) and the spec §1.6 sanitize + MERGE write-through (identity fields are
 * sanitized through the shared schema and overlaid onto the existing meta array WITHOUT dropping
 * the migration's term-filter scope keys). The redirect/exit side effects live in dispatch() and
 * are exercised by the (un-run) integration test, not here.
 */
final class PodcastIdentityControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Sensible defaults; individual tests override.
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( '__' )->returnArg();
        Functions\when( 'add_settings_error' )->justReturn( null );
        Functions\when( 'clean_post_cache' )->justReturn( null );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'get_bloginfo' )->justReturn( 'Grace Church' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** A request with a valid nonce field. */
    private function request( array $extra = array() ): array {
        return array_merge(
            array( PodcastIdentityController::NONCE_FIELD => 'ok' ),
            $extra
        );
    }

    // --- gates ---------------------------------------------------------------

    public function test_missing_capability_is_denied_and_writes_nothing(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\expect( 'update_post_meta' )->never();
        Functions\expect( 'wp_insert_post' )->never();

        $result = ( new PodcastIdentityController() )->handle( $this->request() );

        $this->assertSame( 'denied', $result['status'] );
    }

    public function test_invalid_nonce_is_denied_and_writes_nothing(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\expect( 'update_post_meta' )->never();
        Functions\expect( 'wp_insert_post' )->never();

        $result = ( new PodcastIdentityController() )->handle( $this->request() );

        $this->assertSame( 'denied', $result['status'] );
    }

    // --- phase gate: legacy data present, not finalized → REFUSE -------------

    public function test_legacy_data_unfinalized_refuses_without_write(): void {
        // Detector::hasLegacyData() → true (legacy posts exist); MigrationState::phase() → migrated.
        Functions\when( 'get_option' )->alias( static function ( $name ) {
            if ( Identifiers::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'migrated' );
            }
            return false;
        } );
        // Detector probes via WP_Query; make found_posts > 0.
        $this->stubLegacyData( true );

        Functions\expect( 'update_post_meta' )->never();
        Functions\expect( 'wp_insert_post' )->never();
        Functions\expect( 'update_option' )->never();

        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array( 'title' => 'My Podcast' ) )
        );

        $this->assertSame( 'refused', $result['status'] );
    }

    // --- phase gate: fresh site → auto-create + write -----------------------

    public function test_fresh_site_auto_creates_default_podcast_and_writes(): void {
        // No legacy data; no stored default podcast yet.
        $this->stubLegacyData( false );
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            if ( Identifiers::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'none' );
            }
            if ( Identifiers::OPTION_DEFAULT_PODCAST === $name ) {
                return 0; // none stored → must auto-create
            }
            return $default;
        } );
        Functions\when( 'get_post_type' )->justReturn( false );
        Functions\when( 'get_post_meta' )->justReturn( array() );

        // Auto-create returns a new podcast id and points the option at it.
        Functions\expect( 'wp_insert_post' )->once()->andReturn( 77 );
        Functions\expect( 'update_option' )
            ->once()
            ->with( Identifiers::OPTION_DEFAULT_PODCAST, 77 );

        // The write-through persists the sanitized identity onto the new post.
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\expect( 'update_post_meta' )
            ->once()
            ->with(
                77,
                Identifiers::META_PODCAST_SETTINGS,
                \Mockery::on( static function ( $value ): bool {
                    return is_array( $value ) && ( $value['title'] ?? null ) === 'My Show';
                } )
            );

        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array( 'title' => 'My Show' ) )
        );

        $this->assertSame( 'saved', $result['status'] );
        $this->assertSame( 77, $result['podcastId'] );
    }

    // --- write-through MERGE preserves term-filter scope keys ---------------

    public function test_write_through_merges_into_existing_meta_preserving_scope_keys(): void {
        $this->stubLegacyData( false );
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            if ( Identifiers::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => 'none' );
            }
            if ( Identifiers::OPTION_DEFAULT_PODCAST === $name ) {
                return 42; // an existing default podcast
            }
            return $default;
        } );
        Functions\when( 'get_post_type' )->justReturn( Identifiers::POST_TYPE_PODCAST );

        // Existing meta carries a migration term-filter scope key + an identity field.
        Functions\when( 'get_post_meta' )->justReturn( array(
            'title'                => 'Old Title',
            'sermonator_series'    => array( 12, 13 ),
        ) );

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\expect( 'wp_insert_post' )->never();

        $captured = null;
        Functions\expect( 'update_post_meta' )
            ->once()
            ->andReturnUsing( static function ( $id, $key, $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } );

        $result = ( new PodcastIdentityController() )->handle(
            $this->request( array( 'title' => 'New Title', 'author' => 'Pastor Dave' ) )
        );

        $this->assertSame( 'saved', $result['status'] );
        $this->assertIsArray( $captured );
        // Identity fields overwritten...
        $this->assertSame( 'New Title', $captured['title'] );
        $this->assertSame( 'Pastor Dave', $captured['author'] );
        // ...but the migration term-filter scope key is preserved verbatim.
        $this->assertSame( array( 12, 13 ), $captured['sermonator_series'] );
    }

    /**
     * Stub the WP_Query the Detector uses for hasLegacyData(): found_posts reflects $hasData.
     * Also stubs the LegacySchemaRegistrar's re-register side effects to no-ops.
     */
    private function stubLegacyData( bool $hasData ): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            eval(
                'class WP_Query { public $found_posts = 0;'
                . ' public function __construct( $args = array() ) { $this->found_posts = $GLOBALS["__sermonator_legacy_found"] ?? 0; } }'
            );
        }
        $GLOBALS['__sermonator_legacy_found'] = $hasData ? 1 : 0;

        // Detector::hasLegacyData() calls LegacySchemaRegistrar::ensureRegistered(), which under
        // unit isolation touches register_post_type/taxonomy — stub them as harmless no-ops.
        Functions\when( 'register_post_type' )->justReturn( null );
        Functions\when( 'register_taxonomy' )->justReturn( null );
        Functions\when( 'post_type_exists' )->justReturn( true );
        Functions\when( 'taxonomy_exists' )->justReturn( true );
    }
}
