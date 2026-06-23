<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\SermonWriter;
use Sermonator\Migration\TermWriter;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 14: SermonWriter — terms + threaded comments + COMPLETE-last.
 *
 * This task covers the TERMS, COMMENTS, and COMPLETE-LAST facets of write():
 *
 *  - TERMS: each legacy term assignment is translated through the taxonomy-aware
 *    crosswalk (Crosswalk::findNewTermByLegacyId, keyed by the mapped target
 *    taxonomy) and re-assigned with wp_set_object_terms per target taxonomy. A
 *    legacy term without a crosswalk is NEVER silently dropped — it records a
 *    missing_term_crosswalk:<id> flag. A wp_set_object_terms WP_Error surfaces a
 *    flag, never a crash. Term repair is re-appliable on an EXISTING post: a
 *    record carrying an OPEN missing_term_crosswalk flag re-resolves the now-
 *    available term on a later write (self-heal), even after COMPLETE.
 *
 *  - COMMENTS: all legacy comments are copied depth-first with NEW ids, their
 *    comment_parent remapped via an old→new map rebuilt from the LEGACY_COMMENT_ID
 *    back-refs (so a reply points at the NEW parent), preserving author/email/url/
 *    date/date_gmt/content/approved/type/user_id + IP/agent/karma. Each new comment
 *    is stamped with LEGACY_COMMENT_ID (already-copied comments are skipped →
 *    idempotent: a second write copies zero duplicates). Commentmeta is copied with
 *    the unserialize discipline (arrays round-trip).
 *
 *  - COMPLETE-LAST: MIGRATION_COMPLETE is written at the very END of write(), after
 *    meta/terms/comments — so an abort before it is resumed, and the gate can tell
 *    a complete record from a stamped-but-partial one.
 *
 * Legacy posts, terms, and comments are read READ-ONLY.
 */
final class SermonWriterTermsCommentsTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    public function set_up(): void {
        parent::set_up();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        ( new \Sermonator\Model\Registrar() )->register();
    }

    /** A minimal legacy sermon with no default meta seeded. */
    private function bareSermon(): int {
        $id = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Bare ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => 'blob',
        ) );
        add_post_meta( $id, LegacyIdentifiers::META_DESCRIPTION, '' );
        return $id;
    }

    /** Snapshot a legacy comment + its meta for byte-equality assertions. */
    private function snapshotComment( int $commentId ): array {
        return array(
            'comment' => get_comment( $commentId, ARRAY_A ),
            'meta'    => get_comment_meta( $commentId ),
        );
    }

    // ------------------------------------------------------------------ TERMS

    public function test_legacy_term_assigned_into_crosswalked_target_taxonomy(): void {
        // A legacy wpfc_preacher term, once crosswalked, lands on the new post in
        // the mapped sermonator_preacher taxonomy (correct taxonomy, new term id).
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_PREACHER );

        // Migrate the term so a crosswalk exists.
        ( new TermWriter() )->migrateAll();
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_PREACHER );
        $this->assertNotNull( $newTermId );

        $result = ( new SermonWriter() )->write( $legacyId );

        $assigned = wp_get_object_terms( $result->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) );
        $this->assertSame( array( (int) $newTermId ), array_map( 'intval', $assigned ) );
        $this->assertNotContains( 'missing_term_crosswalk:' . $legacyTermId, $result->flags );
    }

    public function test_legacy_term_without_crosswalk_flags_and_is_not_assigned(): void {
        // A legacy term that has NOT been migrated must not be silently dropped:
        // a missing_term_crosswalk:<id> flag is recorded, no term is assigned, no
        // crash.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Grace' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_TOPIC );

        // Deliberately do NOT migrate the term — no crosswalk.
        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertContains( 'missing_term_crosswalk:' . $legacyTermId, $result->flags );
        $assigned = wp_get_object_terms( $result->newId, Identifiers::TAX_TOPIC, array( 'fields' => 'ids' ) );
        $this->assertSame( array(), array_map( 'intval', $assigned ) );

        // Persisted flag, too.
        $persisted = (array) get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( 'missing_term_crosswalk:' . $legacyTermId, $persisted );
    }

    public function test_open_missing_term_flag_self_heals_on_a_later_write(): void {
        // A post first migrated while the term crosswalk was missing carries an
        // OPEN missing_term_crosswalk flag. Once the term is migrated, a LATER
        // write re-resolves and assigns it (term repair on an existing post),
        // dropping the now-resolved flag — even though the post is already COMPLETE.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_SERIES );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertContains( 'missing_term_crosswalk:' . $legacyTermId, $first->flags );
        // First write completed (COMPLETE-last) even with an open term flag.
        $this->assertSame( '1', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // Now the term becomes available.
        ( new TermWriter() )->migrateAll();
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_SERIES );
        $this->assertNotNull( $newTermId );

        $second = $writer->write( $legacyId );
        // A completed record is not "resumed"; term repair still runs (self-heal).
        $this->assertFalse( $second->created );
        $assigned = wp_get_object_terms( $first->newId, Identifiers::TAX_SERIES, array( 'fields' => 'ids' ) );
        $this->assertSame( array( (int) $newTermId ), array_map( 'intval', $assigned ) );
        $this->assertNotContains( 'missing_term_crosswalk:' . $legacyTermId, $second->flags );
        $persisted = (array) get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertNotContains( 'missing_term_crosswalk:' . $legacyTermId, $persisted );
    }

    public function test_terms_assigned_per_target_taxonomy_not_mixed(): void {
        // Two legacy terms in different taxonomies crosswalk into their respective
        // target taxonomies — never cross-contaminated.
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Sue' );
        $series   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Lent' );
        $legacyId = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
        wp_set_object_terms( $legacyId, array( $series ), LegacyIdentifiers::TAX_SERIES );

        ( new TermWriter() )->migrateAll();
        $result = ( new SermonWriter() )->write( $legacyId );

        $newPreacher = Crosswalk::findNewTermByLegacyId( $preacher, Identifiers::TAX_PREACHER );
        $newSeries   = Crosswalk::findNewTermByLegacyId( $series, Identifiers::TAX_SERIES );

        $this->assertSame(
            array( (int) $newPreacher ),
            array_map( 'intval', wp_get_object_terms( $result->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) ) )
        );
        $this->assertSame(
            array( (int) $newSeries ),
            array_map( 'intval', wp_get_object_terms( $result->newId, Identifiers::TAX_SERIES, array( 'fields' => 'ids' ) ) )
        );
        // No leakage: preacher term not present in the series taxonomy.
        $this->assertNotContains(
            (int) $newPreacher,
            array_map( 'intval', wp_get_object_terms( $result->newId, Identifiers::TAX_SERIES, array( 'fields' => 'ids' ) ) )
        );
    }

    // --------------------------------------------------------------- COMMENTS

    public function test_threaded_parent_and_reply_copied_with_remapped_parent(): void {
        $legacyId = $this->bareSermon();

        $parentId = (int) wp_insert_comment( array(
            'comment_post_ID'      => $legacyId,
            'comment_author'       => 'Alice',
            'comment_author_email' => 'alice@example.com',
            'comment_content'      => 'Parent comment',
            'comment_approved'     => '1',
        ) );
        $replyId = (int) wp_insert_comment( array(
            'comment_post_ID'      => $legacyId,
            'comment_author'       => 'Bob',
            'comment_author_email' => 'bob@example.com',
            'comment_content'      => 'Reply comment',
            'comment_approved'     => '1',
            'comment_parent'       => $parentId,
        ) );

        $result   = ( new SermonWriter() )->write( $legacyId );
        $comments = get_comments( array(
            'post_id' => $result->newId,
            'orderby' => 'comment_ID',
            'order'   => 'ASC',
        ) );
        $this->assertCount( 2, $comments );

        // Map old->new via the LEGACY_COMMENT_ID back-ref.
        $newByLegacy = array();
        foreach ( $comments as $c ) {
            $legacyRef = (int) get_comment_meta( $c->comment_ID, Crosswalk::LEGACY_COMMENT_ID, true );
            $newByLegacy[ $legacyRef ] = $c;
        }
        $this->assertArrayHasKey( $parentId, $newByLegacy );
        $this->assertArrayHasKey( $replyId, $newByLegacy );

        $newParent = $newByLegacy[ $parentId ];
        $newReply  = $newByLegacy[ $replyId ];

        // The reply's parent points at the NEW parent id, not the legacy one.
        $this->assertSame( (int) $newParent->comment_ID, (int) $newReply->comment_parent );
        $this->assertNotSame( $parentId, (int) $newReply->comment_parent );

        // Author + approval + email preserved.
        $this->assertSame( 'Alice', $newParent->comment_author );
        $this->assertSame( 'alice@example.com', $newParent->comment_author_email );
        $this->assertSame( '1', (string) $newParent->comment_approved );
        $this->assertSame( 'Reply comment', $newReply->comment_content );
    }

    public function test_comment_ip_agent_karma_and_unapproved_state_preserved(): void {
        $legacyId  = $this->bareSermon();
        $commentId = (int) wp_insert_comment( array(
            'comment_post_ID'      => $legacyId,
            'comment_author'       => 'Carol',
            'comment_author_email' => 'carol@example.com',
            'comment_author_url'   => 'https://carol.example',
            'comment_author_IP'    => '203.0.113.7',
            'comment_agent'        => 'Mozilla/5.0 (Test)',
            'comment_karma'        => 4,
            'comment_content'      => 'Pending comment',
            'comment_approved'     => '0',
            'comment_type'         => 'comment',
        ) );

        $result   = ( new SermonWriter() )->write( $legacyId );
        $comments = get_comments( array( 'post_id' => $result->newId ) );
        $this->assertCount( 1, $comments );
        $new = $comments[0];

        $this->assertSame( '203.0.113.7', $new->comment_author_IP );
        $this->assertSame( 'Mozilla/5.0 (Test)', $new->comment_agent );
        $this->assertSame( '4', (string) $new->comment_karma );
        $this->assertSame( '0', (string) $new->comment_approved );
        $this->assertSame( 'https://carol.example', $new->comment_author_url );
    }

    public function test_commentmeta_arrays_round_trip(): void {
        $legacyId  = $this->bareSermon();
        $commentId = (int) wp_insert_comment( array(
            'comment_post_ID'  => $legacyId,
            'comment_author'   => 'Dave',
            'comment_content'  => 'Has meta',
            'comment_approved' => '1',
        ) );
        $payload = array( 'rating' => 5, 'tags' => array( 'x', 'y' ) );
        add_comment_meta( $commentId, 'custom_comment_payload', $payload );
        add_comment_meta( $commentId, 'flat_scalar', 'plain' );

        $result   = ( new SermonWriter() )->write( $legacyId );
        $comments = get_comments( array( 'post_id' => $result->newId ) );
        $this->assertCount( 1, $comments );
        $newId = (int) $comments[0]->comment_ID;

        $this->assertSame( $payload, get_comment_meta( $newId, 'custom_comment_payload', true ) );
        $this->assertSame( 'plain', get_comment_meta( $newId, 'flat_scalar', true ) );
    }

    public function test_second_write_copies_zero_duplicate_comments(): void {
        $legacyId = $this->bareSermon();
        wp_insert_comment( array(
            'comment_post_ID'  => $legacyId,
            'comment_author'   => 'Eve',
            'comment_content'  => 'Only once',
            'comment_approved' => '1',
        ) );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertCount( 1, get_comments( array( 'post_id' => $first->newId ) ) );

        // Crash-inject a partial (delete COMPLETE) so the second write RESUMES and
        // re-drives the comment copy — it must still not duplicate.
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        $second = $writer->write( $legacyId );
        $this->assertTrue( $second->resumed );

        $this->assertCount( 1, get_comments( array( 'post_id' => $first->newId ) ), 'resume must not duplicate comments' );
    }

    public function test_failed_comment_insert_records_flag_not_silent_drop(): void {
        // Irreplaceable parishioner-authored data must never vanish silently: when
        // wp_insert_comment returns falsy, a comment_copy_failed:<legacyCommentId>
        // flag is recorded (and persisted on the new post), and the migration is
        // not crashed.
        $legacyId        = $this->bareSermon();
        $legacyCommentId = (int) wp_insert_comment( array(
            'comment_post_ID'  => $legacyId,
            'comment_author'   => 'Mallory',
            'comment_content'  => 'Will fail to copy',
            'comment_approved' => '1',
        ) );

        // Force wp_insert_comment to fail by breaking the INSERT into wp_comments
        // for the duration of the write. We rewrite the offending INSERT to a no-op
        // that the DB rejects, so $wpdb->insert returns false → wp_insert_comment
        // returns false. We do NOT touch the legacy comment row that already exists.
        global $wpdb;
        $commentsTable = $wpdb->comments;
        $break = static function ( $query ) use ( $commentsTable ) {
            if ( false !== stripos( $query, 'INSERT INTO `' . $commentsTable . '`' )
                || false !== stripos( $query, 'INSERT INTO ' . $commentsTable ) ) {
                // Invalid SQL → $wpdb->insert returns false → wp_insert_comment false.
                return 'INSERT INTO ' . $commentsTable . ' (this_column_does_not_exist) VALUES (1)';
            }
            return $query;
        };
        add_filter( 'query', $break );

        // Suppress the intentional DB error noise from the forced failure.
        $suppress = $wpdb->suppress_errors( true );

        $result = ( new SermonWriter() )->write( $legacyId );

        remove_filter( 'query', $break );
        $wpdb->suppress_errors( $suppress );

        // The new post still exists (migration not crashed).
        $this->assertGreaterThan( 0, $result->newId );
        // No comment was copied.
        $this->assertCount( 0, get_comments( array( 'post_id' => $result->newId ) ) );

        // The failure is recorded in the returned flags AND persisted.
        $this->assertContains( 'comment_copy_failed:' . $legacyCommentId, $result->flags );
        $persisted = (array) get_post_meta( $result->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertContains( 'comment_copy_failed:' . $legacyCommentId, $persisted );
    }

    public function test_admin_added_native_term_survives_second_write_of_completed_post(): void {
        // A completed post with NO open term flag must NOT have its terms clobbered
        // on a re-run. The COMPLETE-branch repair is gated on an open term flag, so
        // a term a church admin manually added to the migrated post survives.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Hope' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_TOPIC );
        ( new TermWriter() )->migrateAll();

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        // Clean migration: no open term flag persisted.
        $persistedAfterFirst = (array) get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        foreach ( $persistedAfterFirst as $flag ) {
            $this->assertStringStartsNotWith( 'missing_term_crosswalk:', (string) $flag );
            $this->assertStringStartsNotWith( 'term_assign_error:', (string) $flag );
        }

        // An admin manually adds a NEW native term in the SAME mapped taxonomy on
        // the migrated post.
        $adminTermId = $this->factory->term->create( array(
            'taxonomy' => Identifiers::TAX_TOPIC,
            'name'     => 'Admin Added Topic',
        ) );
        wp_set_object_terms( $first->newId, array( (int) $adminTermId ), Identifiers::TAX_TOPIC, true );

        $migratedTermId = (int) Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_TOPIC );
        $before = array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_TOPIC, array( 'fields' => 'ids' ) ) );
        sort( $before );
        $this->assertSame(
            array_values( array_unique( array( $migratedTermId, (int) $adminTermId ) ) ),
            $before,
            'precondition: both the migrated and admin-added terms are present'
        );

        // A second write of the COMPLETE record must NOT clobber the admin term.
        $second = $writer->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertFalse( $second->resumed );

        $after = array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_TOPIC, array( 'fields' => 'ids' ) ) );
        sort( $after );
        $this->assertSame( $before, $after, 'admin-curated term was clobbered by an idempotent re-run' );
        $this->assertContains( (int) $adminTermId, $after, 'admin-added native term must survive a second write' );
    }

    public function test_resolving_the_only_flag_clears_the_persisted_flags_row(): void {
        // When the ONLY open flag is a missing_term_crosswalk and that term later
        // resolves, the persisted MIGRATION_FLAGS row must be DELETED, not left
        // stale — writeFlags deletes on an empty flag set. We engineer a legacy
        // post whose body equals its description so the reconciler produces NO
        // post_content_preserved backup/flag, leaving the term flag as the sole flag.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Eastertide' );
        $legacyId     = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'No-backup ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => 'identical body',
        ) );
        // sermon_description equals post_content → reconciler keeps content, no backup.
        add_post_meta( $legacyId, LegacyIdentifiers::META_DESCRIPTION, 'identical body' );
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_SERIES );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );

        // The ONLY persisted flag is the open missing_term_crosswalk.
        $persisted = (array) get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertSame(
            array( 'missing_term_crosswalk:' . $legacyTermId ),
            array_values( $persisted ),
            'precondition: the term flag must be the sole flag (no backup flag)'
        );

        // Resolve the term, then re-write so the self-heal strips the last flag.
        ( new TermWriter() )->migrateAll();
        $second = $writer->write( $legacyId );

        $this->assertNotContains( 'missing_term_crosswalk:' . $legacyTermId, $second->flags );
        $this->assertSame( array(), $second->flags, 'all flags should be cleared after the heal' );

        // The persisted MIGRATION_FLAGS row must be GONE (not a stale empty/old row).
        $this->assertSame(
            '',
            get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true ),
            'the resolved sole flag must leave NO persisted MIGRATION_FLAGS row'
        );
        $this->assertFalse(
            metadata_exists( 'post', $first->newId, Crosswalk::MIGRATION_FLAGS ),
            'the MIGRATION_FLAGS meta row must be deleted, not left stale'
        );
    }

    public function test_wp_error_on_term_set_surfaces_flag_not_crash(): void {
        // A legacy term whose target taxonomy is not registered makes
        // wp_set_object_terms return a WP_Error — we surface a flag, never crash.
        // We crosswalk a term, then deregister the target taxonomy so the set
        // call fails on an invalid taxonomy.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_BOOK, 'Genesis' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_BOOK );

        ( new TermWriter() )->migrateAll();
        $this->assertNotNull( Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_BOOK ) );

        // Deregister the NEW target taxonomy → wp_set_object_terms WP_Error.
        unregister_taxonomy( Identifiers::TAX_BOOK );

        $result = ( new SermonWriter() )->write( $legacyId );

        // Re-register so teardown / other tests are unaffected.
        ( new \Sermonator\Model\Registrar() )->register();

        $hasErrorFlag = false;
        foreach ( $result->flags as $flag ) {
            if ( str_starts_with( $flag, 'term_assign_error:' ) ) {
                $hasErrorFlag = true;
                break;
            }
        }
        $this->assertTrue( $hasErrorFlag, 'a wp_set_object_terms WP_Error must surface a flag' );
    }

    // ----------------------------------------------------------- COMPLETE-LAST

    public function test_migration_complete_set_after_all_steps(): void {
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Ann' );
        $legacyId     = $this->bareSermon();
        add_post_meta( $legacyId, LegacyIdentifiers::META_BIBLE_PASSAGE, 'Acts 2:1' );
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_PREACHER );
        wp_insert_comment( array(
            'comment_post_ID'  => $legacyId,
            'comment_author'   => 'Frank',
            'comment_content'  => 'Amen',
            'comment_approved' => '1',
        ) );
        ( new TermWriter() )->migrateAll();

        $result = ( new SermonWriter() )->write( $legacyId );

        // COMPLETE flag present (written LAST).
        $this->assertSame( '1', (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // And all the prior steps actually happened (meta, terms, comments).
        $this->assertSame( 'Acts 2:1', get_post_meta( $result->newId, Identifiers::META_BIBLE_PASSAGE, true ) );
        $this->assertCount( 1, get_comments( array( 'post_id' => $result->newId ) ) );
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_PREACHER );
        $this->assertSame(
            array( (int) $newTermId ),
            array_map( 'intval', wp_get_object_terms( $result->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) ) )
        );
    }

    public function test_complete_record_reruns_term_repair_only(): void {
        // A COMPLETE record is not resumed (resumed=false, created=false) but term
        // repair still runs — it must not insert a second post nor duplicate terms.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Faith' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_TOPIC );
        ( new TermWriter() )->migrateAll();

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertSame( '1', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        $second = $writer->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertFalse( $second->resumed );
        $this->assertSame( $first->newId, $second->newId );

        // No duplicate post.
        $all = get_posts( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
        ) );
        $this->assertCount( 1, $all );

        // No duplicate term assignment.
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_TOPIC );
        $this->assertSame(
            array( (int) $newTermId ),
            array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_TOPIC, array( 'fields' => 'ids' ) ) )
        );
    }

    // ------------------------------------------------------ READ-ONLY LEGACY

    public function test_legacy_comments_byte_equal_before_and_after(): void {
        $legacyId = $this->bareSermon();
        $parentId = (int) wp_insert_comment( array(
            'comment_post_ID'      => $legacyId,
            'comment_author'       => 'Grace',
            'comment_author_email' => 'grace@example.com',
            'comment_author_IP'    => '198.51.100.4',
            'comment_agent'        => 'Agent/1.0',
            'comment_karma'        => 2,
            'comment_content'      => 'Original',
            'comment_approved'     => '1',
        ) );
        $replyId = (int) wp_insert_comment( array(
            'comment_post_ID'  => $legacyId,
            'comment_author'   => 'Heidi',
            'comment_content'  => 'Reply original',
            'comment_approved' => '1',
            'comment_parent'   => $parentId,
        ) );
        add_comment_meta( $parentId, 'legacy_cmeta', array( 'k' => 'v' ) );

        $before = array(
            'parent' => $this->snapshotComment( $parentId ),
            'reply'  => $this->snapshotComment( $replyId ),
        );

        $writer = new SermonWriter();
        $writer->write( $legacyId );
        delete_post_meta( Crosswalk::findNewByLegacyId( $legacyId ), Crosswalk::MIGRATION_COMPLETE );
        $writer->write( $legacyId ); // resume too

        $after = array(
            'parent' => $this->snapshotComment( $parentId ),
            'reply'  => $this->snapshotComment( $replyId ),
        );

        $this->assertSame( $before, $after, 'Legacy comments/commentmeta were mutated by the writer.' );
    }
}
