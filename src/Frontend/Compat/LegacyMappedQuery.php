<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Frontend\DateScope;

/**
 * Immutable result of mapping a legacy `[sermons]`/`[sermons_sm]` shortcode's RAW
 * attributes onto the new {@see \Sermonator\Frontend\SermonQuery} engine
 * (Bundle 2, T4 — produced by {@see LegacyAttributeMapper}).
 *
 * The binding Contract rule is fail-visible / never-fail-WRONG: every axis that the
 * mapper could faithfully reproduce is applied; every axis that cannot be validated
 * against the now-absent Sermon Manager source (or is deferred) is named in
 * {@see self::$unfaithfulAttrs}. {@see LegacyShortcodes::render()} (T6) names ONLY
 * the present members of that set in the editor notice — an empty set means the
 * notice is earned-off and not shown.
 *
 * Structure (the spec's `{gridArgs, dateScope, orderby, postIn, postNotIn,
 * unfaithfulAttrs}`):
 *  - {@see self::$gridArgs} — `{perPage, order, taxonomies, dateRange}`, the
 *    non-date-mode SermonQuery args.
 *  - {@see self::$dateScope} — the {@see DateScope} MODE (never a fork of the native
 *    LEFT-JOIN branch): PREACHED reproduces legacy `display_sermons()` (drop future +
 *    dateless); NONE for non-preached orderings (published/id/title/…).
 *  - {@see self::$orderby} — the already-resolved orderby token for SermonQuery
 *    (`date` is resolved UPSTREAM here against the migrated archive orderby option,
 *    never passed through raw).
 *  - {@see self::$postIn}/{@see self::$postNotIn} — crosswalk-RESOLVED new post ids
 *    (legacy ids that did not resolve are dropped, never passed through as new ids).
 *  - {@see self::$disablePagination} — true when the legacy `disable_pagination`
 *    axis (aliases `hide_nav`/`hide_pagination`) is set. HONORED by
 *    {@see LegacyShortcodes::render()} (the pager landed in T5): a truthy value
 *    renders the non-paginated {@see \Sermonator\Frontend\Renderer::grid()}
 *    (first page only, no pager) exactly as legacy `display_sermons()` :1129 hid
 *    the pager — faithful when honored, so it is NOT named.
 *  - {@see self::$unfaithfulAttrs} — the precise per-attribute notice set.
 *
 * The `page` arg is intentionally NOT set here: the embedded current page is read
 * from the registered `sermon_page` query var by the pager (T5), NOT from this VO.
 */
final class LegacyMappedQuery {
    /**
     * @param array{perPage:int,order:string,taxonomies:array<string,list<int|string>>,dateRange:array<string,mixed>} $gridArgs
     * @param list<int>    $postIn
     * @param list<int>    $postNotIn
     * @param list<string> $unfaithfulAttrs
     */
    public function __construct(
        public readonly array $gridArgs,
        public readonly DateScope $dateScope,
        public readonly string $orderby,
        public readonly array $postIn,
        public readonly array $postNotIn,
        public readonly bool $disablePagination,
        public readonly array $unfaithfulAttrs
    ) {}

    /**
     * Merge the discrete fields into a single {@see \Sermonator\Frontend\SermonQuery::run()}
     * / `buildQueryArgs()` args array, so the T6 shortcode renderer has one source of
     * truth and cannot drift from this VO. Absent axes are omitted entirely (the engine
     * applies its own defaults / the INCLUSIVE-vs-PREACHED date pin). The `page` is left
     * for the caller to add from the registered `sermon_page` var.
     *
     * @return array<string,mixed>
     */
    public function toSermonQueryArgs(): array {
        $args = array(
            'perPage'   => $this->gridArgs['perPage'],
            'order'     => $this->gridArgs['order'],
            'orderby'   => $this->orderby,
            'dateScope' => $this->dateScope,
        );

        if ( ! empty( $this->gridArgs['dateRange'] ) ) {
            $args['dateRange'] = $this->gridArgs['dateRange'];
        }
        if ( ! empty( $this->gridArgs['taxonomies'] ) ) {
            $args['taxonomies'] = $this->gridArgs['taxonomies'];
        }
        if ( $this->postIn !== array() ) {
            $args['postIn'] = $this->postIn;
        }
        if ( $this->postNotIn !== array() ) {
            $args['postNotIn'] = $this->postNotIn;
        }

        return $args;
    }
}
