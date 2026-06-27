<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\Feed\PodcastScopeResolver;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers as LID;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\PodcastWriter;
use Sermonator\Migration\PrevalenceCounter;
use Sermonator\Migration\TermWriter;
use Sermonator\Schema\Identifiers as ID;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Integration coverage for MUST-FIX 1 + FIX 2/3 — the REAL SM Pro per-podcast feed scope
 * lives in OBJECT-TERMS on the legacy wpfc_sm_podcast, NOT the sm_podcast_settings blob.
 *
 * Requires wp-env / the WordPress test harness (WP_UnitTestCase). Do NOT run under the Brain
 * Monkey unit suite. (At authoring time wp-env was unavailable — these are written, not run.)
 *
 * Pins the irreversible, subscriber-facing behaviors:
 *
 *  - a legacy podcast's OBJECT-TERM scope MIGRATES into the new META_PODCAST_SETTINGS scope
 *    keys (crosswalked to NEW term ids), so the migrated feed serves ONLY the scoped sermons;
 *  - a single scoped podcast NO LONGER over-includes the full site-wide set;
 *  - an UNRESOLVABLE object-term scope term records the missing_podcast_term_crosswalk flag,
 *    WITHHOLDS MIGRATION_COMPLETE, and makes the feed fall back to UNSCOPED (never a dead-term
 *    empty feed);
 *  - the prevalence counter (FIX 2/3) counts scope from the legacy object-term source at the
 *    REAL Orchestrator::detect() call site.
 */
final class PodcastObjectTermScopeTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        delete_option( ID::OPTION_MIGRATION_PREVALENCE );
        unset( $_GET['podcast'] );
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        remove_all_actions( 'sermonator_feed_scope_incomplete' );
        remove_all_actions( 'sermonator_feed_unscoped_multipodcast' );
        parent::tearDown();
    }

    /** Migrate a legacy series term and return [legacyTermId, newTermId]. */
    private function migratedSeriesTerm( string $name ): array {
        $legacyTermId = $this->fixture->createTerm( LID::TAX_SERIES, $name );
        $legacyTerm   = get_term( $legacyTermId, LID::TAX_SERIES );
        $newTermId    = ( new TermWriter() )->migrateTerm( LID::TAX_SERIES, $legacyTerm );

        return array( $legacyTermId, (int) $newTermId );
    }

    /** Create a published sermon with a resolvable enclosure; optionally assign a new series term. */
    private function sermon( string $title, ?int $newSeriesTermId = null ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_DATE, '1700000000' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/' . $id . '.mp3' );
        update_post_meta( $id, ID::META_AUDIO_SIZE, '1000' );
        if ( null !== $newSeriesTermId ) {
            wp_set_object_terms( $id, array( $newSeriesTermId ), ID::TAX_SERIES );
        }
        return $id;
    }

    private function capture(): string {
        ob_start();
        ( new PodcastFeed() )->render();
        return (string) ob_get_clean();
    }

    // =========================================================================
    // MUST-FIX 1: object-term scope → migrated scope keys.
    // =========================================================================

    public function test_object_term_scope_migrates_to_populated_scope_keys(): void {
        [ $legacyTermId, $newTermId ] = $this->migratedSeriesTerm( 'Romans' );

        // A legacy podcast whose feed scope lives ONLY in object-terms (empty blob — the
        // real SM Pro shape).
        $legacyPodcast = $this->fixture->createPodcastWithSettings( array(), 'Romans Feed' );
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        $result = ( new PodcastWriter() )->write( $legacyPodcast );
        $this->assertGreaterThan( 0, $result->newId );

        // The migrated settings carry the crosswalked NEW series term under the NEW slug.
        $settings = get_post_meta( $result->newId, ID::META_PODCAST_SETTINGS, true );
        $this->assertIsArray( $settings );
        $this->assertSame( array( $newTermId ), array_map( 'intval', (array) $settings[ ID::TAX_SERIES ] ) );

        // And the read-side resolver shapes it for SermonQuery.
        $scope = ( new PodcastScopeResolver() )->forPodcast( $result->newId );
        $this->assertSame( array( ID::TAX_SERIES => array( $newTermId ) ), $scope );

        // Clean scope → COMPLETE is stamped (no open term flag).
        $this->assertSame( '1', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
    }

    public function test_object_term_scope_merges_with_blob_refs_without_clobbering(): void {
        [ $legacyA, $newA ] = $this->migratedSeriesTerm( 'Series A' );
        [ $legacyB, $newB ] = $this->migratedSeriesTerm( 'Series B' );

        // The blob already references series A (legacy id) under the LEGACY taxonomy slug —
        // remapSettingsTerms migrates it to NEW id A. Series B arrives via object-terms.
        $legacyPodcast = $this->fixture->createPodcastWithSettings(
            array( LID::TAX_SERIES => array( $legacyA ) ),
            'Merge Feed'
        );
        wp_set_object_terms( $legacyPodcast, array( $legacyB ), LID::TAX_SERIES );

        $result = ( new PodcastWriter() )->write( $legacyPodcast );

        $settings = get_post_meta( $result->newId, ID::META_PODCAST_SETTINGS, true );
        $merged   = array_map( 'intval', (array) $settings[ ID::TAX_SERIES ] );
        sort( $merged );
        $expected = array( $newA, $newB );
        sort( $expected );
        $this->assertSame( $expected, $merged, 'blob ref and object-term scope must MERGE, never clobber' );
    }

    // =========================================================================
    // Feed surface: scoped serves only scoped; single scoped no longer over-includes.
    // =========================================================================

    public function test_scoped_podcast_feed_serves_only_scoped_sermons(): void {
        [ $legacyTermId, $newTermId ] = $this->migratedSeriesTerm( 'Galatians' );

        $inSeries  = $this->sermon( 'In Galatians', $newTermId );
        $offSeries = $this->sermon( 'Unrelated Sermon' );

        $legacyPodcast = $this->fixture->createPodcastWithSettings( array(), 'Galatians Feed' );
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        $result = ( new PodcastWriter() )->write( $legacyPodcast );
        update_option( ID::OPTION_DEFAULT_PODCAST, $result->newId );

        $xml = $this->capture();

        $this->assertStringContainsString( 'In Galatians', $xml, 'scoped sermon must be served' );
        $this->assertStringNotContainsString( 'Unrelated Sermon', $xml, 'off-series sermon must be excluded — no over-inclusion' );
    }

    // =========================================================================
    // Never-serve-empty: an unresolvable object-term scope term.
    // =========================================================================

    public function test_unresolvable_object_term_sets_missing_flag_and_withholds_complete(): void {
        // A legacy series term that is NEVER migrated (no crosswalk).
        $legacyTermId = $this->fixture->createTerm( LID::TAX_SERIES, 'Unmigrated Series' );

        $legacyPodcast = $this->fixture->createPodcastWithSettings( array(), 'Dead Term Feed' );
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        $result = ( new PodcastWriter() )->write( $legacyPodcast );

        // The unresolved term is flagged (shared contract token) and COMPLETE is WITHHELD.
        $flags = get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . $legacyTermId, (array) $flags );
        $this->assertSame( '', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // The resolver surfaces incomplete scope so the feed falls back to UNSCOPED.
        $this->assertTrue( ( new PodcastScopeResolver() )->hasIncompleteScope( $result->newId ) );
    }

    public function test_unresolvable_object_term_self_heals_once_term_migrates(): void {
        $legacyTermId  = $this->fixture->createTerm( LID::TAX_SERIES, 'Late Series' );
        $legacyPodcast = $this->fixture->createPodcastWithSettings( array(), 'Self Heal Feed' );
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        $writer = new PodcastWriter();
        $first  = $writer->write( $legacyPodcast );
        $this->assertTrue( ( new PodcastScopeResolver() )->hasIncompleteScope( $first->newId ) );

        // NOW migrate the term, then re-run the writer (resume): the scope self-heals.
        $legacyTerm = get_term( $legacyTermId, LID::TAX_SERIES );
        $newTermId  = (int) ( new TermWriter() )->migrateTerm( LID::TAX_SERIES, $legacyTerm );

        $second = $writer->write( $legacyPodcast );
        $this->assertSame( $first->newId, $second->newId );

        $this->assertFalse( ( new PodcastScopeResolver() )->hasIncompleteScope( $second->newId ) );
        $scope = ( new PodcastScopeResolver() )->forPodcast( $second->newId );
        $this->assertSame( array( ID::TAX_SERIES => array( $newTermId ) ), $scope );
        $this->assertSame( '1', (string) get_post_meta( $second->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
    }

    // =========================================================================
    // FIX 2/3: prevalence counts the legacy object-term source at the real call site.
    // =========================================================================

    public function test_detect_persists_prevalence_scope_from_legacy_object_terms(): void {
        $legacyTermId = $this->fixture->createTerm( LID::TAX_SERIES, 'Scope Series' );

        // One scoped legacy podcast (object-term scope, empty blob), one unscoped.
        $scoped = $this->fixture->createPodcastWithSettings( array(), 'Scoped' );
        wp_set_object_terms( $scoped, array( $legacyTermId ), LID::TAX_SERIES );
        $this->fixture->createPodcastWithSettings( array(), 'Unscoped' );

        // Drive the REAL write-gated call site — NOT a hand-built counter.
        ( new Orchestrator() )->detect();

        $stats = PrevalenceCounter::stats();
        $this->assertNotSame( array(), $stats, 'detect() must persist the prevalence rollup' );
        $this->assertSame( 2, $stats['podcasts']['published'] );
        $this->assertSame( 1, $stats['podcasts']['with_scope'], 'scope must be counted from the legacy object-term source at detect' );
        $this->assertSame( 1, $stats['podcasts']['single_scoped'] );
        $this->assertTrue( $stats['podcasts']['multi_podcast'] );
    }

    // =========================================================================
    // Adversarial-review fix 1: object-term scope merge must PRESERVE a settings
    // multiset (>1 META_PODCAST_SETTINGS rows), never collapse it to a single row.
    // =========================================================================

    public function test_object_term_scope_merge_preserves_settings_multiset(): void {
        [ $legacyTermId, $newTermId ] = $this->migratedSeriesTerm( 'Multiset Series' );

        // A legacy podcast that yields >1 META_PODCAST_SETTINGS rows on the migrated
        // record: the renamed sm_podcast_settings row UNIONED with a stray verbatim
        // sermonator_podcast_settings row (applyMeta's FIX IMPORTANT #9 union logic).
        $legacyPodcast = $this->fixture->createPodcastWithSettings(
            array( 'itunes_author' => 'Canonical' ),
            'Multiset Feed'
        );
        add_post_meta( $legacyPodcast, ID::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Stray' ) );
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        $result = ( new PodcastWriter() )->write( $legacyPodcast );

        // The multiset row COUNT must be preserved — the merge must not drop rows 2..N.
        $rows = get_post_meta( $result->newId, ID::META_PODCAST_SETTINGS, false );
        $this->assertCount( 2, $rows, 'settings multiset must survive the object-term scope merge' );

        // The scope is merged into the canonical (row-1) value the resolver reads.
        $canonical = get_post_meta( $result->newId, ID::META_PODCAST_SETTINGS, true );
        $this->assertIsArray( $canonical );
        $this->assertSame( array( $newTermId ), array_map( 'intval', (array) $canonical[ ID::TAX_SERIES ] ) );

        // The stray second row survives verbatim (no scope key injected into it).
        $second = $rows[1];
        $this->assertIsArray( $second );
        $this->assertArrayNotHasKey( ID::TAX_SERIES, $second );

        // Re-run (resume/self-heal) must remain idempotent on the row count.
        ( new PodcastWriter() )->write( $legacyPodcast );
        $rowsAgain = get_post_meta( $result->newId, ID::META_PODCAST_SETTINGS, false );
        $this->assertCount( 2, $rowsAgain, 'a resume pass must not grow or shrink the multiset' );
    }

    // =========================================================================
    // Adversarial-review fix 2: an already-COMPLETE record with NO open term flag
    // must reconcile object-term scope IN PLACE (self-heal across the version
    // boundary) rather than over-including every sermon forever.
    // =========================================================================

    public function test_complete_record_self_heals_object_term_scope_in_place(): void {
        [ $legacyTermId, $newTermId ] = $this->migratedSeriesTerm( 'Late Scope Series' );

        // Simulate a record migrated by the PRE-FIX writer: written + stamped COMPLETE
        // with NO object-term scope (and therefore no missing flag).
        $legacyPodcast = $this->fixture->createPodcastWithSettings( array(), 'Late Scope Feed' );
        $result        = ( new PodcastWriter() )->write( $legacyPodcast );
        $this->assertSame( '1', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
        $scopeBefore = ( new PodcastScopeResolver() )->forPodcast( $result->newId );
        $this->assertSame( array(), $scopeBefore, 'pre-fix record starts with NO scope (over-inclusive)' );

        // The per-podcast scope now exists in object-terms (the real SM Pro source).
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        // Re-run the writer (a spine pass over an already-COMPLETE record): the scope
        // must reconcile in place WITHOUT a Rollback.
        $second = ( new PodcastWriter() )->write( $legacyPodcast );
        $this->assertSame( $result->newId, $second->newId );

        $scopeAfter = ( new PodcastScopeResolver() )->forPodcast( $second->newId );
        $this->assertSame( array( ID::TAX_SERIES => array( $newTermId ) ), $scopeAfter, 'COMPLETE record must self-heal scope in place' );
        $this->assertSame( '1', (string) get_post_meta( $second->newId, Crosswalk::MIGRATION_COMPLETE, true ), 'a resolvable scope leaves COMPLETE stamped' );
    }

    public function test_complete_record_with_unresolvable_object_term_withholds_complete_on_reconcile(): void {
        // Pre-fix COMPLETE record (no scope, no flag).
        $legacyPodcast = $this->fixture->createPodcastWithSettings( array(), 'Dead Late Feed' );
        $result        = ( new PodcastWriter() )->write( $legacyPodcast );
        $this->assertSame( '1', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // An object-term scope appears, but its term is NEVER migrated (no crosswalk).
        $legacyTermId = $this->fixture->createTerm( LID::TAX_SERIES, 'Never Migrated' );
        wp_set_object_terms( $legacyPodcast, array( $legacyTermId ), LID::TAX_SERIES );

        // The reconcile records the missing flag AND WITHHOLDS COMPLETE so the record
        // drops back into the resume leg — never a feed scoped to a dead term, stamped
        // complete-and-skipped-forever.
        $second = ( new PodcastWriter() )->write( $legacyPodcast );
        $flags  = get_post_meta( $second->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . $legacyTermId, (array) $flags );
        $this->assertSame( '', (string) get_post_meta( $second->newId, Crosswalk::MIGRATION_COMPLETE, true ), 'an unresolved reconcile must withhold COMPLETE' );
        $this->assertTrue( ( new PodcastScopeResolver() )->hasIncompleteScope( $second->newId ) );
    }
}
