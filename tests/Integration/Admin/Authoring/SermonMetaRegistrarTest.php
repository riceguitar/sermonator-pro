<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\MigrationGuard;
use Sermonator\Admin\Authoring\SermonMetaRegistrar;
use Sermonator\Schema\Identifiers;
use WP_REST_Request;

/**
 * Integration tests for the authoring meta write contract: REST round-trip, auth, sanitization,
 * and the migration gate.
 */
final class SermonMetaRegistrarTest extends WP_UnitTestCase {
    private int $editorId;
    private int $subscriberId;

    protected function setUp(): void {
        parent::setUp();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );

        $this->editorId = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        $this->subscriberId = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $this->editorId );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Reset the REST server and post-type controller, then register meta, so the schema is
     * built with the meta keys present.
     */
    private function resetRestWithMeta(): void {
        global $wp_rest_server;
        $wp_rest_server = null;

        $post_type_object = get_post_type_object( Identifiers::POST_TYPE_SERMON );
        if ( $post_type_object ) {
            $post_type_object->rest_controller = null;
        }

        ( new SermonMetaRegistrar() )->register();
        rest_get_server();
    }

    public function test_meta_round_trips_via_rest_with_sanitization(): void {
        $this->resetRestWithMeta();

        $post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/' . Identifiers::POST_TYPE_SERMON . '/' . $post_id );
        $request->set_body_params( array(
            'meta' => array(
                Identifiers::META_BIBLE_PASSAGE => '  John 1:1-14  ',
                Identifiers::META_AUDIO         => 'http://example.com/sermon.mp3',
                Identifiers::META_VIDEO_URL     => 'http://example.com/video',
                Identifiers::META_VIDEO_EMBED   => '<iframe src="http://example.com" style="evil"></iframe>',
                Identifiers::META_DATE          => 1734775200,
                Identifiers::META_DATE_AUTO     => 0,
            ),
        ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $this->assertSame( 'John 1:1-14', get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true ) );
        $this->assertSame( 'http://example.com/sermon.mp3', get_post_meta( $post_id, Identifiers::META_AUDIO, true ) );
        $this->assertSame( 'http://example.com/video', get_post_meta( $post_id, Identifiers::META_VIDEO_URL, true ) );
        $this->assertStringContainsString( '<iframe', get_post_meta( $post_id, Identifiers::META_VIDEO_EMBED, true ) );
        $this->assertStringNotContainsString( 'style', get_post_meta( $post_id, Identifiers::META_VIDEO_EMBED, true ) );
    }

    public function test_non_editor_is_denied_rest_meta_write(): void {
        $this->resetRestWithMeta();
        wp_set_current_user( $this->subscriberId );

        $post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );
        $request = new WP_REST_Request( 'POST', '/wp/v2/' . Identifiers::POST_TYPE_SERMON . '/' . $post_id );
        $request->set_body_params( array(
            'meta' => array(
                Identifiers::META_BIBLE_PASSAGE => 'Hacked',
            ),
        ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( '', get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true ) );
    }

    public function test_writes_are_denied_during_active_migration(): void {
        update_option( Identifiers::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );
        $this->assertFalse( MigrationGuard::editingAllowed() );

        $this->resetRestWithMeta();

        $post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );
        $request = new WP_REST_Request( 'POST', '/wp/v2/' . Identifiers::POST_TYPE_SERMON . '/' . $post_id );
        $request->set_body_params( array(
            'meta' => array(
                Identifiers::META_BIBLE_PASSAGE => 'Should not stick',
            ),
        ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( '', get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true ) );
    }

    public function test_protected_audio_meta_is_readable_via_rest(): void {
        $this->resetRestWithMeta();

        $post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );
        update_post_meta( $post_id, Identifiers::META_AUDIO_DURATION, '00:42:00' );
        update_post_meta( $post_id, Identifiers::META_AUDIO_SIZE, 12345 );

        $request  = new WP_REST_Request( 'GET', '/wp/v2/' . Identifiers::POST_TYPE_SERMON . '/' . $post_id );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( '00:42:00', $data['meta'][ Identifiers::META_AUDIO_DURATION ] );
        $this->assertSame( 12345, $data['meta'][ Identifiers::META_AUDIO_SIZE ] );
    }
}
