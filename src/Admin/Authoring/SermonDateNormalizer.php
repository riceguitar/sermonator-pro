<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Schema\Identifiers;

/**
 * Fills an empty preached date from the post's publish date.
 *
 * This is a fill-only, migration-gated normalizer. It runs on save_post and REST insert so
 * that a sermon created in wp-admin (where there is no preached-date UI beyond the new panel)
 * still has a usable ordering date. It never overwrites an explicitly set date, and it is
 * inert during an active migration so it cannot perturb the Verifier's fixity comparison.
 */
final class SermonDateNormalizer {
    public function hook(): void {
        add_action(
            'save_post_' . Identifiers::POST_TYPE_SERMON,
            array( $this, 'normalize' ),
            10,
            2
        );
        add_action(
            'rest_after_insert_' . Identifiers::POST_TYPE_SERMON,
            array( $this, 'normalizeRest' ),
            10,
            1
        );
    }

    public function normalize( int $post_id, \WP_Post $post ): void {
        if ( $post->post_type !== Identifiers::POST_TYPE_SERMON ) {
            return;
        }
        $this->apply( $post_id, $post );
    }

    public function normalizeRest( \WP_Post $post ): void {
        if ( $post->post_type !== Identifiers::POST_TYPE_SERMON ) {
            return;
        }
        $this->apply( $post->ID, $post );
    }

    private function apply( int $post_id, \WP_Post $post ): void {
        if ( ! MigrationGuard::editingAllowed() ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $existing = get_post_meta( $post_id, Identifiers::META_DATE, true );
        if ( is_numeric( $existing ) && (int) $existing !== 0 ) {
            // Fix 6: capture return and log on failure. Guard on === false so that a
            // null (e.g. Brain Monkey unit-test alias returns void/null) is treated as
            // success — only a literal false signals a DB/permissions failure.
            $result = update_post_meta( $post_id, Identifiers::META_DATE_AUTO, '0' );
            if ( false === $result ) {
                error_log( sprintf(
                    'Sermonator SermonDateNormalizer: update_post_meta failed for META_DATE_AUTO=0 on post %d',
                    $post_id
                ) );
            }
            return;
        }

        // Use the GMT publish date so the stored timestamp is the canonical UTC moment.
        // The front-end date label renders it in the site timezone, so the visible day matches
        // what the user sees for the publish date.
        $timestamp = get_post_time( 'U', true, $post_id );
        if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
            return;
        }

        // Fix 6: capture the META_DATE write return. If it fails, log and bail without
        // setting META_DATE_AUTO='1' — the auto flag must only be set when the date
        // actually persisted; otherwise the two fields would drift out of sync.
        $date_result = update_post_meta( $post_id, Identifiers::META_DATE, (string) $timestamp );
        if ( false === $date_result ) {
            error_log( sprintf(
                'Sermonator SermonDateNormalizer: update_post_meta failed for META_DATE on post %d',
                $post_id
            ) );
            return;
        }

        // Fix 6: log but don't bail on META_DATE_AUTO failure — the date itself is
        // already written, so the worst outcome is a stale auto flag, not a lost date.
        $auto_result = update_post_meta( $post_id, Identifiers::META_DATE_AUTO, '1' );
        if ( false === $auto_result ) {
            error_log( sprintf(
                'Sermonator SermonDateNormalizer: update_post_meta failed for META_DATE_AUTO=1 on post %d',
                $post_id
            ) );
        }
    }
}
