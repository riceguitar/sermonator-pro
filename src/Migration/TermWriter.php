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
 *   itself resumable. We detect the collision by probing the slug DIRECTLY
 *   (term_exists) BEFORE the first insert, not by relying on a wp_insert_term
 *   term_exists WP_Error: in a non-hierarchical taxonomy that error only fires
 *   when the NAME also matches, so a same-slug/different-name native term would
 *   otherwise slip through to wp_unique_term_slug and get a silent, order-
 *   dependent '-2' suffix with no flag.
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

        // Detect a NATIVE-term slug collision BEFORE the first insert. The
        // back-ref probe above already returned null, so any term already
        // occupying this slug in the target taxonomy is NOT one of ours — it is
        // a church's NATIVE term we must never adopt.
        //
        // We cannot rely on a wp_insert_term term_exists WP_Error for this: in a
        // non-hierarchical taxonomy WordPress only raises term_exists when the
        // NAME also matches. For a same-slug/different-name native term,
        // wp_insert_term silently routes through wp_unique_term_slug and appends
        // an order-dependent '-2'/'-3' suffix — yielding a non-deterministic
        // slug and NO collision flag. So we probe the slug directly and take the
        // deterministic-suffix branch unconditionally whenever the slug is taken.
        $slugIsTaken = term_exists( $legacySlug, $targetTaxonomy ) !== null;

        if ( $slugIsTaken ) {
            $insertSlug = $legacySlug . '-legacy-' . $legacyTermId;
            $flags[]    = 'slug_collision';
        } else {
            $insertSlug = $legacySlug;
        }

        $result = wp_insert_term(
            $name,
            $targetTaxonomy,
            array(
                'slug'        => $insertSlug,
                'description' => $description,
            )
        );

        // A residual term_exists collision (e.g. a same-NAME native term whose
        // own slug differs, so the slug probe above missed it) still routes to
        // the deterministic suffix — never adopt, never silently '-2'.
        if ( is_wp_error( $result ) && in_array( 'term_exists', $result->get_error_codes(), true ) ) {
            $deterministicSlug = $legacySlug . '-legacy-' . $legacyTermId;
            if ( ! in_array( 'slug_collision', $flags, true ) ) {
                $flags[] = 'slug_collision';
            }

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

    /**
     * Migrate EVERY legacy term across all five legacy taxonomies into their
     * mapped target taxonomies.
     *
     * Iteration order is canonical (LegacyIdentifiers::sermonTaxonomies()).
     * Orphan terms — attached to no posts — are included via hide_empty=false,
     * so nothing is silently left behind. Each term is delegated to
     * migrateTerm(), which is itself idempotent on the cache-safe back-ref
     * probe, making migrateAll fully resumable: a second run skips every
     * already-crosswalked term and creates zero duplicate terms, back-refs, or
     * flag rows.
     *
     * HARD UNIQUENESS GUARD: after processing each legacy term we re-query the
     * authoritative back-ref directly and assert it maps to EXACTLY one new term
     * in the target taxonomy. A >1 state means a prior run (or external
     * corruption) produced a duplicate crosswalk — we stop loudly with a
     * reconciliation error rather than let a divergent mapping propagate into
     * the artwork/term-assignment writers that depend on a single deterministic
     * target id.
     *
     * @return array{migrated:int, skipped:int, flags:list<string>}
     */
    public function migrateAll(): array {
        $migrated = 0;
        $skipped  = 0;
        $flags    = array();

        foreach ( LegacyIdentifiers::sermonTaxonomies() as $legacyTaxonomy ) {
            $targetTaxonomy = MappingContract::taxonomyMap()[ $legacyTaxonomy ];

            $terms = get_terms(
                array(
                    'taxonomy'   => $legacyTaxonomy,
                    'hide_empty' => false,
                )
            );

            if ( is_wp_error( $terms ) ) {
                throw new \RuntimeException( sprintf(
                    'TermWriter::migrateAll: failed to read legacy taxonomy %s: %s',
                    $legacyTaxonomy,
                    $terms->get_error_message()
                ) );
            }

            foreach ( $terms as $legacyTerm ) {
                $legacyTermId = (int) $legacyTerm->term_id;

                // Was this legacy term already crosswalked before we touched it?
                // (status of the probe BEFORE delegating decides migrated/skipped).
                $alreadyMigrated = Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy ) !== null;

                $this->migrateTerm( $legacyTaxonomy, $legacyTerm );

                if ( $alreadyMigrated ) {
                    $skipped++;
                } else {
                    $migrated++;
                }

                // Hard uniqueness guard: re-probe the raw back-ref count. Run for
                // EVERY processed term (migrated or skipped) so a pre-existing
                // duplicate crosswalk — which migrateTerm short-circuits on — is
                // still caught.
                $mappedCount = $this->countNewTermsForLegacyId( $legacyTermId, $targetTaxonomy );
                if ( $mappedCount > 1 ) {
                    throw new \RuntimeException( sprintf(
                        'TermWriter::migrateAll: reconciliation error — legacy term id %d in %s maps to %d new terms in %s.',
                        $legacyTermId,
                        $legacyTaxonomy,
                        $mappedCount,
                        $targetTaxonomy
                    ) );
                }

                foreach ( get_term_meta( $this->resolveNewTermId( $legacyTermId, $targetTaxonomy ), Crosswalk::MIGRATION_FLAGS, false ) as $flag ) {
                    $flags[] = (string) $flag;
                }
            }
        }

        return array(
            'migrated' => $migrated,
            'skipped'  => $skipped,
            'flags'    => array_values( $flags ),
        );
    }

    /**
     * Count the distinct new terms carrying the legacy back-ref in a target
     * taxonomy. Reads $wpdb directly (cache-safe) so a term inserted moments
     * earlier on this same run is counted — the uniqueness guard cannot rely on
     * a stale term cache.
     */
    private function countNewTermsForLegacyId( int $legacyTermId, string $targetTaxonomy ): int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tm.term_id FROM {$wpdb->termmeta} tm"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id"
                . " WHERE tm.meta_key = %s AND tm.meta_value = %d AND tt.taxonomy = %s",
                Crosswalk::LEGACY_TERM_ID,
                $legacyTermId,
                $targetTaxonomy
            )
        );

        return count( (array) $ids );
    }

    private function resolveNewTermId( int $legacyTermId, string $targetTaxonomy ): int {
        return (int) Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy );
    }
}
