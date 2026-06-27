<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers;

/**
 * Reads the per-podcast term-filter SCOPE out of the already-migrated
 * {@see Identifiers::META_PODCAST_SETTINGS} blob and shapes it for
 * {@see \Sermonator\Frontend\SermonQuery::buildTaxQuery()} (relation=AND across
 * taxonomies, IN within — byte-identical to Pro's `filter_the_query`).
 *
 * This is the READ side of the per-podcast feed-filtering feature. The WRITE side
 * is {@see \Sermonator\Migration\PodcastWriter::remapSettingsTerms()}, which stores
 * each scoped taxonomy under its NEW taxonomy slug (`sermonator_preacher`/`series`/
 * `topic`/`book`/`service_type`) with NEW term id(s) as the value — the legacy ids
 * were already remapped through the term crosswalk at migration time. So NO term
 * resolution happens here at read: we trust the migrated ids verbatim.
 *
 * Two anti-foot-gun disciplines:
 *
 *  1. ANTI-DRIFT — the recognized scope keys are {@see Identifiers::sermonTaxonomies()},
 *     NOT a hardcoded five-slug list. The settings blob also carries channel-identity
 *     keys (`title`, `author`, `apple_url`, …); intersecting against the canonical
 *     taxonomy list is what keeps a stray identity key out of the tax_query AND keeps
 *     this resolver honest if the taxonomy set ever changes.
 *
 *  2. NEVER SERVE EMPTY ON A DEAD TERM — {@see PodcastWriter} records an open
 *     `missing_podcast_term_crosswalk:*` flag (in {@see Crosswalk::MIGRATION_FLAGS})
 *     when the Pro feed HAD scope but a scoped term did not resolve at migration.
 *     A feed scoped to an unresolved/dead term id would silently EMPTY a live Apple/
 *     Spotify subscription. {@see self::hasIncompleteScope()} surfaces that flag so
 *     the caller ({@see PodcastFeed}) can fall back to UNSCOPED + fire an observable
 *     signal rather than serving the empty scope.
 *
 * Pure-ish: the only WordPress contact is `get_post_meta()`.
 */
final class PodcastScopeResolver {
    /**
     * The per-taxonomy NEW term-id scope for one podcast, shaped for
     * {@see \Sermonator\Frontend\SermonQuery}'s `taxonomies` arg.
     *
     * Only taxonomies carrying a non-empty id list are returned; an unscoped
     * podcast (no taxonomy keys, or all-zero/empty values) returns `[]`, which the
     * caller treats as today's exact UNSCOPED query.
     *
     * @return array<string,list<int>> taxonomy slug => list of new term ids.
     */
    public function forPodcast( int $podcastId ): array {
        $settings = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        if ( ! is_array( $settings ) ) {
            return array();
        }

        $scope = array();
        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            if ( ! array_key_exists( $taxonomy, $settings ) ) {
                continue;
            }
            $ids = $this->normalizeIds( $settings[ $taxonomy ] );
            if ( $ids !== array() ) {
                $scope[ $taxonomy ] = $ids;
            }
        }

        return $scope;
    }

    /**
     * Whether this podcast carries an OPEN `missing_podcast_term_crosswalk:*` flag —
     * i.e. Pro HAD feed scope but a scoped term did not resolve at migration. The
     * caller MUST fall back to UNSCOPED (never serve the partial/dead scope) and fire
     * a fail-visible signal when this is true. Reads the canonical
     * {@see Crosswalk::MIGRATION_FLAGS} row written by {@see PodcastWriter}.
     */
    public function hasIncompleteScope( int $podcastId ): bool {
        $flags = get_post_meta( $podcastId, Crosswalk::MIGRATION_FLAGS, true );
        if ( ! is_array( $flags ) ) {
            return false;
        }
        foreach ( $flags as $flag ) {
            if ( str_starts_with( (string) $flag, Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Coerce a scope value (a scalar new term id or a list of them) to a clean
     * list<int> of POSITIVE ids. A term id of 0 / empty means "not scoped to any
     * term" in Sermon Manager — dropped here so a zero-valued taxonomy key does not
     * masquerade as a real scope. Non-numeric junk is dropped. Term ids are always
     * positive, so plain `ctype_digit` is correct (unlike the SIGNED sermonator_date).
     *
     * @param mixed $value
     * @return list<int>
     */
    private function normalizeIds( $value ): array {
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
}
