<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Manifest;
use Sermonator\Schema\Identifiers;

/**
 * Task 1: MigrationState — durable, option-backed state machine + per-record
 * progress.
 *
 * The lifecycle phase is monotonic: it advances none → detected → migrating →
 * migrated → verified → finalized and NEVER skips a step nor moves backward,
 * except the one Rollback-flagged retreat migrated → detected. Per-record
 * progress distinguishes an in_progress partial from a complete record so a
 * resumed run redoes partials without duplicating completes. The manifest is
 * captured at detect time for the Verifier/Finalizer. Everything persists in
 * Identifiers::OPTION_MIGRATION_STATE (autoload=no) so it survives a process
 * restart: a fresh MigrationState object reads the same persisted state.
 */
final class MigrationStateTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        parent::tearDown();
    }

    public function test_phase_defaults_to_none(): void {
        $state = new MigrationState();
        $this->assertSame( 'none', $state->phase() );
    }

    public function test_set_advances_to_detected(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        $this->assertSame( 'detected', $state->phase() );
    }

    public function test_illegal_skip_to_finalized_is_rejected(): void {
        $state = new MigrationState();
        $state->set( 'detected' );

        // detected → finalized is an illegal multi-step jump; it must be refused
        // and the phase must remain unchanged.
        $threw = false;
        try {
            $state->set( 'finalized' );
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'Illegal transition detected→finalized must throw.' );
        $this->assertSame( 'detected', $state->phase() );
    }

    public function test_illegal_jump_from_none_to_verified_is_rejected(): void {
        $state = new MigrationState();
        $threw = false;
        try {
            $state->set( 'verified' );
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'Illegal transition none→verified must throw.' );
        $this->assertSame( 'none', $state->phase() );
    }

    public function test_full_forward_progression_is_allowed(): void {
        $state = new MigrationState();
        foreach ( array( 'detected', 'migrating', 'migrated', 'verified', 'finalized' ) as $phase ) {
            $state->set( $phase );
            $this->assertSame( $phase, $state->phase() );
        }
    }

    public function test_idempotent_set_to_same_phase_is_allowed(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        // Re-setting the current phase is a no-op, not an illegal transition.
        $state->set( 'detected' );
        $this->assertSame( 'detected', $state->phase() );
    }

    public function test_backward_retreat_is_rejected_without_rollback_flag(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        $state->set( 'migrating' );
        $state->set( 'migrated' );

        $threw = false;
        try {
            $state->set( 'detected' );
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'migrated→detected without the rollback flag must throw.' );
        $this->assertSame( 'migrated', $state->phase() );
    }

    public function test_rollback_retreat_from_migrated_to_detected_is_allowed(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        $state->set( 'migrating' );
        $state->set( 'migrated' );

        // Only the Rollback path may retreat, and only migrated → detected.
        $state->set( 'detected', true );
        $this->assertSame( 'detected', $state->phase() );
    }

    public function test_rollback_retreat_from_migrating_to_detected_is_allowed(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        $state->set( 'migrating' );

        // A real mid-batch crash leaves the phase at 'migrating'. Rollback must be
        // able to retreat it the SAME single step back to 'detected' (the un-stamped
        // partial-orphan sweep exists precisely to clean up this crash scenario), so
        // the lifecycle is never left stuck at 'migrating' after a rollback.
        $state->set( 'detected', true );
        $this->assertSame( 'detected', $state->phase() );
    }

    public function test_rollback_retreat_from_verified_to_detected_is_allowed(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        $state->set( 'migrating' );
        $state->set( 'migrated' );
        $state->set( 'verified' );

        // 'verified' is the normal pre-finalize review state; legacy data is still
        // byte-intact (Finalize has not run), so a review-then-reject Rollback is fully
        // reversible and MUST be able to retreat the lifecycle to 'detected' (otherwise
        // a rollback-from-verified wedges the phase over now-deleted migrated data).
        $state->set( 'detected', true );
        $this->assertSame( 'detected', $state->phase() );
    }

    public function test_rollback_flag_does_not_permit_retreat_from_finalized(): void {
        $state = new MigrationState();
        $state->set( 'detected' );
        $state->set( 'migrating' );
        $state->set( 'migrated' );
        $state->set( 'verified' );
        $state->set( 'finalized' );

        // 'finalized' is the ONLY irreversible terminal phase — Finalize has already
        // deleted legacy counterparts, so the rollback flag must NOT retreat it (there
        // is nothing left to safely reverse).
        $threw = false;
        try {
            $state->set( 'detected', true );
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'rollback flag must not permit finalized→detected.' );
        $this->assertSame( 'finalized', $state->phase() );
    }

    public function test_record_record_in_progress_is_distinct_from_complete(): void {
        $state = new MigrationState();
        $state->recordRecord( 123, 'in_progress', null, array() );

        $rec = $state->record( 123 );
        $this->assertIsArray( $rec );
        $this->assertSame( 'in_progress', $rec['state'] );
        $this->assertNull( $rec['newId'] );
    }

    public function test_record_record_complete_stores_new_id_and_flags(): void {
        $state = new MigrationState();
        $state->recordRecord( 456, 'complete', 789, array( 'slug_collision' ) );

        $rec = $state->record( 456 );
        $this->assertIsArray( $rec );
        $this->assertSame( 'complete', $rec['state'] );
        $this->assertSame( 789, $rec['newId'] );
        $this->assertContains( 'slug_collision', $rec['flags'] );
    }

    public function test_record_record_overwrites_prior_state_for_same_legacy_id(): void {
        $state = new MigrationState();
        $state->recordRecord( 100, 'in_progress', null, array() );
        $state->recordRecord( 100, 'complete', 200, array() );

        $rec = $state->record( 100 );
        $this->assertSame( 'complete', $rec['state'] );
        $this->assertSame( 200, $rec['newId'] );
    }

    public function test_record_for_unknown_legacy_id_is_null(): void {
        $state = new MigrationState();
        $this->assertNull( $state->record( 999999 ) );
    }

    public function test_record_record_rejects_unknown_state(): void {
        $state = new MigrationState();
        $threw = false;
        try {
            $state->recordRecord( 1, 'bogus', null, array() );
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'recordRecord must reject states outside pending|in_progress|complete|failed.' );
    }

    public function test_mark_phase_complete_round_trips(): void {
        $state = new MigrationState();
        $this->assertFalse( $state->phaseComplete( 'terms' ) );

        $state->markPhaseComplete( 'terms' );
        $this->assertTrue( $state->phaseComplete( 'terms' ) );
        // A different phase key is independent.
        $this->assertFalse( $state->phaseComplete( 'sermons' ) );
    }

    public function test_manifest_round_trips_via_the_option(): void {
        $state    = new MigrationState();
        $manifest = new Manifest(
            array( 'sermons' => 3, 'preacher' => 2 ),
            array( 11 => 'abc123', 22 => 'def456' )
        );
        $state->setManifest( $manifest );

        $got = $state->manifest();
        $this->assertInstanceOf( Manifest::class, $got );
        $this->assertSame( 3, $got->count( 'sermons' ) );
        $this->assertSame( 2, $got->count( 'preacher' ) );
        $this->assertSame( 'abc123', $got->checksum( 11 ) );
        $this->assertSame( 'def456', $got->checksum( 22 ) );
    }

    public function test_manifest_is_null_before_detect(): void {
        $state = new MigrationState();
        $this->assertNull( $state->manifest() );
    }

    public function test_setManifest_is_write_once_past_detected(): void {
        // B2b review (verifier-soundness-0): the detect-time manifest is the immutable
        // fixity oracle. Capturing it is allowed at none/detected, but once migration
        // has begun an overwrite would re-baseline the drift oracle — so setManifest
        // must refuse (defense-in-depth behind Orchestrator::detect's own guard).
        $state    = new MigrationState();
        $original = new Manifest( array( 'sermons' => 2 ), array( 1 => 'h1', 2 => 'h2' ) );

        // Allowed at 'none' and 'detected'.
        $state->setManifest( $original );
        $state->set( 'detected' );
        $state->setManifest( $original ); // idempotent re-detect at 'detected' is fine.

        // Disallowed once work has begun.
        $state->set( 'migrating' );
        $threw = false;
        try {
            $state->setManifest( new Manifest( array( 'sermons' => 99 ), array( 1 => 'POISONED' ) ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'setManifest must refuse to overwrite the manifest past detected.' );

        // The original detect-time checksums are untouched (oracle preserved).
        $stored = $state->manifest();
        $this->assertSame( 'h1', $stored->checksum( 1 ) );
        $this->assertSame( 2, $stored->count( 'sermons' ) );
    }

    public function test_setManifest_allows_first_write_at_advanced_phase_but_refuses_overwrite(): void {
        // B2b round-2 review (finalize-restructure-0): "write-once" means NO OVERWRITE.
        // A FIRST manifest write is permitted even at an advanced phase (the corrupted-
        // state recovery — no oracle exists to poison), but OVERWRITING an existing
        // manifest past 'detected' is refused (the verifier-soundness guarantee).
        $state = new MigrationState();
        $state->set( 'detected' );
        $state->set( 'migrating' );

        // No manifest stored yet → a FIRST write at 'migrating' is allowed (recovery).
        $this->assertNull( $state->manifest() );
        $state->setManifest( new Manifest( array( 'sermons' => 1 ), array( 5 => 'h5' ) ) );
        $this->assertSame( 'h5', $state->manifest()->checksum( 5 ) );

        // A subsequent OVERWRITE at the advanced phase is refused (the oracle now exists).
        $threw = false;
        try {
            $state->setManifest( new Manifest( array( 'sermons' => 99 ), array( 5 => 'POISONED' ) ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'Overwriting an existing manifest past detected must be refused.' );
        $this->assertSame( 'h5', $state->manifest()->checksum( 5 ), 'The original detect-time oracle must be intact.' );
    }

    public function test_manifest_round_trips_podcast_checksums(): void {
        // B2b review (legacy-immutability-0): podcasts now carry a SEPARATE detect-time
        // checksum map (kept distinct from the sermon map so sermon/podcast can still be
        // told apart by checksum() !== null). It must survive the option round-trip.
        $state    = new MigrationState();
        $manifest = new Manifest(
            array( 'sermons' => 1, 'podcasts' => 2 ),
            array( 11 => 'sermonhash' ),
            array( 31 => 'podcasthash31', 32 => 'podcasthash32' )
        );
        $state->setManifest( $manifest );

        $got = $state->manifest();
        $this->assertSame( 'sermonhash', $got->checksum( 11 ) );
        $this->assertNull( $got->checksum( 31 ), 'A podcast id must NOT resolve via the sermon checksum map.' );
        $this->assertSame( 'podcasthash31', $got->podcastChecksum( 31 ) );
        $this->assertSame( array( 31, 32 ), $got->checksummedPodcastLegacyIds() );
    }

    public function test_state_is_durable_across_a_fresh_instance(): void {
        $writer = new MigrationState();
        $writer->set( 'detected' );
        $writer->recordRecord( 321, 'in_progress', null, array( 'post_parent_unresolved' ) );
        $writer->markPhaseComplete( 'terms' );
        $writer->setManifest( new Manifest( array( 'sermons' => 5 ), array( 7 => 'hash7' ) ) );

        // A brand-new object (simulating a process restart / a separate cron
        // run) reads exactly the same persisted state from the option.
        $reader = new MigrationState();
        $this->assertSame( 'detected', $reader->phase() );

        $rec = $reader->record( 321 );
        $this->assertSame( 'in_progress', $rec['state'] );
        $this->assertContains( 'post_parent_unresolved', $rec['flags'] );

        $this->assertTrue( $reader->phaseComplete( 'terms' ) );

        $manifest = $reader->manifest();
        $this->assertInstanceOf( Manifest::class, $manifest );
        $this->assertSame( 5, $manifest->count( 'sermons' ) );
        $this->assertSame( 'hash7', $manifest->checksum( 7 ) );
    }

    public function test_option_is_not_autoloaded(): void {
        $state = new MigrationState();
        $state->set( 'detected' );

        global $wpdb;
        $autoload = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                Identifiers::OPTION_MIGRATION_STATE
            )
        );
        // WP 6.6+ stores explicit non-autoload as 'off' (was 'no'); accept either so the
        // assertion is correct across the supported floor. Both mean "not autoloaded".
        $this->assertContains(
            $autoload,
            array( 'no', 'off' ),
            'Migration state option must be stored with autoload disabled (no/off).'
        );
        $this->assertNotContains( $autoload, array( 'yes', 'on' ), 'Migration state option must NOT be autoloaded.' );
    }
}
