<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Admin\MigrationWizard;

/**
 * READ-ONLY detector of page-builder-embedded legacy sermon content (Bundle 2, spec T10 /
 * §pageBuilderScope). The page-builder MODULE rebuild is explicitly backlogged — this class
 * exists only so that legacy sermon content trapped inside a page builder is NEVER a SILENT
 * break at the migration switch. It is the fail-visible floor for the one surface the bundle
 * cannot faithfully reproduce.
 *
 * ## What it writes: NOTHING.
 *
 * This is the binding invariant. The scanner reads posts/postmeta and emits an in-memory
 * report; it calls no update_, add_, delete_, wp_insert_, or wp_update_ path. Both the unit and
 * integration suites assert zero writes (the integration test snapshots row counts + a content
 * checksum across a full scan). Because it never writes, it is safe to compute on a Site Health
 * GET (the "no write-on-GET" rule that governs {@see \Sermonator\Bible\CoverageAudit} is about
 * not WRITING during a read — a pure read is fine, and avoids a cron/option round-trip).
 *
 * ## The fingerprint FLOOR (required catch-all, not optional — top-risk #7)
 *
 * A post is flagged when BOTH hold:
 *   1. a known page-BUILDER signal is present — a builder meta key
 *      (`_elementor_data`, Divi `_et_pb*`, Beaver `_fl_builder_data`/`_fl_builder_*`,
 *      WPBakery `vc_*`/`_wpb_*`) OR a builder shortcode in `post_content`
 *      (`[et_pb_*]` Divi, `[vc_*]` WPBakery); AND
 *   2. a legacy sermon REFERENCE is present — a `wpfc_sermon` post-type token, a legacy
 *      taxonomy slug ({@see LegacyIdentifiers::sermonTaxonomies()}), or a legacy sermon
 *      shortcode string (`[sermons`, `[list_sermons`, `[latest_series`, `[sermon_images`) —
 *      found in EITHER the builder JSON/postmeta OR `post_content`.
 *
 * Builder-WITHOUT-legacy-ref is not flagged (most builder pages are not sermon pages); a
 * legacy ref WITHOUT a builder signal is not THIS scanner's concern (the `do_shortcode`
 * compatibility shim already covers `post_content` shortcodes).
 *
 * ## The DISTINCT, lower-severity finding (spec §pageBuilderScope)
 *
 * A legacy sermon SHORTCODE embedded specifically in builder POSTMETA (e.g. inside
 * `_elementor_data` JSON) is reported as a SEPARATE, lower-severity finding
 * ({@see self::TYPE_SHORTCODE_IN_META}). These are strictly worse-than-they-look: the
 * `do_shortcode` shim runs on `the_content`, NOT on postmeta, so the shortcode will NOT fire
 * and the section renders empty. We deliberately do NOT build a do_shortcode-on-meta bridge
 * (it would resurrect arbitrary legacy shortcodes from inside opaque builder JSON, an
 * un-auditable write/exec surface) — we surface it for a human rebuild instead.
 *
 * ## Surfaced in BOTH places (so the break is never silent pre-switch)
 *
 *   - the migration wizard report (admin_notices, scoped to the wizard screen via
 *     {@see self::maybeRenderWizardNotice()}), and
 *   - a Site Health "direct" status test ({@see self::registerSiteHealthTest()}), mirroring
 *     {@see \Sermonator\Bible\CoverageAudit}'s pattern.
 *
 * Wire {@see self::hook()} once at boot (alongside CoverageAudit) to register both surfaces.
 */
final class PageBuilderScanner {
    /** Site Health "direct" test id. */
    public const SITE_HEALTH_TEST = 'sermonator_page_builder_scan';

    /** Floor finding: builder content references legacy sermon data anywhere. */
    public const TYPE_BUILDER_EMBEDDED = 'builder_embedded_legacy';

    /** Distinct finding: a legacy sermon shortcode lives inside builder POSTMETA (won't fire). */
    public const TYPE_SHORTCODE_IN_META = 'legacy_shortcode_in_meta';

    /** Finding severities (finding-level; distinct from the Site Health status). */
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING  = 'warning';

    /** Where a legacy reference was found. */
    public const WHERE_CONTENT = 'content';
    public const WHERE_META    = 'meta';
    public const WHERE_BOTH    = 'content+meta';

    /**
     * Legacy sermon shortcode tokens (matched as a `[`-prefixed substring, so `[sermons`
     * catches both `[sermons]` and `[sermons_sm]`). Mirrors the spec's explicit list.
     *
     * @var list<string>
     */
    private const SHORTCODE_TOKENS = array( 'sermons', 'list_sermons', 'latest_series', 'sermon_images' );

    /**
     * Resolves the candidate post ids to scan (posts carrying any builder signal). Injected
     * so the fingerprint logic is unit-testable without a live `$wpdb`; defaults to the real
     * read-only query.
     *
     * @var callable():list<int>
     */
    private $candidateProvider;

    /**
     * @param callable():list<int>|null $candidateProvider Resolve builder-candidate post ids.
     */
    public function __construct( ?callable $candidateProvider = null ) {
        $this->candidateProvider = $candidateProvider ?? array( $this, 'queryCandidates' );
    }

    /**
     * Wire both fail-visible surfaces: the Site Health status test and the wizard-screen
     * admin notice. Both are pure reads; nothing here writes.
     */
    public function hook(): void {
        add_filter( 'site_status_tests', array( $this, 'registerSiteHealthTest' ) );
        add_action( 'admin_notices', array( $this, 'maybeRenderWizardNotice' ) );
    }

    /**
     * Scan the candidate posts and return the findings. READ-ONLY: reads post_content +
     * postmeta and returns an in-memory list; writes nothing, throws nothing.
     *
     * Each finding:
     *   - post_id  int
     *   - title    string (raw; escaped at the render boundary)
     *   - builders list<string>  detected builder names (e.g. ['elementor'])
     *   - type     self::TYPE_*
     *   - severity self::SEVERITY_*
     *   - where    self::WHERE_*  (floor finding only; meta finding is always WHERE_META)
     *   - refs     list<string>  the matched legacy tokens (evidence)
     *
     * A single post can yield BOTH a floor finding AND a meta-shortcode finding.
     *
     * @return list<array{post_id:int,title:string,builders:list<string>,type:string,severity:string,where:string,refs:list<string>}>
     */
    public function scan(): array {
        $findings = array();

        foreach ( ( $this->candidateProvider )() as $postId ) {
            $postId = (int) $postId;
            foreach ( $this->scanPost( $postId ) as $finding ) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Classify ONE post. Returns 0, 1, or 2 findings (the floor and/or the distinct
     * meta-shortcode finding). Never writes.
     *
     * @return list<array{post_id:int,title:string,builders:list<string>,type:string,severity:string,where:string,refs:list<string>}>
     */
    private function scanPost( int $postId ): array {
        $content = (string) get_post_field( 'post_content', $postId );
        $allMeta = get_post_meta( $postId );
        $allMeta = is_array( $allMeta ) ? $allMeta : array();

        // (1) Builder signal — meta keys + content shortcodes.
        $builders   = array();
        $builderKeys = array();
        foreach ( $allMeta as $key => $values ) {
            $name = self::builderForMetaKey( (string) $key );
            if ( null !== $name ) {
                $builders[ $name ] = true;
                $builderKeys[]     = (string) $key;
            }
        }
        if ( false !== strpos( $content, '[et_pb_' ) ) {
            $builders['divi'] = true;
        }
        if ( false !== strpos( $content, '[vc_' ) ) {
            $builders['wpbakery'] = true;
        }

        if ( array() === $builders ) {
            return array(); // not a builder post — out of scope.
        }

        // (2) Legacy reference — in post_content and in the builder postmeta haystack.
        $metaHaystack = $this->builderMetaHaystack( $allMeta, $builderKeys );

        $refsInContent = self::legacyRefsIn( $content );
        $refsInMeta    = self::legacyRefsIn( $metaHaystack );
        $allRefs       = array_values( array_unique( array_merge( $refsInContent, $refsInMeta ) ) );

        if ( array() === $allRefs ) {
            return array(); // builder, but no legacy sermon content — not a break.
        }

        $builderNames = array_keys( $builders );
        $title        = $this->postTitle( $postId );
        $findings     = array();

        // Floor finding (the required catch-all).
        $where = array() !== $refsInContent && array() !== $refsInMeta
            ? self::WHERE_BOTH
            : ( array() !== $refsInMeta ? self::WHERE_META : self::WHERE_CONTENT );

        $findings[] = array(
            'post_id'  => $postId,
            'title'    => $title,
            'builders' => $builderNames,
            'type'     => self::TYPE_BUILDER_EMBEDDED,
            'severity' => self::SEVERITY_CRITICAL,
            'where'    => $where,
            'refs'     => $allRefs,
        );

        // Distinct, lower-severity finding: a legacy SHORTCODE inside builder postmeta — the
        // do_shortcode shim never fires there, so it renders empty (worse than it looks).
        $shortcodesInMeta = self::shortcodeRefsIn( $metaHaystack );
        if ( array() !== $shortcodesInMeta ) {
            $findings[] = array(
                'post_id'  => $postId,
                'title'    => $title,
                'builders' => $builderNames,
                'type'     => self::TYPE_SHORTCODE_IN_META,
                'severity' => self::SEVERITY_WARNING,
                'where'    => self::WHERE_META,
                'refs'     => $shortcodesInMeta,
            );
        }

        return $findings;
    }

    /**
     * Map a postmeta key to the builder that owns it, or null. Exact keys for Elementor and
     * Beaver; prefix matches for Divi (`_et_pb*`), WPBakery (`vc_*`/`_wpb_*`), and Beaver's
     * ancillary keys (`_fl_builder_*`).
     */
    private static function builderForMetaKey( string $key ): ?string {
        if ( '_elementor_data' === $key ) {
            return 'elementor';
        }
        if ( '_fl_builder_data' === $key || self::startsWith( $key, '_fl_builder_' ) ) {
            return 'beaver';
        }
        if ( self::startsWith( $key, '_et_pb' ) ) {
            return 'divi';
        }
        if ( self::startsWith( $key, 'vc_' ) || self::startsWith( $key, '_wpb_' ) ) {
            return 'wpbakery';
        }

        return null;
    }

    /**
     * Flatten the builder postmeta values into one searchable haystack. Builder data may be
     * stored as a JSON string (Elementor) or an unserialized array/object (Beaver), so
     * non-scalars are re-encoded to JSON.
     *
     * @param array<string,mixed> $allMeta     get_post_meta($id) shape: key => list<mixed>.
     * @param list<string>        $builderKeys
     */
    private function builderMetaHaystack( array $allMeta, array $builderKeys ): string {
        $parts = array();
        foreach ( $builderKeys as $key ) {
            $values = $allMeta[ $key ] ?? array();
            if ( ! is_array( $values ) ) {
                $values = array( $values );
            }
            foreach ( $values as $value ) {
                $parts[] = self::stringify( $value );
            }
        }

        return implode( "\n", $parts );
    }

    /**
     * All legacy sermon references found in a haystack: the `wpfc_sermon` post-type token,
     * any legacy taxonomy slug, and any legacy sermon shortcode. Returns the distinct matched
     * tokens (evidence), or [] when none.
     *
     * @return list<string>
     */
    private static function legacyRefsIn( string $haystack ): array {
        if ( '' === $haystack ) {
            return array();
        }

        $refs = array();

        // The legacy post-type token (covers a `wpfc_sermon` id reference / query). The
        // legacy taxonomy slugs share this prefix, so they are also caught here, but we list
        // an exact-matched taxonomy slug separately below for clearer evidence.
        if ( false !== strpos( $haystack, LegacyIdentifiers::POST_TYPE_SERMON ) ) {
            $refs[] = LegacyIdentifiers::POST_TYPE_SERMON;
        }
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $slug ) {
            if ( false !== strpos( $haystack, $slug ) ) {
                $refs[] = $slug;
            }
        }

        return array_values( array_unique( array_merge( $refs, self::shortcodeRefsIn( $haystack ) ) ) );
    }

    /**
     * Legacy sermon shortcode tokens present in a haystack (matched as `[token`). Returns the
     * canonical `[token]` evidence strings.
     *
     * @return list<string>
     */
    private static function shortcodeRefsIn( string $haystack ): array {
        if ( '' === $haystack ) {
            return array();
        }

        $refs = array();
        foreach ( self::SHORTCODE_TOKENS as $token ) {
            if ( false !== strpos( $haystack, '[' . $token ) ) {
                $refs[] = '[' . $token . ']';
            }
        }

        return $refs;
    }

    /**
     * Register the native Site Health "direct" status test. The test callback computes the
     * scan on demand — pure read, no write, no cron.
     *
     * @param array<string,array<string,mixed>> $tests
     *
     * @return array<string,array<string,mixed>>
     */
    public function registerSiteHealthTest( $tests ): array {
        if ( ! is_array( $tests ) ) {
            $tests = array();
        }
        if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
            $tests['direct'] = array();
        }

        $tests['direct'][ self::SITE_HEALTH_TEST ] = array(
            'label' => __( 'Legacy sermon content in page builders', 'sermonator' ),
            'test'  => array( $this, 'siteHealthResult' ),
        );

        return $tests;
    }

    /**
     * Build the Site Health result from a live (read-only) scan. Green when nothing is
     * trapped in a builder; amber (recommended) listing the counts otherwise — the
     * meta-embedded-shortcode subset is called out distinctly because it will render EMPTY.
     *
     * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
     */
    public function siteHealthResult(): array {
        $findings = $this->scan();

        $badge = array(
            'label' => __( 'Sermons', 'sermonator' ),
            'color' => 'blue',
        );

        if ( array() === $findings ) {
            return array(
                'label'       => __( 'No legacy sermon content found inside page builders', 'sermonator' ),
                'status'      => 'good',
                'badge'       => $badge,
                'description' => '<p>' . esc_html__(
                    'No Elementor, Divi, Beaver Builder, or WPBakery content references legacy Sermon Manager sermons. Nothing here needs a manual rebuild.',
                    'sermonator'
                ) . '</p>',
                'actions'     => '',
                'test'        => self::SITE_HEALTH_TEST,
            );
        }

        $pages    = count( self::uniquePostIds( $findings ) );
        $metaOnly = count( self::ofType( $findings, self::TYPE_SHORTCODE_IN_META ) );

        $description = '<p>' . esc_html(
            sprintf(
                /* translators: %d: number of pages embedding legacy sermon content in a page builder. */
                _n(
                    '%d page embeds legacy Sermon Manager content inside a page builder. The page-builder module is NOT migrated or rebuilt automatically — recreate these sections after migrating, before relying on them.',
                    '%d pages embed legacy Sermon Manager content inside a page builder. The page-builder module is NOT migrated or rebuilt automatically — recreate these sections after migrating, before relying on them.',
                    $pages,
                    'sermonator'
                ),
                $pages
            )
        ) . '</p>';

        if ( $metaOnly > 0 ) {
            $description .= '<p>' . esc_html(
                sprintf(
                    /* translators: %d: number of legacy shortcodes embedded in page-builder data. */
                    _n(
                        'WARNING: %d legacy sermon shortcode is stored inside page-builder data. Shortcodes in builder data do NOT run through the compatibility shim — it will render EMPTY, not as a sermon list. Rebuild that section manually.',
                        'WARNING: %d legacy sermon shortcodes are stored inside page-builder data. Shortcodes in builder data do NOT run through the compatibility shim — they will render EMPTY, not as a sermon list. Rebuild those sections manually.',
                        $metaOnly,
                        'sermonator'
                    ),
                    $metaOnly
                )
            ) . '</p>';
        }

        return array(
            'label'       => __( 'Legacy sermon content is embedded in a page builder', 'sermonator' ),
            'status'      => 'recommended',
            'badge'       => $badge,
            'description' => $description,
            'actions'     => '',
            'test'        => self::SITE_HEALTH_TEST,
        );
    }

    /**
     * Render the findings as an escaped admin-notice block for the migration wizard report.
     * Empty string when there is nothing to report. Every interpolated value is escaped here.
     */
    public function renderReport(): string {
        $findings = $this->scan();
        if ( array() === $findings ) {
            return '';
        }

        $pages = count( self::uniquePostIds( $findings ) );

        $h  = '<div class="notice notice-warning sermonator-page-builder-findings">';
        $h .= '<p><strong>' . esc_html(
            sprintf(
                /* translators: %d: number of pages embedding legacy sermon content in a page builder. */
                _n(
                    '%d page embeds legacy sermon content in a page builder',
                    '%d pages embed legacy sermon content in a page builder',
                    $pages,
                    'sermonator'
                ),
                $pages
            )
        ) . '</strong></p>';
        $h .= '<p>' . esc_html__(
            'These page-builder sections are NOT migrated or rebuilt automatically. Review and recreate them after migrating.',
            'sermonator'
        ) . '</p>';

        $h .= '<table class="widefat striped sermonator-page-builder-table"><thead><tr>';
        $h .= '<th scope="col">' . esc_html__( 'Page', 'sermonator' ) . '</th>';
        $h .= '<th scope="col">' . esc_html__( 'Builder', 'sermonator' ) . '</th>';
        $h .= '<th scope="col">' . esc_html__( 'Issue', 'sermonator' ) . '</th>';
        $h .= '</tr></thead><tbody>';

        foreach ( $findings as $finding ) {
            $h .= '<tr><td>' . $this->pageCell( $finding ) . '</td>';
            $h .= '<td>' . esc_html( implode( ', ', $finding['builders'] ) ) . '</td>';
            $h .= '<td>' . esc_html( $this->describe( $finding ) ) . '</td></tr>';
        }

        $h .= '</tbody></table></div>';

        return $h;
    }

    /**
     * Echo the wizard report on the migration-wizard screen only (admin_notices fires on
     * every admin page). Pure read; the markup is fully escaped inside renderReport().
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
     * A human, untranslated-then-translated description of one finding (escaped by the
     * caller). The two finding types read differently because the meta-embedded shortcode is
     * the strictly-worse "renders empty" case.
     *
     * @param array{type:string,where:string,refs:list<string>} $finding
     */
    private function describe( array $finding ): string {
        $refs = implode( ', ', $finding['refs'] );

        if ( self::TYPE_SHORTCODE_IN_META === $finding['type'] ) {
            return sprintf(
                /* translators: %s: comma-separated legacy shortcode tokens. */
                __( 'Legacy shortcode(s) %s stored in builder data — will render EMPTY (the shortcode shim does not run on builder data).', 'sermonator' ),
                $refs
            );
        }

        return sprintf(
            /* translators: %s: comma-separated legacy sermon references. */
            __( 'References legacy sermon content (%s); the builder section will not be rebuilt automatically.', 'sermonator' ),
            $refs
        );
    }

    /**
     * The Page cell: an edit link when available, else the escaped title, else the post id.
     *
     * @param array{post_id:int,title:string} $finding
     */
    private function pageCell( array $finding ): string {
        $label = '' !== $finding['title']
            ? $finding['title']
            : sprintf(
                /* translators: %d: post id. */
                __( 'Post #%d', 'sermonator' ),
                $finding['post_id']
            );

        $link = get_edit_post_link( $finding['post_id'] );
        if ( is_string( $link ) && '' !== $link ) {
            return '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
        }

        return esc_html( $label );
    }

    /** Read a post's title for the report (read-only). */
    private function postTitle( int $postId ): string {
        $title = get_the_title( $postId );

        return is_string( $title ) ? $title : '';
    }

    /**
     * Default candidate query: every post (any non-trashed type) carrying a builder signal —
     * a builder meta key OR a builder shortcode in post_content. READ-ONLY (`get_col`).
     *
     * @return list<int>
     */
    public function queryCandidates(): array {
        global $wpdb;

        $metaIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                   FROM {$wpdb->postmeta} pm
                   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE p.post_status NOT IN ('trash', 'auto-draft')
                    AND ( pm.meta_key = '_elementor_data'
                       OR pm.meta_key = '_fl_builder_data'
                       OR pm.meta_key LIKE %s
                       OR pm.meta_key LIKE %s
                       OR pm.meta_key LIKE %s
                       OR pm.meta_key LIKE %s )",
                $wpdb->esc_like( '_et_pb' ) . '%',
                $wpdb->esc_like( '_fl_builder_' ) . '%',
                $wpdb->esc_like( 'vc_' ) . '%',
                $wpdb->esc_like( '_wpb_' ) . '%'
            )
        );

        $contentIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                   FROM {$wpdb->posts}
                  WHERE post_status NOT IN ('trash', 'auto-draft')
                    AND ( post_content LIKE %s OR post_content LIKE %s )",
                '%' . $wpdb->esc_like( '[et_pb_' ) . '%',
                '%' . $wpdb->esc_like( '[vc_' ) . '%'
            )
        );

        $ids = array_map( 'intval', array_merge( (array) $metaIds, (array) $contentIds ) );

        return array_values( array_unique( $ids ) );
    }

    /**
     * Convert one postmeta value to a searchable string (JSON for non-scalars).
     *
     * @param mixed $value
     */
    private static function stringify( $value ): string {
        if ( is_string( $value ) ) {
            return $value;
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }

        $json = wp_json_encode( $value );

        return is_string( $json ) ? $json : '';
    }

    /**
     * @param list<array{post_id:int}> $findings
     *
     * @return list<int>
     */
    private static function uniquePostIds( array $findings ): array {
        $ids = array();
        foreach ( $findings as $finding ) {
            $ids[ $finding['post_id'] ] = true;
        }

        return array_map( 'intval', array_keys( $ids ) );
    }

    /**
     * @param list<array{type:string}> $findings
     *
     * @return list<array{type:string}>
     */
    private static function ofType( array $findings, string $type ): array {
        return array_values( array_filter( $findings, static fn( array $f ): bool => $f['type'] === $type ) );
    }

    /** PHP 8.0+ has str_starts_with; kept as a private helper to read at call sites. */
    private static function startsWith( string $haystack, string $needle ): bool {
        return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
    }
}
