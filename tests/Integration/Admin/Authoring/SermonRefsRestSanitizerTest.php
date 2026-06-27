<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\SermonMetaRegistrar;
use Sermonator\Admin\Authoring\SermonRefsCapture;
use Sermonator\Admin\Authoring\SermonRefsRestSanitizer;
use Sermonator\Schema\Identifiers as ID;
use WP_REST_Request;

/**
 * Integration coverage for {@see SermonRefsRestSanitizer}: the confirm-chip REST write
 * contract end-to-end through the real /wp/v2 post controller.
 *
 * Drives the full save path: rest_pre_insert (the sanitizer stamps server-side
 * provenance, drops invalid, caps) -> meta persist (string envelope) -> rest_after_insert
 * (SermonRefsCapture's per-ref clearStale keeps confirmed refs still in the passage). The
 * client-submitted confidence/source/srcVersification* are NEVER trusted: the author
 * saving confirmed chips is the only confirmation.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env).
 */
final class SermonRefsRestSanitizerTest extends WP_UnitTestCase {
    private int $editorId;

    protected function setUp(): void {
        parent::setUp();

        delete_option( ID::OPTION_MIGRATION_STATE );
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $this->editorId = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $this->editorId );

        // Wire the two halves of the T12 write path: the rest_pre_insert stamp and the
        // rest_after_insert per-ref clearStale/producer.
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

    public function test_confirm_chip_save_persists_server_stamped_exact_envelope(): void {
        // Client forges low-trust provenance; the server must overwrite it to exact/authoring.
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
                    'source'                     => 'evil-import',
                    'confidence'                 => 'probable',
                    'srcVersification'           => 'KJV',
                    'srcVersificationConfidence' => 'authored',
                ),
            ),
        ) );

        $refs = $this->refs( $id );
        $this->assertCount( 1, $refs );
        $this->assertSame( 'exact', $refs[0]['confidence'], 'confidence forced to exact server-side' );
        $this->assertSame( 'authoring', $refs[0]['source'] );
        $this->assertSame( 'ESV', $refs[0]['srcVersification'], 'stamped from the live link version' );
        $this->assertSame( 'authored', $refs[0]['srcVersificationConfidence'] );

        // #1 data preservation: the passage label is untouched.
        $this->assertSame( 'John 3:16', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }

    public function test_invalid_ref_is_dropped_on_save(): void {
        $id = $this->writeSermon( 'John 3:16', array(
            'v'    => 1,
            'refs' => array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
                array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Bogus 1:1' ),
            ),
        ) );

        $refs = $this->refs( $id );
        $this->assertCount( 1, $refs, 'Out-of-canon ref dropped server-side.' );
        $this->assertSame( 'JHN', $refs[0]['bookUSFM'] );
    }

    public function test_ref_count_is_capped_at_fifty(): void {
        $refs    = array();
        $labels  = array();
        for ( $i = 1; $i <= 60; $i++ ) {
            $refs[]   = array( 'bookUSFM' => 'PSA', 'chapterStart' => $i, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Psalm ' . $i . ':1' );
            $labels[] = 'Psalm ' . $i . ':1';
        }

        // Passage lists every ref so clearStale keeps them all — isolating the cap.
        $id = $this->writeSermon( implode( '; ', $labels ), array( 'v' => 1, 'refs' => $refs ) );

        $this->assertCount( SermonRefsRestSanitizer::MAX_REFS, $this->refs( $id ) );
    }

    public function test_mid_migration_write_is_rejected(): void {
        update_option( ID::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $id = $this->writeSermon( 'John 3:16', array(
            'v'    => 1,
            'refs' => array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
            ),
        ) );

        // The meta auth_callback (editingAllowed) denies the write and the sanitizer is
        // inert: no envelope is persisted while a migration is active.
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
    }
}
