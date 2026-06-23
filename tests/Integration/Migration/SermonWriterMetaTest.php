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

    public function test_literal_backslash_meta_roundtrips_byte_exact(): void {
        // CRITICAL #2: get_post_meta($id,$key,false) returns UNSLASHED values.
        // Feeding them straight to add_post_meta() lets add_metadata()'s internal
        // wp_unslash() strip one backslash level (UNC/audio paths, escaped quotes,
        // serialized inner strings). The writer must wp_slash() before add_*_meta so
        // the value is byte-exact on the new post.
        $legacyId = $this->bareSermon();

        $uncPath = 'C\\\\server\\share\\audio.mp3'; // literal backslashes
        $quoted  = 'He said \\"amen\\" twice';       // escaped quotes with backslashes
        // Seed via the fixture so the legacy DB row holds the EXACT bytes (wp_slash'd
        // through add_post_meta's unslash) — mirroring real legacy data.
        $this->fixture->seedRawMeta( $legacyId, '_audio_unc', $uncPath );
        $this->fixture->seedRawMeta( $legacyId, '_quoted', $quoted );

        // An ARRAY whose inner strings carry literal backslashes — wp_slash must
        // recurse so the array round-trips byte-exact.
        $arrayWithBackslashes = array(
            'path'  => 'D\\\\vol\\clip.wav',
            'inner' => array( 'a\\b', 'c\\\\d' ),
        );
        $this->fixture->seedRawMeta( $legacyId, '_backslash_array', $arrayWithBackslashes );

        // Sanity: the legacy DB row holds the exact bytes before the writer runs.
        $this->assertSame( $uncPath, get_post_meta( $legacyId, '_audio_unc', true ), 'fixture seed must be byte-exact' );
        $this->assertSame( $arrayWithBackslashes, get_post_meta( $legacyId, '_backslash_array', true ), 'fixture array seed must be byte-exact' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame(
            $uncPath,
            get_post_meta( $result->newId, '_audio_unc', true ),
            'UNC/backslash scalar meta must be byte-exact (no stripped backslash level)'
        );
        $this->assertSame(
            $quoted,
            get_post_meta( $result->newId, '_quoted', true ),
            'escaped-quote meta must be byte-exact'
        );
        $this->assertSame(
            $arrayWithBackslashes,
            get_post_meta( $result->newId, '_backslash_array', true ),
            'array meta with backslash inner strings must round-trip byte-exact (wp_slash recurses)'
        );
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
        // reconciler. The reconciler must NOT ALSO route it to the backup body
        // (single canonical home, no double-flag): the only substantive text here
        // lives in post_content_temp, so LEGACY_POST_CONTENT must be EMPTY and the
        // post_content_preserved flag absent.
        //
        // Use an EMPTY-post_content sermon so post_content_temp is the SOLE
        // substantive source — otherwise an independent old post_content blob
        // would (correctly) be preserved and mask the single-home assertion.
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'TempOnly ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => '',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_POST_CONTENT_TEMP, 'a temp-only fragment' );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Single canonical home: its own meta row, verbatim.
        $rows = get_post_meta( $result->newId, LegacyIdentifiers::META_POST_CONTENT_TEMP, false );
        $this->assertSame( array( 'a temp-only fragment' ), $rows, 'post_content_temp is its own single canonical row' );

        // NOT a second home: the reconciler backup must be empty.
        $this->assertSame(
            '',
            (string) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_CONTENT, true ),
            'post_content_temp must not ALSO land in the backup body (single home)'
        );

        // No double-flag: post_content_preserved must be absent.
        $flags = (array) get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertNotContains( 'post_content_preserved', $flags, 'no double-flag for the single-home temp row' );
        $this->assertNotContains( 'post_content_preserved', $result->flags );
    }

    public function test_numeric_first_nonnumeric_later_date_flags_and_companions_the_later_row(): void {
        // A multi-row sermon_date whose FIRST value is a numeric unix timestamp but
        // a LATER value is non-numeric must STILL: (a) write a normalized companion
        // for the later non-numeric row, and (b) record legacy_nonnumeric_date in
        // both the result flags and the persisted MIGRATION_FLAGS row. First-row-
        // only inspection would silently disagree with the per-row companion set.
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '1612137600' ); // numeric first
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' ); // non-numeric later

        $result = ( new SermonWriter() )->write( $legacyId );

        // Both raw values preserved verbatim, in order.
        $this->assertSame(
            array( '1612137600', '01/05/2021' ),
            get_post_meta( $result->newId, Identifiers::META_DATE, false )
        );

        // Exactly ONE companion — for the later non-numeric row only (numeric first
        // gets none).
        $normalized = get_post_meta( $result->newId, Identifiers::META_DATE_NORMALIZED, false );
        $this->assertSame(
            array( (string) strtotime( '2021-01-05 00:00:00 UTC' ) ),
            $normalized,
            'companion written for the later non-numeric row, none for the numeric first row'
        );

        // Flag present in BOTH the result and the persisted flags row.
        $this->assertContains( 'legacy_nonnumeric_date', $result->flags );
        $flags = (array) get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( 'legacy_nonnumeric_date', $flags );
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

    public function test_nonnumeric_date_companion_anchored_to_site_timezone_not_utc(): void {
        // IMPORTANT #9: applyDateNormalization must pass wp_timezone() so a date-only
        // legacy string is anchored to the SITE timezone, not UTC. Under
        // America/New_York the companion must equal site-TZ midnight (which is
        // 05:00 UTC in January / EST), NOT UTC midnight.
        $originalTz = get_option( 'timezone_string' );
        update_option( 'timezone_string', 'America/New_York' );

        try {
            $legacyId = $this->bareSermon();
            add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' );

            $result = ( new SermonWriter() )->write( $legacyId );

            $normalized = (int) get_post_meta( $result->newId, Identifiers::META_DATE_NORMALIZED, true );

            $siteMidnight = ( new \DateTimeImmutable( '2021-01-05 00:00:00', new \DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
            $utcMidnight  = strtotime( '2021-01-05 00:00:00 UTC' );

            $this->assertNotSame( $utcMidnight, $siteMidnight, 'fixture sanity: site-TZ and UTC midnight must differ' );
            $this->assertSame( $siteMidnight, $normalized, 'companion must be site-TZ midnight (TZ-anchored), not UTC midnight' );
            $this->assertNotSame( $utcMidnight, $normalized, 'companion must NOT be UTC midnight' );
        } finally {
            update_option( 'timezone_string', $originalTz );
        }
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
        // A full write() now stamps COMPLETE LAST; crash-inject a partial so the
        // second write re-drives the meta steps via the RESUME leg.
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
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
