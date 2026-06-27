<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\AuthoringServiceProvider;
use Sermonator\Schema\Identifiers;
use WP_REST_Request;

/**
 * Integration coverage for {@see \Sermonator\Admin\Authoring\BibleParseController}: the
 * read-only confirm-chip parse-preview endpoint registered under sermonator/v1.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). It exercises the
 * real REST registration, the permission gate (capability + migration), and the
 * write-nothing contract over the live dispatch path.
 */
final class BibleParseControllerTest extends WP_UnitTestCase {
    private int $editorId;
    private int $subscriberId;

    protected function setUp(): void {
        parent::setUp();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        ( new AuthoringServiceProvider() )->hook();

        global $wp_rest_server;
        $wp_rest_server = null;
        do_action( 'rest_api_init' );

        $this->editorId     = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        $this->subscriberId = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $this->editorId );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function request( string $passage ): WP_REST_Request {
        $request = new WP_REST_Request( 'GET', '/sermonator/v1/bible-parse' );
        $request->set_param( 'passage', $passage );
        return $request;
    }

    public function test_returns_segments_and_candidate_refs(): void {
        $response = rest_get_server()->dispatch( $this->request( 'John 3:16; Romans 8:28' ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'John 3:16; Romans 8:28', $data['passage'] );
        $this->assertCount( 2, $data['segments'] );
        $this->assertCount( 2, $data['refs'] );
        $this->assertSame( 'JHN', $data['refs'][0]['bookUSFM'] );
        $this->assertTrue( $data['refs'][0]['validation']['inCanon'] );
    }

    public function test_subscriber_is_denied(): void {
        wp_set_current_user( $this->subscriberId );

        $response = rest_get_server()->dispatch( $this->request( 'John 3:16' ) );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_gated_during_migration(): void {
        update_option( Identifiers::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $response = rest_get_server()->dispatch( $this->request( 'John 3:16' ) );

        $this->assertSame( 403, $response->get_status() );
    }

    /**
     * The read-only contract end-to-end: parsing a passage that WOULD produce refs must
     * not create the META_BIBLE_REFS envelope on any post (the endpoint has no post
     * context and writes nothing).
     */
    public function test_get_writes_no_envelope(): void {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );

        rest_get_server()->dispatch( $this->request( 'John 3:16' ) );

        $this->assertSame( '', get_post_meta( $id, Identifiers::META_BIBLE_REFS, true ) );
    }
}
