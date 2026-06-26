<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Migration\LegacyFeedSnapshot;
use Sermonator\Schema\Identifiers as ID;

/**
 * Rollback story 1: a migrated episode must keep its pre-migration GUID so already-subscribed
 * apps do not re-download it; a brand-new episode (no snapshot entry) keeps 'sermonator-<id>'.
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

    public function test_migrated_episode_replays_snapshot_guid(): void {
        $this->podcast();
        $migrated = $this->sermonWithAudio( 'Migrated' );

        ( new LegacyFeedSnapshot() )->store( array( $migrated => 'wpfc-legacy-guid-xyz' ) );

        $xml = $this->capture();

        $this->assertStringContainsString( '<guid isPermaLink="false">wpfc-legacy-guid-xyz</guid>', $xml );
        $this->assertStringNotContainsString( 'sermonator-' . $migrated, $xml );
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
