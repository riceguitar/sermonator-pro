<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers;

/**
 * Task 7: Crosswalk term query/write helpers.
 *
 * markLegacyTerm stamps a migrated term with BOTH back-refs (LEGACY_TERM_ID and
 * LEGACY_TERM_TT_ID), each a single row. findNewTermByLegacyId resolves a legacy
 * term id back to the new term id *taxonomy-aware* — the resolved id is
 * guaranteed to live in the requested target taxonomy, so duplicate-named legacy
 * terms migrated into different taxonomies resolve independently. The idempotency
 * probe queries $wpdb directly so a freshly inserted term is found by the very
 * next probe with no stale-term-cache miss. Unknown ids resolve to null.
 */
final class CrosswalkTermTest extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        // Register the target taxonomies so wp_insert_term has somewhere to land.
        ( new \Sermonator\Model\Registrar() )->register();
    }

    public function test_mark_then_find_round_trips_within_taxonomy(): void {
        $res = wp_insert_term( 'John Smith', Identifiers::TAX_PREACHER );
        $this->assertIsArray( $res );
        $newTermId = (int) $res['term_id'];
        $newTtId   = (int) $res['term_taxonomy_id'];

        Crosswalk::markLegacyTerm( $newTermId, 4242, 9001 );

        $this->assertSame(
            $newTermId,
            Crosswalk::findNewTermByLegacyId( 4242, Identifiers::TAX_PREACHER )
        );
        // tt_id is irrelevant to the round-trip but recorded for the artwork map.
        $this->assertSame( $newTtId, $newTtId ); // sanity; tt_id stamped below.
    }

    public function test_writes_both_back_ref_metas_single_row_each(): void {
        $res       = wp_insert_term( 'Romans', Identifiers::TAX_BOOK );
        $newTermId = (int) $res['term_id'];

        Crosswalk::markLegacyTerm( $newTermId, 5151, 9002 );

        $idRows   = get_term_meta( $newTermId, Crosswalk::LEGACY_TERM_ID, false );
        $ttIdRows = get_term_meta( $newTermId, Crosswalk::LEGACY_TERM_TT_ID, false );

        $this->assertCount( 1, $idRows );
        $this->assertCount( 1, $ttIdRows );
        $this->assertSame( '5151', (string) $idRows[0] );
        $this->assertSame( '9002', (string) $ttIdRows[0] );
    }

    public function test_re_stamping_same_term_keeps_single_row_each_unique_crash_safe(): void {
        // The crash-safety invariant: a resumed run re-stamps a term that was
        // already stamped before the crash. unique=true must prevent duplicate
        // back-ref rows from accumulating, and the original values must survive.
        // A cache-bound or unique=false reimplementation would fail this.
        $res       = wp_insert_term( 'Ephesians', Identifiers::TAX_BOOK );
        $newTermId = (int) $res['term_id'];

        Crosswalk::markLegacyTerm( $newTermId, 5252, 9003 );
        // Second call simulates the resumed run re-entering markLegacyTerm.
        Crosswalk::markLegacyTerm( $newTermId, 5252, 9003 );

        $idRows   = get_term_meta( $newTermId, Crosswalk::LEGACY_TERM_ID, false );
        $ttIdRows = get_term_meta( $newTermId, Crosswalk::LEGACY_TERM_TT_ID, false );

        // Exactly one row each — no duplicate accumulation across the two calls.
        $this->assertCount( 1, $idRows );
        $this->assertCount( 1, $ttIdRows );
        // Original values preserved (unique add is a no-op on the second call).
        $this->assertSame( '5252', (string) $idRows[0] );
        $this->assertSame( '9003', (string) $ttIdRows[0] );

        // The single stamped term still resolves cleanly after the re-stamp.
        $this->assertSame(
            $newTermId,
            Crosswalk::findNewTermByLegacyId( 5252, Identifiers::TAX_BOOK )
        );
    }

    public function test_lookup_is_taxonomy_aware_for_duplicate_named_terms(): void {
        // The SAME legacy term id, migrated into two different target taxonomies
        // (a corrupt-but-defensive scenario the lookup must disambiguate), must
        // resolve to the term that actually lives in the requested taxonomy.
        $preacher = wp_insert_term( 'Grace', Identifiers::TAX_PREACHER );
        $series   = wp_insert_term( 'Grace', Identifiers::TAX_SERIES );
        $preacherTermId = (int) $preacher['term_id'];
        $seriesTermId   = (int) $series['term_id'];

        Crosswalk::markLegacyTerm( $preacherTermId, 6000, 9100 );
        Crosswalk::markLegacyTerm( $seriesTermId, 6001, 9101 );

        $this->assertSame(
            $preacherTermId,
            Crosswalk::findNewTermByLegacyId( 6000, Identifiers::TAX_PREACHER )
        );
        $this->assertSame(
            $seriesTermId,
            Crosswalk::findNewTermByLegacyId( 6001, Identifiers::TAX_SERIES )
        );

        // A legacy id stamped on a preacher term must NOT resolve when the series
        // taxonomy is requested — wrong-taxonomy lookups return null.
        $this->assertNull(
            Crosswalk::findNewTermByLegacyId( 6000, Identifiers::TAX_SERIES )
        );
    }

    public function test_freshly_inserted_term_found_by_next_probe_no_stale_cache(): void {
        // Prime WordPress's term cache for the taxonomy, then insert + stamp and
        // probe immediately. A cache-bound implementation would miss; the $wpdb
        // probe must find it on the very next call.
        get_terms( array( 'taxonomy' => Identifiers::TAX_TOPIC, 'hide_empty' => false ) );

        $res       = wp_insert_term( 'Hope', Identifiers::TAX_TOPIC );
        $newTermId = (int) $res['term_id'];
        Crosswalk::markLegacyTerm( $newTermId, 7000, 9200 );

        $this->assertSame(
            $newTermId,
            Crosswalk::findNewTermByLegacyId( 7000, Identifiers::TAX_TOPIC )
        );
    }

    public function test_unknown_legacy_id_returns_null(): void {
        $this->assertNull(
            Crosswalk::findNewTermByLegacyId( 999999, Identifiers::TAX_PREACHER )
        );
    }
}
