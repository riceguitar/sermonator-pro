<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
use Sermonator\Model\Registrar;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;

final class RegistrarTest extends WP_UnitTestCase {
    public function test_sermon_post_type_is_registered(): void {
        $this->assertTrue( post_type_exists( Identifiers::POST_TYPE_SERMON ) );
    }

    public function test_podcast_post_type_is_registered(): void {
        $this->assertTrue( post_type_exists( Identifiers::POST_TYPE_PODCAST ) );
    }

    public function test_sermon_supports_editor_for_native_post_content_body(): void {
        $this->assertTrue( post_type_supports( Identifiers::POST_TYPE_SERMON, 'editor' ) );
    }

    public function test_sermon_is_rest_enabled(): void {
        $object = get_post_type_object( Identifiers::POST_TYPE_SERMON );
        $this->assertTrue( $object->show_in_rest );
    }

    public function test_all_five_taxonomies_registered_for_sermon(): void {
        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            $this->assertTrue( taxonomy_exists( $taxonomy ), "$taxonomy missing" );
            $this->assertContains(
                Identifiers::POST_TYPE_SERMON,
                get_taxonomy( $taxonomy )->object_type,
                "$taxonomy not attached to sermon"
            );
        }
    }

    public function test_taxonomies_are_non_hierarchical(): void {
        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            $this->assertFalse( get_taxonomy( $taxonomy )->hierarchical, "$taxonomy should be flat" );
        }
    }

    /**
     * LOAD-BEARING CONTRACT (spec §1.3/§1.4 adversarial fix): with NO live
     * archive-slug row, the CPT must register under exactly
     * {@see DisplayDefaults::defaultArchiveSlug()} — the same seed the
     * SlugRewriteFlusher's first-save (add) path treats as the "old routing
     * slug". A drift to a hardcoded 'sermons' here would desync the flusher
     * baseline on a migrated site and silently 404 / spurious-flush.
     */
    public function test_archive_slug_falls_back_to_display_defaults_seed_when_no_live_option(): void {
        delete_option( Identifiers::OPTION_ARCHIVE_SLUG );

        ( new Registrar() )->register();

        $object = get_post_type_object( Identifiers::POST_TYPE_SERMON );

        $this->assertSame(
            DisplayDefaults::defaultArchiveSlug(),
            $object->rewrite['slug'],
            'CPT archive base must equal the DisplayDefaults seed the flusher baselines against.'
        );
    }

    /**
     * When an admin has saved a live archive slug (e.g. a migrated church whose
     * seed resolves to a non-default base), the CPT must register under THAT
     * live value, not the hard fallback — proving the live key, not a hardcoded
     * string, drives the CPT rewrite base.
     */
    public function test_archive_slug_reads_live_option_when_present(): void {
        update_option( Identifiers::OPTION_ARCHIVE_SLUG, 'preken' );

        ( new Registrar() )->register();

        $object = get_post_type_object( Identifiers::POST_TYPE_SERMON );

        $this->assertSame( 'preken', $object->rewrite['slug'] );

        delete_option( Identifiers::OPTION_ARCHIVE_SLUG );
    }

    /**
     * The fallback the Registrar passes to get_option() must be value-identical
     * to the flusher's add-path baseline. Both resolve through the single
     * {@see DisplayDefaults::defaultArchiveSlug()} resolver, so a first save that
     * merely persists that seed changes no routing and the flusher correctly
     * suppresses the flush.
     */
    public function test_registrar_fallback_matches_flusher_add_path_baseline(): void {
        delete_option( Identifiers::OPTION_ARCHIVE_SLUG );

        ( new Registrar() )->register();

        $registered = get_post_type_object( Identifiers::POST_TYPE_SERMON )->rewrite['slug'];

        // The flusher's onSlugAdded() baselines against this exact resolver.
        $this->assertSame( DisplayDefaults::defaultArchiveSlug(), $registered );
    }
}
