<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Admin\Authoring\MigrationGuard;
use Sermonator\Bible\VersificationGate;
use Sermonator\Frontend\Bible\ChapterFetcher;
use Sermonator\Frontend\Bible\ChapterNormalizer;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers as ID;

/**
 * Phase 3b Task 8 — the ON-DEMAND vendoring engine for the inline-Bible verse text.
 *
 * Fetches ({@see ChapterFetcher}) + normalizes ({@see ChapterNormalizer}) the public-
 * domain ENGWEBP text one chapter at a time and writes a SCHEMA-STAMPED per-chapter
 * snapshot to the uploads directory:
 *
 *     wp-content/uploads/<BIBLE_VENDOR_DIR>/<TRANSLATION>/<BOOK>/<chapter>.json
 *     = { "schema": BIBLE_CACHE_SCHEMA_VERSION, "chapter": [ …normalized render shape… ] }
 *
 * This is the exact on-disk envelope {@see \Sermonator\Frontend\Bible\ChapterProvider}
 * reads at the FRONT of its disk → cache → (warm-only) fetch → null order, so a complete
 * snapshot lets the render path serve inline verse text with ZERO network I/O (design
 * §3.4 / §3.6).
 *
 * ## This is a write path — it clears the audio-backfill bar
 *
 *  - REVERSIBLE: the entire snapshot is disposable, vendored data — never authoritative.
 *    {@see self::rollback()} deletes the per-translation snapshot directory in full, the
 *    exact reverse of vendoring. No touched-id log is needed (a strictly nicer
 *    reversibility story than the meta backfill — design §3.6); the snapshot's presence
 *    on disk IS the record.
 *  - FILL-MISSING / IDEMPOTENT: a chapter already on disk under the CURRENT
 *    {@see ID::BIBLE_CACHE_SCHEMA_VERSION} is skipped, so a `--limit=N` run drains the
 *    backlog and a re-run is a no-op. A stale-schema file is NOT current and is re-
 *    vendored (the on-disk analogue of the cache-key schema fold). `--force` re-vendors
 *    everything.
 *  - MIGRATION-GATED: a real write (and rollback) is inert unless the migration phase is
 *    `none` or `finalized` ({@see MigrationGuard}); the read-only DRY-RUN report and the
 *    offline audit are always permitted.
 *  - DRY-RUN-FIRST: {@see self::vendor()} defaults to dry-run (count what WOULD be
 *    fetched, touching neither the network nor the disk). A real fetch+write is opt-in.
 *  - NEVER-FAIL-WRONG: a per-chapter fetch/normalize failure falls open (the chapter is
 *    simply left un-vendored → it stays a 3a link at render time), never a partial /
 *    corrupt snapshot.
 *
 * ## ENGWEBP only
 *
 * Vendoring is refused for any translation that is not BOTH public-domain AND an audited
 * inline target ({@see BibleTranslations::all()}). Today that is ENGWEBP alone: ENGKJV is
 * public-domain but inline-INELIGIBLE (its divergences are unaudited), and BSB is license-
 * ambiguous. We never redistribute text we are not licensed to host (design §3.3).
 *
 * ## Offline count-diff audit
 *
 * {@see self::auditCountDiff()} is a never-network completeness BACKSTOP for the
 * {@see VersificationGate} divergent-zone blocklist (risk §5.2). It diffs the vendored
 * WEB per-chapter PRESENT-verse count against a small vendored reference (critical-text /
 * ESV-family) count table and REPORTS — never auto-commits — chapters whose counts
 * disagree and that are NOT already modeled, as candidate divergent-zone ADDITIONS for a
 * human to triage. It cannot detect a zero-net-delta renumber (that is why the blocklist
 * is hand-authored and load-bearing), but it surfaces every net-delta chapter outside the
 * model — the signal that "the blocklist was incomplete".
 */
final class BibleChapterVendor {
    /**
     * Number of chapters per USFM book (the 66-book Protestant canon helloao keys its
     * ENGWEBP data on). Sum = 1189 — the full snapshot size (design §3.4). Drives both
     * the vendoring sweep and the snapshot-complete hard-gate check.
     *
     * @var array<string,int>
     */
    private const CHAPTER_COUNTS = array(
        'GEN' => 50, 'EXO' => 40, 'LEV' => 27, 'NUM' => 36, 'DEU' => 34,
        'JOS' => 24, 'JDG' => 21, 'RUT' => 4,  '1SA' => 31, '2SA' => 24,
        '1KI' => 22, '2KI' => 25, '1CH' => 29, '2CH' => 36, 'EZR' => 10,
        'NEH' => 13, 'EST' => 10, 'JOB' => 42, 'PSA' => 150, 'PRO' => 31,
        'ECC' => 12, 'SNG' => 8,  'ISA' => 66, 'JER' => 52, 'LAM' => 5,
        'EZK' => 48, 'DAN' => 12, 'HOS' => 14, 'JOL' => 3,  'AMO' => 9,
        'OBA' => 1,  'JON' => 4,  'MIC' => 7,  'NAM' => 3,  'HAB' => 3,
        'ZEP' => 3,  'HAG' => 2,  'ZEC' => 14, 'MAL' => 4,
        'MAT' => 28, 'MRK' => 16, 'LUK' => 24, 'JHN' => 21, 'ACT' => 28,
        'ROM' => 16, '1CO' => 16, '2CO' => 13, 'GAL' => 6,  'EPH' => 6,
        'PHP' => 4,  'COL' => 4,  '1TH' => 5,  '2TH' => 3,  '1TI' => 6,
        '2TI' => 4,  'TIT' => 3,  'PHM' => 1,  'HEB' => 13, 'JAS' => 5,
        '1PE' => 5,  '2PE' => 3,  '1JN' => 5,  '2JN' => 1,  '3JN' => 1,
        'JUD' => 1,  'REV' => 22,
    );

    /**
     * SMALL vendored reference (critical-text / ESV-family) PRESENT-verse counts for the
     * count-diff audit — `USFM => [chapter => expectedPresentVerseCount]`.
     *
     * ## Provenance (auditable seed)
     *
     * Each value is the modern critical-text (ESV / NA-UBS) present-verse count = the
     * traditional (Byzantine / KJV) chapter count MINUS the verses that text BRACKETS or
     * OMITS. Where WEB (whose NT carries the Majority/Byzantine basis) keeps the verse,
     * the WEB present count exceeds this reference by the net delta, and the audit flags
     * the chapter — for a HUMAN to decide whether it is an L7 RENUMBER (add to the
     * blocklist) or an L9 GAP (already handled at render time by
     * {@see \Sermonator\Bible\RefValidator::rangeWithinChapter()}; design §2). The table
     * is intentionally small (the well-documented NT critical-text omissions) and never
     * authoritative — it only proposes candidates.
     *
     * The two `alreadyModeled` rows (ROM 16, 2CO 13) are included to demonstrate the
     * audit correctly buckets a count-delta that is ALREADY in
     * {@see VersificationGate::divergentZones()} as modeled (not a proposed addition).
     *
     * @var array<string,array<int,int>>
     */
    private const REFERENCE_VERSE_COUNTS = array(
        // Critical-text single/multi-verse omissions (unmodeled gap chapters → proposed).
        'MAT' => array( 17 => 26, 18 => 34, 23 => 38 ),
        'MRK' => array( 7 => 36, 9 => 48, 11 => 32, 15 => 46 ),
        'LUK' => array( 17 => 36, 23 => 55 ),
        'JHN' => array( 5 => 46 ),
        'ACT' => array( 8 => 39, 15 => 40, 24 => 26, 28 => 30 ),
        // Count-deltas that are ALREADY modeled in VersificationGate (→ alreadyModeled).
        'ROM' => array( 16 => 26 ),
        '2CO' => array( 13 => 13 ),
    );

    /**
     * The per-chapter fetcher. Injected so the engine is testable without live network
     * I/O; defaults to the hardened, fail-open {@see ChapterFetcher::fetch()}.
     *
     * @var callable(string,string,int):(array<string,mixed>|null)
     */
    private $fetcher;

    /**
     * @param callable(string,string,int):(array<string,mixed>|null)|null $fetcher
     *        Override the raw-chapter fetcher (tests); defaults to {@see ChapterFetcher::fetch()}.
     */
    public function __construct( ?callable $fetcher = null ) {
        $this->fetcher = $fetcher ?? array( ChapterFetcher::class, 'fetch' );
    }

    /**
     * Vendor (or, by default, dry-run report) the per-chapter ENGWEBP snapshot.
     *
     * @param string $translation Inline target id (must be PD + inline-eligible).
     * @param bool   $dryRun      TRUE (default) = count what WOULD be vendored, no network/disk.
     * @param bool   $force       Re-vendor chapters already present (otherwise fill-missing).
     * @param int    $limit       0 = no limit; otherwise process at most N MISSING chapters this run.
     *
     * @return array{
     *     translation:string,dryRun:bool,force:bool,
     *     processed:int,written:int,failed:int,skipped:int,
     *     status:array{total:int,present:int,missing:int,complete:bool},
     *     gated:bool,refused:?string,error:?string
     * }
     */
    public function vendor( string $translation, bool $dryRun = true, bool $force = false, int $limit = 0 ): array {
        $translation = trim( $translation );

        $base = $this->emptyResult( $translation, $dryRun, $force );

        // ENGWEBP only — refuse any non-PD / inline-ineligible translation.
        $refusal = self::translationRefusal( $translation );
        if ( null !== $refusal ) {
            $base['refused'] = $refusal;
            return $base;
        }

        // A real write during an active migration could diverge a record from the
        // Verifier's detect-time manifest (the snapshot itself is disposable, but we hold
        // the same bar as every other write path). The dry-run report stays available.
        if ( ! $dryRun && ! MigrationGuard::editingAllowed() ) {
            $base['gated'] = true;
            return $base;
        }

        $baseDir = self::vendorBaseDir( $translation );
        if ( null === $baseDir ) {
            $base['error'] = 'The uploads directory is unavailable; cannot vendor.';
            return $base;
        }

        $processed = 0;
        $written   = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ( self::CHAPTER_COUNTS as $book => $chapters ) {
            for ( $chapter = 1; $chapter <= $chapters; $chapter++ ) {
                $path = $baseDir . '/' . $book . '/' . $chapter . '.json';

                // FILL-MISSING: a schema-current snapshot is left untouched (unless force).
                if ( ! $force && self::snapshotCurrent( $path ) ) {
                    ++$skipped;
                    continue;
                }

                // CHUNKABLE: stop after $limit MISSING chapters so a re-run drains the rest.
                if ( $limit > 0 && $processed >= $limit ) {
                    break 2;
                }
                ++$processed;

                if ( $dryRun ) {
                    // Report-only: no network, no write.
                    continue;
                }

                $raw = ( $this->fetcher )( $translation, $book, $chapter );
                if ( ! is_array( $raw ) ) {
                    // never-fail-WRONG: a failed fetch leaves the chapter un-vendored.
                    ++$failed;
                    continue;
                }

                $normalized = ChapterNormalizer::normalize( $raw );
                if ( array() === $normalized ) {
                    // A body that normalizes to nothing usable is not a chapter — never
                    // poison the snapshot with an empty file.
                    ++$failed;
                    continue;
                }

                if ( self::writeSnapshot( $path, $normalized ) ) {
                    ++$written;
                } else {
                    ++$failed;
                }
            }
        }

        $base['processed'] = $processed;
        $base['written']   = $written;
        $base['failed']    = $failed;
        $base['skipped']   = $skipped;
        $base['status']    = self::snapshotStatus( $translation );

        return $base;
    }

    /**
     * Reverse vendoring: delete the entire per-translation snapshot directory (the exact
     * inverse of {@see self::vendor()} — the snapshot is disposable, so a full directory
     * removal restores the pre-vendor state). Migration-gated like a write.
     *
     * @return array{translation:string,removed:int,gated:bool,error:?string}
     */
    public function rollback( string $translation ): array {
        $translation = trim( $translation );

        if ( ! MigrationGuard::editingAllowed() ) {
            return array( 'translation' => $translation, 'removed' => 0, 'gated' => true, 'error' => null );
        }

        $baseDir = self::vendorBaseDir( $translation );
        if ( null === $baseDir ) {
            return array(
                'translation' => $translation,
                'removed'     => 0,
                'gated'       => false,
                'error'       => 'The uploads directory is unavailable; nothing to roll back.',
            );
        }

        $removed = self::deleteTree( $baseDir );

        return array( 'translation' => $translation, 'removed' => $removed, 'gated' => false, 'error' => null );
    }

    /**
     * OFFLINE count-diff audit (never network). Diffs the vendored WEB present-verse
     * count against {@see self::REFERENCE_VERSE_COUNTS} and buckets every disagreement as
     * a PROPOSED divergent-zone addition (not yet modeled) or as ALREADY-modeled. Reports
     * only — never mutates {@see VersificationGate}.
     *
     * @return array{
     *     comparisons:int,
     *     proposed:list<array{book:string,chapter:int,webCount:int,referenceCount:int}>,
     *     alreadyModeled:list<array{book:string,chapter:int,webCount:int,referenceCount:int}>
     * }
     */
    public function auditCountDiff( string $translation ): array {
        $translation    = trim( $translation );
        $proposed       = array();
        $alreadyModeled = array();
        $comparisons    = 0;

        $baseDir = self::vendorBaseDir( $translation );
        if ( null === $baseDir ) {
            return array( 'comparisons' => 0, 'proposed' => array(), 'alreadyModeled' => array() );
        }

        $zones = VersificationGate::divergentZones();

        foreach ( self::REFERENCE_VERSE_COUNTS as $book => $chapters ) {
            foreach ( $chapters as $chapter => $referenceCount ) {
                $chapterList = self::loadSnapshot( $baseDir . '/' . $book . '/' . $chapter . '.json' );
                if ( null === $chapterList ) {
                    // Not vendored — cannot compare offline; skip (re-run after vendoring).
                    continue;
                }

                ++$comparisons;
                $webCount = self::presentVerseCount( $chapterList );
                if ( $webCount === $referenceCount ) {
                    continue;
                }

                $entry = array(
                    'book'           => $book,
                    'chapter'        => (int) $chapter,
                    'webCount'       => $webCount,
                    'referenceCount' => $referenceCount,
                );

                if ( self::inZone( $zones, $book, (int) $chapter ) ) {
                    $alreadyModeled[] = $entry;
                } else {
                    $proposed[] = $entry;
                }
            }
        }

        return array(
            'comparisons'    => $comparisons,
            'proposed'       => $proposed,
            'alreadyModeled' => $alreadyModeled,
        );
    }

    /**
     * The snapshot-present-and-complete check that POWERS the inline hard-gate (T15:
     * {@see ID::OPTION_BIBLE_INLINE_ENABLED} is physically un-enableable until this is
     * true — design §3.4). Disk-only; no network. A chapter counts only when its snapshot
     * is present AND schema-current.
     */
    public static function isSnapshotComplete( string $translation ): bool {
        return self::snapshotStatus( $translation )['complete'];
    }

    /**
     * Disk-only snapshot census for the operator report and the hard-gate.
     *
     * @return array{total:int,present:int,missing:int,complete:bool}
     */
    public static function snapshotStatus( string $translation ): array {
        $total = self::totalChapters();

        $baseDir = self::vendorBaseDir( trim( $translation ) );
        if ( null === $baseDir ) {
            return array( 'total' => $total, 'present' => 0, 'missing' => $total, 'complete' => false );
        }

        $present = 0;
        foreach ( self::CHAPTER_COUNTS as $book => $chapters ) {
            for ( $chapter = 1; $chapter <= $chapters; $chapter++ ) {
                if ( self::snapshotCurrent( $baseDir . '/' . $book . '/' . $chapter . '.json' ) ) {
                    ++$present;
                }
            }
        }

        return array(
            'total'    => $total,
            'present'  => $present,
            'missing'  => $total - $present,
            'complete' => $present === $total,
        );
    }

    /** Total chapters in the full snapshot (1189). */
    private static function totalChapters(): int {
        return (int) array_sum( self::CHAPTER_COUNTS );
    }

    /**
     * Refusal message if $translation may NOT be vendored (not PD, or inline-ineligible,
     * or unknown), or null when it is an audited PD inline target (ENGWEBP today).
     */
    private static function translationRefusal( string $translation ): ?string {
        foreach ( BibleTranslations::all() as $entry ) {
            if ( $entry['id'] !== $translation ) {
                continue;
            }
            if ( 'public-domain' !== $entry['license'] ) {
                return sprintf(
                    'Translation %s is not public-domain (license: %s); refusing to vendor its text.',
                    $translation,
                    $entry['license']
                );
            }
            if ( empty( $entry['inlineEligible'] ) ) {
                return sprintf(
                    'Translation %s is public-domain but inline-INELIGIBLE (unaudited); refusing to vendor.',
                    $translation
                );
            }
            return null;
        }

        return sprintf( 'Translation %s is not a known inline translation; refusing to vendor.', $translation );
    }

    /**
     * Absolute uploads path for a translation's snapshot directory, or null when the
     * translation code is malformed (path-traversal defense) or uploads is unavailable.
     */
    private static function vendorBaseDir( string $translation ): ?string {
        // Identifiers are simple alphanumeric codes; anything else (empty, '../', slashes)
        // is rejected before it can compose a filesystem path.
        if ( 1 !== preg_match( '/^[A-Za-z0-9]+$/', $translation ) ) {
            return null;
        }

        $uploads = wp_upload_dir();
        if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
            return null;
        }

        return $uploads['basedir'] . '/' . ID::BIBLE_VENDOR_DIR . '/' . $translation;
    }

    /**
     * Write one schema-stamped per-chapter snapshot envelope. The format is PINNED by
     * {@see \Sermonator\Frontend\Bible\ChapterProvider::readDiskSnapshot()}:
     * `{ "schema": int, "chapter": [ …normalized render shape… ] }`.
     *
     * @param list<array{number:int,nodes:list<array{type:string,text:string}>}> $normalized
     */
    private static function writeSnapshot( string $path, array $normalized ): bool {
        if ( ! wp_mkdir_p( dirname( $path ) ) ) {
            return false;
        }

        $json = wp_json_encode( array(
            'schema'  => ID::BIBLE_CACHE_SCHEMA_VERSION,
            'chapter' => $normalized,
        ) );
        if ( ! is_string( $json ) ) {
            return false;
        }

        return false !== file_put_contents( $path, $json );
    }

    /** Is the snapshot at $path present AND stamped with the CURRENT schema version? */
    private static function snapshotCurrent( string $path ): bool {
        return null !== self::loadSnapshot( $path );
    }

    /**
     * Read + schema-validate a snapshot, returning its normalized chapter list, or null
     * when absent / unreadable / undecodable / stale-schema / empty. Mirrors the disk-tier
     * guard in {@see \Sermonator\Frontend\Bible\ChapterProvider::readDiskSnapshot()}.
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>|null
     */
    private static function loadSnapshot( string $path ): ?array {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $body = file_get_contents( $path );
        if ( ! is_string( $body ) || '' === $body ) {
            return null;
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) || ! array_key_exists( 'schema', $decoded ) ) {
            return null;
        }
        if ( ID::BIBLE_CACHE_SCHEMA_VERSION !== $decoded['schema'] ) {
            return null;
        }

        $chapter = $decoded['chapter'] ?? null;

        return ( is_array( $chapter ) && array() !== $chapter ) ? $chapter : null;
    }

    /**
     * Count verses carrying RENDERABLE content (≥1 `text` / `wordsOfJesus` node) — the
     * same presence judgement {@see \Sermonator\Bible\RefValidator::rangeWithinChapter()}
     * uses, so a footnote-only / empty-but-emitted verse (e.g. WEB John 5:4) is NOT
     * counted as present.
     *
     * @param list<array{number:int,nodes:list<array{type:string,text:string}>}> $chapterList
     */
    private static function presentVerseCount( array $chapterList ): int {
        $count = 0;

        foreach ( $chapterList as $verse ) {
            if ( ! is_array( $verse ) || ! isset( $verse['nodes'] ) || ! is_array( $verse['nodes'] ) ) {
                continue;
            }
            foreach ( $verse['nodes'] as $node ) {
                if ( is_array( $node ) && in_array( $node['type'] ?? '', array( 'text', 'wordsOfJesus' ), true ) ) {
                    ++$count;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Does (book, chapter) fall inside a divergent-zone table? Local mirror of the
     * private {@see VersificationGate} predicate, over its public divergentZones().
     *
     * @param array<string,string|list<int>> $zones
     */
    private static function inZone( array $zones, string $book, int $chapter ): bool {
        if ( ! isset( $zones[ $book ] ) ) {
            return false;
        }
        $zone = $zones[ $book ];
        if ( '*' === $zone ) {
            return true;
        }
        return is_array( $zone ) && in_array( $chapter, $zone, true );
    }

    /**
     * Recursively delete a directory tree, returning the number of FILES removed.
     * Safe when the directory is absent (returns 0).
     */
    private static function deleteTree( string $dir ): int {
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $removed  = 0;
        $entries  = scandir( $dir );
        if ( false === $entries ) {
            return 0;
        }

        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) ) {
                $removed += self::deleteTree( $path );
            } elseif ( is_file( $path ) && unlink( $path ) ) {
                ++$removed;
            }
        }

        rmdir( $dir );

        return $removed;
    }

    /**
     * The zeroed result envelope, customized per call.
     *
     * @return array{
     *     translation:string,dryRun:bool,force:bool,
     *     processed:int,written:int,failed:int,skipped:int,
     *     status:array{total:int,present:int,missing:int,complete:bool},
     *     gated:bool,refused:?string,error:?string
     * }
     */
    private function emptyResult( string $translation, bool $dryRun, bool $force ): array {
        $total = self::totalChapters();

        return array(
            'translation' => $translation,
            'dryRun'      => $dryRun,
            'force'       => $force,
            'processed'   => 0,
            'written'     => 0,
            'failed'      => 0,
            'skipped'     => 0,
            'status'      => array( 'total' => $total, 'present' => 0, 'missing' => $total, 'complete' => false ),
            'gated'       => false,
            'refused'     => null,
            'error'       => null,
        );
    }
}
