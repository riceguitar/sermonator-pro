<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Blocks;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * End-to-end render of the sermonator/sermon-images block through the shared Renderer.
 * Registration happens via FrontendServiceProvider on init (booted by the plugin), so these
 * assert real block output.
 *
 * The two contracts under test:
 *   1. OPTION_TERM_IMAGES keyed by term_taxonomy_id (tt_id) — matching ArtworkWriter —
 *      resolves the configured attachment for the matching term.
 *   2. Absent/empty option (or zero resolved images) falls back to the safe sermon list +
 *      the editor "needs review" notice, never a blank grid.
 *
 * NOTE: written for the wp-env integration suite; not run in this environment (no Docker).
 */
final class SermonImagesBlockTest extends WP_UnitTestCase {
    private function makeSeriesTerm( string $name, string $description = '' ): array {
        $term  = self::factory()->term->create_and_get( array(
            'taxonomy'    => ID::TAX_SERIES,
            'name'        => $name,
            'description' => $description,
        ) );
        return array( (int) $term->term_id, (int) $term->term_taxonomy_id );
    }

    private function makeImageAttachment(): int {
        $attId = (int) self::factory()->attachment->create_object(
            'sermon-art.jpg',
            0,
            array(
                'post_mime_type' => 'image/jpeg',
                'post_type'      => 'attachment',
            )
        );
        wp_update_attachment_metadata( $attId, array(
            'width'  => 600,
            'height' => 400,
            'file'   => 'sermon-art.jpg',
            'sizes'  => array(
                'medium' => array(
                    'file'      => 'sermon-art-300x200.jpg',
                    'width'     => 300,
                    'height'    => 200,
                    'mime-type' => 'image/jpeg',
                ),
            ),
        ) );
        return $attId;
    }

    public function test_renders_image_grid_keyed_by_tt_id(): void {
        [ , $ttId ] = $this->makeSeriesTerm( 'Grace Abounding' );
        $attId      = $this->makeImageAttachment();

        // ArtworkWriter keys the option by NEW tt_id → attachment id.
        update_option( ID::OPTION_TERM_IMAGES, array( $ttId => $attId ) );

        $html = do_blocks( '<!-- wp:sermonator/sermon-images /-->' );

        $this->assertStringContainsString( 'sermonator-image-grid', $html );
        $this->assertStringContainsString( 'Grace Abounding', $html );
        // Check for the image URL rather than the wp-image-{id} CSS class: the class is
        // an editor/content artifact whose presence depends on WP version and environment.
        // The src URL is what actually proves the correct attachment was rendered.
        $this->assertStringContainsString( (string) wp_get_attachment_image_url( $attId, 'medium' ), $html );
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_show_title_and_description_attributes_are_wired_through(): void {
        [ , $ttId ] = $this->makeSeriesTerm( 'Grace Abounding', 'A study on <em>grace</em>.' );
        $attId      = $this->makeImageAttachment();
        update_option( ID::OPTION_TERM_IMAGES, array( $ttId => $attId ) );

        // showTitle=false hides the term name; showDescription=true emits the kses'd
        // description — proving both attributes reach the output (not stored-but-dead config).
        $html = do_blocks(
            '<!-- wp:sermonator/sermon-images {"showTitle":false,"showDescription":true} /-->'
        );

        $this->assertStringContainsString( 'sermonator-image-grid', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid__name', $html );
        $this->assertStringContainsString( 'sermonator-image-grid__description', $html );
        $this->assertStringContainsString( 'A study on <em>grace</em>.', $html );
        // Title suppressed: the term name is not emitted in a name span.
        $this->assertStringNotContainsString( 'Grace Abounding</span>', $html );
    }

    public function test_default_attributes_show_title_and_hide_description(): void {
        [ , $ttId ] = $this->makeSeriesTerm( 'Grace Abounding', 'A study on grace.' );
        $attId      = $this->makeImageAttachment();
        update_option( ID::OPTION_TERM_IMAGES, array( $ttId => $attId ) );

        $html = do_blocks( '<!-- wp:sermonator/sermon-images /-->' );

        // Defaults: title visible, description hidden.
        $this->assertStringContainsString( 'sermonator-image-grid__name', $html );
        $this->assertStringContainsString( 'Grace Abounding', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid__description', $html );
    }

    public function test_term_id_collision_does_not_resolve_image(): void {
        // A series whose tt_id is NOT in the map, even if some other term's term_id equals
        // the map key, must not pull the image (proves tt_id keying, not term_id).
        [ , $ttId ] = $this->makeSeriesTerm( 'Unmapped Series' );
        $attId      = $this->makeImageAttachment();

        // Map a tt_id that does NOT belong to the created term.
        update_option( ID::OPTION_TERM_IMAGES, array( ( $ttId + 1000 ) => $attId ) );

        wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $html = do_blocks( '<!-- wp:sermonator/sermon-images /-->' );

        // Zero images resolved → safe fallback, never a blank grid.
        $this->assertStringNotContainsString( 'sermonator-image-grid', $html );
        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_absent_option_falls_back_to_safe_list_with_editor_notice(): void {
        delete_option( ID::OPTION_TERM_IMAGES );
        wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $html = do_blocks( '<!-- wp:sermonator/sermon-images /-->' );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid', $html );
    }

    public function test_fallback_has_no_editor_notice_for_anonymous_visitor(): void {
        delete_option( ID::OPTION_TERM_IMAGES );
        wp_set_current_user( 0 );

        $html = do_blocks( '<!-- wp:sermonator/sermon-images /-->' );

        // Anonymous visitors get the safe list but never the editor-only review notice.
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid', $html );
    }
}
