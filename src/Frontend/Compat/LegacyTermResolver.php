<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Migration\TermCrosswalk;

/**
 * Shared legacy-term-reference -> NEW term id resolver, consumed by BOTH the
 * `[sermons]`/`[sermons_sm]` attribute mapper (T4) and the per-podcast feed
 * scope path (T7). One resolver, so the two surfaces give the SAME answer to
 * "what survives Finalize" (design §2 decision 6).
 *
 * Two resolution paths, by reference shape:
 *
 *  - resolveBySlug() — `get_term_by('slug', …)` on the NEW taxonomy. DURABLE
 *    across Finalize: the migrated term itself persists, so a slug reference
 *    resolves identically before and after Finalize. Prefer this path.
 *
 *  - resolveByLegacyId() — a NUMERIC legacy term_id via Migration\TermCrosswalk.
 *    PRE-Finalize ONLY: the LEGACY_TERM_ID back-ref it reads is a strippable
 *    back-ref (Crosswalk::strippableBackRefs()), so post-Finalize the crosswalk
 *    finds nothing and we return a miss — NEVER passing the legacy id through as
 *    if it were a new id (that would select a DIFFERENT new term N — fail-wrong).
 *
 * Non-resolution returns LegacyResolution::miss() with a precise reason; the
 * caller drops that axis and names it in the editor notice (fail-visible).
 *
 * Pure-ish: WP-API + the migration crosswalk only, no Renderer. The crosswalk
 * is injectable so the numeric path is unit-testable.
 */
final class LegacyTermResolver {
    private TermCrosswalk $termCrosswalk;

    public function __construct( ?TermCrosswalk $termCrosswalk = null ) {
        $this->termCrosswalk = $termCrosswalk ?? new TermCrosswalk();
    }

    /**
     * Resolve a legacy term SLUG to its NEW term id on the given new taxonomy.
     * Durable across Finalize (the new term persists), so this is the preferred
     * path and the only one that survives finalization.
     */
    public function resolveBySlug( string $newTaxonomy, string $slug ): LegacyResolution {
        $slug = trim( $slug );

        if ( $slug === '' || $newTaxonomy === '' ) {
            return LegacyResolution::miss( 'empty_slug_reference' );
        }

        $term = get_term_by( 'slug', $slug, $newTaxonomy );

        if ( ! $term || is_wp_error( $term ) || empty( $term->term_id ) ) {
            return LegacyResolution::miss( 'slug_not_found:' . $newTaxonomy . ':' . $slug );
        }

        return LegacyResolution::hit( (int) $term->term_id );
    }

    /**
     * Resolve a NUMERIC legacy term_id to its NEW term id via the TermCrosswalk
     * back-ref. Works PRE-Finalize only; post-Finalize the back-ref is stripped
     * and this returns a miss (indistinguishable from a genuine pre-Finalize
     * miss — and correctly so: we never smuggle a legacy id through as a new id).
     */
    public function resolveByLegacyId( int $legacyTermId ): LegacyResolution {
        if ( $legacyTermId <= 0 ) {
            return LegacyResolution::miss( 'invalid_legacy_term_id:' . $legacyTermId );
        }

        $newId = $this->termCrosswalk->newTermId( $legacyTermId );

        if ( $newId === null || $newId <= 0 ) {
            return LegacyResolution::miss( 'legacy_term_id_unresolved:' . $legacyTermId );
        }

        return LegacyResolution::hit( $newId );
    }
}
