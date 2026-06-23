<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Migration\MappingContract;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 9: TermWriter::migrateAll.
 *
 * Iterates ALL five legacy taxonomies in canonical
 * LegacyIdentifiers::sermonTaxonomies() order, migrating every term — including
 * orphans (hide_empty => false) — into its mapped target taxonomy. A hard
 * uniqueness guard re-probes the back-ref after each insert and raises a
 * reconciliation error if any legacy term_id maps to >1 new term. The run is
 * resumable: a second migrateAll skips every already-crosswalked term, creating
 * zero duplicate terms/back-refs. Legacy terms are byte-for-byte unchanged.
 */
final class TermWriterMigrateAllTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /** Snapshot every column WordPress stores for a term + its taxonomy row. */
    private function snapshotTerm( int $termId, string $taxonomy ): array {
        $term = get_term( $termId, $taxonomy );
        return array(
            'term_id'          => (int) $term->term_id,
            'name'             => $term->name,
            'slug'             => $term->slug,
            'term_group'       => (int) $term->term_group,
            'term_taxonomy_id' => (int) $term->term_taxonomy_id,
            'taxonomy'         => $term->taxonomy,
            'description'      => $term->description,
            'parent'           => (int) $term->parent,
            'count'            => (int) $term->count,
        );
    }

    private function legacyTermCount( string $taxonomy ): int {
        return (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
    }

    public function test_orphan_term_is_migrated(): void {
        // An orphan term — attached to no posts — must still migrate.
        $orphanId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Orphan Preacher' );
        $orphan   = get_term( $orphanId, LegacyIdentifiers::TAX_PREACHER );
        $this->assertSame( 0, (int) $orphan->count );

        $result = ( new TermWriter() )->migrateAll();

        $this->assertGreaterThanOrEqual( 1, $result['migrated'] );
        $newId = Crosswalk::findNewTermByLegacyId( $orphanId, Identifiers::TAX_PREACHER );
        $this->assertNotNull( $newId );
        $this->assertSame( 'Orphan Preacher', get_term( $newId, Identifiers::TAX_PREACHER )->name );
    }

    public function test_per_taxonomy_target_counts_equal_legacy_counts(): void {
        $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'John Wesley' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Charles Spurgeon' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Faith' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_BOOK, 'Romans' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_SERVICE_TYPE, 'Sunday AM' );

        ( new TermWriter() )->migrateAll();

        foreach ( MappingContract::taxonomyMap() as $legacyTax => $targetTax ) {
            $this->assertSame(
                $this->legacyTermCount( $legacyTax ),
                $this->legacyTermCount( $targetTax ),
                sprintf( 'Target %s count must equal legacy %s count', $targetTax, $legacyTax )
            );
        }
    }

    public function test_full_rerun_reports_all_skipped_zero_duplicates(): void {
        $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'John Wesley' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );

        $writer = new TermWriter();
        $first  = $writer->migrateAll();
        $this->assertSame( 0, $first['skipped'] );
        $this->assertGreaterThanOrEqual( 3, $first['migrated'] );

        $countsAfterFirst = array();
        foreach ( MappingContract::taxonomyMap() as $targetTax ) {
            $countsAfterFirst[ $targetTax ] = $this->legacyTermCount( $targetTax );
        }

        $second = $writer->migrateAll();
        $this->assertSame( 0, $second['migrated'], 'A full re-run migrates nothing new.' );
        $this->assertSame( $first['migrated'], $second['skipped'], 'Every term is skipped on re-run.' );

        // Zero duplicate target terms.
        foreach ( MappingContract::taxonomyMap() as $targetTax ) {
            $this->assertSame(
                $countsAfterFirst[ $targetTax ],
                $this->legacyTermCount( $targetTax ),
                'Re-run created a duplicate target term in ' . $targetTax
            );
        }

        // Zero duplicate back-ref rows.
        $newId  = Crosswalk::findNewTermByLegacyId(
            (int) get_term_by( 'name', 'John Wesley', LegacyIdentifiers::TAX_PREACHER )->term_id,
            Identifiers::TAX_PREACHER
        );
        $this->assertCount( 1, get_term_meta( $newId, Crosswalk::LEGACY_TERM_ID, false ) );
        $this->assertCount( 1, get_term_meta( $newId, Crosswalk::LEGACY_TERM_TT_ID, false ) );
    }

    public function test_legacy_terms_byte_equal_before_and_after(): void {
        $ids = array(
            LegacyIdentifiers::TAX_PREACHER     => $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'John Wesley' ),
            LegacyIdentifiers::TAX_SERIES       => $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' ),
            LegacyIdentifiers::TAX_TOPIC        => $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' ),
            LegacyIdentifiers::TAX_BOOK         => $this->fixture->createTerm( LegacyIdentifiers::TAX_BOOK, 'Romans' ),
            LegacyIdentifiers::TAX_SERVICE_TYPE => $this->fixture->createTerm( LegacyIdentifiers::TAX_SERVICE_TYPE, 'Sunday AM' ),
        );

        $before     = array();
        $metaBefore = array();
        foreach ( $ids as $tax => $id ) {
            $before[ $tax ]     = $this->snapshotTerm( $id, $tax );
            $metaBefore[ $tax ] = get_term_meta( $id );
        }

        ( new TermWriter() )->migrateAll();

        foreach ( $ids as $tax => $id ) {
            $this->assertSame( $before[ $tax ], $this->snapshotTerm( $id, $tax ), 'Legacy term mutated in ' . $tax );
            $this->assertSame( $metaBefore[ $tax ], get_term_meta( $id ), 'Legacy term meta mutated in ' . $tax );
        }
    }

    public function test_migrateall_adopts_crash_orphan_no_duplicate_no_reconciliation_error(): void {
        // A crash orphan (legacy slug, NO back-ref) sits in the target taxonomy
        // when migrateAll resumes. It must be ADOPTED — not duplicated, and not
        // tripping the uniqueness guard, which now counts back-ref-less same-slug
        // terms when asserting a single mapping.
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Sanctification' );
        $legacy   = get_term( $legacyId, LegacyIdentifiers::TAX_TOPIC );

        $orphanId = $this->fixture->injectCrashOrphanTerm(
            Identifiers::TAX_TOPIC,
            'Sanctification',
            $legacy->slug
        );

        $countBefore = $this->legacyTermCount( Identifiers::TAX_TOPIC );

        $result = ( new TermWriter() )->migrateAll();

        // Adopted: returned mapping is the orphan, no duplicate target term.
        $this->assertSame( $orphanId, Crosswalk::findNewTermByLegacyId( $legacyId, Identifiers::TAX_TOPIC ) );
        $this->assertSame( $countBefore, $this->legacyTermCount( Identifiers::TAX_TOPIC ), 'migrateAll duplicated a crash orphan.' );
        $this->assertCount( 1, get_term_meta( $orphanId, Crosswalk::LEGACY_TERM_ID, false ) );
        $this->assertNotContains( 'slug_collision', $result['flags'] );
    }

    public function test_migrateall_uniqueness_guard_counts_backref_less_same_slug_duplicate(): void {
        // The corrupt state: the legacy term is ALREADY crosswalked (a back-ref'd
        // target term exists) AND a second back-ref-less same-slug term lingers
        // from a crash. The guard must count BOTH and raise rather than silently
        // leave a divergent mapping for downstream writers.
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Atonement' );
        $legacy   = get_term( $legacyId, LegacyIdentifiers::TAX_TOPIC );

        // Already-migrated, properly back-ref'd target term.
        $migrated = wp_insert_term( 'Atonement', Identifiers::TAX_TOPIC, array( 'slug' => $legacy->slug . '-migrated' ) );
        add_term_meta( (int) $migrated['term_id'], Crosswalk::LEGACY_TERM_ID, $legacyId, true );
        add_term_meta( (int) $migrated['term_id'], Crosswalk::LEGACY_TERM_TT_ID, (int) $legacy->term_taxonomy_id, true );

        // A back-ref-less crash orphan carrying the ORIGINAL legacy slug.
        $this->fixture->injectCrashOrphanTerm( Identifiers::TAX_TOPIC, 'Atonement', $legacy->slug );

        $this->expectException( \RuntimeException::class );
        ( new TermWriter() )->migrateAll();
    }

    public function test_injected_duplicate_backref_raises_uniqueness_error(): void {
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );

        // Pre-create TWO target terms both stamped with the SAME legacy term_id —
        // the corrupt >1 state the hard uniqueness guard must detect and raise.
        $a = wp_insert_term( 'Grace A', Identifiers::TAX_TOPIC );
        $b = wp_insert_term( 'Grace B', Identifiers::TAX_TOPIC );
        add_term_meta( (int) $a['term_id'], Crosswalk::LEGACY_TERM_ID, $legacyId, true );
        add_term_meta( (int) $b['term_id'], Crosswalk::LEGACY_TERM_ID, $legacyId, true );

        $this->expectException( \RuntimeException::class );
        ( new TermWriter() )->migrateAll();
    }
}
