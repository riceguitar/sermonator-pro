<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Admin\MigrationWizard;
use Sermonator\Schema\Identifiers as ID;

/**
 * §63 migration PREVALENCE counter (Bundle 2, spec §2.12 / T11).
 *
 * The precursor migrator-reality audit is UNMET — there is no real Pro-site sample, so the
 * whole bundle stayed full-scope by default and several behaviors (per-podcast video modes,
 * podcast OBJECT-term scope mirroring) were DEFERRED as signed Contract exceptions. This class
 * emits, from the FIRST real migration, the prevalence numbers that audit lacked, so the
 * deferred work can be sized on real data instead of guesses:
 *
 *   - podcasts carrying a non-empty term-filter SCOPE (the per-podcast-filtering value);
 *   - whether the site runs MORE THAN ONE published podcast (the over-inclusion population);
 *   - how many scoped podcasts are SINGLE-SCOPED (scope spans exactly one taxonomy axis — the
 *     simplest, already-provably-faithful case, vs. multi-axis scopes);
 *   - embedded-`[sermons]` ATTRIBUTE DENSITY — how many posts embed `[sermons]`/`[sermons_sm]`
 *     and with how many attributes (which attributes, how deep) — the population that exercises
 *     the per-attribute ledger;
 *   - page-builder FINDING counts — how many pages trap legacy sermon content in a builder
 *     (the surface whose module rebuild is backlogged).
 *
 * ## Two hard boundaries (mirrors {@see \Sermonator\Bible\CoverageAudit})
 *
 *  - NO WRITE-ON-GET. The rollup is computed and persisted to {@see ID::OPTION_MIGRATION_PREVALENCE}
 *    ONLY on the write-gated migration detect/verify path ({@see self::run()} called from
 *    {@see Orchestrator::detect()} and {@see Verifier::verify()} — both explicit migrator actions,
 *    never a passive page load). The wizard report ({@see self::renderReport()}) is a PURE READER
 *    of the precomputed option; it never recomputes and never writes.
 *  - READ-ONLY tally. {@see self::tally()} reads posts/postmeta and returns an in-memory rollup;
 *    it calls no update_/add_/delete_/wp_insert_/wp_update_ path. Both suites assert this.
 *
 * Podcast SCOPE reads the MIGRATED `sermonator_*` podcasts (created during the migrate phase), so
 * those counts are meaningful only at VERIFY time; the shortcode/builder scans read the legacy/
 * source content and are meaningful from DETECT. Running at BOTH points (each {@see self::run()}
 * overwrites with a complete fresh tally) means the post-verify persisted value carries everything.
 */
final class PrevalenceCounter {
    /**
     * Resolves ONE per-podcast scope map per published LEGACY podcast, sourced from the
     * REAL per-podcast scope source — the `wpfc_sm_podcast` OBJECT-TERM relationships
     * (NOT the migrated `sm_podcast_settings` blob, which is empty on real SM Pro and,
     * at DETECT, has no migrated podcasts to read at all). Each element is one podcast's
     * scope (legacy taxonomy slug => term ids); an empty map is an unscoped podcast.
     * Injected so the tally is unit-testable without `$wpdb`; defaults to the real
     * read-only object-term scan, which is meaningful from DETECT through VERIFY (legacy
     * data is byte-immutable until Finalize).
     *
     * @var callable():list<array<string,list<int>>>
     */
    private $legacyPodcastScopesProvider;

    /**
     * Resolves one parsed-attribute map per post that embeds `[sermons]`/`[sermons_sm]`.
     * Injected so the density math is unit-testable without `$wpdb`/`shortcode_parse_atts`;
     * defaults to the real read-only scan.
     *
     * @var callable():list<array<array-key,mixed>>
     */
    private $shortcodeEmbedsProvider;

    /**
     * Returns the page-builder findings to tally. Defaults to {@see PageBuilderScanner::scan()}
     * (the spec's "reuse the PageBuilderScanner scan") — injected as a callable so the tally is
     * unit-testable with a fixed finding list, without standing up the scanner's `$wpdb` query.
     *
     * @var callable():list<array{post_id:int,type:string}>
     */
    private $builderFindingsProvider;

    /**
     * @param callable():list<array<string,list<int>>>|null             $legacyPodcastScopesProvider
     * @param callable():list<array<array-key,mixed>>|null               $shortcodeEmbedsProvider
     * @param callable():list<array{post_id:int,type:string}>|null       $builderFindingsProvider
     */
    public function __construct(
        ?callable $legacyPodcastScopesProvider = null,
        ?callable $shortcodeEmbedsProvider = null,
        ?callable $builderFindingsProvider = null
    ) {
        $this->legacyPodcastScopesProvider = $legacyPodcastScopesProvider ?? array( $this, 'queryLegacyPodcastScopes' );
        $this->shortcodeEmbedsProvider     = $shortcodeEmbedsProvider ?? array( $this, 'queryShortcodeEmbeds' );
        $this->builderFindingsProvider     = $builderFindingsProvider ?? array( new PageBuilderScanner(), 'scan' );
    }

    /**
     * Wire the wizard-report surface. The report is the ONLY thing that runs on a normal admin
     * GET, and it is a pure read of the precomputed option (no recompute, no write).
     */
    public function hook(): void {
        add_action( 'admin_notices', array( $this, 'maybeRenderWizardNotice' ) );
    }

    /**
     * Compute the prevalence rollup and PERSIST it to {@see ID::OPTION_MIGRATION_PREVALENCE}.
     * The write-gated path: called from the migration detect/verify actions ONLY. Returns the
     * same rollup it stored.
     *
     * @return array<string,mixed>
     */
    public function run(): array {
        $stats = $this->tally();
        update_option( ID::OPTION_MIGRATION_PREVALENCE, $stats, false );

        return $stats;
    }

    /**
     * Compute the prevalence rollup and RETURN it, WRITING NOTHING. The read-only instrument
     * behind {@see self::run()} and the test surface. Never throws.
     *
     * @return array{generated_at:int,podcasts:array{published:int,with_scope:int,single_scoped:int,multi_podcast:bool},shortcodes:array{posts:int,with_attributes:int,total_attributes:int,max_attributes:int,attribute_histogram:array<string,int>},page_builder:array{pages:int,findings:int,builder_embedded:int,shortcode_in_meta:int}}
     */
    public function tally(): array {
        return array(
            'generated_at' => time(),
            'podcasts'     => $this->tallyPodcasts(),
            'shortcodes'   => $this->tallyShortcodes(),
            'page_builder' => $this->tallyPageBuilder(),
        );
    }

    /**
     * Podcast prevalence: published count, scoped count, single-axis-scoped count, and whether
     * the site runs more than one published podcast.
     *
     * The scope is counted from the REAL per-podcast scope source — the legacy
     * `wpfc_sm_podcast` OBJECT-TERM relationships ({@see self::queryLegacyPodcastScopes()}) —
     * NOT the migrated `sm_podcast_settings` blob, which is empty on real SM Pro and is blind
     * at DETECT (no migrated podcasts exist yet). Counting the object-term source is correct
     * from DETECT through VERIFY (legacy data is byte-immutable until Finalize).
     *
     * @return array{published:int,with_scope:int,single_scoped:int,multi_podcast:bool}
     */
    private function tallyPodcasts(): array {
        $scopes    = ( $this->legacyPodcastScopesProvider )();
        $published = is_array( $scopes ) ? count( $scopes ) : 0;

        $withScope    = 0;
        $singleScoped = 0;
        foreach ( (array) $scopes as $scope ) {
            if ( ! is_array( $scope ) || array() === $scope ) {
                continue;
            }
            ++$withScope;
            // SINGLE-SCOPED == the scope constrains exactly one taxonomy axis (the simplest,
            // already-provably-faithful case); >1 axis is a relation=AND multi-axis scope.
            if ( 1 === count( $scope ) ) {
                ++$singleScoped;
            }
        }

        return array(
            'published'     => $published,
            'with_scope'    => $withScope,
            'single_scoped' => $singleScoped,
            'multi_podcast' => $published > 1,
        );
    }

    /**
     * Embedded-`[sermons]` attribute density. One entry per post that embeds the shortcode; for
     * each we count DISTINCT attribute names (a valueless flag like `hide_filters` counts as one
     * attribute), build a name→post histogram, and track the total/max depth.
     *
     * @return array{posts:int,with_attributes:int,total_attributes:int,max_attributes:int,attribute_histogram:array<string,int>}
     */
    private function tallyShortcodes(): array {
        $embeds = ( $this->shortcodeEmbedsProvider )();

        $posts          = 0;
        $withAttributes = 0;
        $totalAttrs     = 0;
        $maxAttrs       = 0;
        $histogram      = array();

        foreach ( $embeds as $atts ) {
            if ( ! is_array( $atts ) ) {
                continue;
            }
            ++$posts;

            $names = self::attributeNames( $atts );
            $count = count( $names );

            $totalAttrs += $count;
            if ( $count > 0 ) {
                ++$withAttributes;
            }
            if ( $count > $maxAttrs ) {
                $maxAttrs = $count;
            }
            foreach ( $names as $name ) {
                $histogram[ $name ] = ( $histogram[ $name ] ?? 0 ) + 1;
            }
        }

        arsort( $histogram );

        return array(
            'posts'               => $posts,
            'with_attributes'     => $withAttributes,
            'total_attributes'    => $totalAttrs,
            'max_attributes'      => $maxAttrs,
            'attribute_histogram' => $histogram,
        );
    }

    /**
     * Distinct attribute NAMES from a `shortcode_parse_atts()`-shaped map. A `name=value`
     * attribute arrives as a string key; a valueless flag (`[sermons hide_filters]`) arrives as
     * an integer key with the flag name as the VALUE — both collapse to the name here.
     *
     * @param array<array-key,mixed> $atts
     *
     * @return list<string>
     */
    private static function attributeNames( array $atts ): array {
        $names = array();
        foreach ( $atts as $key => $value ) {
            if ( is_string( $key ) ) {
                $names[ $key ] = true;
            } elseif ( is_string( $value ) && '' !== $value ) {
                $names[ $value ] = true;
            }
        }

        return array_keys( $names );
    }

    /**
     * Page-builder finding counts (reusing the {@see PageBuilderScanner} scan): unique flagged
     * pages, total findings, and the split between the floor finding and the distinct
     * lower-severity meta-embedded-shortcode finding.
     *
     * @return array{pages:int,findings:int,builder_embedded:int,shortcode_in_meta:int}
     */
    private function tallyPageBuilder(): array {
        $findings = ( $this->builderFindingsProvider )();

        $pageIds  = array();
        $embedded = 0;
        $inMeta   = 0;
        foreach ( $findings as $finding ) {
            if ( ! is_array( $finding ) ) {
                continue;
            }
            $pageIds[ (int) ( $finding['post_id'] ?? 0 ) ] = true;
            $type = (string) ( $finding['type'] ?? '' );
            if ( PageBuilderScanner::TYPE_BUILDER_EMBEDDED === $type ) {
                ++$embedded;
            } elseif ( PageBuilderScanner::TYPE_SHORTCODE_IN_META === $type ) {
                ++$inMeta;
            }
        }

        return array(
            'pages'             => count( $pageIds ),
            'findings'          => count( $findings ),
            'builder_embedded'  => $embedded,
            'shortcode_in_meta' => $inMeta,
        );
    }

    /**
     * Read the persisted rollup (pure read). Empty array when the counter has never run.
     *
     * @return array<string,mixed>
     */
    public static function stats(): array {
        $stored = get_option( ID::OPTION_MIGRATION_PREVALENCE, array() );

        return is_array( $stored ) ? $stored : array();
    }

    /**
     * Echo the prevalence report on the migration-wizard screen only (admin_notices fires on
     * every admin page). PURE READ — the markup is fully escaped inside {@see self::renderReport()}.
     */
    public function maybeRenderWizardNotice(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( null === $screen || ! is_object( $screen ) ) {
            return;
        }
        $id = isset( $screen->id ) ? (string) $screen->id : '';
        if ( '' === $id || false === strpos( $id, MigrationWizard::PAGE_SLUG ) ) {
            return;
        }

        echo $this->renderReport(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderReport escapes every interpolated value.
    }

    /**
     * Render the precomputed rollup as an escaped admin-notice block for the migration report.
     * PURE READER of {@see ID::OPTION_MIGRATION_PREVALENCE} — never recomputes, never writes.
     * Empty string when the counter has not run yet. Every interpolated value is escaped here.
     */
    public function renderReport(): string {
        $stats = self::stats();
        if ( array() === $stats ) {
            return '';
        }

        $podcasts   = is_array( $stats['podcasts'] ?? null ) ? $stats['podcasts'] : array();
        $shortcodes = is_array( $stats['shortcodes'] ?? null ) ? $stats['shortcodes'] : array();
        $builder    = is_array( $stats['page_builder'] ?? null ) ? $stats['page_builder'] : array();

        $rows = array(
            __( 'Published podcasts', 'sermonator' )                  => (int) ( $podcasts['published'] ?? 0 ),
            __( 'Podcasts with a feed term-scope', 'sermonator' )     => (int) ( $podcasts['with_scope'] ?? 0 ),
            __( 'Single-axis scoped podcasts', 'sermonator' )         => (int) ( $podcasts['single_scoped'] ?? 0 ),
            __( 'More than one published podcast', 'sermonator' )     => ! empty( $podcasts['multi_podcast'] )
                ? __( 'yes', 'sermonator' )
                : __( 'no', 'sermonator' ),
            __( 'Posts embedding [sermons]', 'sermonator' )           => (int) ( $shortcodes['posts'] ?? 0 ),
            __( '…of those, carrying attributes', 'sermonator' )      => (int) ( $shortcodes['with_attributes'] ?? 0 ),
            __( 'Most attributes on one [sermons]', 'sermonator' )    => (int) ( $shortcodes['max_attributes'] ?? 0 ),
            __( 'Pages with sermon content in a page builder', 'sermonator' ) => (int) ( $builder['pages'] ?? 0 ),
        );

        $h  = '<div class="notice notice-info sermonator-prevalence">';
        $h .= '<p><strong>' . esc_html__( 'Migration prevalence (sizes deferred work)', 'sermonator' ) . '</strong></p>';
        $h .= '<table class="widefat striped sermonator-prevalence-table"><tbody>';
        foreach ( $rows as $label => $value ) {
            $h .= '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
        }
        $h .= '</tbody></table>';

        $histogram = is_array( $shortcodes['attribute_histogram'] ?? null ) ? $shortcodes['attribute_histogram'] : array();
        if ( array() !== $histogram ) {
            $parts = array();
            foreach ( $histogram as $name => $count ) {
                $parts[] = sprintf( '%s (%d)', (string) $name, (int) $count );
            }
            $h .= '<p>' . esc_html(
                sprintf(
                    /* translators: %s: comma-separated list of attribute name (count) pairs. */
                    __( '[sermons] attributes in use: %s', 'sermonator' ),
                    implode( ', ', $parts )
                )
            ) . '</p>';
        }

        $h .= '</div>';

        return $h;
    }

    /**
     * Default scan: ONE per-podcast scope map per published LEGACY `wpfc_sm_podcast`,
     * sourced from the REAL per-podcast scope source — the OBJECT-TERM relationships
     * (`wp_get_object_terms` over the legacy sermon taxonomies). READ-ONLY, and meaningful
     * from DETECT (the legacy podcasts + their relationships exist before migration; the
     * migrated blob does not). {@see LegacySchemaRegistrar} re-registers the legacy schema
     * so the reads work with the legacy plugin DEACTIVATED.
     *
     * @return list<array<string,list<int>>> one scope map per podcast (legacy taxonomy slug => term ids; [] = unscoped).
     */
    public function queryLegacyPodcastScopes(): array {
        LegacySchemaRegistrar::ensureRegistered();

        $ids = get_posts( array(
            'post_type'              => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ) );

        $taxonomies = LegacyIdentifiers::sermonTaxonomies();
        $out        = array();
        foreach ( (array) $ids as $id ) {
            $scope = array();
            foreach ( $taxonomies as $taxonomy ) {
                $terms = wp_get_object_terms( (int) $id, $taxonomy, array( 'fields' => 'ids' ) );
                if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                    continue;
                }
                $clean = array();
                foreach ( $terms as $termId ) {
                    $termId = (int) $termId;
                    if ( $termId > 0 ) {
                        $clean[] = $termId;
                    }
                }
                if ( $clean !== array() ) {
                    $scope[ $taxonomy ] = array_values( array_unique( $clean ) );
                }
            }
            $out[] = $scope;
        }

        return $out;
    }

    /**
     * Default scan: one parsed-attribute map per post embedding `[sermons]`/`[sermons_sm]`.
     * READ-ONLY — a `$wpdb` `LIKE` over post_content (matching the {@see PageBuilderScanner}
     * candidate pattern), then `shortcode_parse_atts()` on the FIRST sermon shortcode found.
     * A post may carry several embeds; the first is a faithful proxy for attribute DENSITY.
     *
     * @return list<array<array-key,mixed>>
     */
    public function queryShortcodeEmbeds(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                   FROM {$wpdb->posts}
                  WHERE post_status NOT IN ('trash', 'auto-draft')
                    AND post_content LIKE %s",
                '%' . $wpdb->esc_like( '[sermons' ) . '%'
            )
        );

        $out = array();
        foreach ( (array) $ids as $id ) {
            $content = (string) get_post_field( 'post_content', (int) $id );
            $atts    = self::firstSermonsAtts( $content );
            if ( null !== $atts ) {
                $out[] = $atts;
            }
        }

        return $out;
    }

    /**
     * Parse the attribute map of the FIRST `[sermons]`/`[sermons_sm]` shortcode in $content, or
     * null when none is present. `[sermons` deliberately does NOT match `[list_sermons]` (no
     * `[sermons` substring) nor `[sermon_images]`. A bare `[sermons]` yields an empty map.
     *
     * @return array<array-key,mixed>|null
     */
    private static function firstSermonsAtts( string $content ): ?array {
        if ( ! preg_match( '/\[sermons(?:_sm)?\b([^\]]*)\]/', $content, $m ) ) {
            return null;
        }

        $parsed = shortcode_parse_atts( trim( (string) $m[1] ) );

        return is_array( $parsed ) ? $parsed : array();
    }
}
