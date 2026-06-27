<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Bible\RefsCapture;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for {@see RefsCapture}, the SINGLE structured-reference producer
 * (design §3, "one schema, multiple producers"): the parse → envelope → TAX_BOOK
 * dual-write → sentinel logic shared by the migration backfill and the save-time
 * authoring capture.
 *
 * A small in-memory WP harness stands in for post-meta / options / term
 * relationships (no DB). Pins the data-preservation guardrails the producer owns:
 * fill-missing-only, never-overwrite-authoring, never-mutate the passage, the
 * unparseable sentinel on a zero-ref parse, and the per-call `source` tagging that
 * lets two producers emit otherwise-identical envelopes.
 */
final class RefsCaptureTest extends TestCase {
    /** @var array<int,array<string,mixed>> postId => (metaKey => value) */
    private array $meta = array();

    /** @var array<int,list<int>> postId => term ids */
    private array $terms = array();

    /** @var array<string,int> book name => deterministic term id */
    private array $termIds = array();

    private int $nextTermId = 1000;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta    = array();
        $this->terms   = array();
        $this->termIds = array();

        Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => $value );

        // TranslationRegistry::current()->linkVersion() resolves srcVersification.
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( ID::OPTION_BIBLE_LINK_VERSION === $name ) {
                return 'ESV'; // curated -> returned verbatim.
            }
            return $default;
        } );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            return $this->meta[ (int) $id ][ $key ] ?? '';
        } );
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
            $this->meta[ (int) $id ][ $key ] = $value;
            return true;
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

    private function sermon( int $id, string $passage ): void {
        $this->meta[ $id ]                           = array();
        $this->meta[ $id ][ ID::META_BIBLE_PASSAGE ] = $passage;
    }

    /** @return array<string,mixed> the decoded envelope stored on a post */
    private function envelopeOf( int $id ): array {
        $stored = $this->meta[ $id ][ ID::META_BIBLE_REFS ] ?? '';
        return is_string( $stored ) && '' !== $stored ? (array) json_decode( $stored, true ) : array();
    }

    public function test_writes_a_single_versioned_envelope_with_source_tag(): void {
        $this->sermon( 1, 'John 3:16' );

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $this->assertTrue( $result['wrote'] );
        $this->assertTrue( $result['refs'] );
        $this->assertFalse( $result['sentinel'] );

        $envelope = $this->envelopeOf( 1 );
        $this->assertSame( 1, $envelope['v'] );
        $this->assertCount( 1, $envelope['refs'] );

        $ref = $envelope['refs'][0];
        $this->assertSame( 'JHN', $ref['bookUSFM'] );
        $this->assertSame( 3, $ref['chapterStart'] );
        $this->assertSame( 16, $ref['verseStart'] );
        $this->assertSame( 'authoring', $ref['source'], 'Source tag reflects the caller.' );
        $this->assertSame( 'probable', $ref['confidence'] );
        $this->assertSame( 'ESV', $ref['srcVersification'] );
    }

    public function test_backfill_producer_stamps_site_default_versification_confidence(): void {
        $this->sermon( 1, 'John 3:16' );

        ( new RefsCapture() )->captureForPost( 1, 'backfill' );

        $ref = $this->envelopeOf( 1 )['refs'][0];
        $this->assertSame(
            RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT,
            $ref['srcVersificationConfidence'],
            'The backfill/auto-parse producer never authors versification contemporaneously.'
        );
        // Constant is the conservative literal.
        $this->assertSame( 'site-default', RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT );
    }

    public function test_envelope_version_is_not_bumped_by_the_new_optional_field(): void {
        // BACKWARD-COMPATIBLE: adding srcVersificationConfidence must NOT change the
        // envelope schema version (existing v1 envelopes stay valid, unrewritten).
        $this->sermon( 1, 'John 3:16' );
        ( new RefsCapture() )->captureForPost( 1, 'backfill' );
        $this->assertSame( 1, $this->envelopeOf( 1 )['v'] );
        $this->assertSame( 1, RefsCapture::ENVELOPE_VERSION );
    }

    public function test_absent_versification_confidence_reads_as_site_default(): void {
        // A v1 envelope ref written BEFORE this field existed lacks it entirely; the
        // single accessor must read it as the conservative site-default.
        $legacyRef = array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16 );
        $this->assertArrayNotHasKey( 'srcVersificationConfidence', $legacyRef );
        $this->assertSame(
            'site-default',
            RefsCapture::srcVersificationConfidence( $legacyRef )
        );
    }

    public function test_authored_versification_confidence_reads_through_verbatim(): void {
        $authoredRef = array(
            'bookUSFM'                  => 'JHN',
            'srcVersificationConfidence' => 'authored',
        );
        $this->assertSame( 'authored', RefsCapture::srcVersificationConfidence( $authoredRef ) );
    }

    public function test_unrecognized_versification_confidence_falls_back_to_site_default(): void {
        // Never trust a stray/garbage value to clear the provenance gate.
        foreach ( array( 'bogus', '', 'AUTHORED', 0, null, 'SITE-DEFAULT' ) as $bad ) {
            $ref = array( 'srcVersificationConfidence' => $bad );
            $this->assertSame(
                'site-default',
                RefsCapture::srcVersificationConfidence( $ref ),
                'Unrecognized value must fall back to the conservative default.'
            );
        }
    }

    public function test_source_tag_is_per_call_so_producers_agree_on_everything_else(): void {
        $this->sermon( 1, 'John 3:16; Romans 8:28' );
        $this->sermon( 2, 'John 3:16; Romans 8:28' );

        ( new RefsCapture() )->captureForPost( 1, 'backfill' );
        ( new RefsCapture() )->captureForPost( 2, 'authoring' );

        $strip = static function ( array $envelope ): array {
            foreach ( $envelope['refs'] as &$ref ) {
                unset( $ref['source'] );
            }
            return $envelope;
        };

        // One schema, multiple producers: identical save vs backfill output modulo source.
        $this->assertSame( $strip( $this->envelopeOf( 1 ) ), $strip( $this->envelopeOf( 2 ) ) );
        $this->assertSame( 'backfill', $this->envelopeOf( 1 )['refs'][0]['source'] );
        $this->assertSame( 'authoring', $this->envelopeOf( 2 )['refs'][0]['source'] );
    }

    public function test_dual_writes_tax_book_terms_and_reports_added_ids(): void {
        $this->sermon( 1, 'John 3:16; Romans 8:28' );

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $john   = $this->termIdFor( 'John' );
        $romans = $this->termIdFor( 'Romans' );
        $this->assertEqualsCanonicalizing( array( $john, $romans ), $this->terms[1] );
        $this->assertEqualsCanonicalizing( array( $john, $romans ), $result['terms'] );
    }

    public function test_reported_terms_are_only_the_newly_added_ones(): void {
        $this->sermon( 1, 'John 3:16' );
        // A pre-existing John term must NOT be reported as newly added.
        $john           = $this->termIdFor( 'John' );
        $this->terms[1] = array( $john );

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $this->assertSame( array(), $result['terms'], 'Pre-existing term is not reported.' );
        $this->assertSame( array( $john ), $this->terms[1] );
    }

    public function test_never_mutates_the_legacy_passage(): void {
        $this->sermon( 1, 'Romans 8:28' );
        ( new RefsCapture() )->captureForPost( 1, 'authoring' );
        $this->assertSame( 'Romans 8:28', $this->meta[1][ ID::META_BIBLE_PASSAGE ] );
    }

    public function test_empty_passage_is_a_no_op(): void {
        $this->sermon( 1, '   ' );

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $this->assertFalse( $result['wrote'] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[1] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS_UNPARSEABLE, $this->meta[1] );
    }

    public function test_stamps_sentinel_when_a_nonempty_passage_parses_to_zero(): void {
        $this->sermon( 1, 'Welcome and announcements' );

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $this->assertTrue( $result['wrote'] );
        $this->assertTrue( $result['sentinel'] );
        $this->assertFalse( $result['refs'] );
        $this->assertSame( '1', $this->meta[1][ ID::META_BIBLE_REFS_UNPARSEABLE ] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[1] );
    }

    public function test_fill_missing_never_overwrites_an_existing_envelope(): void {
        $this->sermon( 1, 'John 3:16' );
        // An author-confirmed envelope is already present (Phase 3b chips, source:authoring).
        $this->meta[1][ ID::META_BIBLE_REFS ] = json_encode( array(
            'v'    => 1,
            'refs' => array( array( 'bookUSFM' => 'JHN', 'chapterStart' => 1, 'verseStart' => 1, 'source' => 'authoring' ) ),
        ) );

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $this->assertFalse( $result['wrote'] );
        $envelope = $this->envelopeOf( 1 );
        $this->assertSame( 1, $envelope['refs'][0]['chapterStart'], 'Existing authoring envelope untouched.' );
        $this->assertSame( 'authoring', $envelope['refs'][0]['source'] );
    }

    public function test_existing_sentinel_short_circuits(): void {
        $this->sermon( 1, 'Welcome and announcements' );
        $this->meta[1][ ID::META_BIBLE_REFS_UNPARSEABLE ] = '1';

        $result = ( new RefsCapture() )->captureForPost( 1, 'authoring' );

        $this->assertFalse( $result['wrote'], 'A prior sentinel makes a re-capture a no-op.' );
    }

    public function test_plan_reports_outcome_without_writing(): void {
        $this->sermon( 1, 'John 3:16' );
        $this->sermon( 2, 'Welcome message' );

        $capture = new RefsCapture();

        $this->assertSame( 'refs', $capture->plan( 1, 'backfill' )['outcome'] );
        $this->assertSame( 'sentinel', $capture->plan( 2, 'backfill' )['outcome'] );

        // plan() is read-only — nothing was persisted.
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS, $this->meta[1] );
        $this->assertArrayNotHasKey( ID::META_BIBLE_REFS_UNPARSEABLE, $this->meta[2] );
    }
}
