<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Bible;

use Sermonator\Admin\Authoring\MigrationGuard;
use Sermonator\Bible\TranslationRegistry;
use Sermonator\Schema\Identifiers as ID;

/**
 * Phase 3b Task 9 — the CACHE-WARMING engine for the inline-Bible verse text.
 *
 * Primes the disposable, gen|schema-keyed {@see ChapterCache} transient (and ONLY the
 * transient — disk snapshots are {@see \Sermonator\Migration\BibleChapterVendor}'s job)
 * for the chapters a sermon cites, so that when inline rendering is enabled those
 * chapters resolve from cache at render time with ZERO network I/O (design §3.6).
 *
 * Two producers, one shared warming core ({@see self::warmChapters()}):
 *
 *  - WARM-ON-SAVE ({@see self::warmForPost()}, wired in {@see hook()}): runs SYNCHRONOUSLY
 *    in the save request, AFTER {@see \Sermonator\Admin\Authoring\SermonRefsCapture}
 *    has written the {@see ID::META_BIBLE_REFS} envelope, warming that one sermon's cited
 *    chapters for the CURRENTLY-configured inline translation. Synchronous so an author
 *    confirming a reference observes a warm failure (a still-cold chapter falls open to
 *    the 3a link) at save time rather than silently at a later page render.
 *  - CLI BACKFILL ({@see self::warm()}, behind `wp sermonator bible warm`): a chunked
 *    sweep over every existing sermon's refs, draining the cited-chapter backlog.
 *
 * ## This is a write path — it clears the audio-backfill bar
 *
 *  - CACHE-ONLY: every write goes through {@see ChapterProvider::get(..., warmContext:true)}
 *    which, on a disk+cache miss, fetches + normalizes + {@see ChapterCache::set()}s the
 *    TRANSIENT cache. It NEVER writes post meta and NEVER mutates the preserved
 *    {@see ID::META_BIBLE_PASSAGE} or the {@see ID::META_BIBLE_REFS} envelope (#1 data
 *    preservation). The cited chapters are read-only input.
 *  - FILL-MISSING / IDEMPOTENT: a chapter already resolvable from disk or cache (a
 *    render-context — zero-network — {@see ChapterProvider::get()} that returns non-null)
 *    is SKIPPED, so a `--limit=N` run drains the missing tail and a re-run is a no-op.
 *    The cache/disk state IS the progress marker — no touched-id log of derived text is
 *    needed (a strictly nicer reversibility story than the meta backfill — design §3.6).
 *  - STRUCTURALLY REVERSIBLE: the only thing warming writes is the disposable transient
 *    cache. {@see self::rollback()} bumps {@see ID::OPTION_BIBLE_CACHE_GEN}, which folds
 *    into the cache key — every warmed entry instantly becomes unreachable (and expires by
 *    its MONTH TTL). The exact reverse, with no per-entry bookkeeping. This is the same
 *    bump `wp sermonator bible flush` performs.
 *  - MIGRATION-GATED: warming (and rollback) is inert unless the migration phase is
 *    `none` or `finalized` ({@see MigrationGuard}) — we never warm a moving target whose
 *    records the Verifier is still comparing to the detect-time manifest.
 *  - NEVER-FAIL-WRONG: a per-chapter fetch/normalize failure simply leaves the chapter
 *    un-warmed (it stays a 3a link at render time); {@see ChapterProvider::get()} never
 *    throws, and the off-render-path invariant it owns is preserved here verbatim
 *    (warming is the ONLY context that passes `warmContext:true`).
 */
final class BibleWarmer {
    /**
     * Upper bound on chapters warmed SYNCHRONOUSLY in a single sermon-save request, so a
     * scripture-dense sermon cannot serialize dozens of 5s live fetches into one save (the
     * off-render-path fan-out, on the editor path). The chunked CLI backfill drains the rest.
     */
    private const SAVE_WARM_CHAPTER_LIMIT = 6;

    /**
     * Resolves the candidate sermon ids (those carrying a {@see ID::META_BIBLE_REFS}
     * envelope). Injected so the sweep is unit-testable without a live WP_Query.
     *
     * @var callable():list<int>
     */
    private $candidatesProvider;

    /**
     * The chapter resolver. Injected so the warming logic is testable without disk /
     * network I/O; defaults to the off-render-path {@see ChapterProvider::get()}.
     *
     * @var callable(string,string,int,bool):(array<int,mixed>|null)
     */
    private $resolver;

    /**
     * @param callable():list<int>|null                                  $candidatesProvider
     *        Resolve the sermons carrying a refs envelope (CLI sweep); defaults to the real query.
     * @param callable(string,string,int,bool):(array<int,mixed>|null)|null $resolver
     *        Override the chapter resolver (tests); defaults to {@see ChapterProvider::get()}.
     */
    public function __construct( ?callable $candidatesProvider = null, ?callable $resolver = null ) {
        $this->candidatesProvider = $candidatesProvider ?? array( $this, 'queryCandidates' );
        $this->resolver           = $resolver ?? array( ChapterProvider::class, 'get' );
    }

    /**
     * Register the synchronous warm-on-save hooks, AFTER {@see SermonRefsCapture} so the
     * envelope this reads is already persisted:
     *  - classic POST: `save_post_<sermon>` priority 30 (RefsCapture is 20).
     *  - REST insert:  `rest_after_insert_<sermon>` priority 20 (RefsCapture is 10).
     */
    public function hook(): void {
        add_action( 'save_post_' . ID::POST_TYPE_SERMON, array( $this, 'warmOnSave' ), 30, 2 );
        add_action( 'rest_after_insert_' . ID::POST_TYPE_SERMON, array( $this, 'warmOnSaveRest' ), 20, 1 );
    }

    /**
     * Classic full-page POST warm-on-save. Skips autosaves/revisions so a draft autosave
     * does not trigger synchronous network warming on every keystroke-interval.
     */
    public function warmOnSave( int $post_id, \WP_Post $post ): void {
        if ( $post->post_type !== ID::POST_TYPE_SERMON ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $this->warmForPost( $post_id );
    }

    /** REST-insert warm-on-save (fires once on a real insert/update, never on autosave). */
    public function warmOnSaveRest( \WP_Post $post ): void {
        if ( $post->post_type !== ID::POST_TYPE_SERMON ) {
            return;
        }
        $this->warmForPost( $post->ID );
    }

    /**
     * Warm one sermon's cited chapters for the currently-configured inline translation.
     * Synchronous: the warm is attempted in the same request as the save so the author
     * sees failures (a cold chapter → 3a link) immediately, not at a later render.
     *
     * @return array{translation:string,processed:int,warmed:int,skipped:int,failed:int,gated:bool}
     */
    public function warmForPost( int $post_id ): array {
        $translation = $this->inlineTranslation();

        // Warm-on-save has NO render consumer until inline is enabled (the render path is
        // independently kill-switched). Warming pre-enable would fire SYNCHRONOUS live
        // helloao fetches on every sermon save on a default (cold disk+cache) install —
        // serializing one 5s fetch per cited chapter into the save request, the exact
        // N-serial fan-out the off-render-path design forbids, now on the editor path. The
        // pre-enable bulk warm is the CLI's job (the un-enableable gate requires a full
        // vendor+warm pass first; once vendored, the fill-missing disk check makes
        // warm-on-save a near no-op anyway).
        if ( ! get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false ) ) {
            return $this->gatedResult( $translation );
        }

        // Never warm a moving target: the same gate every other cache/meta write path uses.
        if ( ! MigrationGuard::editingAllowed() ) {
            return $this->gatedResult( $translation );
        }

        // Bound the SYNCHRONOUS per-save warm so a single scripture-dense sermon can never
        // serialize dozens of 5s fetches into one save request; the chunked CLI backfill
        // (wp sermonator bible warm) drains any remainder.
        $chapters = $this->citedChaptersForPost( $post_id );
        $result   = $this->warmChapters( $translation, $chapters, self::SAVE_WARM_CHAPTER_LIMIT );
        $result['gated'] = false;

        return $result;
    }

    /**
     * CHUNKED CLI backfill: collect every cited chapter across all sermons carrying a refs
     * envelope (deduped), then warm the MISSING ones up to $limit. Re-running drains the
     * next chunk because already-warmed chapters now resolve from cache (zero-network) and
     * are skipped — the cache state is the resumable progress marker (mirrors the vendor's
     * disk-presence chunking).
     *
     * @param int $limit 0 = no limit; otherwise warm at most N MISSING chapters this run.
     *
     * @return array{translation:string,sermons:int,cited:int,processed:int,warmed:int,skipped:int,failed:int,gated:bool}
     */
    public function warm( int $limit = 0 ): array {
        $translation = $this->inlineTranslation();

        if ( ! MigrationGuard::editingAllowed() ) {
            $gated            = $this->gatedResult( $translation );
            $gated['sermons'] = 0;
            $gated['cited']   = 0;
            return $gated;
        }

        $ids      = ( $this->candidatesProvider )();
        $chapters = array();
        foreach ( $ids as $id ) {
            foreach ( $this->citedChaptersForPost( (int) $id ) as $chapter ) {
                // Dedup across sermons: many sermons cite the same chapter.
                $chapters[ $chapter['book'] . ':' . $chapter['chapter'] ] = $chapter;
            }
        }
        $chapters = array_values( $chapters );

        $result            = $this->warmChapters( $translation, $chapters, $limit );
        $result['gated']   = false;
        $result['sermons'] = count( $ids );
        $result['cited']   = count( $chapters );

        return $result;
    }

    /**
     * Reverse warming: bump {@see ID::OPTION_BIBLE_CACHE_GEN} so every warmed transient
     * entry (whose key folds the generation) becomes unreachable and expires by TTL — the
     * exact, bookkeeping-free reverse of a cache-only write. Migration-gated like a write.
     *
     * Identical effect to `wp sermonator bible flush`; surfaced here as the warm engine's
     * own reverse so `bible warm --rollback` is self-describing.
     *
     * @return array{from:int,to:int,gated:bool}
     */
    public function rollback(): array {
        if ( ! MigrationGuard::editingAllowed() ) {
            return array( 'from' => 0, 'to' => 0, 'gated' => true );
        }

        $current = (int) get_option( ID::OPTION_BIBLE_CACHE_GEN, 0 );
        $next    = $current + 1;
        update_option( ID::OPTION_BIBLE_CACHE_GEN, $next );

        return array( 'from' => $current, 'to' => $next, 'gated' => false );
    }

    /**
     * The shared warming core: for each cited chapter, SKIP it when it already resolves
     * off the render path (disk or cache — a zero-network {@see ChapterProvider::get()}
     * returning non-null = fill-missing), otherwise warm it once in warm-context (the only
     * place a network fetch is allowed). $limit bounds the number of MISSING chapters
     * actually warmed this run (skips are free and uncounted against it).
     *
     * @param list<array{book:string,chapter:int}> $chapters
     *
     * @return array{translation:string,processed:int,warmed:int,skipped:int,failed:int}
     */
    private function warmChapters( string $translation, array $chapters, int $limit ): array {
        $processed = 0;
        $warmed    = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ( $chapters as $chapter ) {
            $book = $chapter['book'];
            $num  = $chapter['chapter'];

            // FILL-MISSING: already on disk or in cache? Render-context get = ZERO network.
            if ( null !== ( $this->resolver )( $translation, $book, $num, false ) ) {
                ++$skipped;
                continue;
            }

            // CHUNKABLE: stop after $limit MISSING chapters so a re-run drains the rest.
            if ( $limit > 0 && $processed >= $limit ) {
                break;
            }
            ++$processed;

            // Warm-context: the ONLY path allowed to fetch + normalize + cache.
            $result = ( $this->resolver )( $translation, $book, $num, true );
            if ( null !== $result ) {
                ++$warmed;
            } else {
                // never-fail-WRONG: a failed warm leaves the chapter cold → 3a link.
                ++$failed;
            }
        }

        return array(
            'translation' => $translation,
            'processed'   => $processed,
            'warmed'      => $warmed,
            'skipped'     => $skipped,
            'failed'      => $failed,
        );
    }

    /**
     * The unique (book, chapter) pairs a sermon's refs envelope cites. Read-only: it never
     * mutates the envelope or the passage. A cross-chapter ref expands to each chapter in
     * its inclusive range (guarded against a pathological span). Unknown/blank book codes
     * and chapterless refs are skipped.
     *
     * @return list<array{book:string,chapter:int}>
     */
    private function citedChaptersForPost( int $post_id ): array {
        $raw = (string) get_post_meta( $post_id, ID::META_BIBLE_REFS, true );
        if ( '' === $raw ) {
            return array();
        }

        $env  = json_decode( $raw, true );
        $refs = ( is_array( $env ) && isset( $env['refs'] ) && is_array( $env['refs'] ) ) ? $env['refs'] : array();

        $out = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array( $ref ) ) {
                continue;
            }

            $book = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
            if ( '' === $book ) {
                continue;
            }

            $start = (int) ( $ref['chapterStart'] ?? 0 );
            if ( $start < 1 ) {
                continue;
            }

            $end = isset( $ref['chapterEnd'] ) && null !== $ref['chapterEnd'] ? (int) $ref['chapterEnd'] : $start;
            if ( $end < $start || ( $end - $start ) > 150 ) {
                // Malformed/pathological range — warm only the cited start chapter.
                $end = $start;
            }

            for ( $chapter = $start; $chapter <= $end; $chapter++ ) {
                $out[ $book . ':' . $chapter ] = array( 'book' => $book, 'chapter' => $chapter );
            }
        }

        return array_values( $out );
    }

    /** The validated, currently-configured inline-text translation id (e.g. ENGWEBP). */
    private function inlineTranslation(): string {
        return TranslationRegistry::current()->inlineTranslation();
    }

    /**
     * The zeroed, migration-gated result envelope.
     *
     * @return array{translation:string,processed:int,warmed:int,skipped:int,failed:int,gated:bool}
     */
    private function gatedResult( string $translation ): array {
        return array(
            'translation' => $translation,
            'processed'   => 0,
            'warmed'      => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'gated'       => true,
        );
    }

    /**
     * Default candidate query: native sermons carrying a non-empty structured refs
     * envelope (the only sermons with chapters to warm).
     *
     * @return list<int>
     */
    private function queryCandidates(): array {
        $query = new \WP_Query( array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array( 'key' => ID::META_BIBLE_REFS, 'compare' => 'EXISTS' ),
                array( 'key' => ID::META_BIBLE_REFS, 'value' => '', 'compare' => '!=' ),
            ),
        ) );

        return array_map( 'intval', $query->posts );
    }
}
