<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\BibleChapterVendor;
use Sermonator\Frontend\Bible\ChapterProvider;
use Sermonator\Schema\Identifiers as ID;

/**
 * Phase 3b Task 8 — integration coverage for {@see BibleChapterVendor} under a real
 * WordPress object stack (real `wp_upload_dir()`, real filesystem, real options).
 *
 * Proves the spec acceptance criteria for the vendoring engine:
 *   - vendoring WRITES the schema-stamped per-chapter snapshot the render path reads,
 *     and ROLLBACK deletes it (the exact reverse);
 *   - ENGWEBP only — a non-public-domain (BSB) or inline-ineligible (ENGKJV) translation
 *     is REFUSED, nothing is written;
 *   - dry-run / fill-missing / force / migration-gated discipline;
 *   - the offline count-diff audit buckets an unmodeled divergence as a PROPOSED
 *     divergent-zone addition and an already-modeled one as such (never auto-committed).
 *
 * The live helloao fetch is NOT exercised (it is a network dependency); a fake fetcher is
 * injected so the engine logic is proven against real WP disk I/O without the network.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Authored to run
 * later under wp-env.
 */
final class BibleChapterVendorTest extends WP_UnitTestCase {
    private string $vendorRoot;

    protected function setUp(): void {
        parent::setUp();

        $uploads          = wp_upload_dir();
        $this->vendorRoot = $uploads['basedir'] . '/' . ID::BIBLE_VENDOR_DIR;
        $this->deleteTree( $this->vendorRoot );

        delete_option( ID::OPTION_MIGRATION_STATE );
    }

    protected function tearDown(): void {
        $this->deleteTree( $this->vendorRoot );
        delete_option( ID::OPTION_MIGRATION_STATE );
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A raw helloao chapter object (the inner `chapter` {@see ChapterFetcher} returns):
     * $verses verse items, each one plain text run → normalizes to $verses present verses.
     *
     * @return array<string,mixed>
     */
    private function rawChapter( int $verses ): array {
        $content = array();
        for ( $n = 1; $n <= $verses; $n++ ) {
            $content[] = array(
                'type'    => 'verse',
                'number'  => $n,
                'content' => array( 'Verse ' . $n . ' text.' ),
            );
        }

        return array( 'number' => 1, 'content' => $content, 'footnotes' => array() );
    }

    /** A vendor wired to a fetcher that returns a fixed-size chapter for every call. */
    private function vendorReturning( int $verses ): BibleChapterVendor {
        return new BibleChapterVendor(
            fn( string $t, string $b, int $c ): array => $this->rawChapter( $verses )
        );
    }

    /**
     * A vendor wired to a fetcher that returns a chapter ONLY for the given (book =>
     * chapter => verseCount) map, and null everywhere else (those chapters stay missing).
     *
     * @param array<string,array<int,int>> $map
     */
    private function vendorFor( array $map ): BibleChapterVendor {
        return new BibleChapterVendor(
            function ( string $t, string $b, int $c ) use ( $map ): ?array {
                $count = $map[ $b ][ $c ] ?? null;
                return null === $count ? null : $this->rawChapter( $count );
            }
        );
    }

    private function snapshotPath( string $translation, string $book, int $chapter ): string {
        return $this->vendorRoot . '/' . $translation . '/' . $book . '/' . $chapter . '.json';
    }

    // -------------------------------------------------------------------------
    // Vendor writes the snapshot + rollback deletes it
    // -------------------------------------------------------------------------

    public function test_vendor_write_creates_schema_stamped_snapshot_and_rollback_deletes_it(): void {
        $vendor = $this->vendorReturning( 3 );

        $result = $vendor->vendor( 'ENGWEBP', false, false, 2 );

        $this->assertNull( $result['refused'] );
        $this->assertFalse( $result['gated'] );
        $this->assertSame( 2, $result['written'] );

        // The sweep is in canon order, so the first two chapters are GEN 1 + GEN 2.
        $path = $this->snapshotPath( 'ENGWEBP', 'GEN', 1 );
        $this->assertFileExists( $path );

        $decoded = json_decode( (string) file_get_contents( $path ), true );
        $this->assertSame( ID::BIBLE_CACHE_SCHEMA_VERSION, $decoded['schema'] );
        $this->assertIsArray( $decoded['chapter'] );
        $this->assertCount( 3, $decoded['chapter'] );

        // The render path reads exactly this snapshot — proving the on-disk format
        // matches the ChapterProvider disk tier (zero network at render).
        $fromRender = ChapterProvider::get( 'ENGWEBP', 'GEN', 1, false );
        $this->assertNotNull( $fromRender );
        $this->assertSame( $decoded['chapter'], $fromRender );

        // Rollback removes the whole snapshot tree (the exact reverse).
        $rollback = $vendor->rollback( 'ENGWEBP' );
        $this->assertFalse( $rollback['gated'] );
        $this->assertGreaterThanOrEqual( 2, $rollback['removed'] );
        $this->assertFileDoesNotExist( $path );
        $this->assertDirectoryDoesNotExist( $this->vendorRoot . '/ENGWEBP' );
    }

    // -------------------------------------------------------------------------
    // ENGWEBP only — refuse non-PD / inline-ineligible translations
    // -------------------------------------------------------------------------

    public function test_vendor_refuses_non_public_domain_translation(): void {
        $vendor = $this->vendorReturning( 3 );

        $result = $vendor->vendor( 'BSB', false, false, 1 );

        $this->assertNotNull( $result['refused'] );
        $this->assertStringContainsString( 'public-domain', $result['refused'] );
        $this->assertSame( 0, $result['written'] );
        $this->assertDirectoryDoesNotExist( $this->vendorRoot . '/BSB' );
    }

    public function test_vendor_refuses_inline_ineligible_public_domain_translation(): void {
        // ENGKJV is public-domain but inline-INELIGIBLE (unaudited divergences).
        $result = $this->vendorReturning( 3 )->vendor( 'ENGKJV', false, false, 1 );

        $this->assertNotNull( $result['refused'] );
        $this->assertStringContainsString( 'INELIGIBLE', $result['refused'] );
        $this->assertDirectoryDoesNotExist( $this->vendorRoot . '/ENGKJV' );
    }

    public function test_vendor_refuses_unknown_translation(): void {
        $result = $this->vendorReturning( 3 )->vendor( 'NOPE', false, false, 1 );

        $this->assertNotNull( $result['refused'] );
        $this->assertSame( 0, $result['written'] );
    }

    // -------------------------------------------------------------------------
    // Dry-run / fill-missing / force / gating discipline
    // -------------------------------------------------------------------------

    public function test_dry_run_writes_nothing(): void {
        $result = $this->vendorReturning( 3 )->vendor( 'ENGWEBP', true, false, 3 );

        $this->assertSame( 3, $result['processed'] );
        $this->assertSame( 0, $result['written'] );
        $this->assertFileDoesNotExist( $this->snapshotPath( 'ENGWEBP', 'GEN', 1 ) );
        $this->assertDirectoryDoesNotExist( $this->vendorRoot );
    }

    public function test_vendor_is_fill_missing_and_idempotent_across_runs(): void {
        $vendor = $this->vendorReturning( 3 );

        $vendor->vendor( 'ENGWEBP', false, false, 2 ); // GEN 1, 2
        $second = $vendor->vendor( 'ENGWEBP', false, false, 2 ); // skips 1,2 → GEN 3, 4

        $this->assertSame( 2, $second['skipped'] );
        $this->assertSame( 2, $second['written'] );
        $this->assertFileExists( $this->snapshotPath( 'ENGWEBP', 'GEN', 3 ) );
        $this->assertFileExists( $this->snapshotPath( 'ENGWEBP', 'GEN', 4 ) );
    }

    public function test_force_revendors_an_existing_chapter(): void {
        $this->vendorReturning( 3 )->vendor( 'ENGWEBP', false, false, 1 ); // GEN 1, 3 verses
        $path = $this->snapshotPath( 'ENGWEBP', 'GEN', 1 );
        $before = json_decode( (string) file_get_contents( $path ), true );
        $this->assertCount( 3, $before['chapter'] );

        // Force re-vendor with a DIFFERENT verse count → the file is overwritten.
        $this->vendorReturning( 5 )->vendor( 'ENGWEBP', false, true, 1 );
        $after = json_decode( (string) file_get_contents( $path ), true );
        $this->assertCount( 5, $after['chapter'] );
    }

    public function test_vendor_write_is_gated_during_active_migration(): void {
        update_option( ID::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $vendor = $this->vendorReturning( 3 );

        $write = $vendor->vendor( 'ENGWEBP', false, false, 1 );
        $this->assertTrue( $write['gated'] );
        $this->assertSame( 0, $write['written'] );
        $this->assertFileDoesNotExist( $this->snapshotPath( 'ENGWEBP', 'GEN', 1 ) );

        // The dry-run report stays available mid-migration (it touches nothing).
        $dry = $vendor->vendor( 'ENGWEBP', true, false, 1 );
        $this->assertFalse( $dry['gated'] );
        $this->assertSame( 1, $dry['processed'] );
    }

    public function test_rollback_is_gated_during_active_migration(): void {
        $this->vendorReturning( 3 )->vendor( 'ENGWEBP', false, false, 1 );
        update_option( ID::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $rollback = $this->vendorReturning( 3 )->rollback( 'ENGWEBP' );
        $this->assertTrue( $rollback['gated'] );
        $this->assertFileExists( $this->snapshotPath( 'ENGWEBP', 'GEN', 1 ) );
    }

    // -------------------------------------------------------------------------
    // Snapshot completeness (powers the T15 inline hard-gate)
    // -------------------------------------------------------------------------

    public function test_snapshot_is_incomplete_until_fully_vendored(): void {
        $this->assertFalse( BibleChapterVendor::isSnapshotComplete( 'ENGWEBP' ) );

        $this->vendorReturning( 3 )->vendor( 'ENGWEBP', false, false, 2 );

        $status = BibleChapterVendor::snapshotStatus( 'ENGWEBP' );
        $this->assertSame( 1189, $status['total'] );
        $this->assertSame( 2, $status['present'] );
        $this->assertSame( 1187, $status['missing'] );
        $this->assertFalse( $status['complete'] );
        $this->assertFalse( BibleChapterVendor::isSnapshotComplete( 'ENGWEBP' ) );
    }

    // -------------------------------------------------------------------------
    // Offline count-diff audit
    // -------------------------------------------------------------------------

    public function test_count_diff_audit_proposes_unmodeled_and_buckets_modeled(): void {
        // MAT 17 reference (critical-text) present count is 26; an ENGWEBP snapshot with
        // 27 present verses diverges and is NOT modeled → a proposed addition.
        // ROM 16 reference is 26; 27 present diverges but ROM 16 IS in the divergent zones
        // → bucketed as already-modeled (never proposed).
        $vendor = $this->vendorFor( array(
            'MAT' => array( 17 => 27 ),
            'ROM' => array( 16 => 27 ),
        ) );

        $vendor->vendor( 'ENGWEBP', false, false, 0 );

        // Only the two targeted chapters were actually written (others fetched null).
        $this->assertFileExists( $this->snapshotPath( 'ENGWEBP', 'MAT', 17 ) );
        $this->assertFileExists( $this->snapshotPath( 'ENGWEBP', 'ROM', 16 ) );

        $audit = $vendor->auditCountDiff( 'ENGWEBP' );

        $this->assertSame( 2, $audit['comparisons'] );

        $this->assertCount( 1, $audit['proposed'] );
        $this->assertSame( 'MAT', $audit['proposed'][0]['book'] );
        $this->assertSame( 17, $audit['proposed'][0]['chapter'] );
        $this->assertSame( 27, $audit['proposed'][0]['webCount'] );
        $this->assertSame( 26, $audit['proposed'][0]['referenceCount'] );

        $this->assertCount( 1, $audit['alreadyModeled'] );
        $this->assertSame( 'ROM', $audit['alreadyModeled'][0]['book'] );
        $this->assertSame( 16, $audit['alreadyModeled'][0]['chapter'] );
    }

    public function test_count_diff_audit_reports_no_divergence_when_counts_match(): void {
        // MAT 17 vendored with EXACTLY the reference present count (26) → no divergence.
        $vendor = $this->vendorFor( array( 'MAT' => array( 17 => 26 ) ) );
        $vendor->vendor( 'ENGWEBP', false, false, 0 );

        $audit = $vendor->auditCountDiff( 'ENGWEBP' );
        $this->assertSame( 1, $audit['comparisons'] );
        $this->assertSame( array(), $audit['proposed'] );
        $this->assertSame( array(), $audit['alreadyModeled'] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function deleteTree( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $entries = scandir( $dir );
        if ( false === $entries ) {
            return;
        }
        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) ) {
                $this->deleteTree( $path );
            } else {
                unlink( $path );
            }
        }
        rmdir( $dir );
    }
}
