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
 *  - re-run idempotent (no duplicate writes, stable result);
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
