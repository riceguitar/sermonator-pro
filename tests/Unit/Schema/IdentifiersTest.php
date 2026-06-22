<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\Identifiers;

final class IdentifiersTest extends TestCase {
    public function test_post_types_are_prefixed(): void {
        $this->assertSame( 'sermonator_sermon', Identifiers::POST_TYPE_SERMON );
        $this->assertSame( 'sermonator_podcast', Identifiers::POST_TYPE_PODCAST );
    }

    public function test_five_sermon_taxonomies_in_order(): void {
        $this->assertSame(
            array(
                'sermonator_preacher',
                'sermonator_series',
                'sermonator_topic',
                'sermonator_book',
                'sermonator_service_type',
            ),
            Identifiers::sermonTaxonomies()
        );
    }

    public function test_every_identifier_uses_the_prefix(): void {
        $all = array_merge(
            array( Identifiers::POST_TYPE_SERMON, Identifiers::POST_TYPE_PODCAST ),
            Identifiers::sermonTaxonomies(),
            Identifiers::metaKeys()
        );
        foreach ( $all as $id ) {
            $this->assertMatchesRegularExpression( '/^_?sermonator_/', $id, "$id is not prefixed" );
        }
    }

    public function test_identifiers_are_unique(): void {
        $all = array_merge(
            Identifiers::sermonTaxonomies(),
            Identifiers::metaKeys()
        );
        $this->assertSame( count( $all ), count( array_unique( $all ) ), 'duplicate identifier' );
    }

    public function test_meta_keys_include_core_sermon_fields(): void {
        $keys = Identifiers::metaKeys();
        foreach ( array( 'sermonator_date', 'sermonator_bible_passage', 'sermonator_audio', 'sermonator_views' ) as $expected ) {
            $this->assertContains( $expected, $keys );
        }
    }
}
