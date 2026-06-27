<?php

declare(strict_types=1);

namespace {
    if ( ! \class_exists( 'WP_Post' ) ) {
        final class WP_Post {
            public int $ID = 0;
            public string $post_type = '';
        }
    }
}

namespace Sermonator\Tests\Unit\Admin\Authoring {
    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use Sermonator\Admin\Authoring\SermonRefsCapture;
    use Sermonator\Schema\Identifiers as ID;

    /**
     * Unit coverage for {@see SermonRefsCapture}: the save-time hook is correctly
     * gated (inert during an active migration, capability-checked, sermon-only) and,
     * when allowed, drives the shared {@see \Sermonator\Bible\RefsCapture} producer to
     * fill the structured envelope with the `authoring` source.
     *
     * Uses the same in-memory WP harness idiom as {@see SermonDateNormalizerTest}: a
     * seeded passage means the producer's visible effect is a written
     * {@see ID::META_BIBLE_REFS} envelope — present only when the gates allow the write.
     */
    final class SermonRefsCaptureTest extends TestCase {
        private int $postId = 7;

        /** @var array<int,array<string,mixed>> postId => (metaKey => value) */
        private array $meta = array();

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $this->meta                                = array();
            $this->meta[ $this->postId ]               = array();
            $this->meta[ $this->postId ][ ID::META_BIBLE_PASSAGE ] = 'John 3:16';

            Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
            Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => $value );

            Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
                if ( ID::OPTION_MIGRATION_STATE === $name ) {
                    return array( 'phase' => 'none' );
                }
                if ( ID::OPTION_BIBLE_LINK_VERSION === $name ) {
                    return 'ESV';
                }
                return $default;
            } );
            Functions\when( 'current_user_can' )->justReturn( true );

            Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
                return $this->meta[ (int) $id ][ $key ] ?? '';
            } );
            Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
                $this->meta[ (int) $id ][ $key ] = $value;
                return true;
            } );
            Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
                unset( $this->meta[ (int) $id ][ $key ] );
                return true;
            } );
            Functions\when( 'wp_get_object_terms' )->justReturn( array() );
            Functions\when( 'wp_set_object_terms' )->justReturn( array() );
        }

        protected function tearDown(): void {
            Monkey\tearDown();
            parent::tearDown();
        }

        private function post(): \WP_Post {
            $post            = new \WP_Post();
            $post->ID        = $this->postId;
            $post->post_type = ID::POST_TYPE_SERMON;
            return $post;
        }

        /** @return array<string,mixed> decoded envelope written for the seeded post */
        private function envelope(): array {
            $stored = $this->meta[ $this->postId ][ ID::META_BIBLE_REFS ] ?? '';
            return is_string( $stored ) && '' !== $stored ? (array) json_decode( $stored, true ) : array();
        }

        public function test_fills_envelope_with_authoring_source_on_save(): void {
            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $envelope = $this->envelope();
            $this->assertNotEmpty( $envelope, 'Envelope written on an allowed save.' );
            $this->assertSame( 'JHN', $envelope['refs'][0]['bookUSFM'] );
            $this->assertSame( 'authoring', $envelope['refs'][0]['source'] );
        }

        public function test_fills_on_rest_insert(): void {
            ( new SermonRefsCapture() )->captureRest( $this->post() );

            $this->assertNotEmpty( $this->envelope(), 'Envelope written on REST insert.' );
        }

        public function test_inert_during_active_migration(): void {
            Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
                if ( ID::OPTION_MIGRATION_STATE === $name ) {
                    return array( 'phase' => 'migrating' );
                }
                if ( ID::OPTION_BIBLE_LINK_VERSION === $name ) {
                    return 'ESV';
                }
                return $default;
            } );

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[ $this->postId ] );
        }

        public function test_insufficient_caps_blocks_capture(): void {
            Functions\when( 'current_user_can' )->justReturn( false );

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[ $this->postId ] );
        }

        public function test_ignores_non_sermon_post_type(): void {
            $post            = $this->post();
            $post->post_type = 'post';

            ( new SermonRefsCapture() )->capture( $this->postId, $post );

            $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[ $this->postId ] );
        }

        /**
         * Fix 2 regression: on the EDITABLE authoring surface a previously stamped
         * UNPARSEABLE sentinel must not become a permanent trap. After an author
         * corrects a typo'd passage to a valid reference and re-saves, the stale
         * sentinel is cleared so the producer re-evaluates the current passage and
         * finally writes the structured envelope (the backfill's frozen-data
         * idempotency is unaffected — it never clears the sentinel first).
         */
        public function test_corrected_passage_clears_stale_sentinel_and_captures(): void {
            // Author first saved a passage that parsed to zero refs -> sentinel stamped.
            $this->meta[ $this->postId ][ ID::META_BIBLE_REFS_UNPARSEABLE ] = '1';
            // ...then corrected the passage to a valid reference (seeded 'John 3:16').

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $this->assertArrayNotHasKey(
                ID::META_BIBLE_REFS_UNPARSEABLE,
                $this->meta[ $this->postId ],
                'Stale sentinel is cleared on an authoring re-save.'
            );
            $envelope = $this->envelope();
            $this->assertNotEmpty( $envelope, 'Corrected passage now produces a structured envelope.' );
            $this->assertSame( 'JHN', $envelope['refs'][0]['bookUSFM'] );
        }

        public function test_edited_passage_refreshes_a_stale_autoparse_envelope(): void {
            // An existing AUTO-PARSE envelope (confidence 'probable', not author-confirmed)
            // for the OLD passage must be re-derived when the author edits the passage and
            // re-saves — otherwise the single-sermon row keeps rendering the old reference.
            $this->meta[ $this->postId ][ ID::META_BIBLE_REFS ] = json_encode( array(
                'v'    => 1,
                'refs' => array(
                    array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16', 'source' => 'backfill', 'confidence' => 'probable', 'srcVersification' => 'ESV' ),
                ),
            ) );
            $this->meta[ $this->postId ][ ID::META_BIBLE_PASSAGE ] = 'Romans 5:1';

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $envelope = $this->envelope();
            $this->assertSame( 'ROM', $envelope['refs'][0]['bookUSFM'], 'Stale auto-parse envelope is refreshed from the edited passage.' );
            $this->assertSame( 'authoring', $envelope['refs'][0]['source'] );
        }

        public function test_confirmed_exact_ref_preserved_when_still_in_passage(): void {
            // A Phase-3b author-CONFIRMED ref (confidence 'exact') survives a re-save when
            // its (book,chapter,verse) STILL appears in the current passage parse — even
            // alongside newly-added free text.
            $this->meta[ $this->postId ][ ID::META_BIBLE_REFS ] = json_encode( array(
                'v'    => 1,
                'refs' => array(
                    array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16', 'source' => 'authoring', 'confidence' => 'exact', 'srcVersification' => 'ESV' ),
                ),
            ) );
            $this->meta[ $this->postId ][ ID::META_BIBLE_PASSAGE ] = 'John 3:16 and Romans 5:1';

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $envelope = $this->envelope();
            // The producer no-ops on the still-present envelope: only the confirmed ref
            // remains (the confirmed set is authoritative and is never auto-widened).
            $this->assertCount( 1, $envelope['refs'] );
            $this->assertSame( 'JHN', $envelope['refs'][0]['bookUSFM'], 'Confirmed ref still in the passage is preserved.' );
            $this->assertSame( 'exact', $envelope['refs'][0]['confidence'] );
        }

        public function test_orphan_exact_ref_dropped_on_passage_rewrite(): void {
            // never-fail-WRONG: a confirmed ref the rewritten passage no longer mentions
            // is an ORPHAN and must be dropped, so its inline verse text can never render
            // for a passage that lost it. With no surviving confirmed ref the producer
            // re-derives a fresh auto-parse from the new passage.
            $this->meta[ $this->postId ][ ID::META_BIBLE_REFS ] = json_encode( array(
                'v'    => 1,
                'refs' => array(
                    array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16', 'source' => 'authoring', 'confidence' => 'exact', 'srcVersification' => 'ESV' ),
                ),
            ) );
            $this->meta[ $this->postId ][ ID::META_BIBLE_PASSAGE ] = 'Romans 5:1';

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $envelope = $this->envelope();
            $this->assertCount( 1, $envelope['refs'] );
            $this->assertSame( 'ROM', $envelope['refs'][0]['bookUSFM'], 'Orphaned confirmed ref dropped; passage re-derived.' );
            $this->assertSame( 'authoring', $envelope['refs'][0]['source'] );
            $this->assertNotSame( 'exact', $envelope['refs'][0]['confidence'], 'Re-derived ref is an auto-parse, not confirmed.' );
        }

        public function test_partial_survival_keeps_present_exact_drops_orphan(): void {
            // Two confirmed refs; the passage is rewritten to keep only one of them. The
            // present one survives, the orphan is dropped, and the producer no-ops on the
            // surviving envelope (no auto-widening to the new free text).
            $this->meta[ $this->postId ][ ID::META_BIBLE_REFS ] = json_encode( array(
                'v'    => 1,
                'refs' => array(
                    array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16', 'source' => 'authoring', 'confidence' => 'exact', 'srcVersification' => 'ESV' ),
                    array( 'bookUSFM' => 'ROM', 'chapterStart' => 5, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Romans 5:1', 'source' => 'authoring', 'confidence' => 'exact', 'srcVersification' => 'ESV' ),
                ),
            ) );
            $this->meta[ $this->postId ][ ID::META_BIBLE_PASSAGE ] = 'Romans 5:1';

            ( new SermonRefsCapture() )->capture( $this->postId, $this->post() );

            $envelope = $this->envelope();
            $this->assertCount( 1, $envelope['refs'], 'Only the still-present confirmed ref survives.' );
            $this->assertSame( 'ROM', $envelope['refs'][0]['bookUSFM'] );
            $this->assertSame( 'exact', $envelope['refs'][0]['confidence'], 'Surviving ref keeps its confirmed status.' );
        }
    }
}
