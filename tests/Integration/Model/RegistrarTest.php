<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
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
}
