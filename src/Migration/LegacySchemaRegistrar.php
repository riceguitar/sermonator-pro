<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Registration-agnostic legacy-schema guard (MUST-FIX #1, CRITICAL).
 *
 * The normal drop-in-replacement production config DEACTIVATES the legacy Sermon
 * Manager plugin at migration time. Once deactivated, the wpfc_* post types and
 * taxonomies are UNREGISTERED, so wp_get_object_terms / get_terms / get_posts /
 * WP_Query all return WP_Error('invalid_taxonomy'/'invalid_post_type') or empty
 * even though the wp_posts / wp_term_relationships rows still exist on disk. Any
 * legacy READ that goes through the WP query/term APIs would then silently see no
 * data — and the migration writers would stamp records COMPLETE with their primary
 * term assignments dropped, never to self-heal.
 *
 * ensureRegistered() idempotently re-registers the legacy schema (post types +
 * the five sermon taxonomies, associated with wpfc_sermon) so those reads work
 * regardless of whether the legacy plugin is active. Each registration is guarded
 * by post_type_exists()/taxonomy_exists(), so when the legacy plugin IS active it
 * is a pure no-op — the live plugin's own (richer) registration is never disturbed.
 *
 * It is called at the TOP of every legacy-read entry point (Detector::hasLegacyData,
 * Detector::detect, TermWriter::migrateAll, SermonWriter::write, PodcastWriter::write,
 * ArtworkWriter::migrate, OptionWriter::migrate) so no read path can observe the
 * unregistered-source state. It NEVER writes to legacy primary data — registering a
 * post type / taxonomy is an in-memory runtime declaration, not a DB mutation.
 */
final class LegacySchemaRegistrar {
    public static function ensureRegistered(): void {
        // Post types. 'public' => false keeps these registrations invisible to the
        // front end (we only need them readable by the query/term APIs during the
        // migration), and a no-op when the live legacy plugin already registered them.
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_SERMON, array(
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'has_archive'         => false,
                'rewrite'             => false,
                'query_var'           => false,
                'label'               => 'Legacy Sermon (migration)',
            ) );
        }

        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_PODCAST ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_PODCAST, array(
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'has_archive'         => false,
                'rewrite'             => false,
                'query_var'           => false,
                'label'               => 'Legacy Podcast (migration)',
            ) );
        }

        // The five primary sermon taxonomies, associated with wpfc_sermon so
        // wp_get_object_terms($legacyId, $tax) and get_terms($tax) resolve. Guarded
        // by taxonomy_exists() so an active legacy plugin's registration wins.
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                register_taxonomy( $taxonomy, LegacyIdentifiers::POST_TYPE_SERMON, array(
                    'public'             => false,
                    'publicly_queryable' => false,
                    'show_ui'            => false,
                    'show_in_menu'       => false,
                    'show_in_rest'       => false,
                    'rewrite'            => false,
                    'query_var'          => false,
                    'label'              => $taxonomy . ' (migration)',
                ) );
            }
        }
    }

    private function __construct() {}
}
