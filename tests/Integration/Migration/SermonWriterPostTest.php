<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\SermonWriter;
use Sermonator\Migration\WriteResult;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 12: SermonWriter — post columns, KSES-safe body, back-ref-first, idempotency.
 *
 * This task covers the POST/BODY/IDEMPOTENCY facet of write(): it inserts the
 * new sermonator_sermon post preserving the legacy post columns, reconciles the
 * body from (post_content, sermon_description, post_content_temp), survives KSES
 * (iframes/shortcodes), stamps the legacy back-ref IMMEDIATELY after insert, and
 * is resumable (a partial — back-ref present but MIGRATION_COMPLETE absent — is
 * re-entered, never duplicated).
 *
 * Invariants under test:
 *  - every preserved column matches legacy; post_type = sermonator_sermon;
 *  - post_content = reconciled description; an <iframe>/[shortcode] in the legacy
 *    body survives verbatim into LEGACY_POST_CONTENT backup (KSES off);
 *  - body with quotes/backslashes/unicode is not corrupted (wp_slash);
 *  - back-ref present right after insert; MIGRATION_COMPLETE NOT yet written
 *    (this task leaves the post "stamped but partial");
 *  - simulated abort before complete → re-run does NOT create a second post;
 *  - slug uniquified by WP → slug_changed flag + LEGACY_SLUG recorded;
 *  - non-zero post_parent translated via findNewByLegacyId else 0 + flag;
 *  - legacy get_post + get_post_meta byte-equal before/after;
 *  - shared attachment posts never mutated.
 */
final class SermonWriterPostTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /** Snapshot a legacy post + all its meta for byte-equality assertions. */
    private function snapshot( int $legacyId ): array {
        return array(
            'post' => get_post( $legacyId, ARRAY_A ),
            'meta' => get_post_meta( $legacyId ),
        );
    }

    /**
     * Insert a legacy post with KSES disabled and slashes applied, so structural
     * HTML (iframes/shortcodes) lands in the DB verbatim — exactly as real legacy
     * Sermon Manager data already exists, before the migration ever runs.
     */
    private function insertLegacyRaw( array $postarr ): int {
        kses_remove_filters();
        try {
            $id = (int) wp_insert_post( wp_slash( $postarr ) );
        } finally {
            kses_init_filters();
        }
        return $id;
    }

    public function test_preserved_columns_match_legacy(): void {
        $author = self::factory()->user->create( array( 'role' => 'editor' ) );

        $legacyId = (int) wp_insert_post( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'     => 'Sermon On The Mount',
            'post_author'    => $author,
            'post_status'    => 'publish',
            'post_name'      => 'sermon-on-the-mount-unique-slug',
            'post_date'      => '2021-05-01 09:30:00',
            'post_date_gmt'  => '2021-05-01 09:30:00',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'menu_order'     => 7,
            'post_excerpt'   => 'A short excerpt.',
            'post_password'  => 'secret',
            'post_content'   => 'Auto blob',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '<p>The real body.</p>' );

        // Set legacy last-modified + content_filtered directly (wp_insert_post
        // forces modified to date on insert) so we can assert they are preserved.
        global $wpdb;
        $wpdb->update( $wpdb->posts, array(
            'post_modified'         => '2021-07-09 12:00:00',
            'post_modified_gmt'     => '2021-07-09 12:00:00',
            'post_content_filtered' => 'cached filtered body',
        ), array( 'ID' => $legacyId ) );
        clean_post_cache( $legacyId );

        $result = ( new SermonWriter() )->write( $legacyId );
        $this->assertInstanceOf( WriteResult::class, $result );
        $this->assertTrue( $result->created );

        $new = get_post( $result->newId );
        $this->assertSame( Identifiers::POST_TYPE_SERMON, $new->post_type );
        $this->assertSame( 'Sermon On The Mount', $new->post_title );
        $this->assertSame( $author, (int) $new->post_author );
        $this->assertSame( 'publish', $new->post_status );
        $this->assertSame( 'sermon-on-the-mount-unique-slug', $new->post_name );
        $this->assertSame( '2021-05-01 09:30:00', $new->post_date );
        $this->assertSame( '2021-05-01 09:30:00', $new->post_date_gmt );
        $this->assertSame( 'closed', $new->comment_status );
        $this->assertSame( 'closed', $new->ping_status );
        $this->assertSame( 7, (int) $new->menu_order );
        $this->assertSame( 'A short excerpt.', $new->post_excerpt );
        $this->assertSame( 'secret', $new->post_password );
        $this->assertSame( '<p>The real body.</p>', $new->post_content );
        // Sweep: last-modified + content_filtered preserved (not re-stamped/dropped).
        $this->assertSame( '2021-07-09 12:00:00', $new->post_modified, 'post_modified must be preserved' );
        $this->assertSame( '2021-07-09 12:00:00', $new->post_modified_gmt, 'post_modified_gmt must be preserved' );
        $this->assertSame( 'cached filtered body', $new->post_content_filtered, 'post_content_filtered must be preserved' );
    }

    public function test_iframe_and_shortcode_body_survives_kses(): void {
        // Body lives only in the legacy auto post_content (iframe + shortcode);
        // the description is empty. KSES would strip <iframe>; it must survive
        // into the LEGACY_POST_CONTENT backup verbatim. Seed the legacy body the
        // way real legacy data exists in the DB — with KSES OFF — so the fixture
        // itself does not strip the iframe before the writer ever reads it.
        $body = '<iframe src="https://player.example/embed/42" allowfullscreen></iframe>[audio src="https://x/a.mp3"]';

        $legacyId = $this->insertLegacyRaw( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Embed Sermon',
            'post_status'  => 'publish',
            'post_content' => $body,
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $backup = get_post_meta( $result->newId, Crosswalk::LEGACY_POST_CONTENT, true );
        $this->assertStringContainsString( '<iframe', $backup );
        $this->assertStringContainsString( 'allowfullscreen', $backup );
        $this->assertStringContainsString( '[audio', $backup );
        $this->assertContains( 'post_content_preserved', $result->flags );
    }

    public function test_iframe_in_description_survives_kses_into_reconciled_post_content(): void {
        // The structural HTML lives in the sermon_description meta itself, so the
        // reconciler routes it to post_content (NOT the backup). If KSES were left
        // ON around wp_insert_post, WordPress would strip the <iframe> from
        // post_content. This proves kses_remove_filters()/kses_init_filters()
        // around the insert is genuinely load-bearing for the reconciled body.
        $desc = '<p>Watch the stream:</p><iframe src="https://player.example/embed/99" allowfullscreen></iframe>[audio src="https://x/b.mp3"]';

        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Iframe In Description',
            'post_status'  => 'publish',
            'post_content' => '',
        ) );
        // Slash the seed so the iframe lands in the meta verbatim (add_post_meta
        // unslashes), mirroring how real legacy data is stored.
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, wp_slash( $desc ) );
        $this->assertSame(
            $desc,
            get_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, true ),
            'fixture seed sanity: iframe present in legacy description verbatim'
        );

        $result = ( new SermonWriter() )->write( $legacyId );

        // The reconciled body (from the description) must keep the iframe and the
        // shortcode verbatim in the ACTUAL post_content column — only possible if
        // KSES was disabled around wp_insert_post.
        $new = get_post( $result->newId );
        $this->assertSame( $desc, $new->post_content );
        $this->assertStringContainsString( '<iframe', $new->post_content );
        $this->assertStringContainsString( 'allowfullscreen', $new->post_content );
        $this->assertStringContainsString( '[audio', $new->post_content );

        // The description (now post_content) was NOT routed to the backup.
        $this->assertSame( '', (string) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_CONTENT, true ) );
    }

    public function test_complete_record_is_skipped_not_resumed(): void {
        // A back-ref + MIGRATION_COMPLETE record is a no-op skip: created=false
        // AND resumed=false. This is the COMPLETE leg of the idempotency gate,
        // observably distinct from the partial (resume) leg below.
        $legacyId = $this->fixture->createSermon();

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertTrue( $first->created );
        $this->assertFalse( $first->resumed );

        // Simulate Task 14 stamping COMPLETE at the true end of write().
        update_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, '1' );

        $second = $writer->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertFalse( $second->resumed, 'A completed record must be skipped, not resumed.' );
        $this->assertSame( $first->newId, $second->newId );
    }

    public function test_partial_record_is_resumed_not_skipped(): void {
        // A back-ref present but MIGRATION_COMPLETE absent is the PARTIAL leg:
        // created=false AND resumed=true. The resume re-enters the post-insert
        // self-healing block on the SAME post (no duplicate, no second insert).
        //
        // A full write() now stamps COMPLETE LAST, so we CRASH-INJECT a partial by
        // deleting the COMPLETE flag — exactly the on-disk state an abort between
        // the insert and the final COMPLETE stamp would leave behind.
        $legacyId = $this->fixture->createSermon();

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertTrue( $first->created );

        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        $this->assertSame(
            '',
            (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'precondition: post is stamped but partial (crash-injected)'
        );

        $second = $writer->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertTrue( $second->resumed, 'A stamped-but-partial record must be resumed.' );
        $this->assertSame( $first->newId, $second->newId );

        // Resume re-drives the spine idempotently — exactly one back-ref row, one post.
        $backRefs = get_post_meta( $first->newId, Crosswalk::LEGACY_POST_ID, false );
        $this->assertCount( 1, $backRefs );

        $all = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
        ) );
        $this->assertCount( 1, $all );
    }

    public function test_resume_does_not_duplicate_backup_or_drop_flags(): void {
        // A partial record that carries a preserved backup body + an unresolved
        // post_parent flag must, on resume: keep exactly one LEGACY_POST_CONTENT
        // row and retain the post_parent_unresolved flag (replace semantics must
        // not drop a flag the resume pass does not re-derive).
        $orphanParent = $this->fixture->createSermon();

        $legacyId = $this->insertLegacyRaw( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Resume Spine',
            'post_status'  => 'publish',
            'post_parent'  => $orphanParent,
            'post_content' => '<iframe src="https://x/embed"></iframe>[audio src="https://x/a.mp3"]',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '' );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertContains( 'post_content_preserved', $first->flags );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, $first->flags );

        // Crash-inject a partial so the second write takes the RESUME leg.
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        $second = $writer->write( $legacyId );
        $this->assertTrue( $second->resumed );

        // Exactly one backup row after resume (unique guard held).
        $backups = get_post_meta( $first->newId, Crosswalk::LEGACY_POST_CONTENT, false );
        $this->assertCount( 1, $backups );

        // Both flags survive the resume's replace-semantics writeFlags().
        $this->assertContains( 'post_content_preserved', $second->flags );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, $second->flags );
        $persisted = get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, (array) $persisted );
    }

    public function test_body_with_quotes_backslashes_unicode_not_corrupted(): void {
        $desc = 'He said "grace" \\ mercy — ✝ café façade';

        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Tricky Body',
            'post_status'  => 'publish',
            'post_content' => 'blob',
        ) );
        // add_post_meta unslashes, so slash the seed to land $desc verbatim in
        // the DB — mirroring how WordPress actually stores meta.
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, wp_slash( $desc ) );
        $this->assertSame( $desc, get_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, true ), 'fixture seed sanity' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( $desc, $new->post_content );
    }

    public function test_back_ref_present_and_record_completed(): void {
        $legacyId = $this->fixture->createSermon();

        $result = ( new SermonWriter() )->write( $legacyId );

        // Back-ref stamped right after insert (the crash-safety spine: written
        // FIRST, immediately after the post insert).
        $this->assertSame(
            (string) $legacyId,
            get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, true )
        );
        $this->assertSame( $result->newId, Crosswalk::findNewByLegacyId( $legacyId ) );

        // A full write() finishes by stamping MIGRATION_COMPLETE LAST (Task 14):
        // back-ref FIRST, COMPLETE LAST — both present after a successful write.
        $this->assertSame( '1', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
    }

    public function test_resume_does_not_create_a_second_post(): void {
        $legacyId = $this->fixture->createSermon();

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertTrue( $first->created );

        // Second run: back-ref exists → resolve the SAME post, never a second
        // insert (whether the record is complete-skip or partial-resume).
        $second = $writer->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertSame( $first->newId, $second->newId );

        $all = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
        ) );
        $this->assertCount( 1, $all );
    }

    public function test_back_ref_written_atomically_in_the_insert(): void {
        // CRITICAL #4: the LEGACY_POST_ID back-ref must be written ATOMICALLY in
        // the SAME insert call (meta_input), not as a separate later statement.
        // The single canonical back-ref row is present on a freshly-created post.
        $legacyId = $this->fixture->createSermon();

        $result = ( new SermonWriter() )->write( $legacyId );
        $this->assertTrue( $result->created );

        $backRefRows = get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, false );
        $this->assertCount( 1, $backRefRows, 'Exactly one back-ref row (atomic insert + idempotent markLegacy).' );
        $this->assertSame( (string) $legacyId, (string) $backRefRows[0] );
    }

    public function test_crash_orphan_back_ref_less_post_is_adopted_not_duplicated(): void {
        // CRITICAL #4: the duplicate-post crash window. An OLDER writer inserted the
        // post but the process aborted BEFORE the separate markLegacy stamp, leaving
        // a back-ref-less orphan. The authoritative back-ref probe can't see it, so
        // a naive resume would mint a SECOND visible sermon (invisible to the >1
        // guard, the Verifier, and Rollback — all of which enumerate by back-ref).
        // The writer must ADOPT the orphan (stamp the back-ref, drive the spine
        // forward) and yield EXACTLY ONE post.
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Crash Window Sermon',
            'post_status'  => 'publish',
            'post_date'     => '2021-03-07 10:00:00',
            'post_date_gmt' => '2021-03-07 10:00:00',
            'post_content' => 'Auto blob',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '<p>The real body.</p>' );

        // Inject the back-ref-less orphan exactly as the old insert-then-crash left
        // it: the legacy identity columns, NO LEGACY_POST_ID back-ref.
        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => 'Crash Window Sermon',
                'post_date'     => '2021-03-07 10:00:00',
                'post_date_gmt' => '2021-03-07 10:00:00',
                'post_content'  => '<p>The real body.</p>',
            )
        );
        $this->assertSame( '', (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ), 'precondition: orphan is back-ref-less' );

        $result = ( new SermonWriter() )->write( $legacyId );

        // ADOPTED: the writer resolved the orphan, did NOT create a fresh post.
        $this->assertFalse( $result->created, 'A back-ref-less orphan must be adopted, not re-created.' );
        $this->assertSame( $orphanId, $result->newId, 'The orphan id must be the migration target.' );

        // Exactly ONE migrated sermon — no duplicate.
        $migrated = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
            'meta_key'    => Crosswalk::LEGACY_POST_ID,
        ) );
        $this->assertCount( 1, $migrated, 'Adoption must not leave a second back-ref-less duplicate.' );
        $this->assertSame( array( $orphanId ), array_map( 'intval', $migrated ) );

        // The orphan now carries the back-ref and resolves authoritatively.
        $this->assertSame( (string) $legacyId, (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ) );
        $this->assertSame( $orphanId, Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON ) );

        // Idempotent: a re-run resolves the same post, still exactly one.
        $second = ( new SermonWriter() )->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertSame( $orphanId, $second->newId );
        $this->assertCount( 1, get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
            'meta_key'    => Crosswalk::LEGACY_POST_ID,
        ) ) );
    }

    public function test_slug_uniquified_records_flag_and_legacy_slug(): void {
        // Occupy the slug with a pre-existing sermonator_sermon so WP must
        // uniquify the migrated post's slug.
        self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_name'   => 'shared-slug',
        ) );

        $legacyId = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'Slug Clash',
            'post_status' => 'publish',
            'post_name'   => 'shared-slug',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'body' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertNotSame( 'shared-slug', $new->post_name, 'WP should have uniquified the slug.' );
        $this->assertContains( 'slug_changed', $result->flags );
        $this->assertSame( 'shared-slug', get_post_meta( $result->newId, Crosswalk::LEGACY_SLUG, true ) );
    }

    public function test_post_parent_translated_when_migrated(): void {
        $parentLegacy = $this->fixture->createSermon();
        $parentResult = ( new SermonWriter() )->write( $parentLegacy );

        $childLegacy = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'Child',
            'post_status' => 'publish',
            'post_parent' => $parentLegacy,
        ) );
        add_post_meta( $childLegacy, LegacyIdentifiers::META_DESCRIPTION, 'child body' );

        $childResult = ( new SermonWriter() )->write( $childLegacy );

        $this->assertSame( $parentResult->newId, (int) get_post( $childResult->newId )->post_parent );
    }

    public function test_post_parent_unmigrated_becomes_zero_and_flags(): void {
        // Parent legacy post exists but is NOT migrated → parent cannot be
        // translated; new post_parent must be 0 + a flag, never a dangling legacy id.
        $orphanParent = $this->fixture->createSermon();

        $childLegacy = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'Orphan Child',
            'post_status' => 'publish',
            'post_parent' => $orphanParent,
        ) );
        add_post_meta( $childLegacy, LegacyIdentifiers::META_DESCRIPTION, 'orphan child body' );

        $result = ( new SermonWriter() )->write( $childLegacy );

        $this->assertSame( 0, (int) get_post( $result->newId )->post_parent );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, $result->flags );
    }

    public function test_post_parent_unresolved_self_heals_after_parent_migrated(): void {
        // IMPORTANT #5: post_parent_unresolved must SELF-HEAL. A child migrated
        // BEFORE its parent collapses to post_parent=0 and records
        // post_parent_unresolved:<id>. Previously parent translation ran ONLY in the
        // fresh-insert branch, so after the parent was later migrated, re-writing the
        // child never re-translated the parent nor cleared the flag — the record stayed
        // never-clean forever (blocking the Verifier's empty-flags completeness check).
        // Now re-translation runs in applyPostInsertSpine (both fresh AND resume), and
        // the COMPLETE branch re-drives it whenever the open flag is present: the prior
        // flag is stripped, the parent re-derived, and wp_update_post applies the newly
        // resolved parent so COMPLETE clears symmetrically.
        $orphanParent = $this->fixture->createSermon();

        $childLegacy = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'Heal Child',
            'post_status' => 'publish',
            'post_parent' => $orphanParent,
        ) );
        add_post_meta( $childLegacy, LegacyIdentifiers::META_DESCRIPTION, 'heal child body' );

        // (1) Migrate the child BEFORE the parent: parent collapses to 0 + flag.
        $writer      = new SermonWriter();
        $childResult = $writer->write( $childLegacy );
        $this->assertSame( 0, (int) get_post( $childResult->newId )->post_parent );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, $childResult->flags );
        // The child is COMPLETE (its only flag is the unresolved parent — no comment
        // failure), so the self-heal must work from the COMPLETE branch.
        $this->assertSame( '1', (string) get_post_meta( $childResult->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // (2) Now migrate the parent.
        $parentResult = $writer->write( $orphanParent );

        // (3) Re-write the child: the parent now resolves; flag clears.
        $rewrite = $writer->write( $childLegacy );

        $this->assertSame(
            $parentResult->newId,
            (int) get_post( $childResult->newId )->post_parent,
            'parent must be re-derived and applied on re-write'
        );
        $this->assertNotContains( 'post_parent_unresolved:' . $orphanParent, $rewrite->flags, 'unresolved flag must clear' );

        // Persisted flags row no longer carries the unresolved flag.
        $persisted = get_post_meta( $childResult->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertNotContains( 'post_parent_unresolved:' . $orphanParent, (array) $persisted );
    }

    public function test_post_parent_self_heals_on_resume_leg(): void {
        // Same self-heal, but through the RESUME leg (record stamped-but-partial):
        // the parent-translation block must run in applyPostInsertSpine on resume too.
        $orphanParent = $this->fixture->createSermon();

        $childLegacy = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'Resume Heal Child',
            'post_status' => 'publish',
            'post_parent' => $orphanParent,
        ) );
        add_post_meta( $childLegacy, LegacyIdentifiers::META_DESCRIPTION, 'resume heal body' );

        $writer      = new SermonWriter();
        $childResult = $writer->write( $childLegacy );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, $childResult->flags );

        $parentResult = $writer->write( $orphanParent );

        // Crash-inject a partial so the re-write takes the RESUME leg.
        delete_post_meta( $childResult->newId, Crosswalk::MIGRATION_COMPLETE );
        $resume = $writer->write( $childLegacy );
        $this->assertTrue( $resume->resumed );

        $this->assertSame( $parentResult->newId, (int) get_post( $childResult->newId )->post_parent );
        $this->assertNotContains( 'post_parent_unresolved:' . $orphanParent, $resume->flags );
    }

    // ----------------------------------- FIX 2: symmetric KSES restore (sermon)

    /**
     * FIX 2: insertKsesSafe must restore KSES filter state symmetrically.
     * If KSES is OFF before write(), it must still be OFF after.
     */
    public function test_kses_filter_state_restored_symmetrically_when_off_before(): void {
        kses_remove_filters();
        $this->assertFalse(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'precondition: KSES must be OFF before write()'
        );

        $legacyId = $this->fixture->createSermon();
        ( new SermonWriter() )->write( $legacyId );

        $this->assertFalse(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'KSES must remain OFF after SermonWriter::write() when it was OFF before (symmetric restore)'
        );

        kses_init_filters();
    }

    /**
     * FIX 2: If KSES is ON before write(), it must still be ON after.
     */
    public function test_kses_filter_state_restored_symmetrically_when_on_before(): void {
        kses_init_filters();
        $this->assertTrue(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'precondition: KSES must be ON before write()'
        );

        $legacyId = $this->fixture->createSermon();
        ( new SermonWriter() )->write( $legacyId );

        $this->assertTrue(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'KSES must remain ON after SermonWriter::write() when it was ON before (symmetric restore)'
        );
    }

    /**
     * MUST-FIX #3: reconcilePostParent() must restore KSES to the captured prior
     * state, not unconditionally ON. With KSES OFF before write() and a non-zero
     * legacy post_parent that NEWLY resolves to a migrated parent (so wp_update_post
     * actually runs inside reconcilePostParent), KSES must remain OFF afterwards.
     * The existing symmetric-restore tests use post_parent=0, never hitting this
     * branch.
     */
    public function test_kses_state_restored_when_parent_resolves_and_off_before(): void {
        // Force reconcilePostParent's wp_update_post branch to actually fire: a
        // child migrated BEFORE its parent (parent collapses to 0 + flag), then the
        // parent is migrated, then a re-write self-heals the parent via
        // wp_update_post. With KSES OFF before that re-write, the asymmetric finally
        // (unconditional kses_init_filters) would leave KSES ON. It must stay OFF.
        $orphanParent = $this->fixture->createSermon();
        $childLegacy  = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'KSES Heal Child',
            'post_status' => 'publish',
            'post_parent' => $orphanParent,
        ) );
        add_post_meta( $childLegacy, LegacyIdentifiers::META_DESCRIPTION, 'child body' );

        $writer = new SermonWriter();
        $child  = $writer->write( $childLegacy );
        $this->assertContains( 'post_parent_unresolved:' . $orphanParent, $child->flags );
        $parent = $writer->write( $orphanParent );

        kses_remove_filters();
        $this->assertFalse(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'precondition: KSES OFF before the self-heal re-write'
        );

        $rewrite = $writer->write( $childLegacy );

        // Sanity: the parent self-heal branch (wp_update_post) actually ran.
        $this->assertSame( $parent->newId, (int) get_post( $child->newId )->post_parent, 'parent must have been re-applied via wp_update_post' );
        $this->assertNotContains( 'post_parent_unresolved:' . $orphanParent, $rewrite->flags );

        $this->assertFalse(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'KSES must remain OFF after reconcilePostParent ran wp_update_post (symmetric restore)'
        );

        kses_init_filters();
    }

    /**
     * MUST-FIX #3: the same self-heal path with KSES ON before must leave KSES ON
     * after (the reconcilePostParent restore must not leak in either direction).
     */
    public function test_kses_state_restored_when_parent_resolves_and_on_before(): void {
        $orphanParent = $this->fixture->createSermon();
        $childLegacy  = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'  => 'KSES Heal Child On',
            'post_status' => 'publish',
            'post_parent' => $orphanParent,
        ) );
        add_post_meta( $childLegacy, LegacyIdentifiers::META_DESCRIPTION, 'child body' );

        $writer = new SermonWriter();
        $writer->write( $childLegacy );
        $writer->write( $orphanParent );

        kses_init_filters();
        $this->assertTrue( (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'precondition: KSES ON' );

        $writer->write( $childLegacy );

        $this->assertTrue(
            (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
            'KSES must remain ON after reconcilePostParent ran wp_update_post'
        );
    }

    /**
     * MUST-FIX #4: post_modified / post_modified_gmt must carry the LEGACY
     * last-modified timestamps, not be re-stamped to migration run time.
     */
    public function test_post_modified_preserved_from_legacy(): void {
        $legacyId = (int) wp_insert_post( array(
            'post_type'         => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'        => 'Modified Preserve',
            'post_status'       => 'publish',
            'post_date'         => '2019-02-03 08:00:00',
            'post_date_gmt'     => '2019-02-03 08:00:00',
            'post_content'      => 'Auto blob',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'body' );

        // wp_insert_post FORCES post_modified to post_date on insert, so set the
        // legacy last-modified directly — mirroring how real legacy data (edited
        // after creation) lives in the DB with modified != date.
        global $wpdb;
        $wpdb->update( $wpdb->posts, array(
            'post_modified'     => '2020-06-15 14:22:31',
            'post_modified_gmt' => '2020-06-15 14:22:31',
        ), array( 'ID' => $legacyId ) );
        clean_post_cache( $legacyId );

        // Sanity: legacy carries the intended modified timestamp.
        $this->assertSame( '2020-06-15 14:22:31', get_post( $legacyId )->post_modified_gmt );

        $result = ( new SermonWriter() )->write( $legacyId );

        $new = get_post( $result->newId );
        $this->assertSame( '2020-06-15 14:22:31', $new->post_modified_gmt, 'post_modified_gmt must carry the legacy value' );
        $this->assertSame( '2020-06-15 14:22:31', $new->post_modified, 'post_modified must carry the legacy value' );
    }

    // ----------------------------------- FIX 3: orphan adoption uniqueness (sermon)

    /**
     * FIX 3: Two legacy sermons sharing title + post_date_gmt — when each has a
     * crash orphan, the writer must NOT mis-adopt across records. Require unique
     * candidate; refuse if >1 matches.
     */
    public function test_sermon_orphan_not_adopted_when_multiple_candidates_match(): void {
        $sharedTitle = 'Ambiguous Sermon ' . wp_generate_uuid4();
        $sharedDate  = '2022-08-10 11:00:00';

        $legacyA = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'    => $sharedTitle,
            'post_status'   => 'publish',
            'post_date'     => $sharedDate,
            'post_date_gmt' => $sharedDate,
            'post_content'  => 'Auto blob A',
        ) );
        add_post_meta( $legacyA, LegacyIdentifiers::META_DESCRIPTION, '<p>Description A</p>' );

        $legacyB = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'    => $sharedTitle,
            'post_status'   => 'publish',
            'post_date'     => $sharedDate,
            'post_date_gmt' => $sharedDate,
            'post_content'  => 'Auto blob B',
        ) );
        add_post_meta( $legacyB, LegacyIdentifiers::META_DESCRIPTION, '<p>Description B</p>' );

        // Inject TWO back-ref-less orphans with the same title + date, creating
        // an ambiguous situation; the writer must refuse to adopt either.
        $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => $sharedTitle,
                'post_date'     => $sharedDate,
                'post_date_gmt' => $sharedDate,
                'post_content'  => '<p>Description A</p>',
            )
        );
        $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => $sharedTitle,
                'post_date'     => $sharedDate,
                'post_date_gmt' => $sharedDate,
                'post_content'  => '<p>Description B</p>',
            )
        );

        $writer  = new SermonWriter();
        $resultA = $writer->write( $legacyA );
        $resultB = $writer->write( $legacyB );

        // Both legacy records must migrate to distinct posts.
        $this->assertNotSame( $resultA->newId, $resultB->newId, 'Each legacy sermon must map to a distinct new post' );

        // Back-refs must point to the correct legacy ids.
        $this->assertSame(
            (string) $legacyA,
            (string) get_post_meta( $resultA->newId, Crosswalk::LEGACY_POST_ID, true ),
            'resultA back-ref must point to legacyA'
        );
        $this->assertSame(
            (string) $legacyB,
            (string) get_post_meta( $resultB->newId, Crosswalk::LEGACY_POST_ID, true ),
            'resultB back-ref must point to legacyB'
        );
    }

    /**
     * FIX 2 (IMPORTANT #9): the orphan probe now matches on title+date+type+back-ref-absent
     * ONLY — post_content is no longer a discriminator. When exactly one back-ref-less
     * candidate matches, it is ADOPTED regardless of content (the cross-version content-
     * drift case). A native look-alike (same title+date, different content, no back-ref)
     * is therefore ALSO adopted if it is the only match — the >1 guard keeps the
     * ambiguous-multi case safe. The spec accepts this trade-off: a back-ref stamp on a
     * byte-coinciding native post is content-loss-free and vanishingly rare in practice.
     */
    public function test_sermon_single_backref_less_candidate_adopted_regardless_of_content(): void {
        $sharedTitle = 'Lookalike Sermon ' . wp_generate_uuid4();
        $sharedDate  = '2022-09-01 08:30:00';

        $legacyId = (int) wp_insert_post( array(
            'post_type'     => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'    => $sharedTitle,
            'post_status'   => 'publish',
            'post_date'     => $sharedDate,
            'post_date_gmt' => $sharedDate,
            'post_content'  => 'Auto blob',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '<p>Real sermon body</p>' );

        // A back-ref-less post with matching title + date but different content.
        // After FIX 2 this is ADOPTED (not bypassed) — exactly-one-candidate rule.
        $candidateId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => $sharedTitle,
                'post_date'     => $sharedDate,
                'post_date_gmt' => $sharedDate,
                'post_content'  => 'DIFFERENT body — content drift / native lookalike',
            )
        );

        $result = ( new SermonWriter() )->write( $legacyId );

        // ADOPTED: exactly one back-ref-less candidate, so adopted regardless of content.
        $this->assertSame( $candidateId, $result->newId, 'Single back-ref-less candidate must be adopted regardless of post_content.' );
        $this->assertFalse( $result->created, 'Adoption must not set created=true.' );
        $this->assertSame(
            (string) $legacyId,
            (string) get_post_meta( $result->newId, Crosswalk::LEGACY_POST_ID, true )
        );
    }

    // ---

    public function test_legacy_post_and_meta_byte_equal_before_and_after(): void {
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Untouched',
            'post_status'  => 'publish',
            'post_name'    => 'untouched-slug',
            'post_content' => '<iframe src="x"></iframe>',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '<p>desc</p>' );
        add_post_meta( $legacyId, LegacyIdentifiers::META_POST_CONTENT_TEMP, 'a temp-only fragment' );

        $before = $this->snapshot( $legacyId );

        ( new SermonWriter() )->write( $legacyId );

        $after = $this->snapshot( $legacyId );
        $this->assertSame( $before, $after, 'Legacy post/meta were mutated by the writer.' );
    }

    // ------------------------------------------------------------------ FIX 2: content-drift orphan adoption

    public function test_crash_orphan_adopted_despite_content_drift(): void {
        // FIX 2 (IMPORTANT): the orphan-adoption probe currently requires post_content
        // byte-equality with the FRESHLY-RECONCILED body. An older writer may have
        // stored a DIFFERENT body (raw legacy content, or a differently-reconciled body)
        // — a one-byte drift returns zero rows, the code falls through to a fresh insert
        // and DUPLICATES the sermon. The fix: match on strong discriminators only
        // (post_date_gmt + post_title + post_type + back-ref-absent), ignoring
        // post_content when exactly ONE candidate matches.
        $uuid     = wp_generate_uuid4();
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Content Drift Sermon ' . $uuid,
            'post_status'  => 'publish',
            'post_date'     => '2022-06-15 12:00:00',
            'post_date_gmt' => '2022-06-15 12:00:00',
            'post_content' => 'raw legacy blob',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, '<p>reconciled body</p>' );

        // Orphan carries a DIFFERENT post_content than what the current writer would
        // produce — exactly the cross-version content-drift scenario.
        $orphanId = $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => 'Content Drift Sermon ' . $uuid,
                'post_date'     => '2022-06-15 12:00:00',
                'post_date_gmt' => '2022-06-15 12:00:00',
                'post_content'  => 'OLDER WRITER stored a different body — drift',
            )
        );
        $this->assertSame( '', (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true ), 'precondition: orphan is back-ref-less' );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Must ADOPT, not create a duplicate.
        $this->assertFalse( $result->created, 'Orphan with drifted content must be adopted, not re-created.' );
        $this->assertSame( $orphanId, $result->newId, 'The drifted orphan must be the migration target.' );

        // Exactly ONE migrated sermon — no duplicate.
        $migrated = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
            'meta_key'    => Crosswalk::LEGACY_POST_ID,
        ) );
        $this->assertCount( 1, $migrated, 'Content-drift orphan adoption must not leave a duplicate.' );
        $this->assertSame( array( $orphanId ), array_map( 'intval', $migrated ) );

        // Back-ref stamped on the orphan.
        $this->assertSame(
            (string) $legacyId,
            (string) get_post_meta( $orphanId, Crosswalk::LEGACY_POST_ID, true )
        );
    }

    public function test_ambiguous_orphans_refuse_adoption(): void {
        // The >1-ambiguous-refuses guard must remain green: when more than one
        // back-ref-less candidate matches title+date+type, none is adopted.
        $uuid     = wp_generate_uuid4();
        $legacyId = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Ambiguous Sermon ' . $uuid,
            'post_status'  => 'publish',
            'post_date'     => '2023-01-01 00:00:00',
            'post_date_gmt' => '2023-01-01 00:00:00',
            'post_content' => 'auto',
        ) );
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'body' );

        // Two back-ref-less orphans with the same title+date — ambiguous.
        $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => 'Ambiguous Sermon ' . $uuid,
                'post_date'     => '2023-01-01 00:00:00',
                'post_date_gmt' => '2023-01-01 00:00:00',
            )
        );
        $this->fixture->injectBackRefLessPostOrphan(
            Identifiers::POST_TYPE_SERMON,
            array(
                'post_title'    => 'Ambiguous Sermon ' . $uuid,
                'post_date'     => '2023-01-01 00:00:00',
                'post_date_gmt' => '2023-01-01 00:00:00',
            )
        );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Falls through to fresh insert (not adopting either ambiguous candidate).
        $this->assertTrue( $result->created, 'Ambiguous orphans (>1 candidate) must not be adopted — fall through to fresh insert.' );
    }
}
