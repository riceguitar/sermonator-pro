<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
use Sermonator\Model\Activation;
use Sermonator\Schema\BibleCanon;
use Sermonator\Schema\Identifiers;

final class ActivationTest extends WP_UnitTestCase {
    public function test_seeds_full_default_canon(): void {
        ( new Activation() )->activate();
        $terms = get_terms( array( 'taxonomy' => Identifiers::TAX_BOOK, 'hide_empty' => false ) );
        $names = wp_list_pluck( $terms, 'name' );
        foreach ( BibleCanon::defaultBooks() as $book ) {
            $this->assertContains( $book, $names, "$book not seeded" );
        }
    }

    public function test_seeding_is_idempotent(): void {
        ( new Activation() )->activate();
        ( new Activation() )->activate();
        $count = count( get_terms( array( 'taxonomy' => Identifiers::TAX_BOOK, 'hide_empty' => false ) ) );
        $this->assertSame( count( BibleCanon::defaultBooks() ), $count );
    }

    public function test_does_not_delete_admin_added_books(): void {
        ( new Activation() )->activate();
        wp_insert_term( 'Tobit', Identifiers::TAX_BOOK );
        ( new Activation() )->activate(); // re-activation must not remove custom books
        $names = wp_list_pluck(
            get_terms( array( 'taxonomy' => Identifiers::TAX_BOOK, 'hide_empty' => false ) ),
            'name'
        );
        $this->assertContains( 'Tobit', $names );
    }

    public function test_records_version(): void {
        ( new Activation() )->activate();
        $this->assertSame( SERMONATOR_VERSION, get_option( 'sermonator_version' ) );
    }
}
