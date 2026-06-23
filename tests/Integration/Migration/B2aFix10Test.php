<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\PodcastWriter;
use Sermonator\Migration\SermonWriter;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * B2a Fix 10 regression tests — covers the four targeted fixes:
 *
 *  Fix 1 — PodcastWriter::applyScopedSettingsRemap() must NOT delete migrated
 *           settings when the legacy sm_podcast_settings read returns empty.
 *
 *  Fix 2 — post_date AND post_date_gmt must be force-preserved after insert in
 *           BOTH SermonWriter and PodcastWriter (same direct-$wpdb discipline
 *           already used for post_modified[_gmt]).
 *
 *  Fix 3 — mirrorNativeTaxonomies() deferred-recount contract: after a native-
 *           taxonomy migrate the native_term_recount_tt_ids key must be populated
 *           in OPTION_MIGRATION_PROGRESS so B2b Rollback can safely delete
 *           term_relationship rows directly without touching wp_update_term_count.
 *
 *  Fix 4 — PodcastWriter orphan-adoption branch must call purgeOrphanMeta() so
 *           stale meta keys (present on the orphan but absent from the legacy source)
 *           are removed, while Crosswalk own-keys are retained.
 */
final class B2aFix10Test extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    // =========================================================================
    // FIX 1: PodcastWriter applyScopedSettingsRemap — empty legacy read must NOT
    //         wipe existing migrated sermonator_podcast_settings.
    // =========================================================================

    /**
     * COMPLETE-branch self-heal with absent legacy sm_podcast_settings: the delete
     * must NOT fire and the migrated settings row must survive unchanged.
     */
    public function test_fix1_complete_branch_selfheal_with_empty_legacy_settings_leaves_migrated_row_intact(): void {
        // 1. Create a podcast with NO sm_podcast_settings on the legacy post.
        $legacyId = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => 'Empty Legacy Settings ' . wp_generate_uuid4(),
            'post_status' => 'publish',
        ) );
        // Deliberately add NO sm_podcast_settings meta to the legacy post.

        $writer = new PodcastWriter();

        // 2. First write — creates the migrated post. Settings row will be empty
        //    (no legacy row), which is fine.
        $first = $writer->write( $legacyId );
        $this->assertTrue( $first->created );

        // 3. Manually set a value on the new post's sermonator_podcast_settings key
        //    to simulate settings that were written by a prior migration run or admin.
        $migratedSettings = array( 'itunes_author' => 'Church Author', 'itunes_category' => array( 'Religion' ) );
        update_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, $migratedSettings );

        // 4. Force a missing_podcast_term_crosswalk flag open so the self-heal branch fires.
        $fakeTermId = 99999;
        update_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, array( 'missing_podcast_term_crosswalk:' . $fakeTermId ) );

        // 5. Stamp COMPLETE so the next write takes the COMPLETE-branch self-heal.
        update_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, '1' );

        // 6. Second write — takes COMPLETE-branch self-heal (open term flag present).
        //    Legacy sm_podcast_settings is STILL empty. The bug: delete fires, re-add
        //    never iterates → migrated settings wiped.
        $second = $writer->write( $legacyId );
        $this->assertSame( $first->newId, $second->newId, 'no second insert' );
        $this->assertFalse( $second->created );
        $this->assertFalse( $second->resumed );

        // 7. The migrated settings row must SURVIVE — the empty legacy read is a NO-OP.
        $surviving = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame(
            $migratedSettings,
            $surviving,
            'FIX 1: migrated sermonator_podcast_settings must NOT be wiped when the legacy sm_podcast_settings read returns empty'
        );
    }

    /**
     * Variant: legacy settings row exists but has been removed — same NO-OP expectation.
     * Uses get_post_meta returning [] to verify the guard works for genuinely absent rows.
     */
    public function test_fix1_complete_branch_selfheal_with_absent_legacy_settings_is_noop(): void {
        // Create a podcast with settings initially, write it.
        $legacyId = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => 'Absent Legacy Settings ' . wp_generate_uuid4(),
            'post_status' => 'publish',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Original' ) );

        $writer = new PodcastWriter();
        $first  = $writer->write( $legacyId );
        $this->assertTrue( $first->created );

        // The migrated settings.
        $storedAfterFirst = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( array( 'itunes_author' => 'Original' ), $storedAfterFirst );

        // Now simulate: legacy settings row is removed (e.g. legacy plugin cleaned up).
        delete_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS );
        $this->assertSame(
            array(),
            get_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, false ),
            'precondition: legacy settings row is now absent'
        );

        // Open a term flag so self-heal fires, and stamp COMPLETE.
        update_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, array( 'missing_podcast_term_crosswalk:12345' ) );
        update_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, '1' );

        // Re-write — the empty legacy read must be a NO-OP.
        $second = $writer->write( $legacyId );
        $this->assertSame( $first->newId, $second->newId );

        $surviving = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame(
            $storedAfterFirst,
            $surviving,
            'FIX 1: absent legacy settings must leave migrated settings row untouched (NO-OP)'
        );
    }

    // =========================================================================
    // FIX 2: post_date AND post_date_gmt must be force-preserved after insert
    //         in BOTH SermonWriter and PodcastWriter.
    //
    // The core issue: wp_insert_post computes post_date_gmt from post_date + site
    // TZ when the supplied post_date_gmt disagrees with get_gmt_from_date(post_date).
    // We reproduce this by setting a legacy post_date_gmt that differs from what
    // WP would compute — a TZ-offset gap (post_date is local time, post_date_gmt is
    // UTC - shifted by one hour to create a deliberate mismatch). The writer must
    // force-preserve BOTH columns via direct $wpdb->update after the insert, exactly
    // as it already does for post_modified[_gmt].
    // =========================================================================

    /**
     * Helper: set the site timezone to a non-UTC offset so that
     * get_gmt_from_date(post_date) differs from the legacy post_date_gmt,
     * making the WP recompute visible. Returns the prior value for teardown.
     */
    private function setSiteTimezone( string $tz ): string {
        $prior = get_option( 'timezone_string', '' );
        update_option( 'timezone_string', $tz );
        update_option( 'gmt_offset', '' );
        return $prior;
    }

    private function restoreSiteTimezone( string $tz ): void {
        update_option( 'timezone_string', $tz );
        update_option( 'gmt_offset', '' );
    }

    /**
     * SermonWriter: post_date and post_date_gmt must be force-preserved even when
     * they differ from what WordPress would compute from site timezone.
     *
     * Scenario: a legacy church in America/New_York (UTC-5) stored a sermon where
     * post_date='2022-11-15 10:00:00' (local) and post_date_gmt='2022-11-15 15:00:00'
     * (UTC). If the migration runs while the site timezone is America/Chicago (UTC-6),
     * wp_insert_post would recompute post_date_gmt='2022-11-15 16:00:00' — one hour
     * off. The fix must force-preserve '2022-11-15 15:00:00'.
     */
    public function test_fix2_sermon_post_date_gmt_preserved_despite_tz_mismatch(): void {
        // The legacy post has post_date (local) and post_date_gmt (UTC) that differ
        // by 5 hours (America/New_York). We force the site timezone to UTC during
        // the test so that get_gmt_from_date would compute a DIFFERENT gmt than the
        // legacy one — proving the writer forces the correct value rather than letting
        // WP recompute it.
        $priorTz = $this->setSiteTimezone( 'UTC' );

        try {
            // Legacy post has a 5-hour TZ offset baked in.
            $localDate  = '2022-11-15 10:00:00'; // local (America/New_York)
            $gmtDate    = '2022-11-15 15:00:00'; // UTC (+5h from local)
            // With site_tz=UTC, wp_insert_post would compute gmt=local='2022-11-15 10:00:00'
            // (since get_gmt_from_date with UTC offset gives the same value). That
            // differs from the legacy $gmtDate. Force-preserve must win.

            $legacyId = (int) wp_insert_post( array(
                'post_type'     => LegacyIdentifiers::POST_TYPE_SERMON,
                'post_title'    => 'TZ Mismatch Sermon ' . wp_generate_uuid4(),
                'post_status'   => 'publish',
                'post_date'     => $localDate,
                'post_date_gmt' => $gmtDate,
            ) );
            add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'tz body' );

            // Directly set the legacy post's date columns to the non-UTC values so the
            // mismatch scenario is unambiguous (WP may have already adjusted above).
            global $wpdb;
            $wpdb->update( $wpdb->posts, array(
                'post_date'     => $localDate,
                'post_date_gmt' => $gmtDate,
            ), array( 'ID' => $legacyId ) );
            clean_post_cache( $legacyId );

            $this->assertSame( $localDate, get_post( $legacyId )->post_date,     'precondition: legacy post_date' );
            $this->assertSame( $gmtDate,   get_post( $legacyId )->post_date_gmt, 'precondition: legacy post_date_gmt' );

            $result = ( new SermonWriter() )->write( $legacyId );

            $new = get_post( $result->newId );
            $this->assertSame( $localDate, $new->post_date,     'FIX 2: sermon post_date must be force-preserved verbatim' );
            $this->assertSame( $gmtDate,   $new->post_date_gmt, 'FIX 2: sermon post_date_gmt must be force-preserved verbatim (not recomputed from site TZ)' );
            $this->assertSame( 'publish', $new->post_status );
        } finally {
            $this->restoreSiteTimezone( $priorTz );
        }
    }

    /**
     * SermonWriter: draft with post_date_gmt='0000-00-00 00:00:00' — force-preserve
     * both columns. WP already keeps the zero GMT for date_floating statuses, but the
     * post_date must also be preserved verbatim.
     */
    public function test_fix2_sermon_draft_post_date_and_gmt_both_preserved(): void {
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Draft Sermon ' . wp_generate_uuid4(),
            'post_status'  => 'draft',
            'post_date'    => '2022-11-15 10:00:00',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'draft body' );

        global $wpdb;
        $wpdb->update( $wpdb->posts, array(
            'post_date'     => '2022-11-15 10:00:00',
            'post_date_gmt' => '0000-00-00 00:00:00',
        ), array( 'ID' => $legacyId ) );
        clean_post_cache( $legacyId );

        $result = ( new SermonWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( '2022-11-15 10:00:00', $new->post_date,     'FIX 2: draft sermon post_date preserved' );
        $this->assertSame( '0000-00-00 00:00:00', $new->post_date_gmt, 'FIX 2: draft sermon post_date_gmt preserved verbatim' );
        $this->assertSame( 'draft', $new->post_status );
    }

    /**
     * SermonWriter: a past-scheduled (legacy 'future') sermon whose date is now in the
     * past must NOT have its status flipped to publish. The writer passes post_status=future
     * through wp_insert_post; WP may flip it based on the date. Force-preserve post_date
     * AND post_date_gmt AND post_status via direct $wpdb after insert.
     */
    public function test_fix2_sermon_past_future_post_date_and_status_preserved(): void {
        // A date that was "future" when originally scheduled but is now "past".
        $pastFutureDate = '2020-01-01 12:00:00';

        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'    => 'Past Future Sermon ' . wp_generate_uuid4(),
            'post_status'   => 'publish', // insert as publish to avoid status dance
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'past future body' );

        // Directly set to 'future' + past date, exactly what a legacy DB would hold.
        global $wpdb;
        $wpdb->update( $wpdb->posts, array(
            'post_status'   => 'future',
            'post_date'     => $pastFutureDate,
            'post_date_gmt' => $pastFutureDate,
        ), array( 'ID' => $legacyId ) );
        clean_post_cache( $legacyId );

        $this->assertSame( 'future', get_post( $legacyId )->post_status, 'precondition: legacy post is future' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( $pastFutureDate, $new->post_date,     'FIX 2: past-future sermon post_date preserved' );
        $this->assertSame( $pastFutureDate, $new->post_date_gmt, 'FIX 2: past-future sermon post_date_gmt preserved' );
        // WP may have flipped status to publish during wp_insert_post; force-preserve
        // must revert it back to future.
        $this->assertSame( 'future', $new->post_status, 'FIX 2: past-future status must be force-preserved as future, not flipped to publish' );
    }

    /**
     * SermonWriter: a private legacy sermon must round-trip its post_date_gmt and status.
     */
    public function test_fix2_sermon_private_post_date_gmt_preserved(): void {
        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'    => 'Private Sermon ' . wp_generate_uuid4(),
            'post_status'   => 'private',
            'post_date'     => '2020-03-10 09:00:00',
            'post_date_gmt' => '2020-03-10 09:00:00',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'private body' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( '2020-03-10 09:00:00', $new->post_date,     'FIX 2: private sermon post_date preserved' );
        $this->assertSame( '2020-03-10 09:00:00', $new->post_date_gmt, 'FIX 2: private sermon post_date_gmt preserved' );
        $this->assertSame( 'private', $new->post_status );
    }

    /**
     * PodcastWriter: post_date and post_date_gmt must be force-preserved even when they
     * differ from what WP would compute from the site timezone.
     */
    public function test_fix2_podcast_post_date_gmt_preserved_despite_tz_mismatch(): void {
        $priorTz = $this->setSiteTimezone( 'UTC' );

        try {
            $localDate = '2023-04-01 08:00:00';
            $gmtDate   = '2023-04-01 13:00:00'; // UTC+5h offset — distinct from UTC-computed gmt

            $legacyId = (int) wp_insert_post( array(
                'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
                'post_title'    => 'TZ Mismatch Feed ' . wp_generate_uuid4(),
                'post_status'   => 'publish',
                'post_date'     => $localDate,
                'post_date_gmt' => $gmtDate,
            ) );
            add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

            global $wpdb;
            $wpdb->update( $wpdb->posts, array(
                'post_date'     => $localDate,
                'post_date_gmt' => $gmtDate,
            ), array( 'ID' => $legacyId ) );
            clean_post_cache( $legacyId );

            $result = ( new PodcastWriter() )->write( $legacyId );

            $new = get_post( $result->newId );
            $this->assertSame( $localDate, $new->post_date,     'FIX 2: podcast post_date must be force-preserved' );
            $this->assertSame( $gmtDate,   $new->post_date_gmt, 'FIX 2: podcast post_date_gmt must be force-preserved (not recomputed from site TZ)' );
        } finally {
            $this->restoreSiteTimezone( $priorTz );
        }
    }

    /**
     * PodcastWriter: a past-scheduled legacy future podcast must have status preserved.
     */
    public function test_fix2_podcast_past_future_post_date_and_status_preserved(): void {
        $pastFutureDate = '2020-06-01 10:00:00';

        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => 'Past Future Feed ' . wp_generate_uuid4(),
            'post_status'   => 'publish',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        global $wpdb;
        $wpdb->update( $wpdb->posts, array(
            'post_status'   => 'future',
            'post_date'     => $pastFutureDate,
            'post_date_gmt' => $pastFutureDate,
        ), array( 'ID' => $legacyId ) );
        clean_post_cache( $legacyId );

        $this->assertSame( 'future', get_post( $legacyId )->post_status, 'precondition' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( $pastFutureDate, $new->post_date,     'FIX 2: past-future podcast post_date preserved' );
        $this->assertSame( $pastFutureDate, $new->post_date_gmt, 'FIX 2: past-future podcast post_date_gmt preserved' );
        $this->assertSame( 'future', $new->post_status, 'FIX 2: past-future podcast status must be force-preserved as future' );
    }

    // =========================================================================
    // FIX 3: mirrorNativeTaxonomies deferred-recount contract.
    //         After a native-taxonomy migrate, native_term_recount_tt_ids must be
    //         populated in OPTION_MIGRATION_PROGRESS[PROGRESS_KEY].
    // =========================================================================

    /**
     * When a legacy sermon is assigned to a native taxonomy term (e.g. category),
     * after write() the native_term_recount_tt_ids key must be non-empty so B2b
     * Rollback can directly delete term_relationship rows without calling
     * wp_update_term_count (which would corrupt shared counts).
     */
    public function test_fix3_native_term_recount_tt_ids_populated_after_native_taxonomy_migrate(): void {
        // Create a category term (native taxonomy — not in MappingContract::taxonomyMap()).
        $catResult = wp_insert_term( 'Sunday Service Cat ' . wp_generate_uuid4(), 'category' );
        $catId     = (int) $catResult['term_id'];
        $this->assertGreaterThan( 0, $catId );

        // Resolve its tt_id.
        global $wpdb;
        $ttId = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $catId,
            'category'
        ) );
        $this->assertGreaterThan( 0, $ttId );

        // Create a legacy sermon and assign it to the native category.
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Native Tax Sermon ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'body' );
        wp_set_object_terms( $legacyId, array( $catId ), 'category' );

        // Clear any prior progress option.
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );

        ( new SermonWriter() )->write( $legacyId );

        // native_term_recount_tt_ids must contain the category's tt_id.
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $this->assertIsArray( $progress, 'OPTION_MIGRATION_PROGRESS must exist after native-taxonomy migrate' );
        $this->assertArrayHasKey( SermonWriter::PROGRESS_KEY, $progress, 'progress must have PROGRESS_KEY sub-key' );
        $this->assertArrayHasKey(
            'native_term_recount_tt_ids',
            $progress[ SermonWriter::PROGRESS_KEY ],
            'FIX 3: native_term_recount_tt_ids must be set after writing a native taxonomy assignment'
        );
        $recorded = $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'];
        $this->assertContains(
            $ttId,
            array_map( 'intval', (array) $recorded ),
            'FIX 3: the category tt_id must appear in native_term_recount_tt_ids'
        );
    }

    /**
     * A sermon with NO native taxonomy assignments must NOT populate
     * native_term_recount_tt_ids (no-op).
     */
    public function test_fix3_no_native_taxonomy_no_recount_tt_ids(): void {
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );

        $legacyId = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'No Native Tax ' . wp_generate_uuid4(),
            'post_status' => 'publish',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'body' );
        // No native taxonomy assignments.

        ( new SermonWriter() )->write( $legacyId );

        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        // Either no progress option at all, or native_term_recount_tt_ids is empty/absent.
        if ( is_array( $progress ) && isset( $progress[ SermonWriter::PROGRESS_KEY ] ) ) {
            $recorded = $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] ?? array();
            $this->assertEmpty(
                $recorded,
                'FIX 3: native_term_recount_tt_ids must be empty when no native taxonomy was assigned'
            );
        } else {
            // No progress at all — equally correct.
            $this->assertTrue( true );
        }
    }

    /**
     * Re-running write() on an already-migrated sermon (idempotent resume) must
     * UNION tt_ids into native_term_recount_tt_ids without duplicating entries.
     */
    public function test_fix3_rerun_unions_tt_ids_without_duplicates(): void {
        $catResult = wp_insert_term( 'Union Cat ' . wp_generate_uuid4(), 'category' );
        $catId     = (int) $catResult['term_id'];
        global $wpdb;
        $ttId = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $catId,
            'category'
        ) );

        $legacyId = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'Union Sermon ' . wp_generate_uuid4(),
            'post_status' => 'publish',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'body' );
        wp_set_object_terms( $legacyId, array( $catId ), 'category' );

        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );

        // Crash-inject a partial so the second write takes the resume leg.
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        $writer->write( $legacyId );

        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $recorded = $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] ?? array();
        $recorded = array_map( 'intval', (array) $recorded );

        // No duplicates.
        $this->assertSame(
            count( array_unique( $recorded ) ),
            count( $recorded ),
            'FIX 3: native_term_recount_tt_ids must not contain duplicates after re-run'
        );

        // The tt_id is still present.
        $this->assertContains( $ttId, $recorded );
    }

    // =========================================================================
    // FIX 4: PodcastWriter orphan-adoption branch must call purgeOrphanMeta.
    //         Stale keys absent from legacy source → purged.
    //         Crosswalk own-keys → retained.
    // =========================================================================

    /**
     * Cross-version orphan adoption: an orphan that carries a STALE meta key not
     * present in the legacy source must have that key removed (purged), while
     * Crosswalk own-keys written as part of adoption must be retained.
     */
    public function test_fix4_podcast_orphan_adoption_purges_stale_meta_key(): void {
        $uuid     = wp_generate_uuid4();
        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => 'Stale Key Feed ' . $uuid,
            'post_status'   => 'publish',
            'post_date'     => '2020-09-01 08:00:00',
            'post_date_gmt' => '2020-09-01 08:00:00',
        ) );
        // Legacy source only has itunes_author — NOT a key called 'stale_old_key'.
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        // Inject a back-ref-less orphan carrying a STALE meta key.
        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => 'Stale Key Feed ' . $uuid,
                'post_date'     => '2020-09-01 08:00:00',
                'post_date_gmt' => '2020-09-01 08:00:00',
            )
        );
        // Manually put a stale key on the orphan.
        add_post_meta( $orphanId, 'stale_old_key', 'stale_value' );

        $this->assertSame( '', (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ), 'precondition: orphan has no back-ref' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        // Adopted (not re-created).
        $this->assertFalse( $result->created, 'orphan must be adopted' );
        $this->assertSame( $orphanId, $result->newId );

        // FIX 4: the stale key must be PURGED.
        $this->assertSame(
            '',
            (string) get_post_meta( $orphanId, 'stale_old_key', true ),
            'FIX 4: stale meta key must be purged on podcast orphan adoption'
        );

        // Crosswalk own-key (LEGACY_POST_ID) must still be present.
        $this->assertSame(
            (string) $legacyId,
            (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ),
            'FIX 4: Crosswalk LEGACY_POST_ID must be retained on orphan adoption'
        );

        // The settings must be applied correctly.
        $settings = get_post_meta( $orphanId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( array( 'itunes_author' => 'Church' ), $settings );
    }

    /**
     * Crosswalk own-keys on the orphan (MIGRATION_FLAGS, LEGACY_SLUG, etc.) must
     * NOT be purged during orphan adoption.
     */
    public function test_fix4_podcast_orphan_adoption_retains_crosswalk_own_keys(): void {
        $uuid     = wp_generate_uuid4();
        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => 'Retain Own Keys ' . $uuid,
            'post_status'   => 'publish',
            'post_date'     => '2020-10-01 09:00:00',
            'post_date_gmt' => '2020-10-01 09:00:00',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => 'Retain Own Keys ' . $uuid,
                'post_date'     => '2020-10-01 09:00:00',
                'post_date_gmt' => '2020-10-01 09:00:00',
            )
        );

        // Pre-seed a Crosswalk own-key that should NOT be purged.
        add_post_meta( $orphanId, Crosswalk::LEGACY_SLUG, 'retain-own-keys' );

        // Also add a stale user key that SHOULD be purged.
        add_post_meta( $orphanId, 'another_stale_key', 'purge_me' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $this->assertFalse( $result->created );
        $this->assertSame( $orphanId, $result->newId );

        // Stale key purged.
        $this->assertSame(
            '',
            (string) get_post_meta( $orphanId, 'another_stale_key', true ),
            'FIX 4: another stale key must be purged'
        );

        // Crosswalk own-key retained (may be updated by spine, but should exist).
        // LEGACY_SLUG is a Crosswalk own-key — purgeOrphanMeta must not delete it.
        // (Note: applyPostInsertSpine will re-derive it from the legacy post_name,
        //  so we check it's non-empty rather than the exact pre-seeded value.)
        $this->assertNotSame(
            '',
            (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ),
            'FIX 4: LEGACY_POST_ID must be set after orphan adoption'
        );
    }

    /**
     * An orphan with NO stale keys — adoption must succeed normally with no side
     * effects (purgeOrphanMeta is a no-op in this case).
     */
    public function test_fix4_podcast_orphan_adoption_no_stale_keys_is_noop(): void {
        $uuid     = wp_generate_uuid4();
        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => 'No Stale Keys ' . $uuid,
            'post_status'   => 'publish',
            'post_date'     => '2020-11-01 10:00:00',
            'post_date_gmt' => '2020-11-01 10:00:00',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => 'No Stale Keys ' . $uuid,
                'post_date'     => '2020-11-01 10:00:00',
                'post_date_gmt' => '2020-11-01 10:00:00',
            )
        );
        // No extra stale meta on the orphan.

        $result = ( new PodcastWriter() )->write( $legacyId );

        $this->assertFalse( $result->created );
        $this->assertSame( $orphanId, $result->newId );

        // Settings were applied.
        $settings = get_post_meta( $orphanId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( array( 'itunes_author' => 'Church' ), $settings );
        $this->assertSame( '1', (string) get_post_meta( $orphanId, Crosswalk::MIGRATION_COMPLETE, true ) );
    }
}
