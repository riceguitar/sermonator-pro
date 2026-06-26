<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\AudioMetaController;
use Sermonator\Admin\Authoring\AuthoringServiceProvider;
use Sermonator\Schema\Identifiers;
use WP_REST_Request;

final class AudioMetaControllerTest extends WP_UnitTestCase {
    private int $editorId;
    private int $subscriberId;

    protected function setUp(): void {
        parent::setUp();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        ( new AuthoringServiceProvider() )->hook();

        global $wp_rest_server;
        $wp_rest_server = null;
        do_action( 'rest_api_init' );

        $this->editorId = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        $this->subscriberId = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $this->editorId );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function test_attachment_path_returns_metadata(): void {
        $attachment_id = self::factory()->attachment->create_object(
            'sermon.mp3',
            0,
            array(
                'post_mime_type' => 'audio/mpeg',
                'post_type'      => 'attachment',
            )
        );
        wp_update_attachment_metadata(
            $attachment_id,
            array(
                'length_formatted' => '00:42:00',
                'filesize'         => 12345,
                'mime_type'        => 'audio/mpeg',
            )
        );

        $request  = new WP_REST_Request( 'GET', '/sermonator/v1/audio-metadata' );
        $request->set_param( 'attachmentId', $attachment_id );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( '00:42:00', $data['duration'] );
        $this->assertSame( 12345, $data['size'] );
        $this->assertSame( 'audio/mpeg', $data['mime'] );
    }

    public function test_url_path_uses_head_probe(): void {
        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                return array(
                    'response' => array( 'code' => 200 ),
                    'headers'  => array(
                        'content-length' => '999',
                        'content-type'   => 'audio/mpeg',
                    ),
                );
            },
            10,
            3
        );

        $request  = new WP_REST_Request( 'GET', '/sermonator/v1/audio-metadata' );
        $request->set_param( 'url', 'https://example.com/sermon.mp3' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( '', $data['duration'] );
        $this->assertSame( 999, $data['size'] );
        $this->assertSame( 'audio/mpeg', $data['mime'] );
    }

    public function test_subscriber_is_denied(): void {
        wp_set_current_user( $this->subscriberId );

        $request = new WP_REST_Request( 'GET', '/sermonator/v1/audio-metadata' );
        $request->set_param( 'url', 'https://example.com/sermon.mp3' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_gated_during_migration(): void {
        update_option( Identifiers::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $request = new WP_REST_Request( 'GET', '/sermonator/v1/audio-metadata' );
        $request->set_param( 'url', 'https://example.com/sermon.mp3' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_missing_input_returns_error(): void {
        $request  = new WP_REST_Request( 'GET', '/sermonator/v1/audio-metadata' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }
}
