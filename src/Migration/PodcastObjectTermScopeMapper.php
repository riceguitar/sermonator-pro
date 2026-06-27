<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * WordPress-FREE merge of a legacy podcast's OBJECT-TERM feed scope into the new
 * {@see \Sermonator\Schema\Identifiers::META_PODCAST_SETTINGS} settings array.
 *
 * The REAL Sermon Manager Pro per-podcast feed scope is NOT stored in the
 * `sm_podcast_settings` blob (it has no taxonomy-scope field). Pro derives a feed's
 * per-podcast scope from `wp_get_object_terms( $podcast->ID, $taxonomy )` over the
 * sermon taxonomies registered onto the `wpfc_sm_podcast` post type
 * (`podcasting_manager.php::filter_the_query()`). On a real install the blob is empty
 * and the scope lives entirely in OBJECT-TERMS — so {@see PodcastWriter} must mirror
 * that object-term scope into the new settings scope keys, or per-podcast filtering is
 * inert on real data and a scoped single podcast silently over-includes every sermon.
 *
 * This class is the pure, deterministic merge step (mirrors the
 * {@see TermArtworkMapper}/{@see TermCrosswalk} split: a WordPress-free mapper the
 * WP-touching writer feeds). It:
 *
 *  - renames each legacy taxonomy slug to its NEW slug via the supplied taxonomy map;
 *  - translates each legacy term id to its NEW term id via the supplied resolver;
 *  - MERGES the resolved new ids into the existing scope key (never clobbering blob
 *    refs {@see PodcastWriter::remapSettingsTerms()} already migrated, nor any other
 *    settings key — admin-edit safe);
 *  - records a {@see Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX}`<legacy id>` flag for
 *    any scope term that does NOT crosswalk, so the writer can WITHHOLD
 *    MIGRATION_COMPLETE and the resolver/feed fall back to UNSCOPED rather than
 *    serving a feed scoped to a dead term id.
 *
 * Idempotent: a re-run over the same legacy object-terms + crosswalk reproduces the
 * identical merged array (dedup by value), so it is safe on every resume/self-heal
 * pass. It NEVER reads or writes WordPress — the caller does the object-term read and
 * the meta write.
 */
final class PodcastObjectTermScopeMapper {
    /**
     * Merge the resolved object-term scope into $settings.
     *
     * @param array<mixed>                $settings              Current migrated settings (blob-derived; may be []).
     * @param array<string,list<int>>     $legacyScopeByTaxonomy Legacy taxonomy slug => legacy term ids (only non-empty axes).
     * @param array<string,string>        $taxonomyMap           Legacy taxonomy slug => new taxonomy slug.
     * @param callable(int):(int|null)    $resolve               Legacy term id => new term id, or null when unresolved.
     *
     * @return array{settings:array<mixed>,flags:list<string>,changed:bool}
     */
    public static function merge( array $settings, array $legacyScopeByTaxonomy, array $taxonomyMap, callable $resolve ): array {
        $flags   = array();
        $changed = false;

        foreach ( $legacyScopeByTaxonomy as $legacyTaxonomy => $legacyTermIds ) {
            $newTaxonomy = $taxonomyMap[ $legacyTaxonomy ] ?? null;
            if ( null === $newTaxonomy || ! is_array( $legacyTermIds ) ) {
                continue;
            }

            $resolvedNewIds = array();
            foreach ( $legacyTermIds as $legacyTermId ) {
                $legacyTermId = (int) $legacyTermId;
                if ( $legacyTermId <= 0 ) {
                    continue;
                }

                $newTermId = $resolve( $legacyTermId );
                if ( null === $newTermId ) {
                    // Unresolved scope term — never a silent drop. Flag it (shared
                    // contract token) so COMPLETE is withheld and the feed serves
                    // UNSCOPED until the term migrates and the id self-heals.
                    $flags[] = Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . $legacyTermId;
                    continue;
                }

                $resolvedNewIds[] = (int) $newTermId;
            }

            if ( array() === $resolvedNewIds ) {
                continue;
            }

            // MERGE with (never clobber) the blob-migrated refs already on this key.
            $existing = isset( $settings[ $newTaxonomy ] ) ? self::toIntList( $settings[ $newTaxonomy ] ) : array();
            $settings[ $newTaxonomy ] = array_values( array_unique( array_merge( $existing, $resolvedNewIds ) ) );
            $changed = true;
        }

        return array(
            'settings' => $settings,
            'flags'    => array_values( array_unique( $flags ) ),
            'changed'  => $changed,
        );
    }

    /**
     * Coerce an existing scope value (scalar new term id or a list of them) to a clean
     * list<int> of POSITIVE ids — the same normalization the read-side
     * {@see \Sermonator\Frontend\Feed\PodcastScopeResolver} applies, so a merge round-
     * trips byte-stable. A 0/empty/non-numeric entry is dropped.
     *
     * @param mixed $value
     * @return list<int>
     */
    private static function toIntList( $value ): array {
        $out = array();
        foreach ( (array) $value as $candidate ) {
            if ( is_int( $candidate ) || ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) {
                $id = (int) $candidate;
                if ( $id > 0 ) {
                    $out[] = $id;
                }
            }
        }

        return array_values( array_unique( $out ) );
    }

    private function __construct() {}
}
