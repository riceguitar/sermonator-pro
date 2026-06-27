<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\PodcastScopeResolver;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the per-podcast feed-scope resolver against a real WP
 * post + real post meta storage (serialization round-trip).
 *
 * The scope values are written exactly as {@see \Sermonator\Migration\PodcastWriter}
 * persists them: NEW taxonomy slugs as keys, NEW term ids (scalar or list) as values,
 * inside the single META_PODCAST_SETTINGS array. This pins that the resolver reads the
 * migrated shape verbatim without re-resolving ids.
 *
 * DO NOT RUN under this branch's local toolchain: there is no Docker / wp-env here, so
 * the integration suite cannot execute. Authored per spec (TDD) for the wp-env run.
 */
final class PodcastScopeResolverTest extends WP_UnitTestCase {
    private function podcast( array $settings, array $flags = array() ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_PODCAST ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, $settings );
        if ( $flags !== array() ) {
            update_post_meta( $id, Crosswalk::MIGRATION_FLAGS, $flags );
        }
        return $id;
    }

    public function test_scoped_podcast_returns_per_taxonomy_ids(): void {
        $id = $this->podcast(
            array(
                'title'          => 'Sunday Service',
                ID::TAX_PREACHER => array( 12, 34 ),
                ID::TAX_SERIES   => 56,
            )
        );

        $scope = ( new PodcastScopeResolver() )->forPodcast( $id );

        $this->assertSame(
            array(
                ID::TAX_PREACHER => array( 12, 34 ),
                ID::TAX_SERIES   => array( 56 ),
            ),
            $scope
        );
    }

    public function test_unscoped_podcast_returns_empty(): void {
        $id = $this->podcast( array( 'title' => 'Sunday Service', 'author' => 'Pastor' ) );

        $this->assertSame( array(), ( new PodcastScopeResolver() )->forPodcast( $id ) );
    }

    public function test_podcast_with_no_settings_returns_empty(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_PODCAST ) );

        $this->assertSame( array(), ( new PodcastScopeResolver() )->forPodcast( $id ) );
    }

    public function test_only_sermon_taxonomy_keys_survive_the_intersection(): void {
        $id = $this->podcast(
            array(
                'title'       => 42,
                'apple_url'   => 7,
                ID::TAX_TOPIC => array( 99 ),
            )
        );

        $this->assertSame(
            array( ID::TAX_TOPIC => array( 99 ) ),
            ( new PodcastScopeResolver() )->forPodcast( $id )
        );
    }

    public function test_zero_valued_taxonomy_is_omitted(): void {
        $id = $this->podcast(
            array(
                ID::TAX_PREACHER => 0,
                ID::TAX_BOOK     => array( 0, 88 ),
            )
        );

        $this->assertSame(
            array( ID::TAX_BOOK => array( 88 ) ),
            ( new PodcastScopeResolver() )->forPodcast( $id )
        );
    }

    public function test_open_missing_crosswalk_flag_is_surfaced(): void {
        $id = $this->podcast(
            array( ID::TAX_PREACHER => array( 12 ) ),
            array( 'slug_changed', 'missing_podcast_term_crosswalk:777' )
        );

        $resolver = new PodcastScopeResolver();
        $this->assertTrue( $resolver->hasIncompleteScope( $id ) );
        // The scope still reads what DID resolve; the caller chooses to fall back.
        $this->assertSame(
            array( ID::TAX_PREACHER => array( 12 ) ),
            $resolver->forPodcast( $id )
        );
    }

    public function test_no_missing_crosswalk_flag_is_not_incomplete(): void {
        $id = $this->podcast(
            array( ID::TAX_PREACHER => array( 12 ) ),
            array( 'slug_changed' )
        );

        $this->assertFalse( ( new PodcastScopeResolver() )->hasIncompleteScope( $id ) );
    }
}
