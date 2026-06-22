<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use WP_Term;

/**
 * Writes legacy taxonomy terms into the new sermonator_* taxonomies.
 *
 * Data-preservation contract:
 * - Legacy terms are READ-ONLY. This writer never updates a wpfc_* term.
 * - Idempotency is gated on the authoritative back-ref probe
 *   (Crosswalk::findNewTermByLegacyId), which reads $wpdb directly so a term
 *   inserted moments earlier on a resumed run is found by the very next call
 *   with no stale term-cache miss. A re-run returns the same id and creates no
 *   duplicate term, back-ref, or flag rows.
 * - NEVER adopt a native term. If the target taxonomy already contains a term
 *   with the same slug (a church's own term), we do NOT stamp our back-ref onto
 *   it — we create a NEW term with a DETERMINISTIC suffix slug
 *   ($slug.'-legacy-'.$legacyTermId), flag slug_collision, and leave the native
 *   term byte-for-byte untouched. Determinism is what makes the collision branch
 *   itself resumable.
 * - Back-refs (term_id + tt_id) and LEGACY_SLUG (the ORIGINAL legacy slug) are
 *   stamped immediately after a confirmed, non-error insert — never before the
 *   is_wp_error guard.
 */
final class TermWriter {
    /**
     * Migrate one legacy term into its mapped target taxonomy.
     *
     * @param string  $legacyTaxonomy A legacy taxonomy slug (e.g. wpfc_preacher).
     * @param WP_Term $legacyTerm     The legacy term to copy (read-only).
     * @return int The new term id (existing id on a resumed/idempotent re-run).
     */
    public function migrateTerm( string $legacyTaxonomy, WP_Term $legacyTerm ): int {
        $targetTaxonomy = MappingContract::taxonomyMap()[ $legacyTaxonomy ];
        $legacyTermId   = (int) $legacyTerm->term_id;

        // Cache-safe idempotency probe: already migrated? Return the existing id.
        $existing = Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy );
        if ( $existing !== null ) {
            return $existing;
        }

        $name        = $legacyTerm->name;
        $legacySlug  = $legacyTerm->slug;
        $description = (string) $legacyTerm->description;

        $flags = array();

        // First attempt: copy slug verbatim.
        $result = wp_insert_term(
            $name,
            $targetTaxonomy,
            array(
                'slug'        => $legacySlug,
                'description' => $description,
            )
        );

        // Collision with an existing term in the target taxonomy. Because the
        // back-ref probe above already returned null, any colliding term is NOT
        // one of ours — it is a church's NATIVE term, which we must never adopt.
        if ( is_wp_error( $result ) && in_array( 'term_exists', $result->get_error_codes(), true ) ) {
            $deterministicSlug = $legacySlug . '-legacy-' . $legacyTermId;
            $flags[]           = 'slug_collision';

            $result = wp_insert_term(
                $name,
                $targetTaxonomy,
                array(
                    'slug'        => $deterministicSlug,
                    'description' => $description,
                )
            );
        }

        // A WP_Error here is a genuine failure — do NOT proceed to add_term_meta.
        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( sprintf(
                'TermWriter: failed to insert term "%s" into %s: %s',
                $name,
                $targetTaxonomy,
                $result->get_error_message()
            ) );
        }

        $newTermId = (int) $result['term_id'];
        $newTtId   = (int) $result['term_taxonomy_id'];

        // Back-refs FIRST (crash-safety), then preserved provenance. LEGACY_SLUG
        // records the ORIGINAL legacy slug, not any suffixed collision slug.
        Crosswalk::markLegacyTerm( $newTermId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id );
        add_term_meta( $newTermId, Crosswalk::LEGACY_SLUG, $legacySlug, true );

        foreach ( $flags as $flag ) {
            add_term_meta( $newTermId, Crosswalk::MIGRATION_FLAGS, $flag );
        }

        return $newTermId;
    }
}
