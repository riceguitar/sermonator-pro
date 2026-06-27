<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Frontend\DateScope;
use Sermonator\Migration\LegacyIdentifiers as Legacy;
use Sermonator\Schema\Identifiers as ID;

/**
 * Maps the RAW `[sermons]`/`[sermons_sm]` shortcode attributes onto the new
 * {@see \Sermonator\Frontend\SermonQuery} engine, per-attribute (Bundle 2, T4).
 *
 * WHY raw atts: this inspects the attributes BEFORE WP's `shortcode_atts()`, which
 * silently drops unknown keys. Dropping them silently would be the exact fail-wrong
 * the Compatibility Contract forbids — an unknown/unsupported attribute must be NAMED
 * in the editor notice, not erased. So the mapper does its own alias normalization
 * (mirroring `display_sermons()` :812-821) and its own classification.
 *
 * Each attribute is classified into one of the Contract's four ledger cells and the
 * present unfaithful ones are collected into {@see LegacyMappedQuery::$unfaithfulAttrs}:
 *  - FAITHFUL      — reproduced against the engine; not named.
 *  - NO-OP-SAFE    — honoring vs ignoring yields byte-identical content; not named.
 *  - UNVALIDATABLE — best-effort applied or dropped, but cannot be validated against
 *                    the absent SM source; NAMED (precise per-attribute notice).
 *  - UNSUPPORTED   — a deferred surface; NAMED, no faked content render.
 *
 * Faithfulness anchors (all line refs into Sermon Manager 2.15.15
 * includes/class-sm-shortcodes.php `display_sermons()`):
 *  - default order/orderby are OPTION-driven (:790-791) — the migrated
 *    {@see ID::OPTION_ARCHIVE_ORDER}/{@see ID::OPTION_ARCHIVE_ORDERBY} (preserved
 *    verbatim by OptionWriter's wholesale prefix-swap), falling back to SM's own
 *    hardcoded defaults (order `desc`, orderby `date_preached`) only when absent.
 *  - `orderby=date` resolves to PUBLISHED date ONLY when the migrated archive orderby
 *    option === 'date', else to the PREACHED branch (:868-870) — never unconditionally.
 *  - the PREACHED branch (orderby preached/date_preached and the SM invalid-value
 *    default) drops FUTURE + DATELESS via a {@see DateScope::PREACHED} mode, never by
 *    forking the native LEFT-JOIN branch.
 *  - `year`/`month`/`before`/`after` are gated on the PREACHED branch (:899/:944); SM
 *    applies them only inside `meta_value_num`, so under any non-preached orderby they
 *    are bug-compatible no-ops (matching SM, which would render the full set).
 *  - `after` is the bug-for-bug `=>`→`=` exact-equality (:962): an exact-day,
 *    near-empty match, NOT an ignore — and UNVALIDATABLE (the strtotime/timezone
 *    reconstruction has no running-SM oracle), so it is NAMED when applied.
 *  - numeric `filter_value`/`include`/`exclude` RESOLVE-or-DROP via the crosswalks
 *    (never pass a legacy id through as a new id); numeric `filter_value` keeps its
 *    notice even on clean resolution (SM's `field=slug` numeric path was near-empty).
 *  - `per_page` is FAITHFUL only once real pagination (T5) lands; until then it is
 *    NAMED whenever present (do not certify-while-truncating).
 *
 * WP-light + unit-testable: native PHP plus `get_option()` and the two injected,
 * shared resolvers ({@see LegacyTermResolver}/{@see LegacyPostResolver} — decision 6:
 * one resolver answers "what survives Finalize" for BOTH this mapper and the feed).
 * No escaping happens here; the notice is escaped at the Renderer/notice boundary (T6).
 */
final class LegacyAttributeMapper {
    /**
     * Every attribute key the mapper recognizes (canonical + every legacy alias).
     * Anything outside this set in the raw atts is an UNKNOWN attribute and is named.
     *
     * @var list<string>
     */
    private const RECOGNIZED = array(
        'per_page', 'posts_per_page',
        'order', 'orderby',
        'include', 'exclude', 'id', 'sermon', 'sermons',
        'filter_by', 'taxonomy', 'filter_value', 'tax_term',
        'year', 'month', 'before', 'after',
        'disable_pagination', 'hide_nav', 'hide_pagination',
        'image_size',
        'hide_filters', 'hide_topics', 'hide_series', 'hide_preachers',
        'hide_books', 'hide_dates', 'hide_service_types',
        'show_initial',
    );

    /** Legacy filter-form hide_* attrs (UNSUPPORTED — the filter FORM is not rendered). */
    private const HIDE_FILTER_ATTRS = array(
        'hide_filters', 'hide_topics', 'hide_series', 'hide_preachers',
        'hide_books', 'hide_dates', 'hide_service_types',
    );

    public function __construct(
        private readonly LegacyTermResolver $termResolver = new LegacyTermResolver(),
        private readonly LegacyPostResolver $postResolver = new LegacyPostResolver(),
        private readonly ?int $now = null
    ) {}

    /**
     * Classify and map the raw shortcode atts.
     *
     * @param array<string|int,mixed> $rawAtts The shortcode atts BEFORE shortcode_atts().
     */
    public function map( array $rawAtts ): LegacyMappedQuery {
        // Lowercase string keys once (WP's parser already lowercases, but be defensive)
        // and keep the result as the single working copy used for lookup AND unknown
        // detection.
        $atts = array();
        foreach ( $rawAtts as $key => $value ) {
            $atts[ is_string( $key ) ? strtolower( $key ) : $key ] = $value;
        }

        $unfaithful = array();

        // --- per_page (alias posts_per_page) -------------------------------------
        // FAITHFUL pending real pagination (T5); until then NAMED when present.
        $perPageRaw = $this->aliasValue( $atts, 'per_page', array( 'posts_per_page' ) );
        if ( $perPageRaw !== '' ) {
            $perPage      = max( 1, min( 100, (int) $perPageRaw ) );
            $unfaithful[] = 'per_page';
        } else {
            $configured = (int) get_option( 'posts_per_page' );
            $perPage    = $configured > 0 ? min( 100, $configured ) : 10;
        }

        // --- order (FAITHFUL, option-driven default) -----------------------------
        $orderRaw = $this->present( $atts['order'] ?? null ) ? (string) $atts['order'] : '';
        if ( $orderRaw === '' ) {
            $optOrder = (string) get_option( ID::OPTION_ARCHIVE_ORDER );
            $orderRaw = $optOrder !== '' ? $optOrder : Legacy::ARCHIVE_ORDER_DEFAULT;
        }
        $order = strtoupper( trim( $orderRaw ) ) === 'ASC' ? 'ASC' : 'DESC';

        // --- orderby + dateScope (FAITHFUL, option-driven default + date resolve) -
        $archiveOrderbyOpt = (string) get_option( ID::OPTION_ARCHIVE_ORDERBY );
        $orderbyRaw        = $this->present( $atts['orderby'] ?? null )
            ? (string) $atts['orderby']
            : ( $archiveOrderbyOpt !== '' ? $archiveOrderbyOpt : Legacy::ARCHIVE_ORDERBY_DEFAULT );
        [ $orderbyToken, $dateScope ] = $this->resolveOrderby( $orderbyRaw, $archiveOrderbyOpt );

        // --- date filters: ONLY on the PREACHED branch ---------------------------
        // Under any non-preached orderby these are bug-compatible no-ops (SM ignores
        // them outside meta_value_num), so they are neither applied nor named.
        $dateRange = $dateScope === DateScope::PREACHED
            ? $this->buildDateRange( $atts, $unfaithful )
            : array();

        // --- include / exclude -> post__in / post__not_in (resolve-or-drop) ------
        $postIn    = $this->resolvePostIds(
            $this->aliasValue( $atts, 'include', array( 'id', 'sermon', 'sermons' ) ),
            'include',
            $unfaithful
        );
        $postNotIn = $this->resolvePostIds(
            $this->present( $atts['exclude'] ?? null ) ? (string) $atts['exclude'] : '',
            'exclude',
            $unfaithful
        );

        // --- filter_by + filter_value -> tax_query -------------------------------
        $taxonomies = $this->resolveTaxonomy( $atts, $unfaithful );

        // --- show_initial (UNVALIDATABLE; not honored) ---------------------------
        if ( $this->present( $atts['show_initial'] ?? null ) ) {
            $unfaithful[] = 'show_initial';
        }

        // --- image_size (UNSUPPORTED §63 no-op; named) ---------------------------
        if ( $this->present( $atts['image_size'] ?? null ) ) {
            $unfaithful[] = 'image_size';
        }

        // --- hide_* filter-form attrs (UNSUPPORTED §63; named) -------------------
        // NOTE: hide_nav / hide_pagination are NOT here — they are pagination aliases
        // (NO-OP-SAFE) consumed by the disable_pagination axis below, not filter-form
        // controls.
        foreach ( self::HIDE_FILTER_ATTRS as $hideAttr ) {
            if ( $this->present( $atts[ $hideAttr ] ?? null ) ) {
                $unfaithful[] = $hideAttr;
            }
        }

        // disable_pagination (aliases hide_nav, hide_pagination) is NO-OP-SAFE: the
        // grid renders no pager today, so there is nothing to disable -> not named.

        // --- any UNKNOWN raw attribute -> named ----------------------------------
        foreach ( $atts as $key => $value ) {
            if ( is_int( $key ) ) {
                // A positional shortcode token ([sermons foo]) -> unknown; name the token.
                $token = trim( (string) $value );
                if ( $token !== '' ) {
                    $unfaithful[] = $token;
                }
                continue;
            }
            if ( ! in_array( $key, self::RECOGNIZED, true ) ) {
                $unfaithful[] = $key;
            }
        }

        $gridArgs = array(
            'perPage'    => $perPage,
            'order'      => $order,
            'taxonomies' => $taxonomies,
            'dateRange'  => $dateRange,
        );

        return new LegacyMappedQuery(
            $gridArgs,
            $dateScope,
            $orderbyToken,
            $this->uniqueInts( $postIn ),
            $this->uniqueInts( $postNotIn ),
            array_values( array_unique( $unfaithful ) )
        );
    }

    /**
     * Resolve the effective orderby to an internal SermonQuery token + its DateScope
     * mode, byte-for-byte per `display_sermons()` :852-894. Invalid values default to
     * `date_preached` (:865); `date` resolves to published ONLY when the migrated
     * archive orderby option === 'date' (:868-870).
     *
     * @return array{0:string,1:DateScope}
     */
    private function resolveOrderby( string $orderby, string $archiveOrderbyOption ): array {
        $token = strtolower( trim( $orderby ) );

        $valid = array(
            'date', 'preached', 'date_preached', 'published', 'date_published',
            'id', 'none', 'title', 'name', 'rand', 'comment_count',
        );
        if ( ! in_array( $token, $valid, true ) ) {
            $token = 'date_preached';
        }

        if ( $token === 'date' ) {
            $token = strtolower( trim( $archiveOrderbyOption ) ) === 'date'
                ? 'date_published'
                : 'date_preached';
        }

        switch ( $token ) {
            case 'published':
            case 'date_published':
                return array( 'published', DateScope::NONE );
            case 'id':
                return array( 'id', DateScope::NONE );
            case 'title':
                return array( 'title', DateScope::NONE );
            case 'name':
                return array( 'name', DateScope::NONE );
            case 'rand':
                return array( 'rand', DateScope::NONE );
            case 'none':
                return array( 'none', DateScope::NONE );
            case 'comment_count':
                return array( 'comment_count', DateScope::NONE );
            case 'preached':
            case 'date_preached':
            default:
                return array( 'preached', DateScope::PREACHED );
        }
    }

    /**
     * Build the NUMERIC, signed-ts-safe date-range bounds for the PREACHED branch,
     * mirroring `display_sermons()` :898-968. year/month set a BETWEEN and turn the
     * `<= now()` future-cap OFF (SM resets the meta_query, :911). before is an upper
     * bound (:952); after is the bug-for-bug `=>`→`=` exact-equality (:962) and is
     * NAMED (UNVALIDATABLE) whenever applied.
     *
     * @param array<string|int,mixed> $atts
     * @param list<string>            $unfaithful  (by ref) the notice set.
     * @return array{min?:int,max?:int,equals?:int,capFuture?:bool}
     */
    private function buildDateRange( array $atts, array &$unfaithful ): array {
        $year   = $this->present( $atts['year'] ?? null ) ? trim( (string) $atts['year'] ) : '';
        $month  = $this->present( $atts['month'] ?? null ) ? trim( (string) $atts['month'] ) : '';
        $before = $this->present( $atts['before'] ?? null ) ? trim( (string) $atts['before'] ) : '';
        $after  = $this->present( $atts['after'] ?? null ) ? trim( (string) $atts['after'] ) : '';

        $range = array();
        $now   = $this->now();

        if ( $month !== '' ) {
            // SM's month branch wins over year (the reset on each loop pass leaves only
            // the last-set clause); the year defaults to the current year (:927).
            $resolvedYear  = $year !== '' ? $year : date( 'Y', $now );
            $resolvedMonth = (int) $month ?: (int) date( 'm', $now );
            $start         = strtotime( $resolvedYear . '-' . $month . '-01' );
            $end           = $this->monthEnd( (int) $resolvedYear, $resolvedMonth );
            if ( is_int( $start ) && $end !== null ) {
                $range['min']       = $start;
                $range['max']       = $end;
                $range['capFuture'] = false;
            }
        } elseif ( $year !== '' ) {
            $start = strtotime( $year . '-01-01' );
            $end   = strtotime( $year . '-12-31' );
            if ( is_int( $start ) && is_int( $end ) ) {
                $range['min']       = $start;
                $range['max']       = $end;
                $range['capFuture'] = false;
            }
        }

        if ( $before !== '' ) {
            $beforeTs = strtotime( $before );
            if ( is_int( $beforeTs ) ) {
                // SM ANDs before's `<=` with any year/month BETWEEN; intersecting the
                // upper bounds (min) reproduces that AND as a single tighter ceiling.
                $range['max'] = isset( $range['max'] ) ? min( $range['max'], $beforeTs ) : $beforeTs;
            }
        }

        if ( $after !== '' ) {
            $afterTs = strtotime( $after );
            if ( is_int( $afterTs ) ) {
                $range['equals'] = $afterTs;
            }
            // KEPT whenever applied under PREACHED: silently ignoring after would render
            // the FULL list (content SM never produced), and the reconstruction has no
            // running-SM oracle.
            $unfaithful[] = 'after';
        }

        return $range;
    }

    /** Last-day timestamp of a month, or null when the month/year is out of range. */
    private function monthEnd( int $year, int $month ): ?int {
        if ( $month < 1 || $month > 12 ) {
            return null;
        }
        $firstOfMonth = strtotime( sprintf( '%04d-%02d-01', $year, $month ) );
        if ( ! is_int( $firstOfMonth ) ) {
            return null;
        }
        $days = (int) date( 't', $firstOfMonth );
        $end  = strtotime( sprintf( '%04d-%02d-%02d', $year, $month, $days ) );
        return is_int( $end ) ? $end : null;
    }

    /**
     * Resolve a comma-separated legacy post-id list to NEW post ids via the crosswalk
     * (resolve-or-DROP; never pass a legacy id through). Non-numeric tokens are dropped
     * silently (SM does the same, :982); a numeric id that does NOT resolve drops that
     * id AND names the attribute. A clean all-resolve earns no notice.
     *
     * @param list<string> $unfaithful (by ref)
     * @return list<int>
     */
    private function resolvePostIds( string $csv, string $attrName, array &$unfaithful ): array {
        if ( $csv === '' ) {
            return array();
        }

        $ids        = array();
        $anyDropped = false;
        foreach ( explode( ',', $csv ) as $token ) {
            $token = trim( $token );
            if ( $token === '' ) {
                continue;
            }
            if ( ! is_numeric( $token ) ) {
                continue; // SM removes non-numeric include/exclude tokens silently.
            }
            $resolution = $this->postResolver->resolveByLegacyId( (int) $token );
            if ( $resolution->resolved() ) {
                $ids[] = (int) $resolution->newId;
            } else {
                $anyDropped = true;
            }
        }

        if ( $anyDropped ) {
            $unfaithful[] = $attrName;
        }

        return $ids;
    }

    /**
     * Map filter_by (alias taxonomy) + filter_value (alias tax_term) onto a taxonomy
     * scope. SM requires BOTH (:1015); an incomplete pair is a faithful no-op.
     *
     * Slug values are FAITHFUL (durable across Finalize): resolved to NEW term ids when
     * possible, else passed through as slugs (a non-existent slug yields SM's same empty
     * match) — no notice. Numeric values are UNVALIDATABLE: resolved via the
     * TermCrosswalk (resolve-or-DROP, never pass-through) and ALWAYS named, because SM's
     * numeric path built `field=slug` and matched a term whose slug equals the number
     * (:1040, near-empty) — rendering the crosswalk-resolved term is a faithful
     * reinterpretation of intent, not a reproduction of SM's output.
     *
     * @param array<string|int,mixed> $atts
     * @param list<string>            $unfaithful (by ref)
     * @return array<string,list<int|string>>
     */
    private function resolveTaxonomy( array $atts, array &$unfaithful ): array {
        $filterBy    = $this->aliasValue( $atts, 'filter_by', array( 'taxonomy' ) );
        $filterValue = $this->aliasValue( $atts, 'filter_value', array( 'tax_term' ) );

        if ( $filterBy === '' || $filterValue === '' ) {
            return array(); // SM needs both; an incomplete pair is a faithful no-op.
        }

        $taxonomy = $this->taxonomyFor( $filterBy );
        if ( $taxonomy === null ) {
            // An unrecognized filter_by cannot be validated against the SM taxonomy map.
            $unfaithful[] = 'filter_by';
            return array();
        }

        $terms = array_values( array_filter(
            array_map( 'trim', explode( ',', $filterValue ) ),
            static fn( string $term ): bool => $term !== ''
        ) );
        if ( $terms === array() ) {
            return array();
        }

        // SM decides numeric-vs-slug for the WHOLE list from the FIRST term (:1022).
        if ( is_numeric( $terms[0] ) ) {
            $ids = array();
            foreach ( $terms as $term ) {
                if ( ! is_numeric( $term ) ) {
                    continue;
                }
                $resolution = $this->termResolver->resolveByLegacyId( (int) $term );
                if ( $resolution->resolved() ) {
                    $ids[] = (int) $resolution->newId;
                }
            }
            // Numeric filter_value is unvalidatable even on clean resolution.
            $unfaithful[] = 'filter_value';
            return $ids === array() ? array() : array( $taxonomy => $ids );
        }

        // Slug path: resolve to NEW term ids where possible (durable); fall back to the
        // raw slugs when none resolve (SermonQuery queries field=slug — byte-identical
        // to SM's non-existent-or-matching slug). Faithful in every branch -> no notice.
        $ids = array();
        foreach ( $terms as $slug ) {
            $resolution = $this->termResolver->resolveBySlug( $taxonomy, $slug );
            if ( $resolution->resolved() ) {
                $ids[] = (int) $resolution->newId;
            }
        }

        return array( $taxonomy => $ids !== array() ? $ids : $terms );
    }

    /**
     * Map a legacy filter_by name to a NEW taxonomy slug, mirroring SM's
     * convert_taxonomy_name() (singular/plural "new" names + the wpfc_* old names).
     * Unrecognized -> null (the caller names filter_by).
     */
    private function taxonomyFor( string $filterBy ): ?string {
        $map = array(
            'series'           => ID::TAX_SERIES,
            'preacher'         => ID::TAX_PREACHER,
            'preachers'        => ID::TAX_PREACHER,
            'topic'            => ID::TAX_TOPIC,
            'topics'           => ID::TAX_TOPIC,
            'book'             => ID::TAX_BOOK,
            'books'            => ID::TAX_BOOK,
            'service_type'     => ID::TAX_SERVICE_TYPE,
            'service_types'    => ID::TAX_SERVICE_TYPE,
            Legacy::TAX_SERIES       => ID::TAX_SERIES,
            Legacy::TAX_PREACHER     => ID::TAX_PREACHER,
            Legacy::TAX_TOPIC        => ID::TAX_TOPIC,
            Legacy::TAX_BOOK         => ID::TAX_BOOK,
            Legacy::TAX_SERVICE_TYPE => ID::TAX_SERVICE_TYPE,
        );

        return $map[ strtolower( trim( $filterBy ) ) ] ?? null;
    }

    /**
     * Resolve an attribute's effective raw value from its canonical key + aliases,
     * mirroring SM precedence (`display_sermons()` :823-831): the canonical key wins;
     * among aliases the last non-empty in declaration order wins. SM's alias mapping
     * uses `! empty()`, so an empty alias value never overrides the default.
     *
     * @param array<string|int,mixed> $atts
     * @param list<string>            $aliases
     */
    private function aliasValue( array $atts, string $canonical, array $aliases ): string {
        if ( $this->present( $atts[ $canonical ] ?? null ) ) {
            return (string) $atts[ $canonical ];
        }
        $value = '';
        foreach ( $aliases as $alias ) {
            if ( $this->present( $atts[ $alias ] ?? null ) ) {
                $value = (string) $atts[ $alias ];
            }
        }
        return $value;
    }

    /** A value counts as present when it is a non-empty trimmed string. */
    private function present( mixed $value ): bool {
        return $value !== null && trim( (string) $value ) !== '';
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function uniqueInts( array $ids ): array {
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }

    private function now(): int {
        return $this->now ?? time();
    }
}
