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
        $counts    = array();
        $checksums = array();

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

        // Podcasts.
        $counts['podcasts'] = count( get_posts( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) ) );

        // Settings options present (sermonmanager_*).
        $counts['options'] = $this->countLegacyOptions();

        // Artwork associations (term_taxonomy_id → attachment_id).
        $artwork = get_option( LegacyIdentifiers::OPTION_TERM_IMAGES, array() );
        $counts['artwork'] = is_array( $artwork ) ? count( $artwork ) : 0;

        return new Manifest( $counts, $checksums );
    }

    private function sermonChecksum( int $id ): string {
        $post = get_post( $id );
        $meta = get_post_meta( $id );
        ksort( $meta );
        return md5( ( $post ? $post->post_content : '' ) . wp_json_encode( $meta ) );
    }

    private function countLegacyOptions(): int {
        global $wpdb;
        $like = $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%';
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
        );
    }
}
