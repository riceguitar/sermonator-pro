<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\AuthoringServiceProvider;
use Sermonator\Schema\Identifiers;

final class SermonDateNormalizerTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        update_option( 'timezone_string', 'America/New_York' );

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( 'timezone_string' );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function test_empty_date_filled_from_publish_date(): void {
        $date_gmt = '2025-12-21 09:00:00';
        $post_id  = self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_date'   => $date_gmt,
            'post_date_gmt' => $date_gmt,
            'post_status' => 'publish',
        ) );

        $this->assertSame( '1766307600', get_post_meta( $post_id, Identifiers::META_DATE, true ) );
        $this->assertSame( '1', get_post_meta( $post_id, Identifiers::META_DATE_AUTO, true ) );
    }

    public function test_explicit_date_sets_auto_to_zero(): void {
        $post_id = self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );
        update_post_meta( $post_id, Identifiers::META_DATE, '1734775200' );

        // Re-save to trigger normalizer.
        wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Updated' ) );

        $this->assertSame( '1734775200', get_post_meta( $post_id, Identifiers::META_DATE, true ) );
        $this->assertSame( '0', get_post_meta( $post_id, Identifiers::META_DATE_AUTO, true ) );
    }

    public function test_migration_phase_blocks_normalizer(): void {
        update_option( Identifiers::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $post_id = self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );

        $this->assertSame( '', get_post_meta( $post_id, Identifiers::META_DATE, true ) );
        $this->assertSame( '', get_post_meta( $post_id, Identifiers::META_DATE_AUTO, true ) );
    }

    public function test_day_boundary_renders_intended_day_in_site_timezone(): void {
        // 2025-12-21 04:00 UTC is 2025-12-20 23:00 EST (America/New_York).
        $post_id = self::factory()->post->create( array(
            'post_type'     => Identifiers::POST_TYPE_SERMON,
            'post_date'     => '2025-12-20 23:00:00',
            'post_date_gmt' => '2025-12-21 04:00:00',
            'post_status'   => 'publish',
        ) );

        $timestamp = (int) get_post_meta( $post_id, Identifiers::META_DATE, true );
        $this->assertSame( '2025-12-20', wp_date( 'Y-m-d', $timestamp ) );
    }
}
