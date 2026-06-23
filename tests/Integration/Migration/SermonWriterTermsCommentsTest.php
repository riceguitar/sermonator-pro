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

    public function test_commentmeta_with_backslashes_round_trips_byte_exact(): void {
        // CRITICAL #1: copyCommentMeta wrote add_comment_meta WITHOUT wp_slash().
        // get_comment_meta(...,false) returns UNSLASHED values; add_comment_meta()'s
        // internal wp_unslash() then strips a backslash level on copy, corrupting any
        // backslash / escaped-quote / serialized-inner-string commentmeta (a stored
        // serialized string un-serializes to false downstream). We seed the legacy
        // commentmeta via seedRawCommentMeta (wp_slash so the DB row holds the exact
        // bytes) so the test exercises the WRITER path, then assert byte-equality on
        // the migrated comment.
        $legacyId  = $this->bareSermon();
        $commentId = (int) wp_insert_comment( array(
            'comment_post_ID'  => $legacyId,
            'comment_author'   => 'Slashy',
            'comment_content'  => 'Has backslash meta',
            'comment_approved' => '1',
        ) );

        $backslashPath   = 'C:\\Users\\church\\audio\\sermon.mp3';
        $escapedQuote    = 'He said \\"Amen\\" loudly';
        $serializedInner = serialize( 'a string with a \\ backslash' );
        $arrayWithSlash  = array( 'unc' => '\\\\server\\share\\clip.wav', 'q' => 'say \\"hi\\"' );

        $this->fixture->seedRawCommentMeta( $commentId, 'audio_path', $backslashPath );
        $this->fixture->seedRawCommentMeta( $commentId, 'quoted', $escapedQuote );
        $this->fixture->seedRawCommentMeta( $commentId, 'serialized_inner', $serializedInner );
        $this->fixture->seedRawCommentMeta( $commentId, 'array_meta', $arrayWithSlash );

        // Precondition: the seeded legacy row holds the EXACT bytes (fixture path OK).
        $this->assertSame( $backslashPath, get_comment_meta( $commentId, 'audio_path', true ) );
        $this->assertSame( $serializedInner, get_comment_meta( $commentId, 'serialized_inner', true ) );

        $result   = ( new SermonWriter() )->write( $legacyId );
        $comments = get_comments( array( 'post_id' => $result->newId, 'status' => 'any' ) );
        $this->assertCount( 1, $comments );
        $newId = (int) $comments[0]->comment_ID;

        // Byte-equality on the migrated comment — no backslash level lost.
        $this->assertSame( $backslashPath, get_comment_meta( $newId, 'audio_path', true ) );
        $this->assertSame( $escapedQuote, get_comment_meta( $newId, 'quoted', true ) );
        $this->assertSame( $serializedInner, get_comment_meta( $newId, 'serialized_inner', true ) );
        $this->assertSame( $arrayWithSlash, get_comment_meta( $newId, 'array_meta', true ) );

        // The serialized-inner-string must still un-serialize correctly downstream
        // (a lost backslash would make maybe_unserialize return the raw string/false).
        $this->assertSame( 'a string with a \\ backslash', maybe_unserialize( get_comment_meta( $newId, 'serialized_inner', true ) ) );
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

        // CRITICAL: the record must NOT be stamped COMPLETE while the comment copy
        // is still outstanding — otherwise the gate would route every re-run to the
        // COMPLETE branch (term-only self-heal) and the irreplaceable comment would
        // be skipped forever. It stays in the resume leg.
        $this->assertSame(
            '',
            (string) get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true ),
            'a post with an open comment_copy_failed flag must NOT be marked complete'
        );
    }

    public function test_failed_comment_recovers_on_a_later_write_not_skipped_forever(): void {
        // The "never skip-forever" invariant for irreplaceable parishioner-authored
        // data: a comment whose copy failed on the first write is RECOVERED on a
        // subsequent write (the record stayed in the resume leg), the
        // comment_copy_failed flag clears, and only then is MIGRATION_COMPLETE
        // stamped.
        $legacyId        = $this->bareSermon();
        $legacyCommentId = (int) wp_insert_comment( array(
            'comment_post_ID'      => $legacyId,
            'comment_author'       => 'Trent',
            'comment_author_email' => 'trent@example.com',
            'comment_content'      => 'Recoverable comment',
            'comment_approved'     => '1',
        ) );

        global $wpdb;
        $commentsTable = $wpdb->comments;
        $break = static function ( $query ) use ( $commentsTable ) {
            if ( false !== stripos( $query, 'INSERT INTO `' . $commentsTable . '`' )
                || false !== stripos( $query, 'INSERT INTO ' . $commentsTable ) ) {
                return 'INSERT INTO ' . $commentsTable . ' (this_column_does_not_exist) VALUES (1)';
            }
            return $query;
        };

        $writer = new SermonWriter();

        // First write: the comment copy fails.
        add_filter( 'query', $break );
        $suppress = $wpdb->suppress_errors( true );
        $first    = $writer->write( $legacyId );
        remove_filter( 'query', $break );
        $wpdb->suppress_errors( $suppress );

        $this->assertContains( 'comment_copy_failed:' . $legacyCommentId, $first->flags );
        $this->assertCount( 0, get_comments( array( 'post_id' => $first->newId ) ) );
        // Not complete — still resumable.
        $this->assertSame( '', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // Second write: NO break this time → the comment finally copies (resume).
        $second = $writer->write( $legacyId );

        // Recovered: the comment exists on the new post, exactly once.
        $copied = get_comments( array( 'post_id' => $first->newId ) );
        $this->assertCount( 1, $copied, 'the previously-failed comment must be recovered, not skipped forever' );
        $this->assertSame( 'Recoverable comment', $copied[0]->comment_content );
        $this->assertSame( $legacyCommentId, (int) get_comment_meta( $copied[0]->comment_ID, Crosswalk::LEGACY_COMMENT_ID, true ) );

        // The failure flag is cleared (returned and persisted).
        $this->assertNotContains( 'comment_copy_failed:' . $legacyCommentId, $second->flags );
        $persisted = (array) get_post_meta( $first->newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertNotContains( 'comment_copy_failed:' . $legacyCommentId, $persisted );

        // Now that the irreplaceable data is recovered, MIGRATION_COMPLETE stamps.
        $this->assertSame( '1', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );
        $this->assertSame( $first->newId, $second->newId );
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

    public function test_resume_empty_legacy_read_does_not_clobber_existing_assignment(): void {
        // IMPORTANT #10: a transient EMPTY (non-WP_Error) legacy term read on a RESUME
        // pass must NOT REPLACE-clobber a correct prior assignment already on the new
        // post. Empty-legacy is treated as a NO-OP (not authoritative-empty) when the
        // new post already carries a non-empty assignment for that taxonomy.
        $legacyTermId = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Grace' );
        $legacyId     = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $legacyTermId ), LegacyIdentifiers::TAX_PREACHER );
        ( new TermWriter() )->migrateAll();
        $newTermId = (int) Crosswalk::findNewTermByLegacyId( $legacyTermId, Identifiers::TAX_PREACHER );

        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertSame(
            array( $newTermId ),
            array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) ) ),
            'precondition: term assigned on the new post after the fresh migration'
        );

        // Crash-inject a partial so the next write re-enters the RESUME leg, and make
        // the legacy read transiently EMPTY for this taxonomy (a non-WP_Error empty).
        delete_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE );
        wp_set_object_terms( $legacyId, array(), LegacyIdentifiers::TAX_PREACHER );

        $second = $writer->write( $legacyId );
        $this->assertTrue( $second->resumed );

        // The empty legacy read must NOT have wiped the correct prior assignment.
        $this->assertSame(
            array( $newTermId ),
            array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) ) ),
            'transient empty legacy read must not clobber an existing assignment on resume'
        );
    }

    public function test_fresh_insert_empty_legacy_leaves_taxonomy_empty(): void {
        // The fresh-insert counterpart of #10: with NO legacy term assignment, a
        // freshly inserted post must have an EMPTY taxonomy (the empty read is
        // authoritative on the fresh path — nothing to protect).
        $legacyId = $this->bareSermon();
        // No wpfc_preacher assignment at all.

        $result = ( new SermonWriter() )->write( $legacyId );

        $this->assertSame(
            array(),
            array_map( 'intval', wp_get_object_terms( $result->newId, Identifiers::TAX_PREACHER, array( 'fields' => 'ids' ) ) ),
            'fresh insert with empty legacy assignment yields an empty taxonomy'
        );
    }

    public function test_open_flag_in_one_taxonomy_does_not_clobber_admin_term_in_another(): void {
        // The multi-taxonomy edge: a COMPLETE record carries an OPEN
        // missing_term_crosswalk flag in taxonomy A (series). A church admin has
        // manually added a native term in a DIFFERENT taxonomy B (topic), which has
        // NO open flag. When A's term resolves and the COMPLETE-branch repair runs,
        // it must REPLACE ONLY taxonomy A — taxonomy B's admin-curated term must
        // survive untouched (no full-set REPLACE across every taxonomy).
        $seriesLegacyId = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Pentecost' );
        $legacyId       = $this->bareSermon();
        wp_set_object_terms( $legacyId, array( $seriesLegacyId ), LegacyIdentifiers::TAX_SERIES );

        // First write while the SERIES term has no crosswalk → OPEN flag in tax A.
        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $this->assertContains( 'missing_term_crosswalk:' . $seriesLegacyId, $first->flags );
        // Completed even with the open term flag (term flags do not withhold COMPLETE).
        $this->assertSame( '1', (string) get_post_meta( $first->newId, Crosswalk::MIGRATION_COMPLETE, true ) );

        // Admin adds a native term in taxonomy B (TOPIC) on the migrated post.
        $adminTopicId = $this->factory->term->create( array(
            'taxonomy' => Identifiers::TAX_TOPIC,
            'name'     => 'Admin Curated Topic B',
        ) );
        wp_set_object_terms( $first->newId, array( (int) $adminTopicId ), Identifiers::TAX_TOPIC );

        $topicBefore = array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_TOPIC, array( 'fields' => 'ids' ) ) );
        $this->assertSame( array( (int) $adminTopicId ), $topicBefore, 'precondition: admin topic present in tax B' );

        // Resolve the SERIES term so tax A's flag can self-heal on the next write.
        ( new TermWriter() )->migrateAll();
        $newSeriesId = (int) Crosswalk::findNewTermByLegacyId( $seriesLegacyId, Identifiers::TAX_SERIES );

        // Second write: COMPLETE-branch repair runs (open flag in tax A only).
        $second = $writer->write( $legacyId );
        $this->assertFalse( $second->created );
        $this->assertFalse( $second->resumed );

        // Tax A healed: the now-resolved series term is assigned, flag cleared.
        $this->assertSame(
            array( $newSeriesId ),
            array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_SERIES, array( 'fields' => 'ids' ) ) ),
            'taxonomy A (series) must be healed to the resolved term'
        );
        $this->assertNotContains( 'missing_term_crosswalk:' . $seriesLegacyId, $second->flags );

        // Tax B UNTOUCHED: the admin-curated topic term survives the repair.
        $topicAfter = array_map( 'intval', wp_get_object_terms( $first->newId, Identifiers::TAX_TOPIC, array( 'fields' => 'ids' ) ) );
        $this->assertSame(
            array( (int) $adminTopicId ),
            $topicAfter,
            'admin-curated term in an unflagged taxonomy must survive the scoped term repair'
        );
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

    public function test_spam_and_trash_comments_are_copied_with_status_preserved(): void {
        // CRITICAL #1: get_comments(['status'=>'all']) returns ONLY approved+pending,
        // silently dropping spam/trash before MIGRATION_COMPLETE is stamped. The
        // writer must use 'status'=>'any' so spam/trash comments copy too, with
        // their status preserved verbatim (irreplaceable parishioner-authored data).
        $legacyId  = $this->bareSermon();
        $approved  = $this->fixture->createComment( $legacyId, '1', array( 'comment_author' => 'Approved', 'comment_content' => 'approved body' ) );
        $spamId    = $this->fixture->createComment( $legacyId, 'spam', array( 'comment_author' => 'Spammer', 'comment_content' => 'spam body' ) );
        $trashId   = $this->fixture->createComment( $legacyId, 'trash', array( 'comment_author' => 'Trasher', 'comment_content' => 'trash body' ) );

        // Precondition: the fixture seeded the statuses exactly.
        $this->assertSame( 'spam', (string) get_comment( $spamId )->comment_approved );
        $this->assertSame( 'trash', (string) get_comment( $trashId )->comment_approved );

        $result = ( new SermonWriter() )->write( $legacyId );

        $copied = get_comments( array( 'post_id' => $result->newId, 'status' => 'any' ) );
        $byLegacy = array();
        foreach ( $copied as $c ) {
            $byLegacy[ (int) get_comment_meta( $c->comment_ID, Crosswalk::LEGACY_COMMENT_ID, true ) ] = $c;
        }

        $this->assertCount( 3, $copied, 'spam/trash comments must NOT be silently dropped' );
        $this->assertArrayHasKey( $approved, $byLegacy );
        $this->assertArrayHasKey( $spamId, $byLegacy, 'spam comment must be copied' );
        $this->assertArrayHasKey( $trashId, $byLegacy, 'trash comment must be copied' );

        // Status preserved verbatim.
        $this->assertSame( '1', (string) $byLegacy[ $approved ]->comment_approved );
        $this->assertSame( 'spam', (string) $byLegacy[ $spamId ]->comment_approved );
        $this->assertSame( 'trash', (string) $byLegacy[ $trashId ]->comment_approved );
    }

    public function test_resume_after_insert_without_backref_does_not_duplicate(): void {
        // CRITICAL #5: per-comment crash window — wp_insert_comment succeeds, then
        // the process aborts BEFORE add_comment_meta(LEGACY_COMMENT_ID). On resume
        // the un-back-reffed comment must NOT be re-inserted as a duplicate: a
        // reconciliation/probe pass must adopt the orphan by stamping its back-ref.
        $legacyId        = $this->bareSermon();
        $legacyCommentId = $this->fixture->createComment( $legacyId, '1', array(
            'comment_author'       => 'Resumer',
            'comment_author_email' => 'resumer@example.com',
            'comment_content'      => 'Crash-window comment',
        ) );
        $legacy = get_comment( $legacyCommentId );

        // First write produces the new post + (normally) a back-reffed copy.
        $writer = new SermonWriter();
        $first  = $writer->write( $legacyId );
        $newId  = $first->newId;

        // Simulate the crash window: an identical comment exists on the NEW post but
        // is MISSING its LEGACY_COMMENT_ID back-ref (insert succeeded, abort before
        // the stamp). Strip the back-ref off the copy already made so the resume
        // sees an orphan it could otherwise duplicate.
        $copied = get_comments( array( 'post_id' => $newId, 'status' => 'any' ) );
        $this->assertCount( 1, $copied );
        delete_comment_meta( (int) $copied[0]->comment_ID, Crosswalk::LEGACY_COMMENT_ID );

        // Crash-inject a partial so the next write RESUMES and re-drives the copy.
        delete_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE );

        $second = $writer->write( $legacyId );
        $this->assertTrue( $second->resumed );

        // Exactly ONE comment must exist — the orphan was adopted, not duplicated.
        $after = get_comments( array( 'post_id' => $newId, 'status' => 'any' ) );
        $this->assertCount( 1, $after, 'un-back-reffed comment must be adopted, not re-inserted' );

        // And it now carries the back-ref (adopted into the map).
        $this->assertSame(
            $legacyCommentId,
            (int) get_comment_meta( (int) $after[0]->comment_ID, Crosswalk::LEGACY_COMMENT_ID, true ),
            'the adopted comment must be stamped with its LEGACY_COMMENT_ID back-ref'
        );
    }

    public function test_out_of_order_child_id_resolves_parent_in_second_pass(): void {
        // Thread integrity: when a CHILD comment has a LOWER comment_ID than its
        // PARENT (out-of-order ids — legacy imports can produce this), a single
        // ascending pass would copy the child before the parent's new id is known,
        // leaving comment_parent unresolved (0). A second-pass re-parent from the
        // full oldToNew map must fix it.
        $legacyId = $this->bareSermon();

        // Insert the PARENT first to claim a low id, the CHILD second (higher id),
        // then rewrite the rows so the CHILD has the LOWER comment_ID and points at
        // the (higher-id) parent — the out-of-order topology.
        $parentTmp = $this->fixture->createComment( $legacyId, '1', array( 'comment_author' => 'Parent', 'comment_content' => 'parent body' ) );
        $childTmp  = $this->fixture->createComment( $legacyId, '1', array( 'comment_author' => 'Child', 'comment_content' => 'child body' ) );

        // Ensure parentTmp < childTmp (insertion order guarantees this).
        $this->assertLessThan( $childTmp, $parentTmp );

        // Swap their ids so the CHILD ends up with the lower id and references the
        // higher-id parent. We move parent to a brand-new high id.
        global $wpdb;
        $highId = $childTmp + 1000;
        $wpdb->update( $wpdb->comments, array( 'comment_ID' => $highId ), array( 'comment_ID' => $parentTmp ) );
        // Child (lower id) now points at the parent's NEW high id.
        $wpdb->update( $wpdb->comments, array( 'comment_parent' => $highId ), array( 'comment_ID' => $childTmp ) );
        clean_comment_cache( array( $childTmp, $highId ) );

        $parentLegacyId = $highId;
        $childLegacyId  = $childTmp;
        $this->assertLessThan( $parentLegacyId, $childLegacyId, 'child id must be lower than parent id' );

        $result = ( new SermonWriter() )->write( $legacyId );

        $copied = get_comments( array( 'post_id' => $result->newId, 'status' => 'any' ) );
        $this->assertCount( 2, $copied );
        $byLegacy = array();
        foreach ( $copied as $c ) {
            $byLegacy[ (int) get_comment_meta( $c->comment_ID, Crosswalk::LEGACY_COMMENT_ID, true ) ] = $c;
        }
        $this->assertArrayHasKey( $parentLegacyId, $byLegacy );
        $this->assertArrayHasKey( $childLegacyId, $byLegacy );

        // The child's parent must point at the NEW parent id — resolved despite the
        // out-of-order legacy ids.
        $this->assertSame(
            (int) $byLegacy[ $parentLegacyId ]->comment_ID,
            (int) $byLegacy[ $childLegacyId ]->comment_parent,
            'out-of-order child must be re-parented to the new parent id'
        );
        $this->assertNotSame( 0, (int) $byLegacy[ $childLegacyId ]->comment_parent );
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
