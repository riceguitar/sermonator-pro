<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use WP_Term;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Migration\MappingContract;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 8: TermWriter::migrateTerm.
 *
 * Inserts a legacy term into the mapped target taxonomy copying name/slug/
 * description, stamps BOTH back-refs + LEGACY_SLUG, idempotent on re-run. On a
 * collision with a church's NATIVE term (same name+slug already present), it
 * NEVER adopts that native term — it creates a NEW distinct term with a
 * DETERMINISTIC suffix slug ($slug.'-legacy-'.$legacyTerm->term_id), flags
 * slug_collision, and leaves the native term byte-for-byte untouched (no
 * back-ref stamped on it). Re-running after a collision recomputes the same
 * deterministic slug — zero duplicates.
 */
final class TermWriterMigrateTermTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        // Register the target sermonator_* taxonomies so wp_insert_term can land.
        ( new \Sermonator\Model\Registrar() )->register();
    }

    private function legacyTerm( string $taxonomy, int $termId ): WP_Term {
        $term = get_term( $termId, $taxonomy );
        $this->assertInstanceOf( WP_Term::class, $term );
        return $term;
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

    public function test_creates_target_term_copying_name_slug_description(): void {
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'John Wesley' );
        // Give the legacy term a description to prove it is copied.
        wp_update_term( $legacyId, LegacyIdentifiers::TAX_PREACHER, array( 'description' => 'A Methodist.' ) );
        $legacy = $this->legacyTerm( LegacyIdentifiers::TAX_PREACHER, $legacyId );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_PREACHER, $legacy );

        $this->assertGreaterThan( 0, $newId );
        $new = get_term( $newId, Identifiers::TAX_PREACHER );
        $this->assertInstanceOf( WP_Term::class, $new );
        $this->assertSame( 'John Wesley', $new->name );
        $this->assertSame( $legacy->slug, $new->slug );
        $this->assertSame( 'A Methodist.', $new->description );
        $this->assertSame( Identifiers::TAX_PREACHER, $new->taxonomy );
    }

    public function test_writes_both_back_refs_and_legacy_slug(): void {
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_SERIES, $legacyId );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_SERIES, $legacy );

        $idRows   = get_term_meta( $newId, Crosswalk::LEGACY_TERM_ID, false );
        $ttRows   = get_term_meta( $newId, Crosswalk::LEGACY_TERM_TT_ID, false );
        $slugRows = get_term_meta( $newId, Crosswalk::LEGACY_SLUG, false );

        $this->assertCount( 1, $idRows );
        $this->assertCount( 1, $ttRows );
        $this->assertCount( 1, $slugRows );
        $this->assertSame( (string) $legacy->term_id, (string) $idRows[0] );
        $this->assertSame( (string) $legacy->term_taxonomy_id, (string) $ttRows[0] );
        $this->assertSame( $legacy->slug, $slugRows[0] );

        // Round-trips taxonomy-aware via the Crosswalk reader.
        $this->assertSame(
            $newId,
            Crosswalk::findNewTermByLegacyId( (int) $legacy->term_id, Identifiers::TAX_SERIES )
        );
    }

    public function test_idempotent_second_call_same_id_no_new_term(): void {
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_TOPIC, $legacyId );

        $writer = new TermWriter();
        $first  = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        $countBefore = (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );

        $second = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        $countAfter = (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );

        $this->assertSame( $first, $second );
        $this->assertSame( $countBefore, $countAfter );

        // Back-ref rows still single — no duplicate accumulation.
        $this->assertCount( 1, get_term_meta( $second, Crosswalk::LEGACY_TERM_ID, false ) );
        $this->assertCount( 1, get_term_meta( $second, Crosswalk::LEGACY_SLUG, false ) );
    }

    public function test_collision_with_native_term_creates_new_deterministic_term_and_never_adopts(): void {
        // A church's NATIVE term with the same name+slug already in the target taxonomy.
        $native = wp_insert_term( 'Faith', Identifiers::TAX_TOPIC );
        $this->assertIsArray( $native );
        $nativeTermId = (int) $native['term_id'];
        $nativeSnapshot = $this->snapshotTerm( $nativeTermId, Identifiers::TAX_TOPIC );
        $nativeMetaBefore = get_term_meta( $nativeTermId );

        // Legacy term with the same name/slug.
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Faith' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_TOPIC, $legacyId );
        $this->assertSame( 'faith', $legacy->slug );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        // A NEW distinct term — not the native one.
        $this->assertNotSame( $nativeTermId, $newId );
        $new = get_term( $newId, Identifiers::TAX_TOPIC );
        $this->assertInstanceOf( WP_Term::class, $new );
        $this->assertSame( 'Faith', $new->name );
        // Deterministic suffix slug.
        $this->assertSame( 'faith-legacy-' . $legacy->term_id, $new->slug );

        // slug_collision flag recorded on the new term.
        $flags = get_term_meta( $newId, Crosswalk::MIGRATION_FLAGS, false );
        $this->assertContains( 'slug_collision', $this->flattenFlags( $flags ) );

        // LEGACY_SLUG records the ORIGINAL legacy slug (not the suffixed one).
        $this->assertSame( 'faith', get_term_meta( $newId, Crosswalk::LEGACY_SLUG, true ) );

        // The NATIVE term is byte-for-byte untouched, and carries NO back-ref.
        $this->assertSame( $nativeSnapshot, $this->snapshotTerm( $nativeTermId, Identifiers::TAX_TOPIC ) );
        $this->assertSame( $nativeMetaBefore, get_term_meta( $nativeTermId ) );
        $this->assertSame( '', (string) get_term_meta( $nativeTermId, Crosswalk::LEGACY_TERM_ID, true ) );
        $this->assertSame( '', (string) get_term_meta( $nativeTermId, Crosswalk::LEGACY_SLUG, true ) );
    }

    public function test_collision_with_native_term_same_slug_different_name_is_deterministic_and_flagged(): void {
        // A church's NATIVE term whose SLUG matches the legacy term's slug but
        // whose NAME differs. WordPress does NOT raise term_exists for this in a
        // non-hierarchical taxonomy, so a naive insert would silently produce a
        // 'faith-2' slug with no flag. We must still create a NEW term with the
        // DETERMINISTIC '-legacy-{id}' suffix and record slug_collision.
        $native = wp_insert_term( 'Belief', Identifiers::TAX_TOPIC, array( 'slug' => 'faith' ) );
        $this->assertIsArray( $native );
        $nativeTermId   = (int) $native['term_id'];
        $this->assertSame( 'faith', get_term( $nativeTermId, Identifiers::TAX_TOPIC )->slug );
        $nativeSnapshot = $this->snapshotTerm( $nativeTermId, Identifiers::TAX_TOPIC );
        $nativeMetaBefore = get_term_meta( $nativeTermId );

        // Legacy term: DIFFERENT name ('Faith'), SAME slug ('faith').
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Faith' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_TOPIC, $legacyId );
        $this->assertSame( 'faith', $legacy->slug );
        $this->assertNotSame( 'Belief', $legacy->name );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        // A NEW distinct term — not the native one.
        $this->assertNotSame( $nativeTermId, $newId );
        $new = get_term( $newId, Identifiers::TAX_TOPIC );
        $this->assertInstanceOf( WP_Term::class, $new );
        $this->assertSame( 'Faith', $new->name );

        // EXACTLY the deterministic suffix slug — never an order-dependent '-2'.
        $this->assertSame( 'faith-legacy-' . $legacy->term_id, $new->slug );

        // slug_collision flag recorded on the new term.
        $flags = get_term_meta( $newId, Crosswalk::MIGRATION_FLAGS, false );
        $this->assertContains( 'slug_collision', $this->flattenFlags( $flags ) );

        // LEGACY_SLUG records the ORIGINAL legacy slug (not the suffixed one).
        $this->assertSame( 'faith', get_term_meta( $newId, Crosswalk::LEGACY_SLUG, true ) );

        // The NATIVE term is byte-for-byte untouched, and carries NO back-ref.
        $this->assertSame( $nativeSnapshot, $this->snapshotTerm( $nativeTermId, Identifiers::TAX_TOPIC ) );
        $this->assertSame( $nativeMetaBefore, get_term_meta( $nativeTermId ) );
        $this->assertSame( '', (string) get_term_meta( $nativeTermId, Crosswalk::LEGACY_TERM_ID, true ) );
        $this->assertSame( '', (string) get_term_meta( $nativeTermId, Crosswalk::LEGACY_SLUG, true ) );

        // Idempotent: re-run yields the same id, same slug, no duplicate term.
        $countBefore = (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );
        $second      = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );
        $this->assertSame( $newId, $second );
        $this->assertSame( $countBefore, (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) ) );
        $this->assertCount( 1, get_term_meta( $second, Crosswalk::LEGACY_TERM_ID, false ) );
        $this->assertCount( 1, get_term_meta( $second, Crosswalk::MIGRATION_FLAGS, false ) );
    }

    public function test_rerun_after_collision_recomputes_same_deterministic_slug_no_duplicate(): void {
        wp_insert_term( 'Hope', Identifiers::TAX_TOPIC ); // native collision target.
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Hope' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_TOPIC, $legacyId );

        $writer = new TermWriter();
        $first  = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        $countBefore = (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );

        $second = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        $countAfter = (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );

        $this->assertSame( $first, $second );
        $this->assertSame( $countBefore, $countAfter );
        $this->assertSame( 'hope-legacy-' . $legacy->term_id, get_term( $second, Identifiers::TAX_TOPIC )->slug );
        $this->assertCount( 1, get_term_meta( $second, Crosswalk::LEGACY_TERM_ID, false ) );
    }

    public function test_backslash_name_and_description_are_preserved_not_stripped(): void {
        // Real legacy data can carry literal backslashes and escaped quotes in
        // the name/description. wp_insert_term() wp_unslash()es its inputs, so
        // the writer MUST wp_slash() name+description before passing them in,
        // otherwise a backslash level is silently stripped and the migrated
        // term's name/description diverge byte-for-byte from the legacy source.
        $rawName        = 'C:\\Users\\Wesley "the\\ Reverend"';
        $rawDescription = 'Path C:\\sermons\\2020 — quote \\"grace\\" preserved \\ trailing';

        $legacyId = $this->fixture->createTermRaw( LegacyIdentifiers::TAX_PREACHER, $rawName, $rawDescription );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_PREACHER, $legacyId );
        // Sanity: the legacy row stores the exact bytes (fixture wp_slash'd).
        $this->assertSame( $rawName, $legacy->name );
        $this->assertSame( $rawDescription, $legacy->description );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_PREACHER, $legacy );

        $new = get_term( $newId, Identifiers::TAX_PREACHER );
        $this->assertInstanceOf( WP_Term::class, $new );
        $this->assertSame( $rawName, $new->name, 'Migrated term name lost a backslash level.' );
        $this->assertSame( $rawDescription, $new->description, 'Migrated term description lost a backslash level.' );
    }

    public function test_backslash_values_preserved_through_deterministic_collision_suffix_path(): void {
        // Backslash-bearing values must also survive the slug-collision insert
        // (the deterministic '-legacy-{id}' suffix path), which is the branch
        // that actually performs the wp_insert_term for a native collision. Its
        // name/description must be wp_slash'd identically to the primary path.
        $rawName        = 'Grace\\Mercy "and\\ Truth"';
        $rawDescription = 'Desc with \\ backslash and \\"quotes\\"';

        // Native term occupying the legacy slug so the pre-insert probe takes the
        // deterministic-suffix collision branch.
        $native = wp_insert_term( 'Native Holder', Identifiers::TAX_TOPIC, array( 'slug' => 'grace-mercy-and-truth' ) );
        $this->assertIsArray( $native );

        $legacyId = $this->fixture->createTermRaw( LegacyIdentifiers::TAX_TOPIC, $rawName, $rawDescription, 'grace-mercy-and-truth' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_TOPIC, $legacyId );
        $this->assertSame( $rawName, $legacy->name );
        $this->assertSame( 'grace-mercy-and-truth', $legacy->slug );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        $new = get_term( $newId, Identifiers::TAX_TOPIC );
        $this->assertNotSame( (int) $native['term_id'], $newId );
        // Deterministic suffix slug — confirms we took the collision insert path.
        $this->assertSame( 'grace-mercy-and-truth-legacy-' . $legacy->term_id, $new->slug );
        $this->assertSame( $rawName, $new->name, 'Collision-path insert lost a backslash level in name.' );
        $this->assertSame( $rawDescription, $new->description, 'Collision-path insert lost a backslash level in description.' );
        $this->assertContains( 'slug_collision', $this->flattenFlags( get_term_meta( $newId, Crosswalk::MIGRATION_FLAGS, false ) ) );
    }

    public function test_crash_orphan_with_legacy_slug_no_backref_is_adopted_not_duplicated(): void {
        // Crash window: a NEW term was inserted with the legacy slug but the run
        // died before Crosswalk::markLegacyTerm — so it carries NO back-ref. On
        // resume the writer must ADOPT this orphan (stamp the back-ref +
        // LEGACY_SLUG) instead of mistaking it for a native collision and
        // creating a duplicate '-legacy-{id}' term.
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Redemption' );
        $legacy   = $this->legacyTerm( LegacyIdentifiers::TAX_TOPIC, $legacyId );
        $this->assertSame( 'redemption', $legacy->slug );

        $orphanId = $this->fixture->injectCrashOrphanTerm(
            Identifiers::TAX_TOPIC,
            'Redemption',
            'redemption'
        );
        // Precondition: the orphan exists, holds the legacy slug, has NO back-ref.
        $this->assertSame( 'redemption', get_term( $orphanId, Identifiers::TAX_TOPIC )->slug );
        $this->assertSame( '', (string) get_term_meta( $orphanId, Crosswalk::LEGACY_TERM_ID, true ) );

        $countBefore = (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );

        $writer = new TermWriter();
        $newId  = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );

        // ADOPTED: the returned id IS the orphan — no new term created.
        $this->assertSame( $orphanId, $newId, 'Crash orphan was not adopted — a duplicate term was created.' );
        $this->assertSame(
            $countBefore,
            (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) ),
            'Adoption must not create an extra term.'
        );

        // The orphan now carries the back-ref, tt_id, and ORIGINAL legacy slug.
        $this->assertSame( (string) $legacyId, (string) get_term_meta( $orphanId, Crosswalk::LEGACY_TERM_ID, true ) );
        $this->assertSame( (string) $legacy->term_taxonomy_id, (string) get_term_meta( $orphanId, Crosswalk::LEGACY_TERM_TT_ID, true ) );
        $this->assertSame( 'redemption', get_term_meta( $orphanId, Crosswalk::LEGACY_SLUG, true ) );

        // It is NOT flagged as a slug collision — it is OURS, not a native term.
        $this->assertNotContains( 'slug_collision', $this->flattenFlags( get_term_meta( $orphanId, Crosswalk::MIGRATION_FLAGS, false ) ) );
        // Slug is the ORIGINAL legacy slug, NOT a '-legacy-{id}' suffix.
        $this->assertSame( 'redemption', get_term( $orphanId, Identifiers::TAX_TOPIC )->slug );

        // Resolves authoritatively and is idempotent on re-run.
        $this->assertSame( $orphanId, Crosswalk::findNewTermByLegacyId( $legacyId, Identifiers::TAX_TOPIC ) );
        $second = $writer->migrateTerm( LegacyIdentifiers::TAX_TOPIC, $legacy );
        $this->assertSame( $orphanId, $second );
        $this->assertSame(
            $countBefore,
            (int) wp_count_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) )
        );
        $this->assertCount( 1, get_term_meta( $orphanId, Crosswalk::LEGACY_TERM_ID, false ) );
        $this->assertCount( 1, get_term_meta( $orphanId, Crosswalk::LEGACY_SLUG, false ) );
    }

    public function test_legacy_term_is_read_only_unchanged(): void {
        $legacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_BOOK, 'Romans' );
        wp_update_term( $legacyId, LegacyIdentifiers::TAX_BOOK, array( 'description' => 'Pauline.' ) );
        $legacy = $this->legacyTerm( LegacyIdentifiers::TAX_BOOK, $legacyId );

        $before     = $this->snapshotTerm( $legacyId, LegacyIdentifiers::TAX_BOOK );
        $metaBefore = get_term_meta( $legacyId );

        ( new TermWriter() )->migrateTerm( LegacyIdentifiers::TAX_BOOK, $legacy );

        $this->assertSame( $before, $this->snapshotTerm( $legacyId, LegacyIdentifiers::TAX_BOOK ) );
        $this->assertSame( $metaBefore, get_term_meta( $legacyId ) );
    }

    /** @param list<mixed> $flagRows @return list<string> */
    private function flattenFlags( array $flagRows ): array {
        $out = array();
        foreach ( $flagRows as $row ) {
            foreach ( (array) $row as $flag ) {
                $out[] = (string) $flag;
            }
        }
        return $out;
    }
}
