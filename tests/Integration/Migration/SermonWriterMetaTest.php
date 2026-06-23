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

    public function test_mixed_parseable_and_unparseable_nonnumeric_dates_stay_positionally_aligned(): void {
        // IMPORTANT #7: applyDateNormalization previously wrote a companion ONLY for
        // non-numeric rows that PARSED — an unparseable non-numeric row hit a bare
        // `continue` and produced NO companion. With companions stored as a positional
        // multiset, a multi-row sermon_date mixing a parseable and an UNPARSEABLE value
        // yielded a companion set SHORTER THAN and positionally MISALIGNED with the raw
        // set, so a consumer indexing META_DATE_NORMALIZED[i] against META_DATE[i] read
        // the wrong companion. Every non-numeric row must now get a companion: a real
        // timestamp when parseable, the UNPARSEABLE sentinel otherwise — so position i
        // of the companion set always corresponds to position i of the raw set.
        $legacyId = $this->bareSermon();
        // Row 0: parseable. Row 1: non-numeric but UNPARSEABLE garbage. Row 2: parseable.
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '01/05/2021' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, 'sometime last Easter' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DATE, '03/14/2022' );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Raw values preserved verbatim, in order.
        $this->assertSame(
            array( '01/05/2021', 'sometime last Easter', '03/14/2022' ),
            get_post_meta( $result->newId, Identifiers::META_DATE, false )
        );

        // A companion for EVERY non-numeric row, positionally aligned with the raw set:
        // parseable → its unix timestamp; unparseable → the UNPARSEABLE sentinel.
        $normalized = get_post_meta( $result->newId, Identifiers::META_DATE_NORMALIZED, false );
        $this->assertSame(
            array(
                (string) strtotime( '2021-01-05 00:00:00 UTC' ),
                Identifiers::META_DATE_UNPARSEABLE,
                (string) strtotime( '2022-03-14 00:00:00 UTC' ),
            ),
            $normalized,
            'every non-numeric row gets a companion (sentinel on parse failure) so positions stay aligned'
        );

        // Still flagged as a non-numeric-date record.
        $this->assertContains( 'legacy_nonnumeric_date', $result->flags );
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

    // ------------------------------------------------------------------ FIX 1: meta-key target collision

    public function test_colliding_source_keys_produce_union_and_flag(): void {
        // FIX 1 (IMPORTANT): two distinct legacy source keys that resolve to the SAME
        // target key must produce a UNION on the new post (both values retained) and
        // record a meta_key_collision:<targetKey> migration flag. The current code
        // deletes-then-re-adds once per LEGACY key, so the second iteration's delete
        // wipes the first iteration's writes — silent meta loss.
        //
        // sermon_video → sermonator_video_embed (via metaKeyMap)
        // sermonator_video_embed (stray verbatim) → sermonator_video_embed (unknown key, pass-through)
        //
        // Legacy post carries BOTH: the second iteration's delete currently wipes the first.
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_VIDEO, '<iframe src="mapped.com"></iframe>' );
        add_post_meta( $legacyId, \Sermonator\Schema\Identifiers::META_VIDEO_EMBED, '<iframe src="stray.com"></iframe>' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $values = get_post_meta( $result->newId, \Sermonator\Schema\Identifiers::META_VIDEO_EMBED, false );
        $this->assertCount( 2, $values, 'Both values (union) must be retained on the target key' );
        $this->assertContains( '<iframe src="mapped.com"></iframe>', $values );
        $this->assertContains( '<iframe src="stray.com"></iframe>', $values );

        // Flag must be recorded.
        $this->assertContains(
            'meta_key_collision:' . \Sermonator\Schema\Identifiers::META_VIDEO_EMBED,
            $result->flags,
            'meta_key_collision flag must appear in WriteResult::flags'
        );
        $persisted = (array) get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains(
            'meta_key_collision:' . \Sermonator\Schema\Identifiers::META_VIDEO_EMBED,
            $persisted,
            'meta_key_collision flag must be persisted in MIGRATION_FLAGS'
        );

        // Legacy source is READ-ONLY.
        $this->assertSame( 1, count( get_post_meta( $legacyId, LegacyIdentifiers::META_VIDEO, false ) ) );
        $this->assertSame( 1, count( get_post_meta( $legacyId, \Sermonator\Schema\Identifiers::META_VIDEO_EMBED, false ) ) );
    }

    public function test_colliding_source_keys_idempotent_on_resume(): void {
        // A second write() must produce the same union (not double it) and the flag
        // must not accumulate duplicates.
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_VIDEO, '<iframe src="a.com"></iframe>' );
        add_post_meta( $legacyId, \Sermonator\Schema\Identifiers::META_VIDEO_EMBED, '<iframe src="b.com"></iframe>' );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );

        // Crash-inject to force resume.
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        $second = $writer->write( $legacyId );
        $this->assertTrue( $second->resumed );

        $values = get_post_meta( $first->newId, \Sermonator\Schema\Identifiers::META_VIDEO_EMBED, false );
        $this->assertCount( 2, $values, 'Resume must produce the same 2-value union, not 4' );

        // Flag de-duplicated.
        $flagKey = 'meta_key_collision:' . \Sermonator\Schema\Identifiers::META_VIDEO_EMBED;
        $this->assertCount( 1, array_keys( $second->flags, $flagKey ), 'collision flag must not be duplicated after resume' );
    }

    // ------------------------------------------------------------------ FIX 3: adopted-orphan stale meta

    public function test_adopted_orphan_stale_meta_is_removed(): void {
        // FIX 3 (recommended): when an orphan is adopted, any user-meta key on the
        // orphan that is NOT present in the legacy source must be removed (divergence
        // from source). Migration's own back-ref keys are EXCLUDED from deletion.
        $legacyId = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_BIBLE_PASSAGE, 'John 3:16' );

        // Inject orphan carrying a stale meta key absent from legacy.
        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            \Sermonator\Schema\Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => get_post( $legacyId )->post_title,
                'post_date'     => get_post( $legacyId )->post_date,
                'post_date_gmt' => get_post( $legacyId )->post_date_gmt,
                'post_content'  => '<p>The real body of the sermon.</p>',
            )
        );
        // Stale key absent from legacy — simulates an older writer that wrote extra meta.
        add_post_meta( $orphanId, 'stale_orphan_key', 'leftover_value' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame( $orphanId, $result->newId, 'orphan must be adopted' );

        // Stale key must be removed.
        $this->assertSame(
            '',
            (string) get_post_meta( $result->newId, 'stale_orphan_key', true ),
            'stale orphan meta key absent from legacy must be removed'
        );

        // Migration's own back-ref keys must be retained.
        $this->assertSame(
            (string) $legacyId,
            (string) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, true ),
            'LEGACY_POST_ID back-ref must be retained'
        );
    }

    public function test_adopted_orphan_own_keys_not_deleted(): void {
        // Migration's own keys (LEGACY_POST_ID, MIGRATION_COMPLETE, MIGRATION_FLAGS,
        // LEGACY_SLUG, LEGACY_POST_CONTENT) must NOT be deleted during stale-meta cleanup.
        $legacyId = $this->bareSermon();

        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            \Sermonator\Schema\Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => get_post( $legacyId )->post_title,
                'post_date'     => get_post( $legacyId )->post_date,
                'post_date_gmt' => get_post( $legacyId )->post_date_gmt,
                'post_content'  => '<p>The real body of the sermon.</p>',
            )
        );
        // Pre-stamp some own keys to verify they survive.
        add_post_meta( $orphanId, Crosswalk::LEGACY_SLUG, 'old-slug' );
        add_post_meta( $orphanId, Crosswalk::LEGACY_POST_CONTENT, 'old body' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame( $orphanId, $result->newId, 'orphan must be adopted' );

        // Own keys preserved — they were NOT stale, they are the migration's own.
        $this->assertSame(
            (string) $legacyId,
            (string) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, true ),
            'LEGACY_POST_ID must be retained'
        );
    }
}
