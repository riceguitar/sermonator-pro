<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\ArtworkWriter;
use Sermonator\Migration\TermCrosswalk;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 11: ArtworkWriter::migrate.
 *
 * Translates the legacy Sermon Image Plugin options into the sermonator_*
 * namespace. Reads legacy `sermon_image_plugin` (tt_id => attachment_id) and
 * `sermon_image_plugin_settings`, runs them through the pure TermArtworkMapper
 * against TermCrosswalk::ttIdMap(), and writes `sermonator_term_images` +
 * `sermonator_term_images_settings`.
 *
 * Invariants under test:
 *  - target image option keyed by NEW tt_id, attachment_id verbatim;
 *  - an orphaned legacy tt_id (no migrated term) → recorded in `dropped`,
 *    no crash, never written;
 *  - settings: taxonomy-name keys remapped via taxonomyMap(), global keys
 *    pass through verbatim;
 *  - add_option-FIRST + backup: a pre-existing NATIVE sermonator_term_images
 *    is backed up to OPTION_PRE_MIGRATION_BACKUP, never blind-clobbered;
 *  - new-tt_id collision (two legacy tt_ids → one new tt_id): first-wins, no
 *    silent overwrite; collision returned AND persisted;
 *  - re-run idempotent (no duplicate writes, stable result); a re-run over a
 *    pre-existing native value does NOT re-back-up (never clobbers the preserved
 *    native value with the migrated one);
 *  - empty result never stamps an empty array over native config (no clobber,
 *    no backup when there is nothing to migrate);
 *  - dropped/conflicts persisted, not merely returned;
 *  - legacy options byte-for-byte unchanged.
 */
final class ArtworkWriterTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /** Migrate two terms and return their legacy tt_id => new tt_id pairing. */
    private function migrateTwoTermsTtMap(): array {
        $preacherLegacy = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Charles Spurgeon' );
        $seriesLegacy   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );

        $preacherLegacyTt = (int) get_term( $preacherLegacy, LegacyIdentifiers::TAX_PREACHER )->term_taxonomy_id;
        $seriesLegacyTt   = (int) get_term( $seriesLegacy, LegacyIdentifiers::TAX_SERIES )->term_taxonomy_id;

        ( new TermWriter() )->migrateAll();

        $newPreacher = Crosswalk::findNewTermByLegacyId( $preacherLegacy, Identifiers::TAX_PREACHER );
        $newSeries   = Crosswalk::findNewTermByLegacyId( $seriesLegacy, Identifiers::TAX_SERIES );
        $newPreacherTt = (int) get_term( $newPreacher, Identifiers::TAX_PREACHER )->term_taxonomy_id;
        $newSeriesTt   = (int) get_term( $newSeries, Identifiers::TAX_SERIES )->term_taxonomy_id;

        return array(
            'preacherLegacyTt' => $preacherLegacyTt,
            'seriesLegacyTt'   => $seriesLegacyTt,
            'newPreacherTt'    => $newPreacherTt,
            'newSeriesTt'      => $newSeriesTt,
        );
    }

    public function test_target_keyed_by_new_tt_id_with_verbatim_attachment(): void {
        $tt = $this->migrateTwoTermsTtMap();

        $this->fixture->seedArtwork(
            array(
                $tt['preacherLegacyTt'] => 500,
                $tt['seriesLegacyTt']   => 501,
            )
        );

        $result = ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        $this->assertSame( 2, $result['written'] );
        $this->assertSame( array(), $result['dropped'] );

        $target = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 500, $target[ $tt['newPreacherTt'] ] );
        $this->assertSame( 501, $target[ $tt['newSeriesTt'] ] );
    }

    public function test_orphaned_legacy_tt_id_is_dropped_no_crash(): void {
        $tt = $this->migrateTwoTermsTtMap();

        // 999999 is a legacy tt_id with NO migrated term — must drop, not crash.
        $this->fixture->seedArtwork(
            array(
                $tt['preacherLegacyTt'] => 500,
                999999                  => 777,
            )
        );

        $result = ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        $this->assertContains( 999999, $result['dropped'] );
        $this->assertSame( 1, $result['written'] );

        $target = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 500, $target[ $tt['newPreacherTt'] ] );
        $this->assertNotContains( 777, $target );
    }

    public function test_settings_taxonomy_keys_remapped_globals_preserved(): void {
        $this->migrateTwoTermsTtMap();

        $this->fixture->seedArtwork(
            array(),
            array(
                LegacyIdentifiers::TAX_SERIES => 1,
                'image_size'                  => 'medium',
            )
        );

        ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        $settings = get_option( Identifiers::OPTION_TERM_IMAGES_SETTINGS );
        $this->assertArrayHasKey( Identifiers::TAX_SERIES, $settings );
        $this->assertSame( 1, $settings[ Identifiers::TAX_SERIES ] );
        $this->assertSame( 'medium', $settings['image_size'] );
    }

    public function test_preexisting_native_target_backed_up_not_clobbered(): void {
        $tt = $this->migrateTwoTermsTtMap();

        // A church's own pre-existing sermonator_term_images — must be backed up,
        // never silently clobbered.
        $native = array( 42 => 9000 );
        add_option( Identifiers::OPTION_TERM_IMAGES, $native );

        $this->fixture->seedArtwork( array( $tt['preacherLegacyTt'] => 500 ) );

        ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertIsArray( $backup );
        $this->assertArrayHasKey( Identifiers::OPTION_TERM_IMAGES, $backup );
        $this->assertSame( $native, $backup[ Identifiers::OPTION_TERM_IMAGES ] );

        // The migrated value is now in place.
        $target = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 500, $target[ $tt['newPreacherTt'] ] );
    }

    public function test_rerun_is_idempotent(): void {
        $tt = $this->migrateTwoTermsTtMap();
        $this->fixture->seedArtwork(
            array(
                $tt['preacherLegacyTt'] => 500,
                $tt['seriesLegacyTt']   => 501,
            )
        );

        $writer = new ArtworkWriter();
        $first   = $writer->migrate( new TermCrosswalk() );
        $firstTarget = get_option( Identifiers::OPTION_TERM_IMAGES );
        $firstBackup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );

        $second = $writer->migrate( new TermCrosswalk() );

        $this->assertSame( $first['written'], $second['written'] );
        $this->assertSame( $firstTarget, get_option( Identifiers::OPTION_TERM_IMAGES ) );
        // The second run must NOT back up the value WE wrote in the first run.
        $this->assertSame( $firstBackup, get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP ) );
    }

    public function test_dropped_and_conflicts_persisted(): void {
        $tt = $this->migrateTwoTermsTtMap();
        $this->fixture->seedArtwork(
            array(
                $tt['preacherLegacyTt'] => 500,
                888888                  => 600,
            )
        );

        $result = ( new ArtworkWriter() )->migrate( new TermCrosswalk() );
        $this->assertContains( 888888, $result['dropped'] );

        // Persisted (not just returned) for verification / review.
        $persisted = ArtworkWriter::persistedFlags();
        $this->assertContains( 888888, $persisted['dropped'] );
    }

    /**
     * The CONFLICT branch of must_handle: two distinct legacy tt_ids that map to
     * the SAME new tt_id (e.g. a term-collision dedup collapsed two legacy terms
     * into one new term). The colliding new tt_id must be written exactly once
     * (FIRST-WINS, never silently overwritten by the later attachment), and the
     * collision must be both RETURNED and PERSISTED so the verifier can flag it.
     *
     * The live TermCrosswalk reader is exercised end-to-end: ttIdMap() reads the
     * LEGACY_TERM_TT_ID back-ref termmeta joined to each new term's current
     * tt_id. We reproduce a real dedup-collapse by stamping a SECOND
     * LEGACY_TERM_TT_ID back-ref (a second legacy tt_id) onto the already-migrated
     * preacher term — exactly the corrupt/collapsed state the reader can surface.
     * Only sermonator-owned termmeta on the NEW term is touched; legacy data is
     * never mutated, and the legacy artwork option is read READ-ONLY.
     */
    public function test_colliding_new_tt_id_first_wins_and_conflict_recorded(): void {
        $tt = $this->migrateTwoTermsTtMap();

        $newTtId       = $tt['newPreacherTt'];
        $legacyTtFirst = $tt['preacherLegacyTt'];

        // A distinct legacy tt_id that does NOT correspond to any real legacy
        // term — it must collapse onto the SAME new tt_id as legacyTtFirst.
        $legacyTtSecond = $legacyTtFirst + 100000;

        // Resolve the new preacher term_id from the new tt_id we already hold,
        // then stamp a second legacy-tt_id back-ref onto it so ttIdMap() yields
        // two legacy tt_ids → one new tt_id.
        global $wpdb;
        $newTermId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $newTtId
            )
        );
        add_term_meta(
            $newTermId,
            \Sermonator\Migration\Crosswalk::LEGACY_TERM_TT_ID,
            $legacyTtSecond,
            false
        );

        // First-seen legacy tt_id carries 500, the colliding one carries 600.
        // remapImages iterates in legacy-array order, so 500 must win.
        $this->fixture->seedArtwork(
            array(
                $legacyTtFirst  => 500,
                $legacyTtSecond => 600,
            )
        );

        $result = ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        // Exactly one image written; first-wins, no silent overwrite.
        $this->assertSame( 1, $result['written'] );
        $this->assertContains( $newTtId, $result['conflicts'] );

        $target = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 500, $target[ $newTtId ], 'Collision must keep the first attachment, not the overwriting one.' );
        $this->assertNotContains( 600, $target );

        // Persisted (not just returned) for verification / review.
        $persisted = ArtworkWriter::persistedFlags();
        $this->assertContains( $newTtId, $persisted['conflicts'] );
    }

    /**
     * The load-bearing idempotency-of-backup case: a church has a PRE-EXISTING
     * native sermonator_term_images, and migrate() runs TWICE. The first run must
     * back up the native value; the second run must NOT re-back-up (which would
     * clobber the preserved native value with the migrated value WE wrote on the
     * first run, permanently destroying the church's original config). The backup
     * must equal the ORIGINAL native value after BOTH runs.
     */
    public function test_rerun_with_preexisting_native_does_not_clobber_backup(): void {
        $tt = $this->migrateTwoTermsTtMap();

        // (1) Church's own pre-existing native value.
        $native = array( 42 => 9000 );
        add_option( Identifiers::OPTION_TERM_IMAGES, $native );

        // (2) Legacy artwork to migrate over it.
        $this->fixture->seedArtwork( array( $tt['preacherLegacyTt'] => 500 ) );

        // (3) Run twice.
        $writer = new ArtworkWriter();
        $writer->migrate( new TermCrosswalk() );

        $backupAfterFirst = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertIsArray( $backupAfterFirst );
        $this->assertSame( $native, $backupAfterFirst[ Identifiers::OPTION_TERM_IMAGES ] );

        $writer->migrate( new TermCrosswalk() );

        $backupAfterSecond = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertSame(
            $native,
            $backupAfterSecond[ Identifiers::OPTION_TERM_IMAGES ],
            'Second run re-backed-up, clobbering the preserved native value with the migrated one.'
        );

        // The migrated value remains in place; the native value is recoverable.
        $target = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 500, $target[ $tt['newPreacherTt'] ] );
    }

    /**
     * Asymmetric-clobber guard (mirrors the settings path): when the legacy
     * plugin has NO artwork at all, a church's pre-existing native
     * sermonator_term_images must be left byte-for-byte untouched and NO backup
     * taken — there is nothing to migrate, so we must not destroy working native
     * config.
     */
    public function test_native_target_untouched_when_no_legacy_artwork(): void {
        $this->migrateTwoTermsTtMap();

        $native = array( 42 => 9000 );
        add_option( Identifiers::OPTION_TERM_IMAGES, $native );

        // No legacy artwork seeded at all.
        $result = ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        $this->assertSame( 0, $result['written'] );

        // Native value untouched, byte-for-byte.
        $this->assertSame( $native, get_option( Identifiers::OPTION_TERM_IMAGES ) );

        // No backup taken — we never touched the key.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        if ( is_array( $backup ) ) {
            $this->assertArrayNotHasKey( Identifiers::OPTION_TERM_IMAGES, $backup );
        } else {
            $this->assertFalse( $backup );
        }
    }

    /**
     * Crash-safety (IMPORTANT #6): the writer's add_option/update_option is
     * irreversible; the written-key marker is a SEPARATE option write. If the
     * process aborts AFTER the migrated value is stamped but BEFORE markKeyWritten,
     * sermonator_term_images holds OUR OWN migrated value with no marker. A naive
     * resume sees the option exists + keyAlreadyWritten false and backs up the
     * migrated value into OPTION_PRE_MIGRATION_BACKUP as if it were native — so a
     * later rollback would restore the MIGRATED value, not the true native (which,
     * in the add_option path, never existed; the key must simply not be backed up).
     *
     * We inject that exact crash window — migrated value present, marker absent —
     * and assert the resume does NOT record the migrated value as a native backup.
     */
    public function test_resume_after_crash_does_not_backup_migrated_value_as_native(): void {
        $tt = $this->migrateTwoTermsTtMap();
        $this->fixture->seedArtwork( array( $tt['preacherLegacyTt'] => 500 ) );

        // Compute what the writer will produce, then inject the crash state: the
        // migrated value already stamped into the target, but NO written-key marker
        // (the run died between add_option and markKeyWritten).
        $migrated = array( $tt['newPreacherTt'] => 500 );
        add_option( Identifiers::OPTION_TERM_IMAGES, $migrated );

        // Wipe only the artwork written-key marker, faithfully reproducing the crash
        // window (the term-migration progress markers under other sub-keys remain).
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( is_array( $progress ) && isset( $progress['artwork']['written_keys'] ) ) {
            unset( $progress['artwork']['written_keys'] );
            update_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress );
        }

        // Resume.
        ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        // The migrated value remains live.
        $this->assertSame( $migrated, get_option( Identifiers::OPTION_TERM_IMAGES ) );

        // The backup must NOT claim our migrated value as a native pre-existing one.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        if ( is_array( $backup ) && array_key_exists( Identifiers::OPTION_TERM_IMAGES, $backup ) ) {
            $this->assertNotSame(
                $migrated,
                $backup[ Identifiers::OPTION_TERM_IMAGES ],
                'Resume backed up OUR migrated value as if it were native; rollback would restore the migrated value.'
            );
        } else {
            $this->assertTrue( true ); // no spurious native backup recorded — correct.
        }
    }

    public function test_legacy_options_byte_equal_before_and_after(): void {
        $tt = $this->migrateTwoTermsTtMap();

        $legacyImages   = array( $tt['preacherLegacyTt'] => 500, $tt['seriesLegacyTt'] => 501 );
        $legacySettings = array( LegacyIdentifiers::TAX_SERIES => 1, 'image_size' => 'large' );
        $this->fixture->seedArtwork( $legacyImages, $legacySettings );

        $imagesBefore   = get_option( LegacyIdentifiers::OPTION_TERM_IMAGES );
        $settingsBefore = get_option( LegacyIdentifiers::OPTION_TERM_IMAGES_SETTINGS );

        ( new ArtworkWriter() )->migrate( new TermCrosswalk() );

        $this->assertSame( $imagesBefore, get_option( LegacyIdentifiers::OPTION_TERM_IMAGES ), 'Legacy images option mutated.' );
        $this->assertSame( $settingsBefore, get_option( LegacyIdentifiers::OPTION_TERM_IMAGES_SETTINGS ), 'Legacy settings option mutated.' );
    }
}
