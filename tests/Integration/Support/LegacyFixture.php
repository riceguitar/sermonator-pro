<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Support;

use Sermonator\Migration\LegacyIdentifiers;

/**
 * Builds a legacy Sermon Manager (wpfc_*) dataset in the WordPress test DB so
 * the Detector (and B2's end-to-end test) can run against realistic source data.
 */
final class LegacyFixture {
    public function registerLegacySchema(): void {
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_SERMON, array( 'public' => true, 'label' => 'Legacy Sermon' ) );
        }
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_PODCAST ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_PODCAST, array( 'public' => false, 'label' => 'Legacy Podcast' ) );
        }
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                register_taxonomy( $taxonomy, LegacyIdentifiers::POST_TYPE_SERMON, array( 'public' => true ) );
            }
        }
    }

    /**
     * @param array<string, list<string>> $overrides Meta overrides (key → list of values).
     */
    public function createSermon( array $overrides = array() ): int {
        $id = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Legacy Sermon ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => 'Auto-generated blob',
        ) );

        $defaults = array(
            'sermon_date'        => array( '1612137600' ),
            'sermon_date_auto'   => array( '0' ),
            'bible_passage'      => array( 'John 3:16' ),
            'sermon_description' => array( '<p>The real body of the sermon.</p>' ),
        );

        foreach ( array_merge( $defaults, $overrides ) as $key => $values ) {
            foreach ( (array) $values as $value ) {
                add_post_meta( $id, $key, $value );
            }
        }

        return $id;
    }

    public function createTerm( string $taxonomy, string $name ): int {
        $result = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $result ) ) {
            $existing = get_term_by( 'name', $name, $taxonomy );
            return $existing ? (int) $existing->term_id : 0;
        }
        return (int) $result['term_id'];
    }

    public function createPodcast( string $title = 'Default' ): int {
        $id = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        add_post_meta( $id, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );
        return $id;
    }

    public function setOption( string $name, mixed $value ): void {
        update_option( $name, $value );
    }
}
