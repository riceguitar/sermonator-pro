<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\RefsCapture;
use Sermonator\Migration\BibleRefsBackfill;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the "one schema, multiple producers" invariant (design §3):
 * the migration {@see BibleRefsBackfill} and the save-time authoring {@see RefsCapture}
 * are the SAME producer, so for an identical passage they emit byte-identical envelopes
 * and identical {@see ID::TAX_BOOK} terms — differing only by the per-ref `source` tag.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). It pins the
 * guarantee that wiring save-time capture cannot drift from the backfill output, so a
 * sermon's structured refs are independent of which producer happened to create them.
 */
final class RefsCaptureParityTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
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

    /** @return array<string,mixed> decoded envelope on a post */
    private function envelope( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /** @return list<string> sorted TAX_BOOK term names on a post */
    private function bookTermNames( int $id ): array {
        $terms = wp_get_object_terms( $id, ID::TAX_BOOK, array( 'fields' => 'names' ) );
        $names = is_array( $terms ) ? array_values( $terms ) : array();
        sort( $names );
        return $names;
    }

    /** Strip the per-ref source tag so two producers can be compared structurally. */
    private function withoutSource( array $envelope ): array {
        foreach ( $envelope['refs'] as &$ref ) {
            unset( $ref['source'] );
        }
        return $envelope;
    }

    public function test_backfill_and_authoring_capture_produce_identical_envelopes(): void {
        $passage = 'John 3:16; Romans 8:28; Genesis 1:1';

        // Producer A: the migration backfill (source:backfill).
        $backfilled = $this->sermon( $passage );
        ( new BibleRefsBackfill() )->run( 0, false );

        // Producer B: the shared save-time producer (source:authoring).
        $authored = $this->sermon( $passage );
        ( new RefsCapture() )->captureForPost( $authored, 'authoring' );

        $a = $this->envelope( $backfilled );
        $b = $this->envelope( $authored );

        // Same schema version and same ref payload, modulo the source tag.
        $this->assertSame( $this->withoutSource( $a ), $this->withoutSource( $b ) );
        $this->assertSame( 'backfill', $a['refs'][0]['source'] );
        $this->assertSame( 'authoring', $b['refs'][0]['source'] );

        // And the dual-written book terms match too.
        $this->assertSame( $this->bookTermNames( $backfilled ), $this->bookTermNames( $authored ) );
        $this->assertSame( array( 'Genesis', 'John', 'Romans' ), $this->bookTermNames( $authored ) );
    }
}
