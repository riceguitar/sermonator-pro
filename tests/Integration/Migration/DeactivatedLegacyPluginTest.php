<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\SermonWriter;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Detector;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Migration\LegacySchemaRegistrar;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * MUST-FIX #1 (CRITICAL) — deactivated-legacy data-loss regression.
 *
 * The normal drop-in-replacement production config DEACTIVATES the legacy Sermon
 * Manager plugin at migration time. Once deactivated, the wpfc_* post types and
 * taxonomies are UNREGISTERED, so WP_Query(post_type), get_posts(post_type),
 * get_terms(taxonomy) and wp_get_object_terms($id, $tax) all return empty /
 * WP_Error even though the underlying wp_posts / wp_term_relationships rows still
 * exist on disk.
 *
 * Before the fix, SermonWriter::applyTerms (and openFlagTargetTaxonomies)
 * bare-`continue`d on the WP_Error, so EVERY sermon's primary term assignments
 * (preacher/series/topic/book/service_type) were silently dropped while
 * MIGRATION_COMPLETE was still stamped — permanent, self-heal-proof data loss.
 * The Detector likewise counted zero sermons/terms.
 *
 * This test seeds legacy sermons+terms WHILE the schema is registered (so the
 * rows land), MIGRATES the terms, then UNREGISTERS the wpfc_* schema (simulating
 * the deactivated plugin) and runs Detector::detect + SermonWriter::write WITHOUT
 * pre-registering anything. The production entry points must self-register the
 * legacy schema (LegacySchemaRegistrar) so the rows remain readable and the term
 * assignments survive.
 */
final class DeactivatedLegacyPluginTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /**
     * Unregister the wpfc_* taxonomies and post types, reproducing exactly the
     * runtime state of a site whose legacy Sermon Manager plugin is deactivated.
     */
    private function deactivateLegacyPlugin(): void {
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) {
                unregister_taxonomy( $taxonomy );
            }
        }
        foreach ( array( LegacyIdentifiers::POST_TYPE_SERMON, LegacyIdentifiers::POST_TYPE_PODCAST ) as $postType ) {
            if ( post_type_exists( $postType ) ) {
                unregister_post_type( $postType );
            }
        }
        // Sanity: the schema really is gone (this is what a deactivated plugin
        // leaves behind), so a naive wp_get_object_terms read would WP_Error.
        $this->assertFalse( taxonomy_exists( LegacyIdentifiers::TAX_PREACHER ) );
        $this->assertFalse( post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) );
    }

    public function test_term_assignments_survive_when_legacy_plugin_is_deactivated(): void {
        // Seed a legacy sermon assigned to a primary wpfc_ term, WHILE the schema
        // is still registered so the rows land on disk.
        $preacherId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $seriesId   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $legacyId   = $this->fixture->createSermon();
        wp_set_object_terms( $legacyId, array( $preacherId ), LegacyIdentifiers::TAX_PREACHER );
        wp_set_object_terms( $legacyId, array( $seriesId ), LegacyIdentifiers::TAX_SERIES );

        // Migrate the terms so a crosswalk exists, then DEACTIVATE the legacy plugin
        // (unregister the wpfc_* schema) before the sermon write — the production
        // drop-in-replacement timing.
        ( new TermWriter() )->migrateAll();
        $newPreacher = Crosswalk::findNewTermByLegacyId( $preacherId, Identifiers::TAX_PREACHER );
        $newSeries   = Crosswalk::findNewTermByLegacyId( $seriesId, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newPreacher );
        $this->assertNotNull( $newSeries );

        $this->deactivateLegacyPlugin();

        // Detector must still see the legacy data despite the unregistered schema.
        $manifest = ( new Detector() )->detect();
        $this->assertSame( 1, $manifest->counts()['sermons'], 'Detector must count the legacy sermon even when the legacy plugin is deactivated' );
        $this->assertSame( 1, $manifest->counts()[ 'terms_' . LegacyIdentifiers::TAX_PREACHER ], 'Detector must count the legacy preacher term' );

        // SermonWriter::write must read the legacy term assignments registration-
        // agnostically and preserve them on the migrated sermon.
        $result = ( new SermonWriter() )->write( $legacyId );

        $assignedPreacher = array_map( 'intval', (array) wp_get_object_terms( $result->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) ) );
        $assignedSeries   = array_map( 'intval', (array) wp_get_object_terms( $result->newId, Identifiers::TAX_SERIES, array( 'fields' => 'ids' ) ) );

        $this->assertSame( array( (int) $newPreacher ), $assignedPreacher, 'primary preacher assignment must survive a deactivated legacy plugin' );
        $this->assertSame( array( (int) $newSeries ), $assignedSeries, 'primary series assignment must survive a deactivated legacy plugin' );

        // No silent-drop: the record must NOT carry a legacy_taxonomy_unreadable flag
        // (the registrar made the taxonomy readable), and the assignments are present.
        $this->assertNotContains( 'legacy_taxonomy_unreadable:' . LegacyIdentifiers::TAX_PREACHER, $result->flags );
    }

    public function test_registrar_is_idempotent_and_a_noop_when_legacy_plugin_active(): void {
        // The legacy schema is registered (active plugin) in set_up; ensureRegistered
        // must be a pure no-op and must not throw on repeated calls.
        $this->assertTrue( taxonomy_exists( LegacyIdentifiers::TAX_PREACHER ) );
        LegacySchemaRegistrar::ensureRegistered();
        LegacySchemaRegistrar::ensureRegistered();
        $this->assertTrue( taxonomy_exists( LegacyIdentifiers::TAX_PREACHER ) );
        $this->assertTrue( post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) );

        // And after deactivation, ensureRegistered re-registers the schema.
        $this->deactivateLegacyPlugin();
        LegacySchemaRegistrar::ensureRegistered();
        $this->assertTrue( taxonomy_exists( LegacyIdentifiers::TAX_PREACHER ) );
        $this->assertTrue( post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) );
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            $this->assertTrue( taxonomy_exists( $taxonomy ), $taxonomy . ' must be re-registered' );
        }
    }
}
