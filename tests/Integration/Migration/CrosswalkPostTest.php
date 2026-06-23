<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers;

/**
 * Task 6: Crosswalk post query/write helpers.
 *
 * The idempotency spine resolves a migrated post by its legacy back-ref meta
 * *authoritatively and status-agnostically* — a stamped post that has been
 * trashed (or is an auto-draft) MUST still be found, so a re-run never inserts
 * a duplicate. markLegacy writes exactly one back-ref row. migratedPostIds is
 * scoped to a post type and excludes natively-authored posts; allMigratedPostIds
 * spans both sermon and podcast post types.
 */
final class CrosswalkPostTest extends WP_UnitTestCase {
    private function newSermon( string $status = 'publish' ): int {
        return (int) self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => $status,
            'post_title'  => 'Migrated Sermon',
        ) );
    }

    private function newPodcast( string $status = 'publish' ): int {
        return (int) self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_PODCAST,
            'post_status' => $status,
            'post_title'  => 'Migrated Podcast',
        ) );
    }

    public function test_mark_legacy_then_find_round_trips(): void {
        $newId = $this->newSermon();
        Crosswalk::markLegacy( $newId, 4242 );

        $this->assertSame( $newId, Crosswalk::findNewByLegacyId( 4242 ) );
    }

    public function test_absent_legacy_id_returns_null(): void {
        $this->assertNull( Crosswalk::findNewByLegacyId( 999999 ) );
    }

    public function test_trashed_post_is_still_found_no_duplicate_on_rerun(): void {
        $newId = $this->newSermon();
        Crosswalk::markLegacy( $newId, 7000 );

        wp_trash_post( $newId );
        $this->assertSame( 'trash', get_post_status( $newId ) );

        // Status-agnostic resolution: the trashed post must still be found so a
        // resumed migration re-uses it rather than inserting a second post.
        $this->assertSame( $newId, Crosswalk::findNewByLegacyId( 7000 ) );
    }

    public function test_auto_draft_post_is_still_found(): void {
        $newId = $this->newSermon( 'auto-draft' );
        Crosswalk::markLegacy( $newId, 7001 );

        $this->assertSame( $newId, Crosswalk::findNewByLegacyId( 7001 ) );
    }

    public function test_mark_legacy_writes_exactly_one_back_ref_row(): void {
        $newId = $this->newSermon();
        Crosswalk::markLegacy( $newId, 8000 );

        $rows = get_post_meta( $newId, Crosswalk::LEGACY_POST_ID, false );
        $this->assertCount( 1, $rows );
        $this->assertSame( '8000', (string) $rows[0] );
    }

    public function test_find_resolves_within_post_type(): void {
        $sermon  = $this->newSermon();
        $podcast = $this->newPodcast();
        Crosswalk::markLegacy( $sermon, 9100 );
        Crosswalk::markLegacy( $podcast, 9200 );

        $this->assertSame( $sermon, Crosswalk::findNewByLegacyId( 9100, Identifiers::POST_TYPE_SERMON ) );
        $this->assertSame( $podcast, Crosswalk::findNewByLegacyId( 9200, Identifiers::POST_TYPE_PODCAST ) );
        // A sermon legacy id must not resolve against the podcast post type.
        $this->assertNull( Crosswalk::findNewByLegacyId( 9100, Identifiers::POST_TYPE_PODCAST ) );
    }

    public function test_find_returns_lowest_id_on_duplicate_and_does_not_throw(): void {
        $first  = $this->newSermon();
        $second = $this->newSermon();
        // Two new posts both stamped with the same legacy id (a corrupt state).
        // findNewByLegacyId must be loud but deterministic: return the lowest id.
        add_post_meta( $first, Crosswalk::LEGACY_POST_ID, 9500, true );
        add_post_meta( $second, Crosswalk::LEGACY_POST_ID, 9500, true );

        $lowest = min( $first, $second );
        $this->assertSame( $lowest, Crosswalk::findNewByLegacyId( 9500 ) );
    }

    public function test_migrated_post_ids_excludes_native_posts(): void {
        $migrated = $this->newSermon();
        Crosswalk::markLegacy( $migrated, 10000 );

        // A natively-authored sermon (no back-ref) must NOT appear.
        $native = $this->newSermon();

        $ids = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertContains( $migrated, $ids );
        $this->assertNotContains( $native, $ids );
    }

    public function test_migrated_post_ids_scoped_to_post_type(): void {
        $sermon  = $this->newSermon();
        $podcast = $this->newPodcast();
        Crosswalk::markLegacy( $sermon, 11000 );
        Crosswalk::markLegacy( $podcast, 11001 );

        $sermonIds = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertContains( $sermon, $sermonIds );
        $this->assertNotContains( $podcast, $sermonIds );
    }

    public function test_all_migrated_post_ids_spans_sermon_and_podcast(): void {
        $sermon  = $this->newSermon();
        $podcast = $this->newPodcast();
        Crosswalk::markLegacy( $sermon, 12000 );
        Crosswalk::markLegacy( $podcast, 12001 );

        $all = Crosswalk::allMigratedPostIds();
        $this->assertContains( $sermon, $all );
        $this->assertContains( $podcast, $all );
    }
}
