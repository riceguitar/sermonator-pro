<?php

declare(strict_types=1);

namespace Sermonator\Model;

use Sermonator\Schema\BibleCanon;
use Sermonator\Schema\Identifiers;

final class Activation {
    public function activate(): void {
        // Ensure post types & taxonomies exist during activation.
        ( new Registrar() )->register();
        ( new Capabilities() )->grant();
        $this->seedBibleBooks();

        if ( ! get_option( 'sermonator_version' ) ) {
            add_option( 'sermonator_version', SERMONATOR_VERSION, '', 'no' );
        } else {
            update_option( 'sermonator_version', SERMONATOR_VERSION, 'no' );
        }

        flush_rewrite_rules();
    }

    /** Seed default canon; never removes existing or admin-added terms. */
    private function seedBibleBooks(): void {
        foreach ( BibleCanon::defaultBooks() as $book ) {
            if ( ! term_exists( $book, Identifiers::TAX_BOOK ) ) {
                wp_insert_term( $book, Identifiers::TAX_BOOK );
            }
        }
    }
}
