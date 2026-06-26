<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Admin\Authoring\MigrationGuard;
use Sermonator\Bible\ReferenceParser;
use Sermonator\Bible\RefValidator;
use Sermonator\Bible\TranslationRegistry;
use Sermonator\Schema\BibleBookMap;
use Sermonator\Schema\Identifiers as ID;

/**
 * Backfills the structured {@see ID::META_BIBLE_REFS} envelope (and dual-writes the
 * {@see ID::TAX_BOOK} taxonomy) from the preserved, human-authored legacy
 * {@see ID::META_BIBLE_PASSAGE} free-text label, so legacy sermons gain queryable,
 * link-ready references without an authoring round-trip.
 *
 * This is a write path, so it is a CARBON COPY of the {@see \Sermonator\Frontend\Feed\AudioSizeBackfill}
 * guardrails (see the spec rollback story, §3 / §4 "BibleRefsBackfill"):
 *
 *  - NATIVE-ONLY: reads {@see ID::META_BIBLE_PASSAGE} and writes only the NATIVE
 *    companions {@see ID::META_BIBLE_REFS} / {@see ID::META_BIBLE_REFS_UNPARSEABLE}
 *    plus {@see ID::TAX_BOOK} terms on `sermonator_sermon` posts. It NEVER mutates
 *    `META_BIBLE_PASSAGE` — the preserved display label and parser input (#1 data
 *    preservation), so legacy byte-fixity is unaffected.
 *  - FILL-MISSING-ONLY: candidates are sermons that have a non-empty passage but no
 *    `META_BIBLE_REFS` (and no prior unparseable sentinel). An existing envelope is
 *    NEVER overwritten — in particular a `source:'authoring'` envelope is sacrosanct
 *    (the candidate query excludes it; the reverse() also re-checks).
 *  - EXACTLY REVERSIBLE: every touched post id is recorded in {@see ID::OPTION_BIBLE_REFS_BACKFILL_LOG}
 *    together with precisely WHAT was written (refs vs sentinel) and WHICH term ids
 *    were newly added, so {@see self::reverse()} can delete exactly those rows and
 *    detach exactly those terms — restoring the pre-backfill state. Reversibility is
 *    hand-wired via the log, NOT inferred from `Identifiers::metaKeys()` (spec §3).
 *  - IDEMPOTENT + CHUNKABLE: a written envelope (or stamped sentinel) drops the post
 *    out of the candidate set, so a repeated `--limit=N` drains the backlog safely and
 *    a crash leaves a consistent partial state.
 *  - MIGRATION-GATED: writes (and reverse) are inert unless the migration phase is
 *    `none` or `finalized` ({@see MigrationGuard}); a stray write mid-migration could
 *    diverge a record from the Verifier's detect-time manifest. The read-only DRY-RUN
 *    report is always permitted (it touches nothing).
 *  - DRY-RUN-FIRST: {@see self::run()} defaults to dry-run (parse-and-count, write
 *    nothing) — the default-safe mode. A real write requires an explicit `$dryRun=false`.
 *
 * @phpstan-type LogEntry array{refs:bool,sentinel:bool,terms:list<int>}
 */
final class BibleRefsBackfill {
    /** Schema version stamped on every written envelope (mirrors the authoring producer). */
    public const ENVELOPE_VERSION = 1;

    /** Sentinel value stamped when a non-empty passage parses to zero refs. */
    private const UNPARSEABLE_SENTINEL = '1';

    /**
     * Resolves the candidate sermon ids. Injected so the parse/envelope/reversibility
     * logic is unit-testable without a live WP_Query; defaults to the real query.
     *
     * @var callable(int):list<int>
     */
    private $candidatesProvider;

    /** @param callable(int):list<int>|null $candidatesProvider Resolve a limit to candidate post ids. */
    public function __construct( ?callable $candidatesProvider = null ) {
        $this->candidatesProvider = $candidatesProvider ?? array( $this, 'queryCandidates' );
    }

    /**
     * Backfill (or, by default, dry-run report) structured refs for legacy sermons.
     *
     * @param int  $limit  0 = no limit; otherwise process at most this many candidates.
     * @param bool $dryRun TRUE (default) = parse and count, writing nothing.
     *
     * @return array{candidates:int,written:int,unparseable:int,ids:list<int>,dryRun:bool,gated:bool}
     */
    public function run( int $limit = 0, bool $dryRun = true ): array {
        // A real write during an active migration could diverge a record from the
        // Verifier's detect-time manifest, so writes are gated. The read-only dry-run
        // report stays available for inspection at any phase.
        if ( ! $dryRun && ! MigrationGuard::editingAllowed() ) {
            return array(
                'candidates'  => 0,
                'written'     => 0,
                'unparseable' => 0,
                'ids'         => array(),
                'dryRun'      => $dryRun,
                'gated'       => true,
            );
        }

        $ids         = ( $this->candidatesProvider )( $limit );
        $written     = 0;
        $unparseable = 0;
        $touchedIds  = array();

        // Resolve the church's source versification once: it tags every ref so the
        // 3b inline-eligibility gate can detect ESV-vs-WEB divergence later. Computed
        // here (a write/resolution path), never in the pure Renderer.
        $srcVersification = TranslationRegistry::current()->linkVersion();

        foreach ( $ids as $id ) {
            $id = (int) $id;

            $passage = (string) get_post_meta( $id, ID::META_BIBLE_PASSAGE, true );
            if ( '' === trim( $passage ) ) {
                continue;
            }

            // Race/idempotency guard: re-read immediately before writing so an envelope
            // written since the query (a concurrent run, or an author save) is never
            // overwritten — authoring data is sacrosanct (#1 data preservation).
            if ( '' !== (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ) ) {
                continue;
            }

            // Already stamped unparseable on a prior pass: leave it alone so a re-run
            // (even one fed a stale id list) neither double-counts nor re-touches it.
            if ( '' !== (string) get_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE, true ) ) {
                continue;
            }

            $refs = $this->buildRefs( $passage, $srcVersification );

            if ( array() === $refs ) {
                // Parsed a non-empty passage to zero refs: stamp the measurable
                // per-post fail-open sentinel rather than silently doing nothing.
                if ( ! $dryRun ) {
                    update_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE, self::UNPARSEABLE_SENTINEL );
                    $this->logEntry( $id, false, true, array() );
                }
                ++$unparseable;
                $touchedIds[] = $id;
                continue;
            }

            if ( ! $dryRun ) {
                update_post_meta( $id, ID::META_BIBLE_REFS, $this->envelope( $refs ) );
                $addedTermIds = $this->assignBookTerms( $id, $refs );
                $this->logEntry( $id, true, false, $addedTermIds );
            }
            ++$written;
            $touchedIds[] = $id;
        }

        return array(
            'candidates'  => count( $ids ),
            'written'     => $written,
            'unparseable' => $unparseable,
            'ids'         => $touchedIds,
            'dryRun'      => $dryRun,
            'gated'       => false,
        );
    }

    /**
     * Reverse the backfill: for every logged post delete exactly the refs/sentinel
     * THIS command wrote and detach exactly the TAX_BOOK terms it added, then clear
     * the log. Restores the exact pre-backfill state.
     *
     * Inert during an active migration (same gate as a write); see {@see self::run()}.
     *
     * @return array{reversed:int,ids:list<int>,gated:bool}
     */
    public function reverse(): array {
        if ( ! MigrationGuard::editingAllowed() ) {
            return array( 'reversed' => 0, 'ids' => array(), 'gated' => true );
        }

        $log      = $this->log();
        $reversed = 0;
        $ids      = array();

        foreach ( $log as $id => $entry ) {
            $id    = (int) $id;
            $ids[] = $id;

            if ( get_post_type( $id ) !== ID::POST_TYPE_SERMON ) {
                continue;
            }

            if ( $entry['refs'] ) {
                // Only undo an envelope — AND the TAX_BOOK terms we dual-wrote alongside
                // it — when the backfill still owns the post's bible data. If an author
                // has re-saved since the backfill (source:'authoring'), the envelope is
                // theirs and the book terms are load-bearing for that authoring data, so
                // leave BOTH intact: never clobber authoring, stay exactly reversible.
                // (terms are only ever logged on this refs branch; the sentinel branch
                // logs terms=array(), so this gate fully covers term removal.)
                if ( $this->isOwnBackfillEnvelope( $id ) ) {
                    delete_post_meta( $id, ID::META_BIBLE_REFS );

                    if ( array() !== $entry['terms'] ) {
                        wp_remove_object_terms( $id, $entry['terms'], ID::TAX_BOOK );
                    }
                }
            }

            if ( $entry['sentinel'] ) {
                delete_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE );
            }

            ++$reversed;
        }

        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );

        return array( 'reversed' => $reversed, 'ids' => $ids, 'gated' => false );
    }

    /**
     * Parse a raw passage into a list of enriched Ref rows (source/confidence/
     * srcVersification stamped). In-canon, structurally-valid refs only — anything
     * the validator rejects is dropped (never fail-*wrong*).
     *
     * @return list<array<string,mixed>>
     */
    private function buildRefs( string $passage, string $srcVersification ): array {
        $parsed = ReferenceParser::parse( $passage );
        $refs   = array();

        foreach ( $parsed['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                if ( ! is_array( $ref ) ) {
                    continue;
                }

                $flags = RefValidator::validate( $ref );
                if ( ! $flags['inCanon'] || ! $flags['structurallyValid'] ) {
                    continue;
                }

                $ref['source']           = 'backfill';
                $ref['confidence']       = $this->confidence( $flags );
                $ref['srcVersification'] = $srcVersification;
                $refs[]                  = $ref;
            }
        }

        return $refs;
    }

    /**
     * Backfill confidence is honest about its provenance: a heuristic parse of legacy
     * free text is at best `probable` — never `exact` (which is reserved for an author
     * who confirmed the structured chips). A ref the validator could not fully clear
     * is `ambiguous`.
     *
     * @param array{inCanon:bool,structurallyValid:bool,inlineEligible:bool} $flags
     */
    private function confidence( array $flags ): string {
        return ( $flags['inCanon'] && $flags['structurallyValid'] ) ? 'probable' : 'ambiguous';
    }

    /**
     * Serialize the versioned envelope written to META_BIBLE_REFS.
     *
     * @param list<array<string,mixed>> $refs
     */
    private function envelope( array $refs ): string {
        return (string) wp_json_encode( array( 'v' => self::ENVELOPE_VERSION, 'refs' => $refs ) );
    }

    /**
     * Append-assign the TAX_BOOK terms named by the refs and return the ids that were
     * NEWLY added by this call (so reverse() can detach exactly those, leaving any term
     * the post already carried untouched). Idempotent: re-adding a present term is a no-op.
     *
     * @param list<array<string,mixed>> $refs
     *
     * @return list<int>
     */
    private function assignBookTerms( int $id, array $refs ): array {
        $names = $this->bookNames( $refs );
        if ( array() === $names ) {
            return array();
        }

        $before = $this->bookTermIds( $id );
        wp_set_object_terms( $id, $names, ID::TAX_BOOK, true );
        $after = $this->bookTermIds( $id );

        return array_values( array_diff( $after, $before ) );
    }

    /**
     * Unique canonical book display names for the refs (USFM -> BibleCanon name).
     *
     * @param list<array<string,mixed>> $refs
     *
     * @return list<string>
     */
    private function bookNames( array $refs ): array {
        $byCode = array_flip( BibleBookMap::usfm() );
        $names  = array();

        foreach ( $refs as $ref ) {
            $usfm = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
            if ( isset( $byCode[ $usfm ] ) && ! in_array( $byCode[ $usfm ], $names, true ) ) {
                $names[] = $byCode[ $usfm ];
            }
        }

        return $names;
    }

    /** @return list<int> The TAX_BOOK term ids currently attached to the post. */
    private function bookTermIds( int $id ): array {
        $terms = wp_get_object_terms( $id, ID::TAX_BOOK, array( 'fields' => 'ids' ) );
        if ( ! is_array( $terms ) ) {
            return array();
        }

        return array_values( array_map( 'intval', $terms ) );
    }

    /**
     * Is the post's current envelope still the one this backfill wrote? Guards reverse()
     * against clobbering an author's later edit (source:'authoring').
     */
    private function isOwnBackfillEnvelope( int $id ): bool {
        $stored = get_post_meta( $id, ID::META_BIBLE_REFS, true );
        if ( ! is_string( $stored ) || '' === $stored ) {
            return false;
        }

        $decoded = json_decode( $stored, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['refs'] ) || ! is_array( $decoded['refs'] ) ) {
            return false;
        }

        foreach ( $decoded['refs'] as $ref ) {
            // Any non-backfill producer in the envelope means an author touched it.
            if ( ! is_array( $ref ) || ( $ref['source'] ?? '' ) !== 'backfill' ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Default candidate query: native sermons with a non-empty legacy passage but no
     * structured envelope and no prior unparseable sentinel (so re-runs are idempotent).
     *
     * @return list<int>
     */
    private function queryCandidates( int $limit ): array {
        $query = new \WP_Query( array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
            'posts_per_page'         => $limit > 0 ? $limit : -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array( 'key' => ID::META_BIBLE_PASSAGE, 'compare' => 'EXISTS' ),
                array( 'key' => ID::META_BIBLE_PASSAGE, 'value' => '', 'compare' => '!=' ),
                array( 'key' => ID::META_BIBLE_REFS, 'compare' => 'NOT EXISTS' ),
                array( 'key' => ID::META_BIBLE_REFS_UNPARSEABLE, 'compare' => 'NOT EXISTS' ),
            ),
        ) );

        return array_map( 'intval', $query->posts );
    }

    /**
     * Read the reverse log, normalized to `postId => LogEntry`.
     *
     * @return array<int,array{refs:bool,sentinel:bool,terms:list<int>}>
     */
    private function log(): array {
        $raw = get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $log = array();
        foreach ( $raw as $id => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $terms = isset( $entry['terms'] ) && is_array( $entry['terms'] )
                ? array_values( array_map( 'intval', $entry['terms'] ) )
                : array();
            $log[ (int) $id ] = array(
                'refs'     => ! empty( $entry['refs'] ),
                'sentinel' => ! empty( $entry['sentinel'] ),
                'terms'    => $terms,
            );
        }

        return $log;
    }

    /**
     * Record exactly what was written for one post so reverse() can undo precisely it.
     *
     * @param list<int> $termIds
     */
    private function logEntry( int $id, bool $refs, bool $sentinel, array $termIds ): void {
        $log        = $this->log();
        $log[ $id ] = array(
            'refs'     => $refs,
            'sentinel' => $sentinel,
            'terms'    => array_values( array_map( 'intval', $termIds ) ),
        );
        update_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, $log, false );
    }
}
