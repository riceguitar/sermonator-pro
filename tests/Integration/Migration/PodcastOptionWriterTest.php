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
}
