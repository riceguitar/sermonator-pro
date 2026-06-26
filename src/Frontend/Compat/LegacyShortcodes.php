<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Frontend\Shortcode;
use Sermonator\Frontend\Blocks\PodcastSubscribeBlock;

/**
 * Tier A legacy-shortcode shims. Migrated pages contain [sermons] et al.; without these tags
 * WordPress prints the raw "[sermons]" text. Per the Legacy Compatibility Contract these render
 * a SAFE default plus an editor-only "needs review" notice — fail-visible, never fail-wrong.
 * Attribute-faithful output is Bundle 2.
 *
 * [list_podcasts] is the one tag with a semantically-correct Tier A surface: it delegates to the
 * existing podcast-subscribe capability (subscribe links), so it carries no review notice.
 * Every other tag renders the standard Sermonator sermon list with the notice prepended.
 */
final class LegacyShortcodes {
    /** Tags whose Tier A default is the safe sermon list (with the editor notice). */
    public const SERMON_TAGS = array(
        'sermons',
        'sermons_sm',
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
        foreach ( self::SERMON_TAGS as $tag ) {
            $this->registerTag( $tag, array( $this, 'render' ) );
        }
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
     * Safe sermon-list default for the sermon-oriented legacy tags. Prepends the editor-only
     * "needs review" notice so a migrated listing that may differ from its legacy filters is
     * never silently wrong.
     *
     * @param array<string,string>|string $atts
     */
    public function render( $atts = array(), ?string $content = null, string $tag = '' ): string {
        $list = ( new Shortcode() )->render( is_array( $atts ) ? $atts : array() );

        return self::needsReviewNotice() . $list;
    }

    /**
     * [list_podcasts] → the existing podcast-subscribe block render, so the output is
     * semantically correct (subscribe links, NOT the sermon list). No review notice: this
     * surface is faithful enough at Tier A.
     *
     * @param array<string,string>|string $atts
     */
    public function renderPodcasts( $atts = array(), ?string $content = null, string $tag = '' ): string {
        $block = new PodcastSubscribeBlock();

        return $block->render(
            is_array( $atts ) ? $atts : array(),
            '',
            new \WP_Block( array( 'blockName' => $block->name() ) )
        );
    }

    public static function needsReviewNotice(): string {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return '';
        }

        return '<p class="sermonator-compat-notice" role="note">'
            . esc_html__( 'This sermon listing was migrated from Sermon Manager and shows a default layout. Review it before relying on the original filters or order.', 'sermonator' )
            . '</p>';
    }
}
