<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use Sermonator\Migration\LegacyFeedSnapshot;
use Sermonator\Migration\Orchestrator;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;
use WP_UnitTestCase;

/**
 * Proves the legacy feed-GUID continuity (rollback story 1) END TO END through the REAL
 * detect pipeline — NOT by hand-seeding the snapshot. The unit tests exercise the read
 * side (guidFor/makeDurable) on pre-fabricated data; this asserts the production capture
 * path (Orchestrator::detect → LegacyFeedGuidCapturer → LegacyFeedSnapshot::store) is wired
 * and reproduces the exact GUID the legacy feed emitted (the_guid()).
 */
final class LegacyFeedSnapshotTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( LegacyFeedSnapshot::OPTION );
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( LegacyFeedSnapshot::OPTION );
        parent::tearDown();
    }

    public function test_detect_captures_the_exact_legacy_feed_guid_keyed_by_legacy_post_id(): void {
        $legacyId = $this->fixture->createSermon( array( 'post_title' => 'Episode One' ) );
        $this->fixture->seedRawMeta( $legacyId, 'sermon_audio', 'https://example.com/ep1.mp3' );

        // The legacy feed emits <guid isPermaLink="false">the_guid()</guid>; capture must
        // reproduce that EXACT string or every subscriber's app re-downloads the episode.
        $expected = (string) apply_filters( 'the_guid', get_the_guid( $legacyId ), $legacyId );
        $this->assertNotSame( '', $expected, 'A legacy sermon must have a non-empty guid.' );

        // The snapshot must NOT exist until the real detect path runs (guards false-green).
        $this->assertNull(
            ( new LegacyFeedSnapshot() )->guidFor( $legacyId ),
            'Snapshot must be empty before detect — it is captured by detect(), never hand-seeded.'
        );

        ( new Orchestrator() )->detect();

        $this->assertSame(
            $expected,
            ( new LegacyFeedSnapshot() )->guidFor( $legacyId ),
            'detect() must capture the exact legacy feed GUID keyed by legacy post id.'
        );
    }

    public function test_detect_baselines_the_snapshot_only_once(): void {
        $legacyId = $this->fixture->createSermon();
        ( new Orchestrator() )->detect();
        $captured = ( new LegacyFeedSnapshot() )->guidFor( $legacyId );
        $this->assertNotNull( $captured, 'First detect must populate the snapshot.' );

        // A second detect (idempotent no-op) must not change the immutable baseline.
        ( new Orchestrator() )->detect();
        $this->assertSame(
            $captured,
            ( new LegacyFeedSnapshot() )->guidFor( $legacyId ),
            'Re-detect must not re-baseline the GUID snapshot.'
        );
    }
}
