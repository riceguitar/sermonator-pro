<?php

declare(strict_types=1);

namespace Sermonator\Model;

use Sermonator\Schema\DisplayDefaults;
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
                'rewrite'         => array( 'slug' => $this->archiveSlug(), 'with_front' => false ),
            )
        );
    }

    /**
     * The CPT archive (and single-sermon permalink) base slug, read at `init@5`
     * from the live {@see Identifiers::OPTION_ARCHIVE_SLUG} option.
     *
     * LOAD-BEARING CONTRACT (spec §1.3 / §1.4): the explicit `get_option()`
     * fallback MUST be exactly {@see DisplayDefaults::defaultArchiveSlug()} — the
     * identical seed resolver {@see \Sermonator\Frontend\SlugRewriteFlusher}'s
     * first-save (add) path uses as its "old routing slug" baseline. The two are
     * coupled: the flusher suppresses a rewrite flush when the first save merely
     * persists the seed, which is only correct if the CPT was actually registered
     * under that same seed here. Do NOT substitute a hardcoded `'sermons'` /
     * {@see DisplayDefaults::HARD_ARCHIVE_SLUG} — on a migrated site whose seed
     * resolves to e.g. `preken`, that would route the CPT under a different base
     * than the flusher assumes and silently skip a genuinely-needed flush (404)
     * or schedule a spurious one.
     *
     * `register_setting()`'s registered default is absent at `init@5`, so the
     * explicit fallback is the only thing that seeds the value on this path.
     */
    private function archiveSlug(): string {
        return (string) get_option(
            Identifiers::OPTION_ARCHIVE_SLUG,
            DisplayDefaults::defaultArchiveSlug()
        );
    }

    /**
     * The singular preacher taxonomy label, read at `init@5` from the live
     * {@see Identifiers::OPTION_PREACHER_LABEL} option. The plural is derived as
     * value.'s' (TAX_PREACHER is a flat, lowercase-named taxonomy; a simple suffix
     * matches the pre-existing "Preachers"/"Preacher" pairing).
     *
     * Mirrors {@see archiveSlug()}: `register_setting()`'s registered default is
     * absent at `init@5`, so the EXPLICIT {@see DisplayDefaults::preacherLabel()}
     * fallback is the only thing that seeds the value on this path. The identical
     * read+fallback runs in {@see \Sermonator\Frontend\TemplateData::preacherLabel()}
     * so the taxonomy label and the single-sermon meta row can never disagree.
     */
    private function preacherLabel(): string {
        return (string) get_option(
            Identifiers::OPTION_PREACHER_LABEL,
            DisplayDefaults::preacherLabel()
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
        $preacher = $this->preacherLabel();

        $labels = array(
            Identifiers::TAX_PREACHER     => array( $preacher . 's', $preacher ),
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
