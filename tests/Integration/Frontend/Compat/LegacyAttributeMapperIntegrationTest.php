<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Frontend\Compat\LegacyAttributeMapper;
use Sermonator\Frontend\DateScope;
use Sermonator\Migration\Crosswalk;
use Sermonator\Model\Registrar;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2, T4 — integration pins for {@see LegacyAttributeMapper} wired to the REAL
 * shared resolvers ({@see \Sermonator\Frontend\Compat\LegacyTermResolver} /
 * {@see \Sermonator\Frontend\Compat\LegacyPostResolver}), the REAL crosswalks, and
 * REAL migrated options, against a live WordPress term/post store.
 *
 * Where the unit suite stubs get_term_by / $wpdb, this proves the mapper's per-attribute
 * ledger end-to-end: slug + numeric term resolution, include/exclude post resolution,
 * resolve-or-DROP (never pass a legacy id through), and the option-driven default
 * order/orderby. It complements the do_shortcode()-boundary pins in
 * {@see LegacySermonsLedgerTest} (which prove the full T6 render path).
 *
 * NOTE: integration suite — requires wp-env (Docker). NOT run in this environment (no
 * Docker available); written as the pinned spec.
 */
final class LegacyAttributeMapperIntegrationTest extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        ( new Registrar() )->register();
    }

    public function tear_down(): void {
        delete_option( ID::OPTION_ARCHIVE_ORDER );
        delete_option( ID::OPTION_ARCHIVE_ORDERBY );
        parent::tear_down();
    }

    /**
     * Create a NEW-system term carrying a legacy back-ref (a migrated term).
     *
     * @return array{0:int,1:int} [newTermId, legacyTermId]
     */
    private function migratedTerm( string $taxonomy, string $name, string $slug, int $legacyTermId ): array {
        $created   = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
        $newTermId = (int) $created['term_id'];
        add_term_meta( $newTermId, Crosswalk::LEGACY_TERM_ID, $legacyTermId, true );

        return array( $newTermId, $legacyTermId );
    }

    /**
     * Create a NEW-system sermon carrying a legacy post-id back-ref (a migrated post).
     *
     * @return array{0:int,1:int} [newPostId, legacyPostId]
     */
    private function migratedSermon( string $title, int $legacyPostId ): array {
        $newPostId = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => $title,
        ) );
        Crosswalk::markLegacy( $newPostId, $legacyPostId );

        return array( $newPostId, $legacyPostId );
    }

    // --- option-driven defaults ----------------------------------------------

    public function test_bare_call_sources_default_order_and_orderby_from_migrated_options(): void {
        update_option( ID::OPTION_ARCHIVE_ORDER, 'asc' );
        update_option( ID::OPTION_ARCHIVE_ORDERBY, 'date_preached' );

        $result = ( new LegacyAttributeMapper() )->map( array() );

        $this->assertSame( 'ASC', $result->gridArgs['order'] );
        $this->assertSame( 'preached', $result->orderby );
        $this->assertSame( DateScope::PREACHED, $result->dateScope );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_orderby_date_resolves_against_the_real_archive_orderby_option(): void {
        update_option( ID::OPTION_ARCHIVE_ORDERBY, 'date' );

        $result = ( new LegacyAttributeMapper() )->map( array( 'orderby' => 'date' ) );

        $this->assertSame( 'published', $result->orderby );
        $this->assertSame( DateScope::NONE, $result->dateScope );
    }

    // --- slug filter_value (FAITHFUL, durable) -------------------------------

    public function test_slug_filter_value_resolves_to_a_real_new_term(): void {
        [ $newTermId ] = $this->migratedTerm( ID::TAX_SERIES, 'Grace Alone', 'grace-alone', 4242 );

        $result = ( new LegacyAttributeMapper() )->map( array(
            'filter_by'    => 'series',
            'filter_value' => 'grace-alone',
        ) );

        $this->assertSame( array( ID::TAX_SERIES => array( $newTermId ) ), $result->gridArgs['taxonomies'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    // --- numeric filter_value (UNVALIDATABLE; notice kept on resolution) ------

    public function test_numeric_filter_value_resolves_via_crosswalk_but_keeps_its_notice(): void {
        [ $newTermId, $legacyId ] = $this->migratedTerm( ID::TAX_TOPIC, 'Hope', 'hope', 707 );

        $result = ( new LegacyAttributeMapper() )->map( array(
            'filter_by'    => 'topic',
            'filter_value' => (string) $legacyId,
        ) );

        $this->assertSame( array( ID::TAX_TOPIC => array( $newTermId ) ), $result->gridArgs['taxonomies'] );
        $this->assertContains( 'filter_value', $result->unfaithfulAttrs );
    }

    public function test_unresolved_numeric_filter_value_drops_the_axis_and_names_it(): void {
        // No migrated term carries legacy id 987654 -> resolve-or-DROP (never pass through).
        $result = ( new LegacyAttributeMapper() )->map( array(
            'filter_by'    => 'book',
            'filter_value' => '987654',
        ) );

        $this->assertSame( array(), $result->gridArgs['taxonomies'] );
        $this->assertContains( 'filter_value', $result->unfaithfulAttrs );
    }

    // --- include / exclude (resolve-or-DROP) ---------------------------------

    public function test_include_resolves_legacy_post_ids_to_new_ids(): void {
        [ $newPostId, $legacyId ] = $this->migratedSermon( 'Included Sermon', 5151 );

        $result = ( new LegacyAttributeMapper() )->map( array( 'include' => (string) $legacyId ) );

        $this->assertSame( array( $newPostId ), $result->postIn );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_unresolved_include_id_is_dropped_and_named_never_passed_through(): void {
        // Legacy id 424242 was never migrated; passing it through would address a DIFFERENT
        // new post 424242 -> fail-wrong. It must be dropped and named instead.
        $result = ( new LegacyAttributeMapper() )->map( array( 'include' => '424242' ) );

        $this->assertSame( array(), $result->postIn );
        $this->assertContains( 'include', $result->unfaithfulAttrs );
    }

    public function test_include_resolution_misses_after_finalize_strips_the_backref(): void {
        [ $newPostId, $legacyId ] = $this->migratedSermon( 'Finalize Sermon', 6262 );
        $mapper                   = new LegacyAttributeMapper();

        // Pre-Finalize the back-ref resolves.
        $this->assertSame( array( $newPostId ), $mapper->map( array( 'include' => (string) $legacyId ) )->postIn );

        // Finalize strips the strippable LEGACY_POST_ID back-ref.
        delete_post_meta( $newPostId, Crosswalk::LEGACY_POST_ID );

        // Post-Finalize the same legacy id misses (dropped + named), never passed through.
        $post = $mapper->map( array( 'include' => (string) $legacyId ) );
        $this->assertSame( array(), $post->postIn );
        $this->assertContains( 'include', $post->unfaithfulAttrs );
    }
}
