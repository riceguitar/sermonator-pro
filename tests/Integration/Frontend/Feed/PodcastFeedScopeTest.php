<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for Bundle 2 / T8: per-podcast feed SCOPE wired into PodcastFeed.
 *
 * Requires wp-env / the WordPress test harness (WP_UnitTestCase). Do NOT run under the Brain
 * Monkey unit suite. (At authoring time wp-env was unavailable — these are written, not run.)
 *
 * This is the IRREVERSIBLE, subscriber-facing surface, so the tests pin the three behaviors the
 * spec spends its caution budget on:
 *
 *  - a SCOPED podcast feed serves ONLY the scoped sermons;
 *  - a MISSING-CROSSWALK podcast (open missing_podcast_term_crosswalk flag) serves the full
 *    UNSCOPED set — NEVER an empty dead-term feed — AND fires sermonator_feed_scope_incomplete;
 *  - THE HARD NO-REGRESSION PIN: a single unscoped podcast produces a feed BYTE-IDENTICAL to the
 *    unscoped (pre-Bundle-2) query — including when the scope keys are present-but-zeroed (the
 *    empty-scope branch must collapse to the exact unscoped query) — and fires NO scope signals.
 */
final class PodcastFeedScopeTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        unset( $_GET['podcast'] );
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        remove_all_actions( 'sermonator_feed_scope_incomplete' );
        remove_all_actions( 'sermonator_feed_unscoped_multipodcast' );
        parent::tearDown();
    }

    /** Create a published podcast, make it the default, and store its settings blob. */
    private function podcast( array $extraSettings = array() ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_title'  => 'Sunday Sermons',
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array_merge( array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
            'category'    => 'Christianity',
            'explicit'    => 'no',
        ), $extraSettings ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );
        return $id;
    }

    /** Create a published sermon with a resolvable enclosure (audio + persisted size + date). */
    private function sermon( string $title ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_DATE, '1700000000' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/' . $id . '.mp3' );
        update_post_meta( $id, ID::META_AUDIO_SIZE, '1000' );
        return $id;
    }

    private function capture(): string {
        ob_start();
        ( new PodcastFeed() )->render();
        return (string) ob_get_clean();
    }

    /**
     * (a) A podcast scoped to a SERIES term serves ONLY the sermons in that series — the other
     * published sermons are excluded, and the scope earns silence (no scope signal).
     */
    public function test_scoped_podcast_serves_only_scoped_sermons(): void {
        $series = (int) self::factory()->term->create( array(
            'taxonomy' => ID::TAX_SERIES,
            'name'     => 'Grace',
            'slug'     => 'grace',
        ) );

        $inSeries  = $this->sermon( 'InScope' );
        $offSeries = $this->sermon( 'OutOfScope' );
        wp_set_object_terms( $inSeries, array( $series ), ID::TAX_SERIES );

        $this->podcast( array( ID::TAX_SERIES => array( $series ) ) );

        $fired = false;
        add_action( 'sermonator_feed_scope_incomplete', static function () use ( &$fired ): void {
            $fired = true;
        } );

        $xml = $this->capture();

        $this->assertSame( 1, substr_count( $xml, '<item>' ), 'Scoped feed must carry only the scoped sermon.' );
        $this->assertStringContainsString( 'InScope', $xml );
        $this->assertStringNotContainsString( 'OutOfScope', $xml );
        $this->assertFalse( $fired, 'A clean scope must NOT fire the incomplete signal.' );
    }

    /**
     * (b) A podcast with an open missing_podcast_term_crosswalk flag (a scoped term did not resolve
     * at migration) must fall back to the full UNSCOPED set — NEVER an empty dead-term feed — and
     * fire sermonator_feed_scope_incomplete carrying the podcast id.
     */
    public function test_missing_crosswalk_serves_unscoped_and_fires_signal(): void {
        $series = (int) self::factory()->term->create( array(
            'taxonomy' => ID::TAX_SERIES,
            'name'     => 'Grace',
            'slug'     => 'grace',
        ) );

        // Two sermons, NEITHER in the (stale) scoped series — so a scoped query would be EMPTY.
        $this->sermon( 'First' );
        $this->sermon( 'Second' );

        // The stored scope points at a term, but the flag marks it as unresolved at migration.
        $podcastId = $this->podcast( array( ID::TAX_SERIES => array( $series ) ) );
        update_post_meta( $podcastId, Crosswalk::MIGRATION_FLAGS, array(
            'slug_changed',
            Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . $series,
        ) );

        $fired = array();
        add_action( 'sermonator_feed_scope_incomplete', static function ( $id ) use ( &$fired ): void {
            $fired[] = $id;
        } );

        $xml = $this->capture();

        $this->assertSame( 2, substr_count( $xml, '<item>' ), 'Incomplete scope must NEVER empty a live feed.' );
        $this->assertStringContainsString( 'First', $xml );
        $this->assertStringContainsString( 'Second', $xml );
        $this->assertSame( array( $podcastId ), $fired, 'The fail-visible signal must fire with the podcast id.' );
    }

    /**
     * THE HARD NO-REGRESSION PIN. A single unscoped podcast must produce a feed BYTE-IDENTICAL to
     * the unscoped (pre-Bundle-2) behavior, and an empty/zeroed scope key must collapse to that
     * EXACT same feed — proving the (d) empty-scope branch is the provably-unchanged query — while
     * firing NO scope signals.
     */
    public function test_single_unscoped_podcast_feed_is_byte_identical_and_silent(): void {
        $podcastId = $this->podcast();
        $this->sermon( 'First' );
        $this->sermon( 'Second' );

        $overIncluded = false;
        $incomplete   = false;
        add_action( 'sermonator_feed_unscoped_multipodcast', static function () use ( &$overIncluded ): void {
            $overIncluded = true;
        } );
        add_action( 'sermonator_feed_scope_incomplete', static function () use ( &$incomplete ): void {
            $incomplete = true;
        } );

        // Baseline: identity-only settings (no taxonomy scope keys at all).
        $baseline = $this->capture();
        $this->assertSame( 2, substr_count( $baseline, '<item>' ), 'Unscoped feed carries every sermon.' );

        // A present-but-zeroed taxonomy key is "not scoped" — the resolver drops it, so the feed
        // must be byte-for-byte identical to the no-key baseline (the empty-scope == unscoped pin).
        update_post_meta( $podcastId, ID::META_PODCAST_SETTINGS, array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
            'category'    => 'Christianity',
            'explicit'    => 'no',
            ID::TAX_SERIES => 0,
            ID::TAX_TOPIC  => array( 0, '' ),
        ) );
        $zeroedScope = $this->capture();

        $this->assertSame( $baseline, $zeroedScope, 'A zeroed/empty scope must not change a single byte of the feed.' );
        $this->assertFalse( $overIncluded, 'Single-podcast site must not fire the over-inclusion signal.' );
        $this->assertFalse( $incomplete, 'Unscoped podcast must not fire the incomplete signal.' );
    }
}
