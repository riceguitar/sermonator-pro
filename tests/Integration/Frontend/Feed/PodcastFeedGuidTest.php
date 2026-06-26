<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyFeedSnapshot;
use Sermonator\Schema\Identifiers as ID;

/**
 * Rollback story 1: a migrated episode must keep its pre-migration GUID so already-subscribed
 * apps do not re-download it; a brand-new episode (no snapshot entry) keeps 'sermonator-<id>'.
 *
 * Faithful to the non-destructive migration: the feed only ever holds the NEW
 * sermonator_sermon id, while the snapshot is keyed by the LEGACY post id. The two are
 * distinct id spaces bridged by the Crosswalk LEGACY_POST_ID back-ref (pre-Finalize) and
 * the durable META_LEGACY_GUID (post-Finalize). The replay MUST work in both states.
 */
final class PodcastFeedGuidTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        delete_option( ID::OPTION_LEGACY_FEED_SNAPSHOT );
        unset( $_GET['podcast'] );
        parent::tearDown();
    }

    private function podcast(): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_title'  => 'Sunday Sermons',
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
            'category'    => 'Christianity',
            'explicit'    => 'no',
        ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );
        return $id;
    }

    private function sermonWithAudio( string $title ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON, 'post_title' => $title ) );
        update_post_meta( $id, ID::META_DATE, '1700000000' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/' . $id . '.mp3' );
        update_post_meta( $id, ID::META_AUDIO_SIZE, '1000' );
        return $id;
    }

    public function test_migrated_episode_replays_snapshot_guid_pre_finalize(): void {
        $this->podcast();
        // The NEW post the feed queries by; the snapshot is keyed by a DISTINCT legacy id.
        $newId    = $this->sermonWithAudio( 'Migrated' );
        $legacyId = 17; // deliberately != $newId, mirroring the non-destructive migration

        Crosswalk::markLegacy( $newId, $legacyId );
        ( new LegacyFeedSnapshot() )->store( array( $legacyId => 'wpfc-legacy-guid-xyz' ) );

        $xml = $this->capture();

        // Replayed via new->legacy translation against the legacy-keyed snapshot.
        $this->assertStringContainsString( '<guid isPermaLink="false">wpfc-legacy-guid-xyz</guid>', $xml );
        // The 'sermonator-<newId>' fallback must NOT have been used (precise: only the guid tag).
        $this->assertStringNotContainsString( '<guid isPermaLink="false">sermonator-' . $newId . '</guid>', $xml );
    }

    public function test_migrated_episode_replays_guid_after_finalize_strips_back_ref(): void {
        $this->podcast();
        $newId    = $this->sermonWithAudio( 'Migrated' );
        $legacyId = 17;

        Crosswalk::markLegacy( $newId, $legacyId );
        ( new LegacyFeedSnapshot() )->store( array( $legacyId => 'wpfc-legacy-guid-xyz' ) );

        // Simulate exactly what the Finalizer does for a verified counterpart: stamp the
        // durable GUID (same makeDurable() call the Finalizer makes) BEFORE stripping the
        // LEGACY_POST_ID back-ref the pre-Finalize translation depends on.
        ( new LegacyFeedSnapshot() )->makeDurable( $newId, $legacyId );
        delete_post_meta( $newId, Crosswalk::LEGACY_POST_ID );

        $xml = $this->capture();

        // Durable replay survives Finalize via META_LEGACY_GUID — no re-churn of subscribers.
        $this->assertStringContainsString( '<guid isPermaLink="false">wpfc-legacy-guid-xyz</guid>', $xml );
        $this->assertStringNotContainsString( '<guid isPermaLink="false">sermonator-' . $newId . '</guid>', $xml );
    }

    public function test_new_episode_without_snapshot_uses_sermonator_guid(): void {
        $this->podcast();
        $fresh = $this->sermonWithAudio( 'Fresh' );

        $xml = $this->capture();

        $this->assertStringContainsString( '<guid isPermaLink="false">sermonator-' . $fresh . '</guid>', $xml );
    }

    private function capture(): string {
        ob_start();
        ( new PodcastFeed() )->render();
        return (string) ob_get_clean();
    }
}
