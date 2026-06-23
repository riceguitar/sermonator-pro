<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Read-only scan of an existing Sermon Manager install. Produces a Manifest
 * (counts + per-sermon checksums) used to size the migration and to verify it
 * later. Performs NO writes — legacy data is never touched.
 */
final class Detector {
    public function hasLegacyData(): bool {
        // MUST-FIX #1: the legacy plugin is normally DEACTIVATED at migration time,
        // so the wpfc_* post type is unregistered and WP_Query would find 0 even
        // though the rows exist. Re-register the legacy schema (no-op if active).
        LegacySchemaRegistrar::ensureRegistered();

        $q = new \WP_Query( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );
        return $q->found_posts > 0;
    }

    public function detect(): Manifest {
        // MUST-FIX #1: re-register the legacy schema so a DEACTIVATED legacy plugin
        // (the normal drop-in-replacement config) cannot make get_posts/get_terms
        // return empty over rows that still exist. No-op when the plugin is active.
        LegacySchemaRegistrar::ensureRegistered();

        $counts           = array();
        $checksums        = array();
        $podcastChecksums = array();

        // Sermons + per-sermon checksum.
        $ids = get_posts( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        $counts['sermons'] = count( $ids );
        foreach ( $ids as $id ) {
            $checksums[ (int) $id ] = $this->sermonChecksum( (int) $id );
        }

        // Terms per legacy taxonomy.
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
            $counts[ 'terms_' . $taxonomy ] = is_array( $terms ) ? count( $terms ) : 0;
        }

        // Podcasts + per-podcast checksum (a podcast carries full post_content/meta
        // copied forward, so it needs the SAME source-fixity oracle as a sermon — a
        // post-detect edit must be caught before Finalize force-deletes the legacy
        // podcast, the only place that edit exists).
        $podcastIds = get_posts( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        $counts['podcasts'] = count( $podcastIds );
        foreach ( $podcastIds as $id ) {
            $podcastChecksums[ (int) $id ] = LegacyChecksum::forPost( (int) $id );
        }

        // Settings options present (sermonmanager_*).
        $counts['options'] = $this->countLegacyOptions();

        // Artwork associations (term_taxonomy_id → attachment_id).
        $artwork = get_option( LegacyIdentifiers::OPTION_TERM_IMAGES, array() );
        $counts['artwork'] = is_array( $artwork ) ? count( $artwork ) : 0;

        return new Manifest( $counts, $checksums, $podcastChecksums );
    }

    private function sermonChecksum( int $id ): string {
        // Delegate to the shared, encoding-hardened implementation so the
        // Detector and the Verifier cannot drift apart.
        return LegacyChecksum::forPost( $id );
    }

    private function countLegacyOptions(): int {
        global $wpdb;
        $like = $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%';
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
        );
    }
}
