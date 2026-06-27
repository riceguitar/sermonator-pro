<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * ZERO-WRITE-ON-RENDER proof for the FULL inline render path (T-J / spec §1, §8,
 * #1 data preservation): render-time promotion mutates NOTHING. The
 * `probable → inline` promotion is computed at render time by the shared pure
 * classifier; the resolver reads meta + options but NEVER writes, and the Renderer
 * is pure. So driving a real single-sermon body through `the_content` with inline
 * ENABLED and the floor set to `derived-exact` (which engages the render-time
 * classifier's re-parse-identity promotion) must leave META_BIBLE_REFS and
 * META_BIBLE_PASSAGE byte-identical, with no meta-write action firing for either key.
 *
 * This is the resolver+renderer counterpart to the pure-Renderer meta-mutation spy
 * in the unit suite: there the Renderer is proven write-free in isolation; here the
 * whole impure resolution + render pipeline is proven write-free against a real post.
 *
 * NOT run in CI here (no Docker / wp-env in this environment) — authored to run
 * under wp-env later. The plugin is already booted by the integration bootstrap.
 */
final class ScriptureRenderZeroWriteTest extends WP_UnitTestCase {
    /** @var list<string> Every meta_key written to the sermon post during render. */
    private array $metaWrites = array();

    private function sermon(): int {
        return (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_title'  => 'Zero Write Sermon',
            'post_status' => 'publish',
        ) );
    }

    /**
     * Register meta-write spies for ONE post id. WordPress fires these three actions
     * on every add/update/delete of post meta, so any write — by the resolver, the
     * classifier, or anything they call — is captured here by key.
     */
    private function spyMetaWrites( int $postId ): void {
        $this->metaWrites = array();
        $capture = function ( $meta_id, $object_id, $meta_key ) use ( $postId ): void {
            if ( (int) $object_id === $postId ) {
                $this->metaWrites[] = (string) $meta_key;
            }
        };
        add_action( 'added_post_meta', $capture, 10, 3 );
        add_action( 'updated_post_meta', $capture, 10, 3 );
        add_action( 'deleted_post_meta', $capture, 10, 3 );
    }

    public function test_inline_render_path_writes_no_bible_meta(): void {
        // Engage the render-time promotion path: inline ON, floor=derived-exact so the
        // shared classifier's re-parse-identity runs during render.
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
        update_option( ID::OPTION_BIBLE_INLINE_ENABLED, 1 );
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, 1 );
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, 'derived-exact' );

        $id = $this->sermon();

        // A concrete in-chapter verse with its own raw — the classifier re-parses
        // `raw` in isolation (render-time promotion). Stored exactly as the producer
        // wrote it; this is the byte-immutable input we must not touch.
        $refsJson = wp_json_encode( array(
            'v'    => 1,
            'refs' => array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
            ),
        ) );
        update_post_meta( $id, ID::META_BIBLE_REFS, $refsJson );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 3:16' );

        // Snapshot the byte-immutable inputs BEFORE render.
        $refsBefore    = get_post_meta( $id, ID::META_BIBLE_REFS, true );
        $passageBefore = get_post_meta( $id, ID::META_BIBLE_PASSAGE, true );

        // Now arm the spy and drive the REAL render path (resolve + Renderer via the
        // single the_content emitter).
        $this->spyMetaWrites( $id );

        $this->go_to( get_permalink( $id ) );
        $this->assertTrue( have_posts() );
        the_post();

        $out = (string) apply_filters( 'the_content', 'BODY' );

        wp_reset_postdata();

        // The section rendered (this is a live render, not a no-op).
        $this->assertStringContainsString( 'sermonator-scripture', $out );
        $this->assertStringContainsString( 'John 3:16', $out );

        // ZERO writes to the Bible meta during render — render-time promotion mutated
        // nothing.
        $this->assertNotContains( ID::META_BIBLE_REFS, $this->metaWrites, 'render must not write META_BIBLE_REFS' );
        $this->assertNotContains( ID::META_BIBLE_PASSAGE, $this->metaWrites, 'render must not write META_BIBLE_PASSAGE' );
        $this->assertNotContains( ID::META_BIBLE_REFS_UNPARSEABLE, $this->metaWrites, 'render must not write the unparseable sentinel' );

        // And the stored bytes are unchanged (belt-and-braces against any write that
        // somehow bypassed the action hooks).
        $this->assertSame( $refsBefore, get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $this->assertSame( $passageBefore, get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }
}
