<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Frontend\Blocks\AbstractBlock;
use Sermonator\Frontend\Blocks\LatestSeriesBlock;
use Sermonator\Frontend\Blocks\SermonImagesBlock;
use Sermonator\Frontend\Blocks\TaxonomyFilterBlock;
use Sermonator\Frontend\Shortcode;
use Sermonator\Frontend\Blocks\PodcastSubscribeBlock;

/**
 * Legacy-shortcode shims. Migrated pages contain [sermons] et al.; without these tags
 * WordPress prints the raw "[sermons]" text.
 *
 * Tiers (per the Legacy Compatibility Contract):
 *  - [sermons]/[sermons_sm] — Tier A safe sermon list + the generic editor "needs review"
 *    notice. Attribute-faithful output is Bundle 2; unchanged here.
 *  - [list_sermons]/[latest_series]/[sermon_images] — now delegate to the FAITHFUL Bundle 4
 *    display blocks (term list / latest-series card / term-image grid) instead of the
 *    wrong-type safe sermon list. A reworded PER-TAG review notice is KEPT and prepended:
 *    the block semantics ("latest" = most-recently-preached series, the legacy→new taxonomy
 *    mapping, the empty-data fallback) are unvalidated against the absent Sermon Manager
 *    source, so the surface must stay fail-visible per the Contract's binding Tier B rows.
 *  - [list_podcasts] — delegates to the podcast-subscribe block (semantically correct
 *    subscribe links); no review notice.
 */
final class LegacyShortcodes {
    /** Bundle 2 tags whose Tier A default is the generic safe sermon list (with the notice). */
    public const GENERIC_SERMON_TAGS = array(
        'sermons',
        'sermons_sm',
    );

    /** Tags upgraded to a faithful Bundle 4 block render (each keeps a per-tag review notice). */
    public const FAITHFUL_TAGS = array(
        'list_sermons',
        'latest_series',
        'sermon_images',
    );

    /** The tag that maps onto the podcast-subscribe capability instead of the sermon list. */
    public const PODCAST_TAG = 'list_podcasts';

    /** All legacy tags this shim registers. */
    public const TAGS = array(
        'sermons',
        'sermons_sm',
        'list_sermons',
        'latest_series',
        'sermon_images',
        'list_podcasts',
    );

    public function hook(): void {
        add_action( 'init', array( $this, 'register' ) );
    }

    public function register(): void {
        foreach ( self::GENERIC_SERMON_TAGS as $tag ) {
            $this->registerTag( $tag, array( $this, 'render' ) );
        }
        $this->registerTag( 'list_sermons', array( $this, 'renderListSermons' ) );
        $this->registerTag( 'latest_series', array( $this, 'renderLatestSeries' ) );
        $this->registerTag( 'sermon_images', array( $this, 'renderSermonImages' ) );
        $this->registerTag( self::PODCAST_TAG, array( $this, 'renderPodcasts' ) );
    }

    /**
     * Register a shim ONLY when the tag is not already taken. During the migration
     * coexistence / rollback window the legacy Sermon Manager plugin stays active (its data
     * is byte-immutable until Finalize) and still owns these GLOBAL shortcode tags. Both
     * plugins register on init@10 and add_shortcode is last-writer-wins, so an unguarded
     * shim could clobber SM's live, attribute-faithful [sermons]/[list_podcasts] and show
     * visitors a silently-different sermon set — the exact fail-wrong the Contract forbids.
     * With this guard SM always wins while active (the shim skips, or SM's later registration
     * overrides); once SM is deactivated the tag is free and the shim registers next request.
     *
     * @param callable $callback
     */
    private function registerTag( string $tag, $callback ): void {
        if ( shortcode_exists( $tag ) ) {
            return;
        }
        add_shortcode( $tag, $callback );
    }

    /**
     * Generic safe sermon-list default for [sermons]/[sermons_sm] (Bundle 2). Prepends the
     * generic editor-only "needs review" notice so a migrated listing that may differ from its
     * legacy filters is never silently wrong.
     *
     * @param array<string,string>|string $atts
     */
    public function render( $atts = array(), ?string $content = null, string $tag = '' ): string {
        $list = ( new Shortcode() )->render( is_array( $atts ) ? $atts : array() );

        return self::needsReviewNotice() . $list;
    }

    /**
     * [list_sermons] → the faithful taxonomy term-list block. The legacy→new taxonomy mapping
     * is unvalidated (the block defaults to the Series taxonomy), so the per-tag notice is kept.
     *
     * @param array<string,string>|string $atts
     */
    public function renderListSermons( $atts = array(), ?string $content = null, string $tag = '' ): string {
        return self::listSermonsNotice() . $this->renderBlock( new TaxonomyFilterBlock(), $atts );
    }

    /**
     * [latest_series] → the faithful latest-series card block. "Latest" is resolved as the
     * most-recently-preached sermon's series (optionally scoped by the serviceType attribute) —
     * provisional semantics unvalidated against the absent SM source, so the notice is kept.
     *
     * @param array<string,string>|string $atts
     */
    public function renderLatestSeries( $atts = array(), ?string $content = null, string $tag = '' ): string {
        return self::latestSeriesNotice() . $this->renderBlock( new LatestSeriesBlock(), $atts );
    }

    /**
     * [sermon_images] → the faithful term-image grid block. The block keys OPTION_TERM_IMAGES
     * strictly by term_taxonomy_id and falls back to a safe sermon list when no artwork
     * resolves; the per-tag notice flags that empty-data fallback.
     *
     * @param array<string,string>|string $atts
     */
    public function renderSermonImages( $atts = array(), ?string $content = null, string $tag = '' ): string {
        return self::sermonImagesNotice() . $this->renderBlock( new SermonImagesBlock(), $atts );
    }

    /**
     * [list_podcasts] → the existing podcast-subscribe block render, so the output is
     * semantically correct (subscribe links, NOT the sermon list). No review notice: this
     * surface is faithful enough at Tier A.
     *
     * @param array<string,string>|string $atts
     */
    public function renderPodcasts( $atts = array(), ?string $content = null, string $tag = '' ): string {
        return $this->renderBlock( new PodcastSubscribeBlock(), $atts );
    }

    /**
     * Render a Bundle 4 display block from a legacy shortcode's attributes. Mirrors the block's
     * own server-render path (attributes + empty content + a minimal WP_Block carrying the
     * block name) so the shim output is byte-identical to the placed block.
     *
     * @param array<string,string>|string $atts
     */
    private function renderBlock( AbstractBlock $block, $atts ): string {
        return $block->render(
            is_array( $atts ) ? $atts : array(),
            '',
            new \WP_Block( array( 'blockName' => $block->name() ) )
        );
    }

    /**
     * Generic editor-only review notice for the Bundle 2 sermon-list tags (and the
     * SermonImagesBlock safe-list fallback). Empty for visitors.
     */
    public static function needsReviewNotice(): string {
        return self::wrapNotice(
            esc_html__( 'This sermon listing was migrated from Sermon Manager and shows a default layout. Review it before relying on the original filters or order.', 'sermonator' )
        );
    }

    /** Per-tag review notice for [list_sermons] — the legacy→new taxonomy mapping is unvalidated. */
    public static function listSermonsNotice(): string {
        return self::wrapNotice(
            esc_html__( 'This term list was migrated from a Sermon Manager list_sermons shortcode and defaults to the Sermon Series taxonomy. Confirm it targets the taxonomy the original shortcode used before relying on it.', 'sermonator' )
        );
    }

    /** Per-tag review notice for [latest_series] — provisional "latest" + serviceType semantics. */
    public static function latestSeriesNotice(): string {
        return self::wrapNotice(
            esc_html__( 'This "latest series" was migrated from a Sermon Manager latest_series shortcode and is resolved as the most-recently-preached sermon\'s series (optionally scoped by service type). Confirm it matches the series the original shortcode highlighted.', 'sermonator' )
        );
    }

    /** Per-tag review notice for [sermon_images] — empty-data falls back to a default listing. */
    public static function sermonImagesNotice(): string {
        return self::wrapNotice(
            esc_html__( 'This series-image grid was migrated from a Sermon Manager sermon_images shortcode. Review it before relying on the original artwork; when no migrated images are found it falls back to a default sermon listing.', 'sermonator' )
        );
    }

    /**
     * Wrap an already-escaped/translated message in the editor-only compat-notice element.
     * Returns '' for non-editors so visitors never see the notice (fail-visible to editors
     * only — the load-bearing Contract rule).
     */
    private static function wrapNotice( string $escapedMessage ): string {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return '';
        }

        return '<p class="sermonator-compat-notice" role="note">' . $escapedMessage . '</p>';
    }
}
