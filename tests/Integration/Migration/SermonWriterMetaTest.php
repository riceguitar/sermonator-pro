<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\SermonWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 13: SermonWriter — meta application (unserialized, idempotent) + date normalization.
 *
 * This task covers the META facet of write(): it applies SermonMetaMapper::map()
 * output, but sources the actual write VALUES from the per-key UNSERIALIZED
 * get_post_meta($legacyId, $key, false) form (never the no-key raw-serialized
 * values). Renamed known keys land under the new namespace; the denormalized
 * wpfc_service_type meta and sermon_description-as-meta are dropped; unknown keys
 * pass through verbatim. Multi-value meta is preserved via delete-then-re-add the
 * full multiset (idempotent on resume); single-value keys are written
 * replace/unique. The post_content_temp meta row is copied verbatim as its own
 * single canonical home (AND fed to the reconciler with no double-flag). For
 * EVERY non-numeric sermon_date row, a sermonator_date_normalized companion is
 * written ALONGSIDE the untouched raw (replace semantics), and a
 * legacy_nonnumeric_date flag is recorded; numeric dates get no normalized row.
 *
 * Legacy data is read READ-ONLY.
 */
final class SermonWriterMetaTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /** Snapshot a legacy post + all its meta for byte-equality assertions. */
    private function snapshot( int $legacyId ): array {
        return array(
            'post' => get_post( $legacyId, ARRAY_A ),
            'meta' => get_post_meta( $legacyId ),
        );
    }

    /** Create a minimal legacy sermon with NO default meta seeded. */
    private function bareSermon(): int {
        $id = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Bare ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => 'blob',
        ) );
        add_post_meta( $id, LegacyIdentifiers::META_DESCRIPTION, '' );
        return $id;
    }

    public function test_serialized_array_meta_roundtrips_as_array_not_string(): void {
        // A legacy unknown key holding a serialized array must arrive on the new
        // post as the actual ARRAY (get_post_meta(...,true) === the array), proving
        // we read the per-key UNSERIALIZED form and let core re-serialize — never
        // double-serialized by feeding the raw-serialized string to add_post_meta.
        $legacyId = $this->bareSermon();
        $array    = array( 'a' => 1, 'b' => array( 2, 3 ) );
        add_post_meta( $legacyId, 'custom_payload', $array );

        $result = ( new SermonWriter() )->write( $legacyId );

        $stored = get_post_meta( $result->newId, 'custom_payload', true );
        $this->assertSame( $array, $stored, 'serialized array meta must round-trip as an array, not a string' );
    }

    public function test_renamed_known_keys_land_under_new_namespace(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_BIBLE_PASSAGE, 'Romans 8:28' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_VIDEO, '<iframe src="x"></iframe>' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame( 'Romans 8:28', get_post_meta( $result->newId, Identifiers::META_BIBLE_PASSAGE, true ) );
        $this->assertSame( '<iframe src="x"></iframe>', get_post_meta( $result->newId, Identifiers::META_VIDEO_EMBED, true ) );
        // Legacy key name must NOT appear on the new post.
        $this->assertSame( '', (string) get_post_meta( $result->newId, LegacyIdentifiers::META_BIBLE_PASSAGE, true ) );
    }

    public function test_multi_value_notes_preserved_as_two_rows(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_NOTES, 'first note' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_NOTES, 'second note' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $notes = get_post_meta( $result->newId, Identifiers::META_NOTES, false );
        $this->assertCount( 2, $notes );
        $this->assertSame( array( 'first note', 'second note' ), $notes );
    }

    public function test_unknown_seo_key_passes_through_verbatim(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, '_yoast_wpseo_title', 'Custom SEO Title' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame( 'Custom SEO Title', get_post_meta( $result->newId, '_yoast_wpseo_title', true ) );
    }

    public function test_dropped_keys_are_not_carried_as_meta(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_SERVICE_TYPE_DENORM, '12' );
        // sermon_description already seeded ('') by bareSermon — it becomes
        // post_content, never a meta row.

        $result = ( new SermonWriter() )->write( $legacyId );

        // wpfc_service_type denorm not carried under EITHER name.
        $this->assertSame( '', (string) get_post_meta( $result->newId, LegacyIdentifiers::META_SERVICE_TYPE_DENORM, true ) );
        $this->assertSame( '', (string) get_post_meta( $result->newId, Identifiers::TAX_SERVICE_TYPE, true ) );
        // sermon_description not a meta row under legacy or new name.
        $this->assertSame( '', (string) get_post_meta( $result->newId, LegacyIdentifiers::META_DESCRIPTION, true ) );
        $this->assertSame( '', (string) get_post_meta( $result->newId, 'sermonator_description', true ) );
    }

    public function test_post_content_temp_is_its_own_canonical_row(): void {
        // post_content_temp is copied verbatim as its own single row AND fed to the
        // reconciler. The reconciler must not ALSO route it to the backup body
        // (single canonical home, no double-flag).
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_POST_CONTENT_TEMP, 'a temp-only fragment' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $rows = get_post_meta( $result->newId, LegacyIdentifiers::META_POST_CONTENT_TEMP, false );
        $this->assertSame( array( 'a temp-only fragment' ), $rows, 'post_content_temp is its own single canonical row' );
    }

    public function test_nonnumeric_date_gets_normalized_companion_plus_flag_raw_untouched(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Raw verbatim under the new date key.
        $this->assertSame( '01/05/2021', get_post_meta( $result->newId, Identifiers::META_DATE, true ) );

        // Companion normalized row is the unix timestamp for the raw date. (WP
        // stores scalar meta as text, so it returns as a numeric string — assert
        // the numeric value and that it is digits, i.e. a unix int.)
        $normalized = get_post_meta( $result->newId, Identifiers::META_DATE_NORMALIZED, true );
        $this->assertTrue( ctype_digit( (string) $normalized ), 'normalized date is a unix int' );
        $this->assertSame( strtotime( '2021-01-05 00:00:00 UTC' ), (int) $normalized );

        // Flag recorded.
        $flags = get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( 'legacy_nonnumeric_date', (array) $flags );
        $this->assertContains( 'legacy_nonnumeric_date', $result->flags );
    }

    public function test_numeric_date_gets_no_normalized_row(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '1612137600' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame( '1612137600', get_post_meta( $result->newId, Identifiers::META_DATE, true ) );
        $this->assertSame( '', (string) get_post_meta( $result->newId, Identifiers::META_DATE_NORMALIZED, true ) );
        $this->assertNotContains( 'legacy_nonnumeric_date', $result->flags );
    }

    public function test_multi_row_nonnumeric_date_gets_companion_per_row(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '03/14/2022' );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Both raw values preserved verbatim as two rows.
        $this->assertSame(
            array( '01/05/2021', '03/14/2022' ),
            get_post_meta( $result->newId, Identifiers::META_DATE, false )
        );

        // A normalized companion per non-numeric row, in order (numeric strings).
        $normalized = get_post_meta( $result->newId, Identifiers::META_DATE_NORMALIZED, false );
        $this->assertSame(
            array(
                (string) strtotime( '2021-01-05 00:00:00 UTC' ),
                (string) strtotime( '2022-03-14 00:00:00 UTC' ),
            ),
            $normalized
        );
    }

    public function test_reapplying_meta_does_not_duplicate_single_value_or_accumulate_flags(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_BIBLE_PASSAGE, 'Romans 8:28' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_NOTES, 'note one' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_NOTES, 'note two' );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $second = $writer->write( $legacyId );
        $this->assertTrue( $second->resumed );

        // Single-value rows are unique after resume.
        $this->assertCount( 1, get_post_meta( $first->newId, Identifiers::META_DATE, false ) );
        $this->assertCount( 1, get_post_meta( $first->newId, Identifiers::META_DATE_NORMALIZED, false ) );
        $this->assertCount( 1, get_post_meta( $first->newId, Identifiers::META_BIBLE_PASSAGE, false ) );

        // Multi-value preserved exactly (delete-then-re-add the full multiset).
        $this->assertSame(
            array( 'note one', 'note two' ),
            get_post_meta( $first->newId, Identifiers::META_NOTES, false )
        );

        // Flags row never accumulates duplicates.
        $flagsRows = get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, false );
        $this->assertCount( 1, $flagsRows );
        $flags = $flagsRows[0];
        $this->assertSame( $flags, array_values( array_unique( $flags ) ), 'flags must not accumulate duplicates' );
    }

    public function test_legacy_post_and_meta_byte_equal_before_and_after(): void {
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_NOTES, 'note one' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_NOTES, 'note two' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_POST_CONTENT_TEMP, 'temp fragment' );
        add_post_meta( $legacyId, 'custom_payload', array( 'a' => 1, 'b' => array( 2, 3 ) ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_SERVICE_TYPE_DENORM, '12' );

        $before = $this->snapshot( $legacyId );

        ( new SermonWriter() )->write( $legacyId );
        ( new SermonWriter() )->write( $legacyId ); // resume too

        $after = $this->snapshot( $legacyId );
        $this->assertSame( $before, $after, 'Legacy post/meta were mutated by the writer.' );
    }
}
