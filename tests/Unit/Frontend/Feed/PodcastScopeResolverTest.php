<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Feed\PodcastScopeResolver;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the read-side per-podcast feed-scope resolver.
 *
 * The resolver only ever calls get_post_meta(): the settings blob (for the scope)
 * and the migration flags row (for the never-serve-empty signal). Both are stubbed.
 */
final class PodcastScopeResolverTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_post_meta( $id, $key, true ) to return the given settings blob for
     * the META_PODCAST_SETTINGS key and the given flags for the MIGRATION_FLAGS key.
     *
     * @param mixed $settings
     * @param mixed $flags
     */
    private function stubMeta( $settings, $flags = '' ): void {
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single = false ) use ( $settings, $flags ) {
                if ( $key === ID::META_PODCAST_SETTINGS ) {
                    return $settings;
                }
                if ( $key === Crosswalk::MIGRATION_FLAGS ) {
                    return $flags;
                }
                return $single ? '' : array();
            }
        );
    }

    public function test_scoped_podcast_returns_taxonomy_to_ids_map(): void {
        $this->stubMeta(
            array(
                'title'              => 'Sunday Service',
                ID::TAX_PREACHER     => array( 12, 34 ),
                ID::TAX_SERIES       => 56, // scalar id
            )
        );

        $scope = ( new PodcastScopeResolver() )->forPodcast( 100 );

        $this->assertSame(
            array(
                ID::TAX_PREACHER => array( 12, 34 ),
                ID::TAX_SERIES   => array( 56 ),
            ),
            $scope
        );
    }

    public function test_unscoped_podcast_returns_empty_array(): void {
        $this->stubMeta(
            array(
                'title'  => 'Sunday Service',
                'author' => 'Pastor',
            )
        );

        $this->assertSame( array(), ( new PodcastScopeResolver() )->forPodcast( 100 ) );
    }

    public function test_absent_or_corrupt_settings_returns_empty_array(): void {
        $this->stubMeta( '' ); // never set

        $this->assertSame( array(), ( new PodcastScopeResolver() )->forPodcast( 100 ) );
    }

    public function test_only_sermon_taxonomy_keys_are_included(): void {
        // A stray identity key whose name is NOT a sermon taxonomy must never reach
        // the tax_query, even if it happens to hold a numeric value.
        $this->stubMeta(
            array(
                'title'          => 42,            // identity key — excluded
                'apple_url'      => 7,             // identity key — excluded
                ID::TAX_TOPIC    => array( 99 ),   // real scope
            )
        );

        $this->assertSame(
            array( ID::TAX_TOPIC => array( 99 ) ),
            ( new PodcastScopeResolver() )->forPodcast( 100 )
        );
    }

    public function test_zero_and_nonnumeric_values_are_dropped_and_taxonomy_omitted(): void {
        // A taxonomy key present but holding only 0/empty/junk means "not scoped"
        // → the taxonomy is omitted entirely (never an empty IN list).
        $this->stubMeta(
            array(
                ID::TAX_PREACHER     => 0,
                ID::TAX_SERIES       => array( 0, '', 'abc' ),
                ID::TAX_BOOK         => array( 0, 88 ), // mixed: keep the real id only
            )
        );

        $this->assertSame(
            array( ID::TAX_BOOK => array( 88 ) ),
            ( new PodcastScopeResolver() )->forPodcast( 100 )
        );
    }

    public function test_string_numeric_ids_are_coerced_and_deduped(): void {
        $this->stubMeta(
            array(
                ID::TAX_SERVICE_TYPE => array( '5', 5, '6' ),
            )
        );

        $this->assertSame(
            array( ID::TAX_SERVICE_TYPE => array( 5, 6 ) ),
            ( new PodcastScopeResolver() )->forPodcast( 100 )
        );
    }

    public function test_incomplete_scope_flag_is_surfaced(): void {
        $this->stubMeta(
            array( ID::TAX_PREACHER => array( 12 ) ),
            array( 'slug_changed', 'missing_podcast_term_crosswalk:777' )
        );

        $this->assertTrue( ( new PodcastScopeResolver() )->hasIncompleteScope( 100 ) );
    }

    public function test_no_missing_crosswalk_flag_is_not_incomplete(): void {
        $this->stubMeta(
            array( ID::TAX_PREACHER => array( 12 ) ),
            array( 'slug_changed' )
        );

        $this->assertFalse( ( new PodcastScopeResolver() )->hasIncompleteScope( 100 ) );
    }

    public function test_no_flags_row_is_not_incomplete(): void {
        $this->stubMeta( array( ID::TAX_PREACHER => array( 12 ) ), '' );

        $this->assertFalse( ( new PodcastScopeResolver() )->hasIncompleteScope( 100 ) );
    }

    /**
     * Contract pin: the dead-term flag prefix is the SOLE cross-subsystem token
     * linking the writer's STAMP to the resolver's MATCH. If this value ever
     * drifts, a feed scoped to an unresolved/legacy term would be served to live
     * subscribers with no observable signal — the irreversible failure the
     * invariant exists to prevent. Both sides reference this one constant, so this
     * test fails (not a live feed) if the token is edited.
     */
    public function test_missing_term_flag_prefix_contract_token_is_stable(): void {
        $this->assertSame(
            'missing_podcast_term_crosswalk:',
            Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX
        );
    }

    /**
     * Round-trip: a flag built EXACTLY as {@see \Sermonator\Migration\PodcastWriter}
     * emits it (the shared prefix constant + a legacy term id) MUST be recognized by
     * the resolver's matcher. This is the regression guard against the write side and
     * the read side silently disagreeing on the flag format.
     */
    public function test_resolver_matches_the_flag_format_the_writer_emits(): void {
        $writerEmittedFlag = Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . 777;

        $this->stubMeta(
            array( ID::TAX_PREACHER => array( 12 ) ),
            array( 'slug_changed', $writerEmittedFlag )
        );

        $this->assertTrue( ( new PodcastScopeResolver() )->hasIncompleteScope( 100 ) );
    }
}
