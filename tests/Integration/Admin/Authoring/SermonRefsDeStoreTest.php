<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\SermonMetaRegistrar;
use Sermonator\Admin\Authoring\SermonRefsCapture;
use Sermonator\Admin\Authoring\SermonRefsRestSanitizer;
use Sermonator\Bible\DerivedExactClassifier;
use Sermonator\Schema\Identifiers as ID;
use WP_REST_Request;

/**
 * Integration coverage for the de-store ENFORCEMENT on the authoring write path
 * (design §3.4, T-D): the confirm-chip REST write must REJECT a client-supplied
 * `confidence:derived-exact*` — the de-stored render-time floor tier — and stamp the
 * server-authored `exact` instead (server-side stamp wins). This closes the bypass where
 * a forged pre-stamp would clear the inline floor without running the
 * {@see DerivedExactClassifier}.
 *
 * Drives the full /wp/v2 post controller save path so the rejection is proven through the
 * real REST stack, not just the unit-level {@see SermonRefsRestSanitizer::stamp()}.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env).
 */
final class SermonRefsDeStoreTest extends WP_UnitTestCase {
    private int $editorId;

    protected function setUp(): void {
        parent::setUp();

        delete_option( ID::OPTION_MIGRATION_STATE );
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $this->editorId = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $this->editorId );

        ( new SermonRefsRestSanitizer() )->hook();
        ( new SermonRefsCapture() )->hook();
        $this->resetRestWithMeta();
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function resetRestWithMeta(): void {
        global $wp_rest_server;
        $wp_rest_server = null;

        $pt = get_post_type_object( ID::POST_TYPE_SERMON );
        if ( $pt ) {
            $pt->rest_controller = null;
        }

        ( new SermonMetaRegistrar() )->register();
        do_action( 'rest_api_init' );
        rest_get_server();
    }

    /** @return array<int,array<string,mixed>> decoded refs persisted on a post */
    private function refs( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
    }

    private function writeSermon( string $passage, array $envelope ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/' . ID::POST_TYPE_SERMON . '/' . $id );
        $request->set_body_params( array(
            'meta' => array(
                ID::META_BIBLE_PASSAGE => $passage,
                ID::META_BIBLE_REFS    => (string) wp_json_encode( $envelope ),
            ),
        ) );
        rest_get_server()->dispatch( $request );

        return $id;
    }

    public function test_client_supplied_derived_exact_is_rejected_server_stamp_wins(): void {
        foreach (
            array(
                DerivedExactClassifier::FLOOR_DERIVED_EXACT,
                DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
            ) as $forged
        ) {
            $id = $this->writeSermon( 'John 3:16', array(
                'v'    => 1,
                'refs' => array(
                    array(
                        'bookUSFM'                   => 'JHN',
                        'chapterStart'               => 3,
                        'verseStart'                 => 16,
                        'verseEnd'                   => 16,
                        'chapterEnd'                 => null,
                        'raw'                        => 'John 3:16',
                        // Forged de-stored render-time tier — must never persist.
                        'confidence'                 => $forged,
                        'srcVersificationConfidence' => 'authored',
                    ),
                ),
            ) );

            $refs = $this->refs( $id );
            $this->assertCount( 1, $refs );
            $this->assertSame(
                'exact',
                $refs[0]['confidence'],
                'Confirm-chip server stamp wins over the forged tier.'
            );
            $this->assertNotSame(
                $forged,
                $refs[0]['confidence'],
                'A de-stored floor tier is never persisted via the REST write.'
            );
        }
    }
}
