<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\TermCrosswalk;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 10: TermCrosswalk reader.
 *
 * A thin read-only companion to TermWriter that resolves migrated terms for the
 * downstream artwork / term-assignment writers. Two queries:
 *
 *   newTermId(int $legacyTermId): ?int
 *     The new term_id a legacy term_id maps to, DETERMINISTIC: it pins the
 *     LOWEST new term_id and flags (error_log) the corrupt >1 case rather than
 *     picking arbitrarily. Unlike Crosswalk::findNewTermByLegacyId it is NOT
 *     taxonomy-scoped — the artwork remapper keys by legacy tt_id, not taxonomy.
 *     Unmigrated → null.
 *
 *   ttIdMap(): array<int,int>
 *     legacy term_taxonomy_id → the migrated term's CURRENT term_taxonomy_id
 *     (read from term_taxonomy, NOT term_id), keyed by the stored
 *     LEGACY_TERM_TT_ID back-ref. One entry per migrated term.
 *
 * The reader stays separate from the pure TermArtworkMapper: this touches WP;
 * the mapper is WordPress-free.
 */
final class TermCrosswalkTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    public function test_new_term_id_resolves_after_migrate_all(): void {
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'John Wesley' );

        ( new TermWriter() )->migrateAll();

        $expected = Crosswalk::findNewTermByLegacyId( $legacyId, Identifiers::TAX_PREACHER );
        $this->assertNotNull( $expected );

        $crosswalk = new TermCrosswalk();
        $this->assertSame( $expected, $crosswalk->newTermId( $legacyId ) );
    }

    public function test_new_term_id_unmigrated_returns_null(): void {
        $crosswalk = new TermCrosswalk();
        $this->assertNull( $crosswalk->newTermId( 987654 ) );
    }

    public function test_new_term_id_is_taxonomy_agnostic(): void {
        // newTermId is NOT taxonomy-scoped (the artwork map keys by tt_id, not
        // taxonomy): a legacy term migrated into ANY taxonomy resolves.
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_BOOK, 'Romans' );

        ( new TermWriter() )->migrateAll();

        $expected = Crosswalk::findNewTermByLegacyId( $legacyId, Identifiers::TAX_BOOK );
        $this->assertNotNull( $expected );

        $crosswalk = new TermCrosswalk();
        // Resolves without the caller knowing the target taxonomy.
        $this->assertSame( $expected, $crosswalk->newTermId( $legacyId ) );
    }

    public function test_new_term_id_pins_lowest_id_on_corrupt_multi_map(): void {
        // A corrupt >1 mapping must be resolved deterministically to the LOWEST
        // new term_id, never arbitrarily — the reader is the single source of a
        // stable target id for downstream writers.
        $a = wp_insert_term( 'Dup A', Identifiers::TAX_TOPIC );
        $b = wp_insert_term( 'Dup B', Identifiers::TAX_TOPIC );
        $aId = (int) $a['term_id'];
        $bId = (int) $b['term_id'];
        $lowest = min( $aId, $bId );

        add_term_meta( $aId, Crosswalk::LEGACY_TERM_ID, 4242, true );
        add_term_meta( $bId, Crosswalk::LEGACY_TERM_ID, 4242, true );

        $crosswalk = new TermCrosswalk();
        $this->assertSame( $lowest, $crosswalk->newTermId( 4242 ) );
    }

    public function test_tt_id_map_uses_current_tt_id_per_migrated_term(): void {
        $preacherLegacy = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Charles Spurgeon' );
        $seriesLegacy   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );

        $preacherLegacyTerm = get_term( $preacherLegacy, LegacyIdentifiers::TAX_PREACHER );
        $seriesLegacyTerm   = get_term( $seriesLegacy, LegacyIdentifiers::TAX_SERIES );
        $preacherLegacyTt   = (int) $preacherLegacyTerm->term_taxonomy_id;
        $seriesLegacyTt     = (int) $seriesLegacyTerm->term_taxonomy_id;

        ( new TermWriter() )->migrateAll();

        $newPreacher = Crosswalk::findNewTermByLegacyId( $preacherLegacy, Identifiers::TAX_PREACHER );
        $newSeries   = Crosswalk::findNewTermByLegacyId( $seriesLegacy, Identifiers::TAX_SERIES );
        $newPreacherTt = (int) get_term( $newPreacher, Identifiers::TAX_PREACHER )->term_taxonomy_id;
        $newSeriesTt   = (int) get_term( $newSeries, Identifiers::TAX_SERIES )->term_taxonomy_id;

        $map = ( new TermCrosswalk() )->ttIdMap();

        // legacy tt_id → the migrated term's CURRENT (new) tt_id.
        $this->assertArrayHasKey( $preacherLegacyTt, $map );
        $this->assertArrayHasKey( $seriesLegacyTt, $map );
        $this->assertSame( $newPreacherTt, $map[ $preacherLegacyTt ] );
        $this->assertSame( $newSeriesTt, $map[ $seriesLegacyTt ] );
        // One entry per migrated term — exactly the two we created.
        $this->assertCount( 2, $map );
    }

    public function test_tt_id_map_empty_when_nothing_migrated(): void {
        $map = ( new TermCrosswalk() )->ttIdMap();
        $this->assertSame( array(), $map );
    }

    public function test_tt_id_map_values_are_new_not_legacy_tt_ids(): void {
        // The map must read the NEW term's CURRENT term_taxonomy_id — never echo
        // back the legacy tt_id key. Assert key != value (new tt_ids are distinct
        // rows from the legacy ones in the same wp_term_taxonomy table).
        $legacy = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );
        $legacyTt = (int) get_term( $legacy, LegacyIdentifiers::TAX_TOPIC )->term_taxonomy_id;

        ( new TermWriter() )->migrateAll();

        $map = ( new TermCrosswalk() )->ttIdMap();
        $this->assertArrayHasKey( $legacyTt, $map );
        $this->assertNotSame( $legacyTt, $map[ $legacyTt ], 'ttIdMap must map to the NEW tt_id, not echo the legacy key.' );

        $newTerm = Crosswalk::findNewTermByLegacyId( $legacy, Identifiers::TAX_TOPIC );
        $this->assertSame(
            (int) get_term( $newTerm, Identifiers::TAX_TOPIC )->term_taxonomy_id,
            $map[ $legacyTt ]
        );
    }
}
