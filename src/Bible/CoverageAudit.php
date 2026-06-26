<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Schema\Identifiers as ID;

/**
 * Ground-truth corpus audit for Bible-reference PARSE-coverage (Bundle 3, spec Task 13).
 *
 * It answers one question over the published sermon corpus, independently of any render:
 * of the sermons that carry a human-authored {@see ID::META_BIBLE_PASSAGE} label
 * (the denominator), how many have at least one reference that RESOLVES to a
 * render-ready link (the numerator) — plus a per-post breakdown so a green headline
 * can never hide a suppressed maybe-wrong verse:
 *
 *   - resolved                : >=1 ref is in-canon AND structurally valid (would link).
 *   - withheld_low_confidence : refs were extracted but NONE clear the validator
 *                               (e.g. an out-of-canon/structurally-invalid imported ref) —
 *                               withheld rather than shown wrong (the #1 standard).
 *   - parse_fail              : a non-empty passage that yields ZERO refs.
 *   - empty                   : no passage label at all (excluded from the denominator).
 *
 * The four buckets PARTITION the corpus, so total == resolved + withheld + parse_fail + empty
 * and with_passage == total - empty. parse_coverage = resolved / with_passage * 100.
 *
 * Two hard boundaries from the spec:
 *
 *  - NO WRITE-ON-GET. The rollup is computed and written to {@see ID::OPTION_BIBLE_STATS}
 *    ONLY on the scheduled cron hook ({@see self::EVENT_HOOK}) and on a sermon save —
 *    never inside the Site Health "test" read. The Site Health status test is a pure
 *    reader of the precomputed option.
 *  - PARSE-coverage is STRUCTURAL and is NEVER a rollback trigger. It is deliberately
 *    kept separate from the live-fetch-failure metric (Phase 3b) so messy legacy data
 *    cannot cry wolf against the helloao alarm. The Site Health green/amber threshold
 *    here is an informational DISPLAY threshold only.
 *
 * Resolution is the SAME contract the link-mode {@see \Sermonator\Frontend\BibleResolver}
 * uses (in-canon + structurally valid), read from the stored envelope when present and
 * otherwise live-parsed from the preserved passage label — so the audit reflects what a
 * visitor would actually get without re-running the renderer.
 *
 * @phpstan-type Breakdown array{resolved:int,withheld_low_confidence:int,parse_fail:int,empty:int}
 * @phpstan-type Stats array{generated_at:int,total:int,with_passage:int,resolved:int,parse_coverage:float,breakdown:Breakdown}
 */
final class CoverageAudit {
    /** Cron action that recomputes + persists the rollup (also the on-save target). */
    public const EVENT_HOOK = 'sermonator_bible_coverage_audit';

    /** The native Site Health "direct" test id. */
    public const SITE_HEALTH_TEST = 'sermonator_bible_coverage';

    /**
     * Informational DISPLAY threshold (percent) above which Site Health is green.
     * NOT a rollback trigger and NOT the helloao fetch-failure alarm (Phase 3b) —
     * parse-coverage is structural, see the class docblock.
     */
    public const GREEN_THRESHOLD = 90.0;

    /**
     * Resolves the published sermon ids to audit. Injected so the audit math is
     * unit-testable without a live WP_Query; defaults to the real query.
     *
     * @var callable():list<int>
     */
    private $postsProvider;

    /** @param callable():list<int>|null $postsProvider Resolve the published sermon ids. */
    public function __construct( ?callable $postsProvider = null ) {
        $this->postsProvider = $postsProvider ?? array( $this, 'queryPublishedSermons' );
    }

    /**
     * Wire the audit: the Site Health status test (pure read), the recurring
     * recompute cron, and the on-save recompute. The Site Health filter is the
     * only piece that runs on a normal admin GET, and it never writes.
     */
    public function hook(): void {
        add_filter( 'site_status_tests', array( $this, 'registerSiteHealthTest' ) );
        add_action( self::EVENT_HOOK, array( $this, 'run' ) );
        add_action( 'init', array( $this, 'ensureScheduled' ) );
        add_action( 'save_post_' . ID::POST_TYPE_SERMON, array( $this, 'onSave' ), 99, 1 );
    }

    /**
     * Ensure a recurring daily recompute exists. Idempotent (guarded by
     * wp_next_scheduled), so it is safe to call on every `init`.
     */
    public function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::EVENT_HOOK );
        }
    }

    /**
     * Recompute off the request after a sermon is saved. Skips autosaves/revisions
     * and debounces rapid saves into a single near-future run, so a bulk edit does
     * not re-audit the whole corpus per post.
     */
    public function onSave( int $postId ): void {
        if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $postId ) )
            || ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $postId ) )
        ) {
            return;
        }

        $delay = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
        wp_schedule_single_event( time() + $delay, self::EVENT_HOOK );
    }

    /**
     * Compute the parse-coverage rollup over the published corpus and persist it to
     * {@see ID::OPTION_BIBLE_STATS}. Returns the same rollup it stored.
     *
     * @return array{generated_at:int,total:int,with_passage:int,resolved:int,parse_coverage:float,breakdown:array{resolved:int,withheld_low_confidence:int,parse_fail:int,empty:int}}
     */
    public function run(): array {
        $ids       = ( $this->postsProvider )();
        $breakdown = array(
            'resolved'                => 0,
            'withheld_low_confidence' => 0,
            'parse_fail'              => 0,
            'empty'                   => 0,
        );

        foreach ( $ids as $id ) {
            $id      = (int) $id;
            $passage = (string) get_post_meta( $id, ID::META_BIBLE_PASSAGE, true );

            if ( '' === trim( $passage ) ) {
                ++$breakdown['empty'];
                continue;
            }

            $refs = $this->refsForPost( $id, $passage );

            if ( array() === $refs ) {
                ++$breakdown['parse_fail'];
                continue;
            }

            if ( $this->anyResolves( $refs ) ) {
                ++$breakdown['resolved'];
            } else {
                ++$breakdown['withheld_low_confidence'];
            }
        }

        $total       = count( $ids );
        $withPassage = $total - $breakdown['empty'];
        $resolved    = $breakdown['resolved'];

        $stats = array(
            'generated_at'   => time(),
            'total'          => $total,
            'with_passage'   => $withPassage,
            'resolved'       => $resolved,
            'parse_coverage' => self::percentage( $resolved, $withPassage ),
            'breakdown'      => $breakdown,
        );

        update_option( ID::OPTION_BIBLE_STATS, $stats, false );

        return $stats;
    }

    /**
     * Register the native Site Health "direct" status test. Pure read of the
     * precomputed option — does NOT recompute (no write-on-GET).
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
            'label' => __( 'Sermon scripture-reference coverage', 'sermonator' ),
            'test'  => array( $this, 'siteHealthResult' ),
        );

        return $tests;
    }

    /**
     * Build the Site Health result array from the precomputed rollup. Green when the
     * corpus has no passages to resolve OR parse-coverage is at/above the display
     * threshold; amber (recommended) otherwise. Reports the parse-coverage % and the
     * withheld/parse-fail counts so the headline cannot hide a suppressed reference.
     *
     * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
     */
    public function siteHealthResult(): array {
        $stored = get_option( ID::OPTION_BIBLE_STATS, array() );
        $stats  = is_array( $stored ) ? $stored : array();

        $badge = array(
            'label' => __( 'Sermons', 'sermonator' ),
            'color' => 'blue',
        );

        if ( ! isset( $stats['with_passage'] ) ) {
            return array(
                'label'       => __( 'Scripture-reference coverage has not been computed yet', 'sermonator' ),
                'status'      => 'recommended',
                'badge'       => $badge,
                'description' => '<p>' . esc_html__(
                    'Sermonator has not yet audited how many sermon scripture references resolve to a link. The audit runs on a schedule and whenever a sermon is saved.',
                    'sermonator'
                ) . '</p>',
                'actions'     => '',
                'test'        => self::SITE_HEALTH_TEST,
            );
        }

        $withPassage = (int) $stats['with_passage'];
        $resolved    = (int) ( $stats['resolved'] ?? 0 );
        $coverage    = isset( $stats['parse_coverage'] )
            ? (float) $stats['parse_coverage']
            : self::percentage( $resolved, $withPassage );
        $withheld    = (int) ( $stats['breakdown']['withheld_low_confidence'] ?? 0 );
        $parseFail   = (int) ( $stats['breakdown']['parse_fail'] ?? 0 );

        $green  = ( 0 === $withPassage ) || ( $coverage >= self::GREEN_THRESHOLD );
        $status = $green ? 'good' : 'recommended';

        $label = 0 === $withPassage
            ? __( 'No sermon scripture references to resolve', 'sermonator' )
            : __( 'Sermon scripture references resolve to links', 'sermonator' );

        $description = '<p>' . esc_html(
            sprintf(
                /* translators: 1: percentage, 2: resolved count, 3: total-with-passage count. */
                __( '%1$s%% of sermon scripture references resolve to a link (%2$d of %3$d sermons with a passage).', 'sermonator' ),
                self::formatPercent( $coverage ),
                $resolved,
                $withPassage
            )
        ) . '</p>';

        if ( $withheld > 0 || $parseFail > 0 ) {
            $description .= '<p>' . esc_html(
                sprintf(
                    /* translators: 1: withheld count, 2: parse-fail count. */
                    __( '%1$d reference set(s) were withheld as low-confidence and %2$d passage(s) could not be parsed; these are shown as plain text rather than a possibly-wrong link.', 'sermonator' ),
                    $withheld,
                    $parseFail
                )
            ) . '</p>';
        }

        return array(
            'label'       => $label,
            'status'      => $status,
            'badge'       => $badge,
            'description' => $description,
            'actions'     => '',
            'test'        => self::SITE_HEALTH_TEST,
        );
    }

    /**
     * Read the persisted rollup (pure read). Returns an empty array when the audit
     * has never run.
     *
     * @return array<string,mixed>
     */
    public static function stats(): array {
        $stored = get_option( ID::OPTION_BIBLE_STATS, array() );

        return is_array( $stored ) ? $stored : array();
    }

    /**
     * The refs to classify for a post: the stored envelope when it carries usable
     * refs, otherwise a live parse of the preserved passage label (so un-backfilled
     * sermons still count as ground truth). Never throws; never writes.
     *
     * @return list<array<string,mixed>>
     */
    private function refsForPost( int $postId, string $passage ): array {
        $stored = get_post_meta( $postId, ID::META_BIBLE_REFS, true );

        if ( is_string( $stored ) && '' !== $stored ) {
            $decoded = json_decode( $stored, true );
            if ( is_array( $decoded ) && isset( $decoded['refs'] ) && is_array( $decoded['refs'] ) ) {
                $refs = array_values( array_filter( $decoded['refs'], 'is_array' ) );
                if ( array() !== $refs ) {
                    return $refs;
                }
            }
        }

        // No usable stored envelope (un-backfilled / un-authored): live-parse the
        // preserved label as ground truth.
        $parsed = ReferenceParser::parse( $passage );
        $refs   = array();
        foreach ( $parsed['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                if ( is_array( $ref ) ) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * Does at least one ref resolve to a render-ready link? Uses the exact link-mode
     * contract of {@see \Sermonator\Frontend\BibleResolver} (in-canon + structurally
     * valid), so the audit numerator matches what a visitor would actually see.
     *
     * @param list<array<string,mixed>> $refs
     */
    private function anyResolves( array $refs ): bool {
        foreach ( $refs as $ref ) {
            $flags = RefValidator::validate( $ref );
            if ( $flags['inCanon'] && $flags['structurallyValid'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default query: every PUBLISHED sermon id (the audited corpus). Includes posts
     * with and without a passage so the denominator (with_passage) and the `empty`
     * bucket are both measured from one pass.
     *
     * @return list<int>
     */
    private function queryPublishedSermons(): array {
        $query = new \WP_Query( array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ) );

        return array_map( 'intval', $query->posts );
    }

    /** Percentage of $numerator over $denominator, one decimal, 0.0 when no denominator. */
    private static function percentage( int $numerator, int $denominator ): float {
        if ( $denominator <= 0 ) {
            return 0.0;
        }

        return round( $numerator / $denominator * 100, 1 );
    }

    /** Trim a one-decimal percentage to an integer-looking string when whole (90 not 90.0). */
    private static function formatPercent( float $percent ): string {
        if ( floor( $percent ) === $percent ) {
            return (string) (int) $percent;
        }

        return rtrim( rtrim( number_format( $percent, 1, '.', '' ), '0' ), '.' );
    }
}
