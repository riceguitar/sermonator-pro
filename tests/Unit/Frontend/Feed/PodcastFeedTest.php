<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionMethod;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\Feed\PodcastScopeResolver;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for Bundle 2 / T8: the per-podcast feed SCOPE DECISION wired into PodcastFeed.
 *
 * The full render() runs through SermonQuery → WP_Query → FeedBuilder, which the integration suite
 * pins end-to-end (serves-only-scoped, never-empty fallback, byte-identical no-regression). The
 * never-fail-WRONG DECISION itself — which scope is applied and which fail-visible signal fires —
 * lives in PodcastFeed::feedScope(), which touches only the resolver (get_post_meta) and do_action.
 * These tests exercise that method directly (via reflection, since it is a private feed-internal
 * seam) with the resolver's meta reads and the action stubbed, so they are immune to the shared
 * eval'd WP_Query other unit files install in the same process.
 */
final class PodcastFeedTest extends TestCase {
    /** @var list<array{hook:string,args:array}> */
    private array $actions = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->actions = array();

        Functions\when( 'do_action' )->alias(
            function ( $hook, ...$args ): void {
                $this->actions[] = array( 'hook' => (string) $hook, 'args' => $args );
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_post_meta() so PodcastScopeResolver reads the given settings blob (scope) and flags
     * row (the never-serve-empty signal source).
     *
     * @param mixed $settings META_PODCAST_SETTINGS blob.
     * @param mixed $flags    MIGRATION_FLAGS row.
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

    /** Invoke the private decision seam PodcastFeed::feedScope() for the given podcast id. */
    private function feedScope( int $podcastId ): array {
        $method = new ReflectionMethod( PodcastFeed::class, 'feedScope' );
        $method->setAccessible( true );
        return $method->invoke( new PodcastFeed(), $podcastId, new PodcastScopeResolver() );
    }

    private function firedHooks(): array {
        return array_column( $this->actions, 'hook' );
    }

    // ---- (a) clean, non-empty scope → applied, EARNED silence --------------------------------

    public function test_clean_scope_is_returned_for_the_tax_query_and_fires_no_signal(): void {
        $this->stubMeta(
            array(
                'title'          => 'Sunday Service', // identity key — excluded from scope
                ID::TAX_SERIES   => array( 56 ),
                ID::TAX_PREACHER => array( 12, 34 ),
            )
        );

        $scope = $this->feedScope( 100 );

        $this->assertSame(
            array(
                ID::TAX_PREACHER => array( 12, 34 ),
                ID::TAX_SERIES   => array( 56 ),
            ),
            $scope,
            'A clean scope must be passed straight through to SermonQuery\'s taxonomies arg.'
        );
        $this->assertSame( array(), $this->firedHooks(), 'A clean scope is EARNED silence — no signal.' );
    }

    // ---- (b) open missing-crosswalk flag → UNSCOPED + signal, NEVER a dead-term scope --------

    public function test_missing_crosswalk_returns_unscoped_and_fires_incomplete_signal(): void {
        // The podcast HAS scope ids stored, but an open missing_podcast_term_crosswalk flag means a
        // scoped term did not resolve at migration. Serving the stored (possibly dead) ids would
        // silently EMPTY a live feed, so feedScope() MUST discard them and signal.
        $this->stubMeta(
            array( ID::TAX_PREACHER => array( 12 ) ),
            array( 'slug_changed', Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . '777' )
        );

        $scope = $this->feedScope( 100 );

        $this->assertSame( array(), $scope, 'Incomplete-crosswalk podcast must NEVER serve a scoped feed.' );
        $this->assertContains( 'sermonator_feed_scope_incomplete', $this->firedHooks() );

        $incomplete = array_values( array_filter(
            $this->actions,
            static fn( $a ) => $a['hook'] === 'sermonator_feed_scope_incomplete'
        ) );
        $this->assertSame( array( 100 ), $incomplete[0]['args'], 'The signal must carry the podcast id.' );
    }

    // ---- (d) empty scope → today's EXACT unscoped query, silent ------------------------------

    public function test_identity_only_settings_return_unscoped_and_silent(): void {
        $this->stubMeta( array( 'title' => 'Sunday Service', 'author' => 'Pastor' ) );

        $this->assertSame( array(), $this->feedScope( 100 ) );
        $this->assertSame( array(), $this->firedHooks(), 'Unscoped (empty) scope fires no signal.' );
    }

    public function test_absent_settings_return_unscoped_and_silent(): void {
        // A never-configured podcast (no settings blob) is the common unscoped case.
        $this->stubMeta( '' );

        $this->assertSame( array(), $this->feedScope( 100 ) );
        $this->assertSame( array(), $this->firedHooks() );
    }

    public function test_zeroed_scope_keys_collapse_to_unscoped(): void {
        // A present-but-zeroed taxonomy key means "not scoped" — it must NOT masquerade as a real
        // scope (which would produce an empty IN()), so feedScope() returns the unscoped [].
        $this->stubMeta(
            array(
                ID::TAX_SERIES => 0,
                ID::TAX_TOPIC  => array( 0, '' ),
            )
        );

        $this->assertSame( array(), $this->feedScope( 100 ) );
        $this->assertSame( array(), $this->firedHooks() );
    }
}
