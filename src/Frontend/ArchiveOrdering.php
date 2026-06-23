<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Orders the sermon archive and the sermon taxonomy archives by PREACHED date
 * (`sermonator_date` meta) on the main query, so even a theme's own archive loop is
 * preached-date ordered (not post_date). Front-end main query only — never touches admin,
 * feeds, or secondary queries.
 */
final class ArchiveOrdering {
    public function hook(): void {
        add_action( 'pre_get_posts', array( $this, 'order' ) );
    }

    public function order( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( ! $this->isSermonArchive( $query ) ) {
            return;
        }
        $query->set( 'meta_key', ID::META_DATE );
        $query->set( 'orderby', 'meta_value_num' );
        $query->set( 'order', 'DESC' );
    }

    private function isSermonArchive( \WP_Query $query ): bool {
        if ( $query->is_post_type_archive( ID::POST_TYPE_SERMON ) ) {
            return true;
        }
        foreach ( ID::sermonTaxonomies() as $taxonomy ) {
            if ( $query->is_tax( $taxonomy ) ) {
                return true;
            }
        }
        return false;
    }
}
