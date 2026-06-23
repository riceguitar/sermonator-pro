<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

final class SermonQueryTest extends WP_UnitTestCase {
    /** Create a published sermon preached on the given day-offset (descending = newer). */
    private function sermon( int $tsOffsetDays, string $title = 'S' ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => $title,
        ) );
        update_post_meta( $id, ID::META_DATE, (string) ( 1_700_000_000 + $tsOffsetDays * 86400 ) );
        return $id;
    }

    public function test_orders_by_preached_date_desc(): void {
        $older = $this->sermon( 0, 'Older' );
        $newer = $this->sermon( 10, 'Newer' );

        $result = ( new SermonQuery() )->run();

        $this->assertCount( 2, $result->sermons );
        $this->assertSame( $newer, $result->sermons[0]->id, 'Newest preached date first.' );
        $this->assertSame( $older, $result->sermons[1]->id );
    }

    public function test_filters_by_taxonomy_slug(): void {
        $a = $this->sermon( 1, 'A' );
        $b = $this->sermon( 2, 'B' );
        $term = self::factory()->term->create( array( 'taxonomy' => ID::TAX_PREACHER, 'name' => 'Pastor Bob', 'slug' => 'pastor-bob' ) );
        wp_set_object_terms( $a, array( (int) $term ), ID::TAX_PREACHER );

        $result = ( new SermonQuery() )->run( array( 'taxonomies' => array( ID::TAX_PREACHER => array( 'pastor-bob' ) ) ) );

        $this->assertCount( 1, $result->sermons );
        $this->assertSame( $a, $result->sermons[0]->id );
    }

    public function test_paginates(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->sermon( $i );
        }
        $page1 = ( new SermonQuery() )->run( array( 'perPage' => 2, 'page' => 1 ) );
        $page3 = ( new SermonQuery() )->run( array( 'perPage' => 2, 'page' => 3 ) );

        $this->assertSame( 5, $page1->total );
        $this->assertSame( 3, $page1->totalPages );
        $this->assertCount( 2, $page1->sermons );
        $this->assertCount( 1, $page3->sermons );
    }

    public function test_includes_dateless_sermon_sorted_last(): void {
        $dated    = $this->sermon( 5, 'Dated' );
        $dateless = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'Dateless',
        ) );
        // No sermonator_date meta on $dateless.

        $result = ( new SermonQuery() )->run();

        $this->assertSame( 2, $result->total, 'A sermon without a preached date must still be listed.' );
        $ids = array_map( static fn( $v ) => $v->id, $result->sermons );
        $this->assertContains( $dateless, $ids );
        $this->assertSame( $dated, $result->sermons[0]->id, 'Dated sermons sort before dateless ones.' );
        $this->assertSame( $dateless, $result->sermons[1]->id );
    }

    public function test_caps_unbounded_per_page(): void {
        $result = ( new \Sermonator\Frontend\SermonQuery() )->run(
            \Sermonator\Frontend\GridArgs::fromAtts( array( 'perPage' => 999999 ) )
        );
        $this->assertSame( 0, $result->total ); // no sermons seeded here; asserts no fatal/limit blow-up
    }

    public function test_excludes_drafts(): void {
        $this->sermon( 1 );
        $draft = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON, 'post_status' => 'draft' ) );
        update_post_meta( $draft, ID::META_DATE, '1700500000' );

        $result = ( new SermonQuery() )->run();
        $this->assertSame( 1, $result->total );
    }
}
