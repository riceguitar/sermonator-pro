<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Migration\BibleRefsBackfill;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for {@see BibleRefsBackfill}: the parse → envelope → TAX_BOOK
 * dual-write → exact-reverse logic, with a small in-memory WP harness standing in
 * for post-meta / options / term relationships (no DB, no WP_Query).
 *
 * Pins the data-preservation guardrails: dry-run-default writes nothing, the legacy
 * passage is NEVER mutated, an existing envelope is never overwritten, the parse is
 * idempotent, and reverse() restores the exact pre-backfill state (refs, sentinel,
 * AND only the terms it added).
 */
final class BibleRefsBackfillTest extends TestCase {
    /** @var array<int,array<string,mixed>> postId => (metaKey => value) */
    private array $meta = array();

    /** @var array<string,mixed> option store */
    private array $options = array();

    /** @var array<int,list<int>> postId => term ids */
    private array $terms = array();

    /** @var array<int,string> postId => post type */
    private array $postType = array();

    /** @var array<string,int> book name => deterministic term id */
    private array $termIds = array();

    private int $nextTermId = 1000;

    /** @var string controls MigrationGuard via OPTION_MIGRATION_STATE */
    private string $phase = 'none';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta     = array();
        $this->options  = array();
        $this->terms    = array();
        $this->postType = array();
        $this->termIds  = array();
        $this->phase    = 'none';

        Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => $value );

        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_MIGRATION_STATE === $name ) {
                return array( 'phase' => $this->phase );
            }
            if ( ID::OPTION_BIBLE_LINK_VERSION === $name ) {
                return 'ESV'; // curated -> TranslationRegistry returns it verbatim.
            }
            return $this->options[ $name ] ?? $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $name ) {
            unset( $this->options[ $name ] );
            return true;
        } );

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
        Functions\when( 'get_post_type' )->alias( function ( $id ) {
            return $this->postType[ (int) $id ] ?? false;
        } );

        Functions\when( 'wp_get_object_terms' )->alias( function ( $id, $tax, $args = array() ) {
            return array_values( $this->terms[ (int) $id ] ?? array() );
        } );
        Functions\when( 'wp_set_object_terms' )->alias( function ( $id, $names, $tax, $append = false ) {
            $id      = (int) $id;
            $current = $append ? ( $this->terms[ $id ] ?? array() ) : array();
            foreach ( (array) $names as $name ) {
                $tid = $this->termIdFor( (string) $name );
                if ( ! in_array( $tid, $current, true ) ) {
                    $current[] = $tid;
                }
            }
            $this->terms[ $id ] = array_values( $current );
            return $this->terms[ $id ];
        } );
        Functions\when( 'wp_remove_object_terms' )->alias( function ( $id, $removeIds, $tax ) {
            $id                 = (int) $id;
            $removeIds          = array_map( 'intval', (array) $removeIds );
            $this->terms[ $id ] = array_values( array_diff( $this->terms[ $id ] ?? array(), $removeIds ) );
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function termIdFor( string $name ): int {
        if ( ! isset( $this->termIds[ $name ] ) ) {
            $this->termIds[ $name ] = $this->nextTermId++;
        }
        return $this->termIds[ $name ];
    }

    /** Seed a native sermon with a legacy passage; returns its id. */
    private function sermon( int $id, string $passage ): int {
        $this->postType[ $id ]            = ID::POST_TYPE_SERMON;
        $this->meta[ $id ]                = array();
        $this->meta[ $id ][ ID::META_BIBLE_PASSAGE ] = $passage;
        return $id;
    }

    /** A backfill whose candidate set is a fixed id list (no WP_Query). */
    private function backfill( int ...$ids ): BibleRefsBackfill {
        return new BibleRefsBackfill( static fn( int $limit ): array => array_values( $ids ) );
    }

    /** @return array<string,mixed> the decoded envelope stored on a post */
    private function envelopeOf( int $id ): array {
        $stored = $this->meta[ $id ][ ID::META_BIBLE_REFS ] ?? '';
        return is_string( $stored ) && '' !== $stored ? (array) json_decode( $stored, true ) : array();
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void {
        $id     = $this->sermon( 1, 'John 3:16' );
        $result = $this->backfill( 1 )->run(); // default dryRun = true

        $this->assertTrue( $result['dryRun'] );
        $this->assertSame( 1, $result['written'] );
        $this->assertSame( 1, $result['candidates'] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[ $id ] );
        $this->assertSame( array(), $this->options[ ID::OPTION_BIBLE_REFS_BACKFILL_LOG ] ?? array() );
        $this->assertSame( array(), $this->terms[ $id ] ?? array() );
    }

    public function test_writes_envelope_with_backfill_provenance(): void {
        $this->sermon( 1, 'John 3:16' );
        $result = $this->backfill( 1 )->run( 0, false );

        $this->assertSame( 1, $result['written'] );
        $this->assertFalse( $result['gated'] );

        $envelope = $this->envelopeOf( 1 );
        $this->assertSame( 1, $envelope['v'] );
        $this->assertCount( 1, $envelope['refs'] );

        $ref = $envelope['refs'][0];
        $this->assertSame( 'JHN', $ref['bookUSFM'] );
        $this->assertSame( 3, $ref['chapterStart'] );
        $this->assertSame( 16, $ref['verseStart'] );
        $this->assertSame( 'backfill', $ref['source'] );
        $this->assertSame( 'probable', $ref['confidence'] );
        $this->assertSame( 'ESV', $ref['srcVersification'] );
    }

    public function test_never_mutates_the_legacy_passage(): void {
        $id = $this->sermon( 1, 'Romans 8:28' );
        $this->backfill( 1 )->run( 0, false );
        $this->assertSame( 'Romans 8:28', $this->meta[ $id ][ ID::META_BIBLE_PASSAGE ] );
    }

    public function test_dual_writes_tax_book_terms(): void {
        $this->sermon( 1, 'John 3:16; Romans 8:28' );
        $this->backfill( 1 )->run( 0, false );

        $john   = $this->termIdFor( 'John' );
        $romans = $this->termIdFor( 'Romans' );
        $this->assertEqualsCanonicalizing( array( $john, $romans ), $this->terms[1] );
    }

    public function test_stamps_unparseable_sentinel_when_parse_yields_zero(): void {
        $id     = $this->sermon( 1, 'Welcome and announcements' );
        $result = $this->backfill( 1 )->run( 0, false );

        $this->assertSame( 0, $result['written'] );
        $this->assertSame( 1, $result['unparseable'] );
        $this->assertSame( '1', $this->meta[ $id ][ ID::META_BIBLE_REFS_UNPARSEABLE ] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[ $id ] );
    }

    public function test_fill_missing_only_never_overwrites_existing_envelope(): void {
        $id = $this->sermon( 1, 'John 3:16' );
        // Simulate an authoring-written envelope already present.
        $this->meta[ $id ][ ID::META_BIBLE_REFS ] = json_encode( array(
            'v'    => 1,
            'refs' => array( array( 'bookUSFM' => 'JHN', 'chapterStart' => 1, 'verseStart' => 1, 'source' => 'authoring' ) ),
        ) );

        $result = $this->backfill( 1 )->run( 0, false );

        $this->assertSame( 0, $result['written'] );
        $envelope = $this->envelopeOf( $id );
        $this->assertSame( 'authoring', $envelope['refs'][0]['source'], 'Authoring envelope untouched.' );
    }

    public function test_idempotent_second_pass_does_nothing(): void {
        $this->sermon( 1, 'John 3:16' );
        $this->sermon( 2, 'Welcome message' );

        $backfill = $this->backfill( 1, 2 );
        $backfill->run( 0, false );
        $second = $backfill->run( 0, false );

        $this->assertSame( 0, $second['written'], 'Envelope already present.' );
        $this->assertSame( 0, $second['unparseable'], 'Sentinel already stamped.' );
    }

    public function test_reverse_restores_exact_pre_state(): void {
        $this->sermon( 1, 'John 3:16' );
        $this->sermon( 2, 'Welcome message' );          // -> sentinel
        // Pre-existing book term that the backfill must NOT remove on reverse.
        $preExisting              = $this->termIdFor( 'Genesis' );
        $this->terms[1]           = array( $preExisting );

        $backfill = $this->backfill( 1, 2 );
        $backfill->run( 0, false );

        $this->assertArrayHasKey( ID::META_BIBLE_REFS, $this->meta[1] );
        $this->assertContains( $this->termIdFor( 'John' ), $this->terms[1] );

        $reverse = $backfill->reverse();

        $this->assertSame( 2, $reverse['reversed'] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[1], 'Backfilled envelope removed.' );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS_UNPARSEABLE, $this->meta[2], 'Sentinel removed.' );
        $this->assertSame( array( $preExisting ), $this->terms[1], 'Only backfill-added terms removed; pre-existing kept.' );
        $this->assertArrayNotHasKey( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, $this->options, 'Log cleared.' );
        // Legacy passages survive the full round-trip.
        $this->assertSame( 'John 3:16', $this->meta[1][ ID::META_BIBLE_PASSAGE ] );
        $this->assertSame( 'Welcome message', $this->meta[2][ ID::META_BIBLE_PASSAGE ] );
    }

    public function test_reverse_preserves_an_author_reedited_envelope(): void {
        $this->sermon( 1, 'John 3:16' );
        $backfill = $this->backfill( 1 );
        $backfill->run( 0, false );

        // Author re-saves the post after the backfill (source flips to authoring).
        $this->meta[1][ ID::META_BIBLE_REFS ] = json_encode( array(
            'v'    => 1,
            'refs' => array( array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'source' => 'authoring' ) ),
        ) );

        // The backfill dual-wrote the JHN book term; the re-saved authoring envelope
        // still depends on it for book-archive / "sermons on John" queryability.
        $john = $this->termIdFor( 'John' );
        $this->assertContains( $john, $this->terms[1], 'Backfill dual-wrote the John term.' );

        $backfill->reverse();

        $envelope = $this->envelopeOf( 1 );
        $this->assertSame( 'authoring', $envelope['refs'][0]['source'], 'Author edit must survive reverse.' );
        // The book term is load-bearing for the preserved authoring envelope: a reverse
        // that strips it would leave refs present but un-queryable — a partial clobber of
        // authoring data. Ownership-gated term removal must keep it intact.
        $this->assertContains( $john, $this->terms[1], 'Author-owned book term must survive reverse.' );
    }

    public function test_writes_are_gated_during_active_migration(): void {
        $id          = $this->sermon( 1, 'John 3:16' );
        $this->phase = 'migrating';

        $result = $this->backfill( 1 )->run( 0, false );

        $this->assertTrue( $result['gated'] );
        $this->assertSame( 0, $result['written'] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[ $id ] );
    }

    public function test_dry_run_report_is_allowed_during_migration(): void {
        $this->sermon( 1, 'John 3:16' );
        $this->phase = 'migrating';

        $result = $this->backfill( 1 )->run(); // dry run

        $this->assertFalse( $result['gated'] );
        $this->assertSame( 1, $result['written'] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[1] ?? array() );
    }

    public function test_reverse_is_gated_during_active_migration(): void {
        $this->sermon( 1, 'John 3:16' );
        $backfill = $this->backfill( 1 );
        $backfill->run( 0, false );

        $this->phase = 'migrating';
        $reverse     = $backfill->reverse();

        $this->assertTrue( $reverse['gated'] );
        $this->assertArrayHasKey( ID::META_BIBLE_REFS, $this->meta[1], 'Gated reverse changed nothing.' );
    }

    public function test_skips_empty_passage(): void {
        $this->sermon( 1, '   ' );
        $result = $this->backfill( 1 )->run( 0, false );
        $this->assertSame( 0, $result['written'] );
        $this->assertSame( 0, $result['unparseable'] );
    }
}
