<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use WP_UnitTestCase;
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
}
