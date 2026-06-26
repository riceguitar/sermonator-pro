<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\BibleRefsBackfill;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see BibleRefsBackfill} against a real WP_Query candidate
 * scan, real post-meta, and a real {@see ID::TAX_BOOK} term graph.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Exercises the
 * full guardrail surface end-to-end: native-only + fill-missing candidate selection,
 * the TAX_BOOK dual-write, the unparseable sentinel, idempotency, and exact reverse.
 */
final class BibleRefsBackfillTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        // Axis-A link version drives srcVersification; pin it to a curated value.
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        parent::tearDown();
    }

    private function sermon( string $passage ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        return $id;
    }

    /** @return list<string> term names attached in TAX_BOOK */
    private function bookTermNames( int $id ): array {
        $terms = wp_get_object_terms( $id, ID::TAX_BOOK, array( 'fields' => 'names' ) );
        return is_array( $terms ) ? array_values( $terms ) : array();
    }

    private function refs( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
    }

    public function test_dry_run_writes_nothing_but_reports_counts(): void {
        $id     = $this->sermon( 'John 3:16' );
        $result = ( new BibleRefsBackfill() )->run(); // dry-run default

        $this->assertTrue( $result['dryRun'] );
        $this->assertSame( 1, $result['written'] );
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $this->assertSame( array(), $this->bookTermNames( $id ) );
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() ) );
    }

    public function test_real_run_writes_envelope_and_book_terms(): void {
        $id = $this->sermon( 'John 3:16; Romans 8:28' );
        ( new BibleRefsBackfill() )->run( 0, false );

        $refs = $this->refs( $id );
        $this->assertCount( 2, $refs );
        $this->assertSame( 'backfill', $refs[0]['source'] );
        $this->assertSame( 'ESV', $refs[0]['srcVersification'] );
        $this->assertEqualsCanonicalizing( array( 'John', 'Romans' ), $this->bookTermNames( $id ) );

        // Legacy passage is never mutated.
        $this->assertSame( 'John 3:16; Romans 8:28', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }

    public function test_unparseable_passage_is_stamped_and_excluded_from_re_runs(): void {
        $id = $this->sermon( 'Welcome and announcements' );

        $first = ( new BibleRefsBackfill() )->run( 0, false );
        $this->assertSame( 1, $first['unparseable'] );
        $this->assertSame( '1', get_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE, true ) );

        // The sentinel drops it from the candidate query — a re-run finds nothing.
        $second = ( new BibleRefsBackfill() )->run( 0, false );
        $this->assertSame( 0, $second['candidates'] );
    }

    public function test_fill_missing_only_skips_posts_with_existing_refs(): void {
        $withRefs = $this->sermon( 'John 3:16' );
        update_post_meta( $withRefs, ID::META_BIBLE_REFS, json_encode( array(
            'v'    => 1,
            'refs' => array( array( 'bookUSFM' => 'JHN', 'chapterStart' => 1, 'verseStart' => 1, 'source' => 'authoring' ) ),
        ) ) );
        $missing = $this->sermon( 'Romans 8:28' );

        $result = ( new BibleRefsBackfill() )->run( 0, false );

        $this->assertSame( 1, $result['written'], 'Only the post missing refs is written.' );
        $authoring = $this->refs( $withRefs );
        $this->assertSame( 'authoring', $authoring[0]['source'], 'Authoring envelope untouched.' );
        $this->assertNotEmpty( $this->refs( $missing ) );
    }

    public function test_ignores_non_sermon_posts(): void {
        $post = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
        update_post_meta( $post, ID::META_BIBLE_PASSAGE, 'John 3:16' );

        $result = ( new BibleRefsBackfill() )->run( 0, false );

        $this->assertSame( 0, $result['written'] );
        $this->assertSame( '', get_post_meta( $post, ID::META_BIBLE_REFS, true ) );
    }

    public function test_idempotent_second_run_finds_no_candidates(): void {
        $this->sermon( 'John 3:16' );
        ( new BibleRefsBackfill() )->run( 0, false );
        $second = ( new BibleRefsBackfill() )->run( 0, false );
        $this->assertSame( 0, $second['candidates'] );
    }

    public function test_reverse_restores_exact_pre_state(): void {
        $a = $this->sermon( 'John 3:16' );
        $b = $this->sermon( 'Welcome message' ); // -> sentinel

        // A pre-existing book term that reverse must NOT remove.
        wp_set_object_terms( $a, array( 'Genesis' ), ID::TAX_BOOK, true );

        $backfill = new BibleRefsBackfill();
        $backfill->run( 0, false );

        $this->assertNotEmpty( get_post_meta( $a, ID::META_BIBLE_REFS, true ) );
        $this->assertContains( 'John', $this->bookTermNames( $a ) );

        $reverse = $backfill->reverse();

        $this->assertSame( 2, $reverse['reversed'] );
        $this->assertSame( '', get_post_meta( $a, ID::META_BIBLE_REFS, true ), 'Backfilled envelope removed.' );
        $this->assertSame( '', get_post_meta( $b, ID::META_BIBLE_REFS_UNPARSEABLE, true ), 'Sentinel removed.' );
        $this->assertSame( array( 'Genesis' ), $this->bookTermNames( $a ), 'Only backfill-added terms removed.' );
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() ), 'Log cleared.' );

        // Legacy passages survived the round-trip.
        $this->assertSame( 'John 3:16', get_post_meta( $a, ID::META_BIBLE_PASSAGE, true ) );
        $this->assertSame( 'Welcome message', get_post_meta( $b, ID::META_BIBLE_PASSAGE, true ) );
    }

    public function test_writes_are_inert_during_active_migration(): void {
        $id = $this->sermon( 'John 3:16' );
        ( new MigrationState() )->set( 'detected' );
        ( new MigrationState() )->set( 'migrating' );

        $result = ( new BibleRefsBackfill() )->run( 0, false );

        $this->assertTrue( $result['gated'] );
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
    }

    public function test_chunkable_limit_drains_backlog(): void {
        $this->sermon( 'John 3:16' );
        $this->sermon( 'Romans 8:28' );
        $this->sermon( 'Genesis 1:1' );

        $backfill = new BibleRefsBackfill();
        $first    = $backfill->run( 2, false );
        $this->assertSame( 2, $first['written'] );

        $second = $backfill->run( 2, false );
        $this->assertSame( 1, $second['written'], 'Remaining candidate drained on the next chunk.' );

        $third = $backfill->run( 2, false );
        $this->assertSame( 0, $third['candidates'], 'Backlog fully drained.' );
    }
}
