<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
use Sermonator\Admin\Authoring\SermonMetaBox;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see \Sermonator\Admin\Authoring\SermonRefsCapture}: a
 * sermon authored (saved) with a {@see ID::META_BIBLE_PASSAGE} gains the structured
 * {@see ID::META_BIBLE_REFS} envelope and {@see ID::TAX_BOOK} terms via the real
 * save_post hook wiring (AuthoringServiceProvider), without an explicit backfill.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). It exercises the
 * full hook surface: register meta -> save -> shared producer -> envelope + dual-write,
 * plus the migration gate and the never-overwrite-authoring guard.
 */
final class SermonRefsCaptureTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_MIGRATION_STATE );
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** @return array<string,mixed> decoded envelope on a post */
    private function refs( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
    }

    /** @return list<string> TAX_BOOK term names attached to the post */
    private function bookTermNames( int $id ): array {
        $terms = wp_get_object_terms( $id, ID::TAX_BOOK, array( 'fields' => 'names' ) );
        return is_array( $terms ) ? array_values( $terms ) : array();
    }

    /**
     * Create a sermon, then set its passage and re-save so the save_post hook runs
     * with the passage already present (mirrors an authoring save).
     */
    private function authorSermon( string $passage ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        wp_update_post( array( 'ID' => $id, 'post_title' => 'Saved with passage' ) );
        return $id;
    }

    /**
     * Drive a passage through the CLASSIC full-page metabox POST flow: the passage is
     * NOT pre-seeded as meta — it is submitted in the metabox JSON blob and persisted
     * by SermonMetaBox::save() during the SAME save_post pass that SermonRefsCapture
     * runs in. This is the Fix 1 path: SermonMetaBox::save() binds save_post at
     * priority 10 and SermonRefsCapture::capture() at priority 20, so the just-saved
     * passage is visible to the capture on the first save (no second save required).
     */
    private function authorViaMetaBox( int $id, string $passage ): void {
        $_POST['sermonator_meta_box_nonce'] = wp_create_nonce( SermonMetaBox::NONCE_ACTION );
        $_POST['sermonator_meta_json']      = wp_slash( (string) wp_json_encode( array(
            ID::META_BIBLE_PASSAGE => $passage,
        ) ) );

        // wp_update_post fires save_post_sermonator_sermon -> metaBox::save (p10)
        // persists the submitted passage -> refsCapture::capture (p20) reads it.
        wp_update_post( array( 'ID' => $id, 'post_title' => 'Authored via metabox' ) );

        unset( $_POST['sermonator_meta_box_nonce'], $_POST['sermonator_meta_json'] );
    }

    public function test_authoring_save_fills_refs_and_book_terms(): void {
        $id = $this->authorSermon( 'John 3:16; Romans 8:28' );

        $refs = $this->refs( $id );
        $this->assertCount( 2, $refs );
        $this->assertSame( 'authoring', $refs[0]['source'], 'Save-time capture tags source:authoring.' );
        $this->assertSame( 'ESV', $refs[0]['srcVersification'] );
        $this->assertEqualsCanonicalizing( array( 'John', 'Romans' ), $this->bookTermNames( $id ) );

        // #1 data preservation: the human passage label is never mutated.
        $this->assertSame( 'John 3:16; Romans 8:28', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }

    public function test_unparseable_passage_is_stamped_on_save(): void {
        $id = $this->authorSermon( 'Welcome and announcements' );

        $this->assertSame( '1', get_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE, true ) );
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
    }

    public function test_save_is_inert_during_active_migration(): void {
        update_option( ID::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $id = $this->authorSermon( 'John 3:16' );

        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
    }

    public function test_resave_never_overwrites_an_existing_envelope(): void {
        $id = $this->authorSermon( 'John 3:16' );
        $this->assertNotEmpty( $this->refs( $id ) );

        // A later passage edit must NOT replace the already-captured envelope
        // (fill-missing-only; a Phase 3b author-confirmed envelope stays sacrosanct).
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'Genesis 1:1' );
        wp_update_post( array( 'ID' => $id, 'post_title' => 'Edited passage' ) );

        $refs = $this->refs( $id );
        $this->assertSame( 'JHN', $refs[0]['bookUSFM'], 'First-captured envelope is preserved.' );
    }

    /**
     * Fix 1 regression: the structured envelope must appear on the SAME save that
     * submits the passage through the classic metabox form — NOT only on a second
     * save. The earlier authorSermon() helper pre-seeds the passage meta before
     * wp_update_post, which CANNOT catch the priority-ordering bug; this helper
     * submits the passage in the metabox blob so SermonMetaBox::save (p10) must run
     * before SermonRefsCapture::capture (p20) within one save_post pass.
     */
    public function test_metabox_save_fills_refs_on_same_save(): void {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );
        // No passage meta exists yet — it arrives only via the metabox POST blob.
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );

        $this->authorViaMetaBox( $id, 'John 3:16; Romans 8:28' );

        // The passage was persisted by the metabox AND captured into the envelope on
        // the one save (priority 20 sees the priority-10 write).
        $this->assertSame( 'John 3:16; Romans 8:28', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
        $refs = $this->refs( $id );
        $this->assertCount( 2, $refs, 'Envelope captured on the first metabox save.' );
        $this->assertSame( 'authoring', $refs[0]['source'] );
        $this->assertEqualsCanonicalizing( array( 'John', 'Romans' ), $this->bookTermNames( $id ) );
    }

    /**
     * Fix 2 regression: a stamped UNPARSEABLE sentinel must not permanently trap an
     * EDITABLE passage. An author first saves a passage that parses to zero refs
     * (sentinel stamped, envelope empty); after correcting it to a valid reference and
     * re-saving, the stale sentinel is cleared and the structured envelope is finally
     * produced. (The frozen-data backfill path keeps its in-producer idempotency — it
     * never clears the sentinel first.)
     */
    public function test_corrected_passage_recovers_from_sentinel(): void {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );

        // First save: a non-parseable passage stamps the sentinel, no envelope.
        $this->authorViaMetaBox( $id, 'see bulletin' );
        $this->assertSame( '1', get_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE, true ) );
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );

        // Correction re-save: sentinel cleared, structured envelope produced.
        $this->authorViaMetaBox( $id, 'John 3:16' );
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS_UNPARSEABLE, true ), 'Stale sentinel cleared.' );
        $refs = $this->refs( $id );
        $this->assertCount( 1, $refs, 'Corrected passage now captures structured refs.' );
        $this->assertSame( 'JHN', $refs[0]['bookUSFM'] );
        $this->assertEqualsCanonicalizing( array( 'John' ), $this->bookTermNames( $id ) );
    }
}
