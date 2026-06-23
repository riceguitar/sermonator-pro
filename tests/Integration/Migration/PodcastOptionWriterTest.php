<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\PodcastWriter;
use Sermonator\Migration\OptionWriter;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 15: PodcastWriter + OptionWriter (integration).
 *
 * PodcastWriter mirrors the SermonWriter disciplines for the wpfc_sm_podcast →
 * sermonator_podcast post type: back-ref FIRST + MIGRATION_COMPLETE LAST,
 * KSES-safe wp_slash'd insert, per-key UNSERIALIZED meta (sm_podcast_settings
 * round-trips as an array, never double-serialized), idempotent re-run, stamped
 * with LEGACY_POST_ID so allMigratedPostIds()/rollback cover it. Any taxonomy/
 * term reference inside sm_podcast_settings is remapped via TermCrosswalk (legacy
 * taxonomy slug → new taxonomy slug; legacy term id → new term id) and renamed
 * sm_podcast_settings → sermonator_podcast_settings.
 *
 * OptionWriter reads every sermonmanager_* option, applies OptionMapper (verbatim
 * value/type under the sermonator_* prefix), with the add_option-first + backup
 * discipline (a pre-existing sermonator_* target is backed up to
 * OPTION_PRE_MIGRATION_BACKUP, never blind-clobbered), and remaps
 * wpfc_sm_default_podcast → sermonator_default_podcast via the post crosswalk.
 *
 * Legacy podcasts and options are read READ-ONLY (byte-equal before/after).
 */
final class PodcastOptionWriterTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /** Snapshot a legacy post + all its meta for byte-equality assertions. */
    private function snapshotPost( int $legacyId ): array {
        return array(
            'post' => get_post( $legacyId, ARRAY_A ),
            'meta' => get_post_meta( $legacyId ),
        );
    }

    // ------------------------------------------------------------------ Podcast

    public function test_podcast_settings_roundtrip_as_array_under_new_key(): void {
        $settings = array(
            'itunes_author'   => 'First Baptist',
            'itunes_category' => array( 'Religion & Spirituality', 'Christianity' ),
            'explicit'        => false,
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $this->assertTrue( $result->created );
        $this->assertSame( Identifiers::POST_TYPE_PODCAST, get_post_type( $result->newId ) );

        // The settings array must arrive under the NEW key as the actual ARRAY
        // (proving we read the per-key UNSERIALIZED form and let core re-serialize),
        // never double-serialized into a string.
        $stored = get_post_meta( $result->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( $settings, $stored, 'sm_podcast_settings must round-trip as an array under the new key' );

        // The legacy key name must NOT appear on the new post.
        $this->assertSame( '', (string) get_post_meta( $result->newId, LegacyIdentifiers::META_PODCAST_SETTINGS, true ) );
    }

    public function test_podcast_stamped_with_legacy_id_appears_in_all_migrated(): void {
        $legacyId = $this->fixture->createPodcast( 'Sunday Service' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        // Back-ref stamped, so the post is resolvable and rollback-covered.
        $this->assertSame( $legacyId, (int) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, true ) );
        $this->assertSame( $result->newId, Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_PODCAST ) );
        $this->assertContains( $result->newId, Crosswalk::allMigratedPostIds() );
        // MIGRATION_COMPLETE stamped LAST.
        $this->assertSame( '1', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
    }

    public function test_podcast_post_modified_preserved_from_legacy(): void {
        // MUST-FIX #4: post_modified / post_modified_gmt must carry the LEGACY
        // last-modified timestamps rather than being re-stamped to run time.
        $legacyId = (int) wp_insert_post( array(
            'post_type'         => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'        => 'Modified Feed',
            'post_status'       => 'publish',
            'post_date'         => '2018-01-01 00:00:00',
            'post_date_gmt'     => '2018-01-01 00:00:00',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        // wp_insert_post FORCES post_modified to post_date on insert, so set the
        // legacy last-modified directly — mirroring real edited-after-creation data.
        global $wpdb;
        $wpdb->update( $wpdb->posts, array(
            'post_modified'     => '2019-11-30 09:15:00',
            'post_modified_gmt' => '2019-11-30 09:15:00',
        ), array( 'ID' => $legacyId ) );
        clean_post_cache( $legacyId );

        $this->assertSame( '2019-11-30 09:15:00', get_post( $legacyId )->post_modified_gmt );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( '2019-11-30 09:15:00', $new->post_modified_gmt, 'podcast post_modified_gmt must carry the legacy value' );
        $this->assertSame( '2019-11-30 09:15:00', $new->post_modified, 'podcast post_modified must carry the legacy value' );
    }

    public function test_podcast_settings_term_references_remapped_via_crosswalk(): void {
        // Migrate a legacy series term so the crosswalk can resolve it.
        $legacySeries = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        ( new TermWriter() )->migrateAll();
        $newSeries = Crosswalk::findNewTermByLegacyId( $legacySeries, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newSeries );

        // A feed filtered by that legacy series term: the legacy taxonomy slug is a
        // key whose value is the legacy term id.
        $settings = array(
            'itunes_author'              => 'Church',
            LegacyIdentifiers::TAX_SERIES => $legacySeries,
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $stored = get_post_meta( $result->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertIsArray( $stored );
        // The legacy taxonomy key is renamed to the NEW taxonomy slug, and the
        // legacy term id is remapped to the NEW term id.
        $this->assertArrayNotHasKey( LegacyIdentifiers::TAX_SERIES, $stored );
        $this->assertArrayHasKey( Identifiers::TAX_SERIES, $stored );
        $this->assertSame( (int) $newSeries, $stored[ Identifiers::TAX_SERIES ] );
        // Non-term keys pass through verbatim.
        $this->assertSame( 'Church', $stored['itunes_author'] );
    }

    public function test_podcast_settings_term_list_references_remapped(): void {
        $a = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );
        $b = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Faith' );
        ( new TermWriter() )->migrateAll();
        $newA = Crosswalk::findNewTermByLegacyId( $a, Identifiers::TAX_TOPIC );
        $newB = Crosswalk::findNewTermByLegacyId( $b, Identifiers::TAX_TOPIC );

        $settings = array(
            LegacyIdentifiers::TAX_TOPIC => array( $a, $b ),
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $stored = get_post_meta( $result->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( array( (int) $newA, (int) $newB ), $stored[ Identifiers::TAX_TOPIC ] );
    }

    /**
     * IMPORTANT #7: a podcast whose feed is scoped to a taxonomy term that has
     * NOT YET been migrated must NOT be stamped MIGRATION_COMPLETE with a dead
     * legacy term id left verbatim in its settings. The unresolved term must
     * record a missing_podcast_term_crosswalk:<legacyId> flag and WITHHOLD
     * COMPLETE so the record stays resumable; a re-run AFTER the term is migrated
     * must self-heal (remap the id, clear the flag, finally stamp COMPLETE).
     */
    public function test_podcast_unresolved_filter_term_flags_and_withholds_complete_then_self_heals(): void {
        // A legacy series term exists but is NOT migrated yet (no crosswalk).
        $legacySeries = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $this->assertNull(
            ( new \Sermonator\Migration\TermCrosswalk() )->newTermId( $legacySeries ),
            'precondition: legacy term not yet migrated'
        );

        $settings = array(
            'itunes_author'               => 'Church',
            LegacyIdentifiers::TAX_SERIES => $legacySeries,
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $writer = new PodcastWriter();
        $first  = $writer->write( $legacyId );

        // Flagged with the unresolved legacy term id, NOT stamped COMPLETE.
        $flags = get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertIsArray( $flags );
        $this->assertContains( 'missing_podcast_term_crosswalk:' . $legacySeries, $flags );
        $this->assertSame(
            '',
            (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'MIGRATION_COMPLETE must be WITHHELD while a podcast filter term is unresolved'
        );
        // The legacy term id is still preserved in settings under the new taxonomy
        // key (never a silent drop) so the feed scope is recoverable.
        $stored = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( $legacySeries, $stored[ Identifiers::TAX_SERIES ] );

        // Now migrate the term and re-run: the record must self-heal.
        ( new TermWriter() )->migrateAll();
        $newSeries = Crosswalk::findNewTermByLegacyId( $legacySeries, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newSeries );

        $second = $writer->write( $legacyId );
        $this->assertSame( $first->newId, $second->newId, 'no second insert' );

        // The flag is cleared and COMPLETE is finally stamped.
        $healedFlags = get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $healedFlags = is_array( $healedFlags ) ? $healedFlags : array();
        $this->assertNotContains( 'missing_podcast_term_crosswalk:' . $legacySeries, $healedFlags );
        $this->assertSame( '1', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // The term id inside settings is now the NEW term id.
        $healed = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( (int) $newSeries, $healed[ Identifiers::TAX_SERIES ] );
    }

    /**
     * IMPORTANT #8: SM historically stores sm_podcast_settings as a SERIALIZED
     * STRING, not an array. The remap branch only fired on is_array($value), so a
     * string-valued settings row was copied verbatim with dangling legacy term
     * refs. The writer must maybe_unserialize() a string value, remap it, and
     * re-store it as an ARRAY (core re-serializes) under the new key.
     */
    public function test_podcast_settings_serialized_string_value_is_unserialized_and_remapped(): void {
        $legacySeries = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Lent' );
        ( new TermWriter() )->migrateAll();
        $newSeries = Crosswalk::findNewTermByLegacyId( $legacySeries, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newSeries );

        $settings = array(
            'itunes_author'               => 'Church',
            LegacyIdentifiers::TAX_SERIES => $legacySeries,
        );
        $legacyId = $this->fixture->createPodcastWithSerializedStringSettings( $settings );

        // Precondition: the legacy row holds a STRING value, not an array.
        $rawLegacy = get_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, false );
        $this->assertIsString( $rawLegacy[0], 'fixture must seed a serialized STRING value' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        // Stored as an ARRAY under the new key, with the term remapped.
        $stored = get_post_meta( $result->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertIsArray( $stored, 'a serialized-string settings value must be re-stored as an array' );
        $this->assertArrayNotHasKey( LegacyIdentifiers::TAX_SERIES, $stored );
        $this->assertSame( (int) $newSeries, $stored[ Identifiers::TAX_SERIES ] );
        $this->assertSame( 'Church', $stored['itunes_author'] );
    }

    public function test_podcast_meta_and_settings_with_backslashes_round_trip_byte_exact(): void {
        // CRITICAL #2: PodcastWriter::applyMeta wrote ALL podcast meta — the remapped
        // settings array AND every verbatim non-settings key — via add_post_meta
        // WITHOUT wp_slash(). get_post_meta(...,false) is UNSLASHED; add_post_meta()'s
        // wp_unslash() then strips a backslash level, corrupting enclosure/audio UNC
        // paths, escaped quotes in itunes_* fields, and serialized-string values. We
        // seed legacy meta via seedRawMeta (wp_slash so the DB row holds the exact
        // bytes) so the test exercises the WRITER path, then assert byte-equality.
        $settings = array(
            'itunes_author'  => 'St. Mary\'s "Voice" \\ Choir',
            'itunes_summary' => 'Quote: \\"Peace\\" and a path C:\\feeds\\main',
            'unc_image'      => '\\\\server\\share\\art.png',
            'nested'         => array( 'slashy' => 'a \\ b \\"c\\"' ),
        );
        $verbatimEnclosure = '\\\\nas\\media\\episode-01.mp3';
        $verbatimQuote     = 'Subtitle \\"Sunday\\" service';

        $legacyId = $this->fixture->createPodcastWithSettings(
            $settings,
            'Feed',
            '',
            array() // extra meta seeded below via seedRawMeta so bytes survive
        );
        // Overwrite the settings row with a raw-seeded one so backslashes survive in
        // the DB exactly. createPodcastWithSettings used add_post_meta (no slash), so
        // delete + re-seed the settings via the raw helper.
        delete_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS );
        $this->fixture->seedRawMeta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, $settings );
        $this->fixture->seedRawMeta( $legacyId, 'enclosure', $verbatimEnclosure );
        $this->fixture->seedRawMeta( $legacyId, 'subtitle', $verbatimQuote );

        // Precondition: the legacy rows hold the EXACT bytes (fixture path OK).
        $this->assertSame( $settings, get_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, true ) );
        $this->assertSame( $verbatimEnclosure, get_post_meta( $legacyId, 'enclosure', true ) );

        $result = ( new PodcastWriter() )->write( $legacyId );

        // Verbatim non-settings keys: byte-exact, no backslash level lost.
        $this->assertSame( $verbatimEnclosure, get_post_meta( $result->newId, 'enclosure', true ) );
        $this->assertSame( $verbatimQuote, get_post_meta( $result->newId, 'subtitle', true ) );

        // The remapped settings array (no term refs here) must round-trip byte-exact
        // under the new key — wp_slash recurses into the array so every nested
        // backslash/escaped-quote survives.
        $stored = get_post_meta( $result->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( $settings, $stored, 'podcast settings backslashes must round-trip byte-exact' );
    }

    public function test_podcast_body_kses_safe_iframe_survives(): void {
        $iframe   = '<iframe src="https://example.com/feed"></iframe>';
        $legacyId = $this->fixture->createPodcastWithSettings( array( 'itunes_author' => 'C' ), 'Feed', $iframe );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $this->assertSame( $iframe, get_post( $result->newId )->post_content, 'iframe must survive KSES-safe insert verbatim' );
    }

    public function test_podcast_idempotent_rerun_no_duplicate_post_or_meta(): void {
        $settings = array( 'itunes_author' => 'Church', 'explicit' => true );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $writer = new PodcastWriter();
        $first  = $writer->write( $legacyId );
        $second = $writer->write( $legacyId );

        // No second insert.
        $this->assertSame( $first->newId, $second->newId );
        $this->assertTrue( $first->created );
        $this->assertFalse( $second->created );

        // Exactly one migrated podcast for this legacy id.
        $this->assertCount(
            1,
            get_posts( array(
                'post_type'   => Identifiers::POST_TYPE_PODCAST,
                'meta_key'    => Crosswalk::LEGACY_POST_ID,
                'meta_value'  => $legacyId,
                'post_status' => 'any',
                'fields'      => 'ids',
                'numberposts' => -1,
            ) )
        );

        // Settings meta is a single canonical row after re-run.
        $this->assertCount( 1, get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, false ) );
        $this->assertSame( $settings, get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true ) );
    }

    public function test_podcast_back_ref_written_atomically_in_the_insert(): void {
        // CRITICAL #4: the LEGACY_POST_ID back-ref must be written ATOMICALLY in the
        // SAME insert call (meta_input). A single canonical back-ref row is present
        // on a freshly-created podcast.
        $legacyId = $this->fixture->createPodcastWithSettings( array( 'itunes_author' => 'Church' ) );

        $result = ( new PodcastWriter() )->write( $legacyId );
        $this->assertTrue( $result->created );

        $backRefRows = get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, false );
        $this->assertCount( 1, $backRefRows, 'Exactly one back-ref row (atomic insert + idempotent markLegacy).' );
        $this->assertSame( (string) $legacyId, (string) $backRefRows[0] );
    }

    public function test_podcast_crash_orphan_back_ref_less_post_is_adopted_not_duplicated(): void {
        // CRITICAL #4: duplicate-post crash window for podcasts. An OLDER writer
        // inserted the podcast but aborted BEFORE the separate markLegacy stamp,
        // leaving a back-ref-less orphan invisible to the authoritative probe. A
        // naive resume would mint a SECOND visible feed. The writer must ADOPT the
        // orphan and yield exactly one post.
        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => 'Crash Window Feed',
            'post_status'   => 'publish',
            'post_date'     => '2021-04-09 08:00:00',
            'post_date_gmt' => '2021-04-09 08:00:00',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => 'Crash Window Feed',
                'post_date'     => '2021-04-09 08:00:00',
                'post_date_gmt' => '2021-04-09 08:00:00',
            )
        );
        $this->assertSame( '', (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ), 'precondition: orphan is back-ref-less' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $this->assertFalse( $result->created, 'A back-ref-less orphan must be adopted, not re-created.' );
        $this->assertSame( $orphanId, $result->newId );

        $migrated = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_PODCAST,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
            'meta_key'    => Crosswalk::LEGACY_POST_ID,
        ) );
        $this->assertCount( 1, $migrated, 'Adoption must not leave a second back-ref-less duplicate.' );
        $this->assertSame( array( $orphanId ), array_map( 'intval', $migrated ) );

        $this->assertSame( (string) $legacyId, (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ) );
        $this->assertSame( $orphanId, Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_PODCAST ) );

        // Settings were applied during the adoption-driven spine.
        $this->assertSame( array( 'itunes_author' => 'Church' ), get_post_meta( $orphanId, Identifiers::META_PODCAST_SETTINGS, true ) );

        // Idempotent re-run: same post, still one.
        $second = ( new PodcastWriter() )->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertSame( $orphanId, $second->newId );
        $this->assertCount( 1, get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_PODCAST,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
            'meta_key'    => Crosswalk::LEGACY_POST_ID,
        ) ) );
    }

    public function test_partial_podcast_resumes_not_reinserts(): void {
        $legacyId = $this->fixture->createPodcast( 'Resumable' );
        $writer   = new PodcastWriter();
        $first    = $writer->write( $legacyId );

        // Crash-inject a partial: drop COMPLETE so the next write resumes.
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        $second = $writer->write( $legacyId );

        $this->assertSame( $first->newId, $second->newId );
        $this->assertTrue( $second->resumed );
        $this->assertSame( '1', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
    }

    public function test_legacy_podcast_byte_equal_before_and_after(): void {
        $settings = array(
            'itunes_author' => 'Church',
            'nested'        => array( 'k' => array( 1, 2, 3 ) ),
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings, 'Feed', '<iframe src="x"></iframe>' );

        $before = $this->snapshotPost( $legacyId );

        ( new PodcastWriter() )->write( $legacyId );
        // resume too
        $newId = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_PODCAST );
        delete_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE );
        ( new PodcastWriter() )->write( $legacyId );

        $after = $this->snapshotPost( $legacyId );
        $this->assertSame( $before, $after, 'Legacy podcast post/meta were mutated by the writer.' );
    }

    // -------------------------------- FIX 1 (podcast): meta-key target collision

    public function test_podcast_colliding_source_keys_produce_union_and_flag(): void {
        // FIX 1 (IMPORTANT, podcast path): sm_podcast_settings → sermonator_podcast_settings
        // (known rename). A legacy podcast carrying BOTH 'sm_podcast_settings' (renamed)
        // AND a stray verbatim 'sermonator_podcast_settings' row (unknown key, pass-through)
        // produces a collision. The current code's second-iteration delete wipes the first.
        // After the fix both values must be on the new post (union) and a collision flag recorded.
        $legacyId = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => 'Collision Feed ' . wp_generate_uuid4(),
            'post_status' => 'publish',
        ) );
        // The RENAMED source key.
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church A' ) );
        // A stray verbatim target-key row (unknown key path — passes through unchanged).
        add_post_meta( $legacyId, Identifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church B' ) );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $values = get_post_meta( $result->newId, Identifiers::META_PODCAST_SETTINGS, false );
        $this->assertCount( 2, $values, 'Both podcast settings values (union) must be retained on the target key' );

        $this->assertContains(
            'meta_key_collision:' . Identifiers::META_PODCAST_SETTINGS,
            $result->flags,
            'meta_key_collision flag must appear for podcast settings collision'
        );
        $persisted = (array) get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains(
            'meta_key_collision:' . Identifiers::META_PODCAST_SETTINGS,
            $persisted,
            'collision flag must be persisted in MIGRATION_FLAGS'
        );
    }

    // -------------------------------- FIX 2 (podcast): content-drift orphan adoption

    public function test_podcast_crash_orphan_adopted_despite_content_drift(): void {
        // FIX 2 (IMPORTANT, podcast path): the orphan probe currently uses post_content
        // byte-equality. An older-writer orphan with a different body must still be adopted
        // when title+date_gmt uniquely identify it.
        $uuid     = wp_generate_uuid4();
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'   => 'Drift Feed ' . $uuid,
            'post_status'  => 'publish',
            'post_date'     => '2022-09-01 08:00:00',
            'post_date_gmt' => '2022-09-01 08:00:00',
            'post_content' => 'legacy raw feed body',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );

        // Orphan with a DIFFERENT body — cross-version content drift.
        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => 'Drift Feed ' . $uuid,
                'post_date'     => '2022-09-01 08:00:00',
                'post_date_gmt' => '2022-09-01 08:00:00',
                'post_content'  => 'OLDER WRITER stored a completely different body',
            )
        );
        $this->assertSame( '', (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ), 'precondition: orphan is back-ref-less' );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $this->assertFalse( $result->created, 'Podcast orphan with drifted content must be adopted, not re-created.' );
        $this->assertSame( $orphanId, $result->newId );

        $migrated = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_PODCAST,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
            'meta_key'    => Crosswalk::LEGACY_POST_ID,
        ) );
        $this->assertCount( 1, $migrated, 'Content-drift orphan adoption must not duplicate the podcast.' );
        $this->assertSame( (string) $legacyId, (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ) );
    }

    // ------------------------------------------------------------------- Options

    public function test_sermonmanager_options_mapped_verbatim_value_and_type(): void {
        $this->fixture->setOption( 'sermonmanager_player', 'mediaelement' );
        $this->fixture->setOption( 'sermonmanager_per_page', 10 ); // int
        $this->fixture->setOption( 'sermonmanager_template', array( 'a' => 1, 'b' => true ) ); // array

        $result = ( new OptionWriter() )->migrate();

        $this->assertSame( 'mediaelement', get_option( 'sermonator_player' ) );
        $this->assertSame( 10, get_option( 'sermonator_per_page' ), 'int type preserved verbatim' );
        $this->assertSame( array( 'a' => 1, 'b' => true ), get_option( 'sermonator_template' ) );
        $this->assertGreaterThanOrEqual( 3, $result['written'] );
    }

    public function test_preexisting_target_option_backed_up_not_clobbered(): void {
        // A church already has a NATIVE sermonator_player value.
        add_option( 'sermonator_player', 'native-choice' );
        $this->fixture->setOption( 'sermonmanager_player', 'mediaelement' );

        $result = ( new OptionWriter() )->migrate();

        // The migrated value wins on the live option...
        $this->assertSame( 'mediaelement', get_option( 'sermonator_player' ) );
        // ...but the native value is preserved in the pre-migration backup.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertIsArray( $backup );
        $this->assertSame( 'native-choice', $backup['sermonator_player'] );
        $this->assertGreaterThanOrEqual( 1, $result['backed_up'] );
    }

    public function test_default_podcast_option_remapped_to_new_podcast_id(): void {
        $legacyPodcast = $this->fixture->createPodcast( 'Main' );
        $newId         = ( new PodcastWriter() )->write( $legacyPodcast )->newId;

        // Legacy default-podcast option points at the legacy podcast id.
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $legacyPodcast );

        ( new OptionWriter() )->migrate();

        $this->assertSame(
            $newId,
            (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST ),
            'default-podcast option must be remapped to the NEW podcast id'
        );
    }

    public function test_options_idempotent_rerun_no_double_backup(): void {
        add_option( 'sermonator_player', 'native-choice' );
        $this->fixture->setOption( 'sermonmanager_player', 'mediaelement' );

        $writer = new OptionWriter();
        $writer->migrate();
        $second = $writer->migrate();

        // The backup still holds the ORIGINAL native value (the re-run must NOT
        // back up the value we ourselves wrote).
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertSame( 'native-choice', $backup['sermonator_player'] );
        $this->assertSame( 0, $second['backed_up'], 'a re-run backs up nothing new' );
        $this->assertSame( 'mediaelement', get_option( 'sermonator_player' ) );
    }

    /**
     * Crash-safety (IMPORTANT #6): the writer's add_option/update_option is
     * irreversible; the written-key marker is a SEPARATE option write. If the
     * process aborts AFTER the value is stamped but BEFORE markKeyWritten, the
     * target option holds OUR OWN migrated value with no marker recorded. A naive
     * resume sees add_option fail + keyAlreadyWritten false and backs up the
     * migrated value into OPTION_PRE_MIGRATION_BACKUP as if it were native — so a
     * later rollback restores the MIGRATED value, not the true native (which, in
     * the add_option path, never existed; the key must simply not be backed up).
     *
     * We inject that exact crash window — migrated value present, marker absent —
     * and assert the resume does NOT record the migrated value as a native backup.
     */
    public function test_resume_after_crash_does_not_backup_migrated_value_as_native(): void {
        // No native sermonator_player exists; the legacy value is 'mediaelement'.
        $this->fixture->setOption( 'sermonmanager_player', 'mediaelement' );

        // Crash injection: the prior run's add_option already stamped the migrated
        // value, but the process died before markKeyWritten ran. Reproduce that
        // state exactly: target holds OUR migrated value, NO written-key marker.
        add_option( 'sermonator_player', 'mediaelement' );
        $this->assertFalse(
            get_option( Identifiers::OPTION_MIGRATION_PROGRESS ),
            'precondition: no progress marker recorded (the crash window)'
        );

        // Resume.
        ( new OptionWriter() )->migrate();

        // The migrated value remains live.
        $this->assertSame( 'mediaelement', get_option( 'sermonator_player' ) );

        // The backup must NOT claim our migrated value as a native pre-existing one.
        // There was no native value, so the key must not appear in the backup at all;
        // if it appears, it must NEVER hold the migrated value.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        if ( is_array( $backup ) && array_key_exists( 'sermonator_player', $backup ) ) {
            $this->assertNotSame(
                'mediaelement',
                $backup['sermonator_player'],
                'Resume backed up OUR migrated value as if it were the native value; rollback would restore the migrated value.'
            );
        } else {
            $this->assertTrue( true ); // no spurious native backup recorded — correct.
        }
    }

    /**
     * The same crash window when a TRUE native value DID exist and was correctly
     * backed up by the first run, but the crash struck between the native backup +
     * marker and... actually the dangerous ordering is the reverse: native present,
     * first run backs up native and marks key, THEN update_option writes migrated.
     * If the crash strikes between markKeyWritten and update_option, the resume
     * sees keyAlreadyWritten true and skips re-backup — correct. The only loss
     * window is add_option-first (covered above). This test pins the native-present
     * resume: after a crash that left the migrated value stamped, the ORIGINAL
     * native backup must survive intact and never be overwritten by the migrated
     * value.
     */
    public function test_resume_preserves_true_native_backup_not_migrated_value(): void {
        // A church's true native value.
        add_option( 'sermonator_player', 'native-choice' );
        $this->fixture->setOption( 'sermonmanager_player', 'mediaelement' );

        // First run: backs up native, writes migrated.
        ( new OptionWriter() )->migrate();
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertSame( 'native-choice', $backup['sermonator_player'] );

        // Crash injection: wipe the progress marker (as if the run died right after
        // update_option, before/around the marker persist), leaving the migrated
        // value live and NO marker — the worst-case resume input.
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );

        // Resume.
        ( new OptionWriter() )->migrate();

        // The backup must STILL hold the true native value, never the migrated one.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertSame(
            'native-choice',
            $backup['sermonator_player'],
            'Resume overwrote the true native backup with our migrated value.'
        );
        $this->assertSame( 'mediaelement', get_option( 'sermonator_player' ) );
    }

    public function test_complete_podcast_with_open_term_flag_self_heal_does_not_clobber_admin_meta(): void {
        // FIX 2: the COMPLETE-branch self-heal called applyMeta() which overwrote ALL
        // meta keys unconditionally — clobbering any admin edits made post-migration.
        // The fix scopes the self-heal to ONLY the sermonator_podcast_settings key.

        // 1. Create a legacy podcast with settings containing an unmigrated term.
        $legacySeries = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Self-Heal Series' );
        $settings = array(
            'itunes_author'               => 'Church Author',
            LegacyIdentifiers::TAX_SERIES => $legacySeries,
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        // Also give it a verbatim meta key on legacy (to verify it stays on the new post).
        add_post_meta( $legacyId, 'itunes_image', 'https://legacy.example/art.jpg' );

        $writer = new PodcastWriter();

        // 2. First write: WITHHELD from COMPLETE due to unresolved term.
        $first = $writer->write( $legacyId );
        $this->assertContains(
            'missing_podcast_term_crosswalk:' . $legacySeries,
            (array) get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true )
        );
        $this->assertSame(
            '',
            (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'precondition: COMPLETE must be withheld while term is unresolved'
        );

        // 3. Stamp MIGRATION_COMPLETE manually (simulate an older writer that completed
        //    despite the open flag — the exact bug scenario).
        update_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, '1' );

        // 4. Admin edits the new podcast: sets itunes_image to a new value.
        update_post_meta( $first->newId, 'itunes_image', 'https://admin-edited.example/art.jpg' );
        $this->assertSame(
            'https://admin-edited.example/art.jpg',
            get_post_meta( $first->newId, 'itunes_image', true ),
            'precondition: admin-edited value is set'
        );

        // 5. Migrate the term.
        ( new TermWriter() )->migrateAll();
        $newSeries = Crosswalk::findNewTermByLegacyId( $legacySeries, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newSeries );

        // 6. Second write: goes through COMPLETE-branch self-heal.
        $second = $writer->write( $legacyId );
        $this->assertSame( $first->newId, $second->newId, 'no second insert' );
        $this->assertFalse( $second->created );
        $this->assertFalse( $second->resumed );

        // 7. Assert: flag is cleared.
        $healedFlags = (array) get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertNotContains(
            'missing_podcast_term_crosswalk:' . $legacySeries,
            $healedFlags,
            'the open term flag must be cleared after self-heal'
        );

        // 8. Assert: settings contain the NEW term id.
        $healed = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertIsArray( $healed );
        $this->assertSame(
            (int) $newSeries,
            $healed[ Identifiers::TAX_SERIES ],
            'settings must contain the remapped new term id after self-heal'
        );

        // 9. Assert: admin-edited itunes_image SURVIVES (was not clobbered).
        $this->assertSame(
            'https://admin-edited.example/art.jpg',
            get_post_meta( $first->newId, 'itunes_image', true ),
            'admin-edited itunes_image must NOT be clobbered by the COMPLETE-branch self-heal'
        );

        // 10. Assert: MIGRATION_COMPLETE is still stamped.
        $this->assertSame(
            '1',
            (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'MIGRATION_COMPLETE must remain stamped after self-heal'
        );
    }

    // ----------------------------------- FIX 1: term-id-0 unscoped passthrough

    /**
     * FIX 1: A podcast settings taxonomy key with integer value 0 means "not
     * scoped to any term" in Sermon Manager. It must NOT trigger a
     * missing_podcast_term_crosswalk flag, must stamp MIGRATION_COMPLETE, and
     * must NOT re-write (clobber admin edits) on a second write() call.
     */
    public function test_podcast_term_id_zero_is_unscoped_not_flagged(): void {
        $settings = array(
            'itunes_author'                => 'Church',
            LegacyIdentifiers::TAX_SERIES => 0,
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $writer = new PodcastWriter();
        $first  = $writer->write( $legacyId );

        $flags = get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $flags = is_array( $flags ) ? $flags : array();
        foreach ( $flags as $flag ) {
            $this->assertStringNotContainsString(
                'missing_podcast_term_crosswalk',
                (string) $flag,
                'A term id of 0 (unscoped) must never produce a crosswalk flag'
            );
        }

        $this->assertSame(
            '1',
            (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'MIGRATION_COMPLETE must be stamped for an unscoped (term id 0) podcast'
        );

        $settingsAfterFirst = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );

        $second = $writer->write( $legacyId );
        $this->assertSame( $first->newId, $second->newId, 'no second insert' );
        $settingsAfterSecond = get_post_meta( $first->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame(
            $settingsAfterFirst,
            $settingsAfterSecond,
            'second write() must not re-write settings (admin edits would be clobbered)'
        );
    }

    /**
     * FIX 1: Same test with string "0" — ctype_digit("0") is true, so the old
     * code attempted a crosswalk on term id 0 (which returns null) and flagged it.
     */
    public function test_podcast_term_id_zero_string_is_unscoped_not_flagged(): void {
        $settings = array(
            LegacyIdentifiers::TAX_SERIES => '0',
        );
        $legacyId = $this->fixture->createPodcastWithSettings( $settings );

        $result = ( new PodcastWriter() )->write( $legacyId );

        $flags = get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $flags = is_array( $flags ) ? $flags : array();
        foreach ( $flags as $flag ) {
            $this->assertStringNotContainsString(
                'missing_podcast_term_crosswalk',
                (string) $flag,
                'String "0" (unscoped) must never produce a crosswalk flag'
            );
        }
        $this->assertSame(
            '1',
            (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'MIGRATION_COMPLETE must be stamped for a string "0" (unscoped) podcast'
        );
    }

    // ----------------------------------- FIX 2: symmetric KSES restore (podcast)

    /**
     * FIX 2: insertKsesSafe must restore KSES filter state symmetrically.
     * If KSES is OFF before write(), it must still be OFF after.
     */
    public function test_podcast_kses_filter_state_restored_symmetrically_when_off_before(): void {
        kses_remove_filters();
        $this->assertFalse(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'precondition: KSES must be OFF before write()'
        );

        $legacyId = $this->fixture->createPodcast( 'KSES Off Test' );
        ( new PodcastWriter() )->write( $legacyId );

        $this->assertFalse(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'KSES must remain OFF after PodcastWriter::write() when it was OFF before (symmetric restore)'
        );

        kses_init_filters();
    }

    /**
     * FIX 2: If KSES is ON before write(), it must still be ON after.
     */
    public function test_podcast_kses_filter_state_restored_symmetrically_when_on_before(): void {
        kses_init_filters();
        $this->assertTrue(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'precondition: KSES must be ON before write()'
        );

        $legacyId = $this->fixture->createPodcast( 'KSES On Test' );
        ( new PodcastWriter() )->write( $legacyId );

        $this->assertTrue(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'KSES must remain ON after PodcastWriter::write() when it was ON before (symmetric restore)'
        );
    }

    // ----------------------------------- FIX 3: orphan adoption uniqueness (podcast)

    /**
     * FIX 3: Two legacy podcasts sharing title + post_date_gmt — when ONE has a
     * crash orphan, the writer must NOT mis-adopt the orphan for the OTHER legacy
     * record (content swap). Require unique candidate; refuse if >1 matches and
     * additionally discriminate by post_content.
     */
    public function test_podcast_orphan_not_adopted_when_multiple_candidates_match(): void {
        $sharedTitle = 'Ambiguous Feed ' . wp_generate_uuid4();
        $sharedDate  = '2022-06-15 10:00:00';

        // Two DISTINCT legacy podcasts that share title + date (unusual but possible).
        $legacyA = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => $sharedTitle,
            'post_status'   => 'publish',
            'post_date'     => $sharedDate,
            'post_date_gmt' => $sharedDate,
            'post_content'  => 'Content from feed A',
        ) );
        add_post_meta( $legacyA, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Author A' ) );

        $legacyB = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => $sharedTitle,
            'post_status'   => 'publish',
            'post_date'     => $sharedDate,
            'post_date_gmt' => $sharedDate,
            'post_content'  => 'Content from feed B',
        ) );
        add_post_meta( $legacyB, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Author B' ) );

        // Inject TWO back-ref-less orphan posts matching the same title + date,
        // so the finder sees >1 candidate and must refuse to adopt (no crosswalk).
        $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => $sharedTitle,
                'post_date'     => $sharedDate,
                'post_date_gmt' => $sharedDate,
                'post_content'  => 'Content from feed A',
            )
        );
        $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => $sharedTitle,
                'post_date'     => $sharedDate,
                'post_date_gmt' => $sharedDate,
                'post_content'  => 'Content from feed B',
            )
        );

        $writer  = new PodcastWriter();
        $resultA = $writer->write( $legacyA );
        $resultB = $writer->write( $legacyB );

        // Both legacy records must migrate successfully (fresh inserts, not mis-adopted).
        $this->assertNotSame( $resultA->newId, $resultB->newId, 'Each legacy record must map to a distinct new post' );

        // Neither result should have cross-adopted: A's settings must appear on A's post, B's on B's.
        $settingsA = get_post_meta( $resultA->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $settingsB = get_post_meta( $resultB->newId, Identifiers::META_PODCAST_SETTINGS, true );
        $this->assertSame( 'Author A', $settingsA['itunes_author'] ?? null, 'legacyA settings must be on resultA post' );
        $this->assertSame( 'Author B', $settingsB['itunes_author'] ?? null, 'legacyB settings must be on resultB post' );
    }

    /**
     * FIX 2 (IMPORTANT #9): the orphan probe now matches on title+date+type+back-ref-absent
     * ONLY — post_content is no longer a discriminator. When exactly one back-ref-less
     * candidate matches, it is ADOPTED regardless of content. A back-ref-less post with
     * the same title+date (whether native or old-writer orphan) is adopted when it is the
     * only candidate — the >1 guard keeps ambiguous-multi cases safe.
     */
    public function test_podcast_single_backref_less_candidate_adopted_regardless_of_content(): void {
        $sharedTitle = 'Lookalike Feed ' . wp_generate_uuid4();
        $sharedDate  = '2022-07-20 09:00:00';

        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'    => $sharedTitle,
            'post_status'   => 'publish',
            'post_date'     => $sharedDate,
            'post_date_gmt' => $sharedDate,
            'post_content'  => 'Legacy podcast content',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Legacy Church' ) );

        // A back-ref-less post with matching title + date but different content.
        // After FIX 2 this is ADOPTED — exactly-one-candidate rule, content ignored.
        $candidateId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'post_title'    => $sharedTitle,
                'post_date'     => $sharedDate,
                'post_date_gmt' => $sharedDate,
                'post_content'  => 'DIFFERENT content — content drift or native lookalike',
            )
        );

        $result = ( new PodcastWriter() )->write( $legacyId );

        // ADOPTED: exactly one back-ref-less candidate, adopted regardless of content.
        $this->assertSame( $candidateId, $result->newId, 'Single back-ref-less candidate must be adopted regardless of post_content.' );
        $this->assertFalse( $result->created, 'Adoption must not set created=true.' );
        $this->assertSame(
            (string) $legacyId,
            (string) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, true )
        );
    }

    // ---

    public function test_legacy_options_untouched_byte_equal(): void {
        $this->fixture->setOption( 'sermonmanager_player', 'mediaelement' );
        $this->fixture->setOption( 'sermonmanager_template', array( 'a' => 1 ) );
        $legacyPodcast = $this->fixture->createPodcast( 'Main' );
        ( new PodcastWriter() )->write( $legacyPodcast );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $legacyPodcast );

        $before = array(
            'sermonmanager_player'    => get_option( 'sermonmanager_player' ),
            'sermonmanager_template'  => get_option( 'sermonmanager_template' ),
            'default_podcast'         => get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST ),
        );

        ( new OptionWriter() )->migrate();
        ( new OptionWriter() )->migrate();

        $after = array(
            'sermonmanager_player'    => get_option( 'sermonmanager_player' ),
            'sermonmanager_template'  => get_option( 'sermonmanager_template' ),
            'default_podcast'         => get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST ),
        );

        $this->assertSame( $before, $after, 'Legacy options must be byte-equal before/after.' );
    }

    // ----------------------------------- FIX 1: embedded term id remap in options

    /**
     * FIX 1a: sermonmanager_default_series (a legacy term id) must be translated to
     * the new term id via the TermCrosswalk after TermWriter has run. The migrated
     * option sermonator_default_series must hold the NEW term id, never the legacy one.
     */
    public function test_option_embedded_term_id_remapped_to_new_term_id(): void {
        // Seed a legacy term and run TermWriter so a crosswalk exists.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Sunday AM ' . wp_generate_uuid4() );
        $this->assertGreaterThan( 0, $legacyTermId );

        // Set the legacy option to the legacy term id (as a string, the way WP stores it).
        $this->fixture->setOption( 'sermonmanager_default_series', (string) $legacyTermId );

        // Run TermWriter to create the term crosswalk.
        ( new \Sermonator\Migration\TermWriter() )->migrateAll();

        // Resolve the new term id via Crosswalk so we know what to expect.
        $newTermId = \Sermonator\Migration\Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newTermId, 'TermWriter must have produced a crosswalk for the legacy term' );

        // Run OptionWriter.
        ( new OptionWriter() )->migrate();

        // The new option must hold the NEW term id, not the legacy one.
        $stored = get_option( 'sermonator_default_series' );
        $this->assertSame(
            (string) $newTermId,
            (string) $stored,
            'sermonator_default_series must hold the NEW term id after remap, not the legacy one'
        );
        $this->assertNotSame(
            (string) $legacyTermId,
            (string) $stored,
            'sermonator_default_series must NOT hold the legacy term id after remap'
        );
    }

    /**
     * FIX 1b: When the crosswalk for sermonmanager_default_series cannot be resolved
     * (TermWriter has NOT run), the value must be left verbatim AND a flag must be
     * recorded under OPTION_MIGRATION_PROGRESS['options']['option_id_flags'].
     */
    public function test_option_embedded_term_id_unresolvable_left_verbatim_and_flagged(): void {
        // Seed a legacy term id that has NO crosswalk (TermWriter never ran).
        $legacyTermId = 99991; // A non-existent term id — no crosswalk will exist.
        $this->fixture->setOption( 'sermonmanager_default_series', (string) $legacyTermId );

        // Run OptionWriter WITHOUT TermWriter.
        ( new OptionWriter() )->migrate();

        // The value must be left verbatim (legacy term id, no crosswalk available).
        $stored = get_option( 'sermonator_default_series' );
        $this->assertSame(
            (string) $legacyTermId,
            (string) $stored,
            'sermonator_default_series must be left verbatim when crosswalk is unresolvable'
        );

        // A flag must appear in OPTION_MIGRATION_PROGRESS['options']['option_id_flags'].
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $this->assertIsArray( $progress, 'OPTION_MIGRATION_PROGRESS must be an array' );
        $this->assertArrayHasKey( 'options', $progress, 'progress must have an "options" sub-key' );
        $this->assertArrayHasKey( 'option_id_flags', $progress['options'], 'options sub-key must have "option_id_flags"' );
        $this->assertContains(
            'missing_option_id_crosswalk:sermonator_default_series',
            $progress['options']['option_id_flags'],
            'option_id_flags must contain the missing-crosswalk flag for sermonator_default_series'
        );
    }

    // ----------------------------------- FIX 2: array-valued embedded term id remap

    /**
     * FIX 2a: An option whose value is an ARRAY embedding a legacy term id must have
     * that id remapped recursively via TermCrosswalk. Non-integer elements must pass
     * through verbatim (type-preserving). Covers the array-recursion gap: previously
     * only scalar TERM_ID_OPTIONS were remapped; array values were copied verbatim.
     */
    public function test_option_array_valued_embedded_term_id_remapped(): void {
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Array Series ' . wp_generate_uuid4() );
        ( new TermWriter() )->migrateAll();
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newTermId, 'TermWriter must produce a crosswalk for the legacy term' );

        // Array value: one legacy term id + one verbatim non-integer entry.
        $this->fixture->setOption( 'sermonmanager_default_series', array( 0 => $legacyTermId, 'extra' => 'keep' ) );

        ( new OptionWriter() )->migrate();

        $stored = get_option( 'sermonator_default_series' );
        $this->assertIsArray( $stored, 'Array-valued option must remain an array after remap.' );
        $this->assertSame(
            (int) $newTermId,
            $stored[0],
            'Legacy term id at index 0 must be remapped to the new term id.'
        );
        $this->assertSame(
            'keep',
            $stored['extra'],
            'Non-integer "extra" key must pass through verbatim.'
        );
    }

    /**
     * FIX 2b: An array-valued option with an unresolvable legacy term id must leave
     * that id verbatim AND record a missing_option_id_crosswalk flag.
     */
    public function test_option_unresolvable_array_term_id_left_verbatim_and_flagged(): void {
        // Non-existent term id — no crosswalk will exist.
        $this->fixture->setOption( 'sermonmanager_default_series', array( 0 => 99998 ) );

        ( new OptionWriter() )->migrate();

        $stored = get_option( 'sermonator_default_series' );
        $this->assertIsArray( $stored, 'Option must remain an array.' );
        $this->assertSame( 99998, $stored[0], 'Unresolvable term id must be left verbatim.' );

        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $this->assertIsArray( $progress );
        $this->assertContains(
            'missing_option_id_crosswalk:sermonator_default_series',
            $progress['options']['option_id_flags'] ?? array(),
            'A missing_option_id_crosswalk flag must be recorded for the unresolvable array-embedded id.'
        );
    }

    // ----------------------------------- FIX 3: replace-semantics for option_id_flags

    /**
     * FIX 3: option_id_flags must use REPLACE semantics. After a term is migrated and
     * OptionWriter is re-run, the stale missing_option_id_crosswalk flag must be
     * CLEARED (sub-key absent/empty) — not left forever because $idFlags was empty and
     * recordOptionFlags() was skipped. A Verifier gating on "no flags" must read clean.
     */
    public function test_option_id_flags_cleared_on_self_heal_after_term_migrated(): void {
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Self Heal ' . wp_generate_uuid4() );
        $this->fixture->setOption( 'sermonmanager_default_series', (string) $legacyTermId );

        // First migrate() — crosswalk missing, flag must be set.
        ( new OptionWriter() )->migrate();
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $this->assertContains(
            'missing_option_id_crosswalk:sermonator_default_series',
            $progress['options']['option_id_flags'] ?? array(),
            'precondition: flag must be set when crosswalk is missing'
        );

        // Migrate the term (crosswalk now exists).
        ( new TermWriter() )->migrateAll();
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newTermId );

        // Second migrate() — crosswalk resolves, flag must be CLEARED.
        ( new OptionWriter() )->migrate();
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $this->assertNotContains(
            'missing_option_id_crosswalk:sermonator_default_series',
            $progress['options']['option_id_flags'] ?? array(),
            'option_id_flags must be cleared on self-heal after the term is migrated'
        );

        // Option must now hold the NEW term id.
        $stored = get_option( 'sermonator_default_series' );
        $this->assertSame( (string) $newTermId, (string) $stored );
    }
}
