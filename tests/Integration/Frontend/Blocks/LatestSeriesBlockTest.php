<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Blocks;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * End-to-end render of the sermonator/latest-series block through the shared Renderer.
 * Registration happens via FrontendServiceProvider on init (booted by the plugin), so these
 * assert real block output.
 *
 * Contracts under test:
 *   1. "latest" = the TAX_SERIES term of the most-recently-preached sermon (greatest
 *      META_DATE), optionally constrained by serviceType (TAX_SERVICE_TYPE).
 *   2. The term image resolves from OPTION_TERM_IMAGES keyed by term_taxonomy_id (tt_id) —
 *      matching ArtworkWriter — NOT term_id.
 *   3. No sermon / no series term → '' (empty-state).
 *
 * NOTE: written for the wp-env integration suite; not run in this environment (no Docker).
 */
final class LatestSeriesBlockTest extends WP_UnitTestCase {
    private function makeSeriesTerm( string $name, string $description = '' ): array {
        $term = self::factory()->term->create_and_get( array(
            'taxonomy'    => ID::TAX_SERIES,
            'name'        => $name,
            'description' => $description,
        ) );
        return array( (int) $term->term_id, (int) $term->term_taxonomy_id );
    }

    private function makeImageAttachment(): int {
        $attId = (int) self::factory()->attachment->create_object(
            'series-art.jpg',
            0,
            array(
                'post_mime_type' => 'image/jpeg',
                'post_type'      => 'attachment',
            )
        );
        wp_update_attachment_metadata( $attId, array(
            'width'  => 1200,
            'height' => 800,
            'file'   => 'series-art.jpg',
            'sizes'  => array(
                'large' => array(
                    'file'      => 'series-art-1024x683.jpg',
                    'width'     => 1024,
                    'height'    => 683,
                    'mime-type' => 'image/jpeg',
                ),
            ),
        ) );
        return $attId;
    }

    /**
     * Create a published sermon preached on $date (Y-m-d), assigned to the given series
     * term_id and (optionally) a service-type term_id.
     */
    private function makeSermon( int $seriesTermId, string $date, ?int $serviceTypeTermId = null ): int {
        $postId = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'Sermon ' . $date,
        ) );
        update_post_meta( $postId, ID::META_DATE, (string) strtotime( $date . ' 00:00:00 UTC' ) );
        wp_set_object_terms( $postId, array( $seriesTermId ), ID::TAX_SERIES );
        if ( null !== $serviceTypeTermId ) {
            wp_set_object_terms( $postId, array( $serviceTypeTermId ), ID::TAX_SERVICE_TYPE );
        }
        return $postId;
    }

    public function test_resolves_most_recent_series_with_tt_id_image(): void {
        [ $oldSeries ]               = $this->makeSeriesTerm( 'Old Series' );
        [ $newSeries, $newSeriesTt ] = $this->makeSeriesTerm( 'Latest Series', 'The newest study.' );
        $attId                       = $this->makeImageAttachment();

        // OPTION_TERM_IMAGES is keyed by tt_id (ArtworkWriter), not term_id.
        update_option( ID::OPTION_TERM_IMAGES, array( $newSeriesTt => $attId ) );

        $this->makeSermon( $oldSeries, '2021-01-03' );
        $this->makeSermon( $newSeries, '2024-09-15' ); // most recent → wins

        $html = do_blocks( '<!-- wp:sermonator/latest-series /-->' );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringContainsString( 'Latest Series', $html );
        // Check for the image URL rather than the wp-image-{id} CSS class: the class is
        // an editor/content artifact whose presence depends on WP version and environment.
        // The src URL is what actually proves the correct attachment was rendered.
        $this->assertStringContainsString( (string) wp_get_attachment_image_url( $attId, 'large' ), $html );
        $this->assertStringNotContainsString( 'Old Series', $html );
    }

    public function test_service_type_constrains_which_sermon_is_latest(): void {
        [ $amSeries ] = $this->makeSeriesTerm( 'Morning Series' );
        [ $pmSeries ] = $this->makeSeriesTerm( 'Evening Series' );

        $amType = (int) self::factory()->term->create( array(
            'taxonomy' => ID::TAX_SERVICE_TYPE,
            'name'     => 'Sunday AM',
            'slug'     => 'sunday-am',
        ) );
        $pmType = (int) self::factory()->term->create( array(
            'taxonomy' => ID::TAX_SERVICE_TYPE,
            'name'     => 'Sunday PM',
            'slug'     => 'sunday-pm',
        ) );

        // The globally-latest sermon is PM; the latest AM sermon is older.
        $this->makeSermon( $amSeries, '2024-03-10', $amType );
        $this->makeSermon( $pmSeries, '2024-09-15', $pmType );

        $html = do_blocks(
            '<!-- wp:sermonator/latest-series {"serviceType":"sunday-am"} /-->'
        );

        // Constrained to AM → resolves the AM series, NOT the globally-latest PM one.
        $this->assertStringContainsString( 'Morning Series', $html );
        $this->assertStringNotContainsString( 'Evening Series', $html );
    }

    public function test_show_title_and_description_attributes_wire_through(): void {
        [ $series, $seriesTt ] = $this->makeSeriesTerm( 'Latest Series', 'A study on <em>hope</em>.' );
        $attId                 = $this->makeImageAttachment();
        update_option( ID::OPTION_TERM_IMAGES, array( $seriesTt => $attId ) );
        $this->makeSermon( $series, '2024-09-15' );

        $html = do_blocks(
            '<!-- wp:sermonator/latest-series {"showTitle":false,"showDescription":true} /-->'
        );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringNotContainsString( 'sermonator-latest-series__title', $html );
        $this->assertStringContainsString( 'sermonator-latest-series__description', $html );
        $this->assertStringContainsString( 'A study on <em>hope</em>.', $html );
    }

    public function test_no_sermon_renders_nothing(): void {
        $html = do_blocks( '<!-- wp:sermonator/latest-series /-->' );

        $this->assertStringNotContainsString( 'sermonator-latest-series', $html );
    }

    public function test_sermon_without_series_term_renders_nothing(): void {
        $postId = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );
        update_post_meta( $postId, ID::META_DATE, (string) strtotime( '2024-09-15 00:00:00 UTC' ) );
        // No TAX_SERIES assignment.

        $html = do_blocks( '<!-- wp:sermonator/latest-series /-->' );

        $this->assertStringNotContainsString( 'sermonator-latest-series', $html );
    }
}
