<?php

declare(strict_types=1);

namespace Sermonator\Model;

use Sermonator\Schema\Identifiers;

final class Registrar {
    public function hook(): void {
        add_action( 'init', array( $this, 'register' ), 5 );
    }

    public function register(): void {
        $this->registerSermonPostType();
        $this->registerPodcastPostType();
        $this->registerTaxonomies();
    }

    private function registerSermonPostType(): void {
        register_post_type(
            Identifiers::POST_TYPE_SERMON,
            array(
                'labels'          => array(
                    'name'          => __( 'Sermons', 'sermonator' ),
                    'singular_name' => __( 'Sermon', 'sermonator' ),
                    'menu_name'     => __( 'Sermonator', 'sermonator' ),
                ),
                'public'          => true,
                'show_in_rest'    => true,
                'has_archive'     => true,
                'menu_icon'       => 'dashicons-book-alt',
                'capability_type' => Identifiers::POST_TYPE_SERMON,
                'map_meta_cap'    => true,
                'hierarchical'    => false,
                'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'revisions', 'author', 'custom-fields' ),
                'rewrite'         => array( 'slug' => 'sermons', 'with_front' => false ),
            )
        );
    }

    private function registerPodcastPostType(): void {
        register_post_type(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'labels'       => array(
                    'name'          => __( 'Podcasts', 'sermonator' ),
                    'singular_name' => __( 'Podcast', 'sermonator' ),
                ),
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON,
                'show_in_rest' => true,
                'supports'     => array( 'title' ),
            )
        );
    }

    private function registerTaxonomies(): void {
        $labels = array(
            Identifiers::TAX_PREACHER     => array( __( 'Preachers', 'sermonator' ), __( 'Preacher', 'sermonator' ) ),
            Identifiers::TAX_SERIES       => array( __( 'Series', 'sermonator' ), __( 'Series', 'sermonator' ) ),
            Identifiers::TAX_TOPIC        => array( __( 'Topics', 'sermonator' ), __( 'Topic', 'sermonator' ) ),
            Identifiers::TAX_BOOK         => array( __( 'Books', 'sermonator' ), __( 'Book', 'sermonator' ) ),
            Identifiers::TAX_SERVICE_TYPE => array( __( 'Service Types', 'sermonator' ), __( 'Service Type', 'sermonator' ) ),
        );

        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            list( $plural, $singular ) = $labels[ $taxonomy ];
            register_taxonomy(
                $taxonomy,
                array( Identifiers::POST_TYPE_SERMON ),
                array(
                    'labels'       => array(
                        'name'          => $plural,
                        'singular_name' => $singular,
                    ),
                    'hierarchical' => false,
                    'public'       => true,
                    'show_ui'      => true,
                    'show_in_rest' => true,
                    'query_var'    => true,
                )
            );
        }
    }
}
