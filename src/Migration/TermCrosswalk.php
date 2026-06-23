<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Read-only reader over the term back-refs TermWriter stamps. The downstream
 * artwork remapper and term-assignment writers ask it two questions:
 *
 *  - newTermId(legacyTermId): which new term did a legacy term_id become?
 *  - ttIdMap(): the legacy-tt_id → new-tt_id translation table.
 *
 * It deliberately stays separate from the pure TermArtworkMapper: this class
 * touches WordPress ($wpdb), the mapper is WordPress-free and unit-tested. The
 * mapper consumes ttIdMap()'s output.
 *
 * Every query reads $wpdb directly (no term cache) so a term inserted moments
 * earlier in the same run is visible — the same cache-safety discipline the
 * Crosswalk probes use.
 */
final class TermCrosswalk {
    /**
     * The new term_id a legacy term_id was migrated into.
     *
     * Unlike Crosswalk::findNewTermByLegacyId this is NOT taxonomy-scoped: the
     * artwork remapper keys translations by legacy tt_id and does not carry the
     * target taxonomy, so the reader must resolve a legacy term_id on its own.
     *
     * DETERMINISTIC: if a corrupt state maps one legacy term_id to more than one
     * new term, we pin the LOWEST new term_id and flag the condition (error_log)
     * rather than pick arbitrarily — downstream writers need one stable id.
     *
     * @return int|null The new term id, or null if the legacy id was never migrated.
     */
    public function newTermId( int $legacyTermId ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->termmeta}"
                . " WHERE meta_key = %s AND meta_value = %d"
                . " ORDER BY term_id ASC",
                Crosswalk::LEGACY_TERM_ID,
                $legacyTermId
            )
        );

        $ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );

        if ( $ids === array() ) {
            return null;
        }

        if ( count( $ids ) > 1 ) {
            error_log( sprintf(
                'Sermonator TermCrosswalk: legacy term id %d maps to %d new terms (%s); using lowest id.',
                $legacyTermId,
                count( $ids ),
                implode( ',', $ids )
            ) );
        }

        return $ids[0];
    }

    /**
     * The legacy-tt_id → new-tt_id translation table, one entry per migrated term.
     *
     * For each migrated term we read the stored LEGACY_TERM_TT_ID back-ref (the
     * source term_taxonomy_id) and pair it with the term's CURRENT
     * term_taxonomy_id from wp_term_taxonomy — NOT its term_id. The artwork
     * remapper's keys are tt_ids, and a term's tt_id differs from its term_id, so
     * reading the wrong column would silently corrupt every artwork assignment.
     *
     * @return array<int,int> legacy term_taxonomy_id => new term_taxonomy_id.
     */
    public function ttIdMap(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tm.meta_value AS legacy_tt_id, tt.term_taxonomy_id AS new_tt_id"
                . " FROM {$wpdb->termmeta} tm"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id"
                . " WHERE tm.meta_key = %s"
                . " ORDER BY tt.term_taxonomy_id ASC",
                Crosswalk::LEGACY_TERM_TT_ID
            ),
            ARRAY_A
        );

        $map = array();
        foreach ( (array) $rows as $row ) {
            $map[ (int) $row['legacy_tt_id'] ] = (int) $row['new_tt_id'];
        }

        return $map;
    }
}
