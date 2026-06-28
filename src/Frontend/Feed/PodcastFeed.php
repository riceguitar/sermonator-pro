<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

/**
 * Registers and renders the Apple-Podcasts-compatible RSS feed at
 * /feed/sermonator-podcast/ (or ?feed=sermonator-podcast), for the default podcast or a
 * specific one via ?podcast=ID. Read-only: reads persisted enclosure sizes and never makes
 * a network call at render time. Episodes whose audio size is unknown are omitted (Apple
 * requires a correct enclosure length); the AudioBackfillCommand reports/fixes those.
 */
final class PodcastFeed {
    public const FEED = 'sermonator-podcast';

    /** Max episodes per feed (Apple/most clients read ~300; keep the query bounded). */
    private const MAX_ITEMS = 300;

    public function hook(): void {
        add_action( 'init', array( $this, 'register' ) );
    }

    public function register(): void {
        add_feed( self::FEED, array( $this, 'render' ) );
        // Belt-and-suspenders: if anything routes through feed_content_type() for our feed,
        // return RSS rather than the WP default of application/octet-stream.
        add_filter( 'feed_content_type', array( $this, 'contentType' ), 10, 2 );
        // A request for OUR feed is always valid (it renders a well-formed channel even
        // with zero episodes), so short-circuit WP::handle_404() — which has no feed
        // exemption and would otherwise status_header(404) when the main query matches
        // no posts. This is what keeps the routed legacy URL on HTTP 200.
        add_filter( 'pre_handle_404', array( $this, 'preventFeed404' ), 10, 2 );
    }

    public function contentType( string $type, string $feed ): string {
        return $feed === self::FEED ? 'application/rss+xml' : $type;
    }

    /**
     * Short-circuit WP::handle_404() for our feed so the request never 404s.
     *
     * @param bool      $preempt  Whether to short-circuit the 404 handling.
     * @param \WP_Query $wpQuery  The query being dispatched.
     */
    public function preventFeed404( $preempt, $wpQuery ) {
        if ( $wpQuery instanceof \WP_Query && self::FEED === $wpQuery->get( 'feed' ) ) {
            return true;
        }
        return $preempt;
    }

    public function render(): void {
        // Send the content type as the very first action so it is emitted before any body
        // output (otherwise nginx falls back to application/octet-stream).
        $charset = (string) get_option( 'blog_charset' ) ?: 'UTF-8';
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/rss+xml; charset=' . $charset, true );
        }

        $feedUrl   = $this->feedUrl();
        $podcastId = $this->resolvePodcast();
        $factory   = new PodcastConfigFactory();

        if ( $podcastId <= 0 ) {
            // SM-Free option-podcast continuity. A migrated Sermon Manager FREE site has NO podcast
            // post — its podcast is option-based and its single iTunes feed served ALL sermons. That
            // feed URL (?feed=rss2&post_type=wpfc_sermon) is routed here by LegacyFeedRouter; serving
            // an empty channel would silently kill live Apple/Spotify subscriptions. So when the
            // migrated SM-Free podcast options are present AND there is NO podcast post at all (the
            // true SM-Free shape), build the channel from those options and serve the full sermon set
            // (no scope) — the same MAX_ITEMS + GUID-continuity path as a real podcast. The
            // no-podcast-post guard is load-bearing: on an SM-PRO site the same global options exist
            // as per-podcast fallbacks, so without it a missing/unresolved default podcast would
            // wrongly flip the base feed to an all-sermons channel with the global identity. A fresh
            // sermonator site (no option-podcast) still serves a valid empty channel.
            $serveOptionPodcast = $factory->hasOptionPodcast() && $this->publishedPodcastCount() === 0;
            $config = $serveOptionPodcast ? $factory->fromOptions( $feedUrl ) : $factory->emptyConfig( $feedUrl );
            $items  = $serveOptionPodcast ? $this->items() : array();
            echo ( new FeedBuilder() )->build( $config, $items ); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $config = $factory->fromPost( $podcastId, $feedUrl );

        // Audio/video `sermons_to_show` MODE fail-visible (spec §2.10, T9). The feed serves audio-only
        // — today's behavior, KEPT. Legacy SM Pro also supported video / *_priority modes whose feed
        // faithfulness is a RECORDED §63 deferral (unbuilt). For any podcast requesting a non-audio
        // mode, fire an observable signal and KEEP (never retire) that podcast's per-feed review
        // notice — the audio-only set still serves, but the divergence is fail-visible, not silent.
        $this->signalUnsupportedMode( $podcastId, new PodcastModeResolver() );

        // Per-podcast feed SCOPE (spec §2.8, the bundle's never-fail-WRONG budget). The resolved
        // scope is fed to SermonQuery via its EXISTING `taxonomies` arg (buildTaxQuery: relation=AND
        // across taxonomies, IN within — byte-identical to Pro's filter_the_query). This is the
        // IRREVERSIBLE, subscriber-facing surface: a wrong scope floods new GUIDs or vanishes
        // episodes for live Apple/Spotify subscribers, so the fallbacks below are deliberate.
        $resolver = new PodcastScopeResolver();
        $scope    = $this->feedScope( $podcastId, $resolver );

        $items = $this->items( $scope );

        // (c) Empty EFFECTIVE scope on a MULTI-podcast site: the feed carries THIS podcast's channel
        // identity but the full site-wide sermon set (over-inclusion). Keep the existing fail-visible
        // signal — observable to the admin/migration report, never silently-different content. NOT
        // fired when a clean scope was applied (a, earned silence) and NOT layered on the
        // incomplete-crosswalk signal (b, which feedScope() already fired) — the two are exclusive.
        if ( $scope === array()
            && ! $resolver->hasIncompleteScope( $podcastId )
            && $this->publishedPodcastCount() > 1 ) {
            do_action( 'sermonator_feed_unscoped_multipodcast', $podcastId, count( $items ) );
        }

        echo ( new FeedBuilder() )->build( $config, $items ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Apply the spec §2.8 never-fail-WRONG decision table for ONE podcast and return the SermonQuery
     * `taxonomies` scope to use — `[]` meaning "today's EXACT unscoped query". The fail-visible
     * incomplete signal is fired here as a side effect. Decoupled from render() so this irreversible,
     * subscriber-facing decision is unit-testable WITHOUT the WP_Query/feed-render stack.
     *
     *  - (b) an open `missing_podcast_term_crosswalk` flag (Pro HAD scope but a scoped term did not
     *    resolve at migration) → NEVER serve that dead/unresolved-term scope (it would silently EMPTY
     *    a live Apple/Spotify subscription). Fall back to UNSCOPED and fire
     *    `sermonator_feed_scope_incomplete`.
     *  - (a) a clean, non-empty scope → apply it, EARNED silence (no signal).
     *  - (d) an empty scope → `[]`; items() omits the taxonomies arg entirely, so the common
     *    single-podcast case is byte-for-byte the pre-Bundle-2 unscoped query.
     *
     * @return array<string,list<int>>
     */
    private function feedScope( int $podcastId, PodcastScopeResolver $resolver ): array {
        if ( $resolver->hasIncompleteScope( $podcastId ) ) {
            do_action( 'sermonator_feed_scope_incomplete', $podcastId );
            return array();
        }
        return $resolver->forPodcast( $podcastId );
    }

    /**
     * Fire the fail-visible `sermonator_feed_mode_unsupported` signal when a podcast requests a
     * non-audio `sermons_to_show` mode (spec §2.10, T9). Decoupled from render() so this §63-deferral
     * decision is unit-testable WITHOUT the WP_Query/feed-render stack — mirroring feedScope().
     *
     * Audio-only / unset mode = faithful = NO signal (the feed already serves audio-only). A non-audio
     * mode is a RECORDED §63 deferral whose feed faithfulness is unbuilt: fire the signal carrying the
     * requested mode so the divergence is observable, and the podcast's per-feed review notice is KEPT
     * (NOT retired) — never serving the wrong item-set as if faithful.
     */
    private function signalUnsupportedMode( int $podcastId, PodcastModeResolver $resolver ): void {
        $mode = $resolver->unsupportedMode( $podcastId );
        if ( $mode !== null ) {
            do_action( 'sermonator_feed_mode_unsupported', $podcastId, $mode );
        }
    }

    /** Number of published podcasts (capped at 2 — callers only need to know if >1). */
    private function publishedPodcastCount(): int {
        $ids = get_posts( array(
            'post_type'      => ID::POST_TYPE_PODCAST,
            'post_status'    => 'publish',
            'posts_per_page' => 2,
            'fields'         => 'ids',
        ) );
        return count( $ids );
    }

    /**
     * @param array<string,list<int>> $scope Per-taxonomy NEW-term-id scope from
     *        {@see PodcastScopeResolver::forPodcast()}. An EMPTY scope ([]) means today's EXACT
     *        UNSCOPED query: the `taxonomies` arg is OMITTED ENTIRELY (not passed as []) so the
     *        single-podcast default path is byte-for-byte identical to the pre-Bundle-2 query.
     * @return list<FeedItem>
     */
    private function items( array $scope = array() ): array {
        // Pin page 1 EXPLICITLY. The feed never paginates — it caps at MAX_ITEMS and fires
        // sermonator_feed_truncated for the tail. SermonQuery::run() defaults a missing `page`
        // to the registered, PUBLIC `sermon_page` query var (the embedded-list read-path pin),
        // so omitting it here would let a stray/hostile `?sermon_page=N` on the feed URL silently
        // serve a DIFFERENT episode set — page 2 of a <=300-sermon site is empty (offset past the
        // data) and larger archives would serve 301-600 instead of the latest 300. That is exactly
        // the never-fail-WRONG the contract forbids on this flagship read-only surface, so the feed
        // is isolated from the request's pagination var.
        $queryArgs = array( 'perPage' => self::MAX_ITEMS, 'page' => 1 );
        if ( $scope !== array() ) {
            $queryArgs['taxonomies'] = $scope;
        }
        $result   = ( new SermonQuery() )->run( $queryArgs );
        $resolver = new EnclosureResolver();
        $guids    = new LegacyEpisodeGuid();

        if ( $result->total > self::MAX_ITEMS ) {
            // Observable, not silent: older episodes beyond the cap are not in the feed.
            do_action( 'sermonator_feed_truncated', $result->total, self::MAX_ITEMS );
        }

        $items = array();
        foreach ( $result->sermons as $view ) {
            $enc = $resolver->resolve( $view->id );
            if ( $enc === null ) {
                continue; // No audio or unknown size — omitted (see AudioBackfillCommand).
            }
            $items[] = new FeedItem(
                title:        $view->title,
                link:         $view->permalink,
                guid:         $guids->resolve( $view->id ),
                description:  $this->description( $view->id ),
                pubTimestamp: $view->preachedTimestamp ?? (int) get_post_time( 'U', true, $view->id ),
                audioUrl:     $enc['url'],
                audioType:    $enc['type'],
                audioSize:    $enc['size'],
                duration:     $enc['duration'],
                explicit:     false
            );
        }
        return $items;
    }

    private function description( int $sermonId ): string {
        $excerpt = (string) get_the_excerpt( $sermonId );
        if ( $excerpt !== '' ) {
            return $excerpt;
        }
        return wp_trim_words( (string) wp_strip_all_tags( (string) get_post_field( 'post_content', $sermonId ) ), 60 );
    }

    private function resolvePodcast(): int {
        // Validate an explicit ?podcast=ID is a published podcast before trusting it.
        if ( isset( $_GET['podcast'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $candidate = (int) $_GET['podcast']; // phpcs:ignore WordPress.Security.NonceVerification
            if ( $candidate > 0
                && get_post_type( $candidate ) === ID::POST_TYPE_PODCAST
                && get_post_status( $candidate ) === 'publish' ) {
                return $candidate;
            }
        }
        return (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );
    }

    private function feedUrl(): string {
        $url = get_feed_link( self::FEED );
        return is_string( $url ) ? $url : home_url( '/feed/' . self::FEED . '/' );
    }
}
