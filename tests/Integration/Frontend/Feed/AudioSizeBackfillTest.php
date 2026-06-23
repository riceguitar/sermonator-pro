<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\AudioSizeBackfill;
use Sermonator\Schema\Identifiers as ID;

/**
 * The audio-size backfill is the only DB write in the front-end layer; these tests pin its
 * data-preservation guardrails: native-only, fill-missing-only, exactly reversible.
 */
final class AudioSizeBackfillTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( AudioSizeBackfill::LOG_OPTION );
        parent::tearDown();
    }

    /** Fetcher stub: deterministic size from the URL, no network. */
    private function backfill(): AudioSizeBackfill {
        return new AudioSizeBackfill( static fn( string $url ): ?int => str_contains( $url, 'nosize' ) ? null : 4242 );
    }

    private function sermonWithAudio( string $url, ?int $existingSize = null ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_AUDIO, $url );
        if ( $existingSize !== null ) {
            update_post_meta( $id, ID::META_AUDIO_SIZE, (string) $existingSize );
        }
        return $id;
    }

    public function test_fills_missing_size_only(): void {
        $missing  = $this->sermonWithAudio( 'http://x/a.mp3' );
        $hasSize  = $this->sermonWithAudio( 'http://x/b.mp3', 9999 );

        $result = $this->backfill()->run();

        $this->assertSame( 1, $result['written'], 'Only the missing-size sermon is written.' );
        $this->assertSame( '4242', get_post_meta( $missing, ID::META_AUDIO_SIZE, true ) );
        $this->assertSame( '9999', get_post_meta( $hasSize, ID::META_AUDIO_SIZE, true ), 'Existing size untouched.' );
    }

    public function test_dry_run_writes_nothing(): void {
        $missing = $this->sermonWithAudio( 'http://x/a.mp3' );
        $result  = $this->backfill()->run( 0, true );

        $this->assertSame( 1, $result['written'] );
        $this->assertTrue( $result['dryRun'] );
        $this->assertSame( '', get_post_meta( $missing, ID::META_AUDIO_SIZE, true ), 'Dry run must not write.' );
        $this->assertSame( array(), get_option( AudioSizeBackfill::LOG_OPTION, array() ) );
    }

    public function test_unresolved_size_is_counted_not_written(): void {
        $this->sermonWithAudio( 'http://x/nosize.mp3' );
        $result = $this->backfill()->run();
        $this->assertSame( 0, $result['written'] );
        $this->assertSame( 1, $result['failed'] );
    }

    public function test_rollback_restores_exact_pre_state(): void {
        $a = $this->sermonWithAudio( 'http://x/a.mp3' );
        $b = $this->sermonWithAudio( 'http://x/b.mp3' );
        $preExisting = $this->sermonWithAudio( 'http://x/c.mp3', 5000 );

        $this->backfill()->run();
        $this->assertSame( '4242', get_post_meta( $a, ID::META_AUDIO_SIZE, true ) );

        $rollback = $this->backfill()->rollback();

        $this->assertSame( 2, $rollback['removed'] );
        $this->assertSame( '', get_post_meta( $a, ID::META_AUDIO_SIZE, true ), 'Backfilled size removed.' );
        $this->assertSame( '', get_post_meta( $b, ID::META_AUDIO_SIZE, true ) );
        $this->assertSame( '5000', get_post_meta( $preExisting, ID::META_AUDIO_SIZE, true ), 'Pre-existing size never touched by rollback.' );
        $this->assertSame( array(), get_option( AudioSizeBackfill::LOG_OPTION, array() ) );
    }

    public function test_never_writes_legacy_size_key(): void {
        $id = $this->sermonWithAudio( 'http://x/a.mp3' );
        $this->backfill()->run();
        $this->assertSame( '', get_post_meta( $id, 'sermon_audio_filesize', true ) );
        $this->assertSame( '', get_post_meta( $id, '_wpfc_sermon_size', true ), 'Legacy size key must remain untouched.' );
    }

    public function test_ignores_non_sermon_posts(): void {
        $post = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
        update_post_meta( $post, ID::META_AUDIO, 'http://x/a.mp3' );

        $result = $this->backfill()->run();

        $this->assertSame( 0, $result['written'], 'Non-sermon posts are not candidates.' );
        $this->assertSame( '', get_post_meta( $post, ID::META_AUDIO_SIZE, true ) );
    }

    public function test_idempotent_second_run_no_new_writes(): void {
        $this->sermonWithAudio( 'http://x/a.mp3' );
        $this->backfill()->run();
        $second = $this->backfill()->run();
        $this->assertSame( 0, $second['written'], 'Second run finds no remaining candidates.' );
    }
}
