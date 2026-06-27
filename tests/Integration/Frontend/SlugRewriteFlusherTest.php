<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\SlugRewriteFlusher;
use Sermonator\Migration\OptionWriter;
use Sermonator\Schema\Identifiers;

/**
 * Integration coverage for the deferred archive-slug rewrite flush (Bundle 4,
 * spec §1.4 / Task 3), exercising the REAL WordPress options API and hook
 * dispatch (Brain Monkey only proves the handler logic in isolation).
 *
 * THE HEADLINE PROOF (spec §1.2 + §4.1): a migration re-run NEVER schedules a
 * rewrite flush. {@see OptionWriter} prefix-swaps `sermonmanager_archive_slug`
 * into the ARTIFACT key `sermonator_archive_slug`, while the live settings key is
 * the DISTINCT {@see Identifiers::OPTION_ARCHIVE_SLUG}
 * (`sermonator_sermon_archive_slug`). Because the flusher's `add_option_` /
 * `update_option_` listeners are bound to the LIVE key, a verbatim re-run that
 * only ever touches the artifact fires them zero times — so the pending flag
 * stays absent and no front-end visitor (nor anyone) ever pays a spurious flush
 * for a re-run. This is the distinct-key provenance invariant made executable.
 *
 * NOTE: requires the wp-env integration harness (WP_UnitTestCase + a live DB).
 * It is NOT run in this environment (no Docker) — authored per the task brief.
 */
final class SlugRewriteFlusherTest extends WP_UnitTestCase {
    private SlugRewriteFlusher $flusher;

    public function set_up(): void {
        parent::set_up();
        $this->flusher = new SlugRewriteFlusher();
        $this->flusher->hook();
        // Start from a known-clean slate: no pending flush scheduled.
        delete_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING );
    }

    /** Whether a rewrite flush is currently scheduled. */
    private function flushPending(): bool {
        return (bool) get_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING, false );
    }

    // ---------------------------------------------------------------- headline

    public function test_migration_rerun_does_not_schedule_a_flush(): void {
        // A pre-existing legacy archive-slug option. OptionWriter reads every
        // sermonmanager_* row and prefix-swaps the NAME only.
        add_option( 'sermonmanager_archive_slug', 'legacy-sermons' );

        // Run (and re-run) the real migration writer.
        ( new OptionWriter() )->migrate();
        ( new OptionWriter() )->migrate();

        // The ARTIFACT key now carries the legacy value verbatim...
        $this->assertSame( 'legacy-sermons', get_option( 'sermonator_archive_slug' ) );

        // ...the DISTINCT live key was never written by the migration...
        $this->assertFalse( get_option( Identifiers::OPTION_ARCHIVE_SLUG, false ) );

        // ...and no rewrite flush was scheduled: the flusher's listeners are bound to the
        // live key the migration never writes, AND the migrated effective slug here is the
        // hard default 'sermons' (this fixture seeds only a FLAT sermonmanager_archive_slug,
        // which DisplayDefaults ignores — it reads the sermonmanager_general/sermonator_general
        // container), so OptionWriter's change-only schedule does not fire. A migration that
        // genuinely changes the container slug DOES schedule one (see PodcastOptionWriterTest).
        $this->assertFalse(
            $this->flushPending(),
            'A migration that does not change the effective archive slug must not schedule a flush.'
        );
    }

    // ------------------------------------------------------------- write path

    public function test_first_save_diverging_from_seed_schedules_a_flush(): void {
        // add_option on the live key fires add_option_{slug}; the value diverges
        // from the DisplayDefaults 'sermons' seed → a flush is scheduled.
        add_option( Identifiers::OPTION_ARCHIVE_SLUG, 'messages' );

        $this->assertTrue( $this->flushPending() );
    }

    public function test_changing_the_live_slug_schedules_a_flush(): void {
        add_option( Identifiers::OPTION_ARCHIVE_SLUG, 'sermons' );
        delete_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING );

        update_option( Identifiers::OPTION_ARCHIVE_SLUG, 'messages' );

        $this->assertTrue( $this->flushPending() );
    }

    public function test_resaving_the_same_live_slug_schedules_nothing(): void {
        add_option( Identifiers::OPTION_ARCHIVE_SLUG, 'sermons' );
        delete_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING );

        // A no-op re-save of the identical value: WordPress suppresses the
        // update_option_ action AND the handler's own guard would reject it.
        update_option( Identifiers::OPTION_ARCHIVE_SLUG, 'sermons' );

        $this->assertFalse( $this->flushPending() );
    }

    // ------------------------------------------------------------- flush path

    public function test_init99_flushes_once_and_clears_in_admin(): void {
        set_current_screen( 'dashboard' ); // is_admin() === true
        update_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING, true );

        $this->flusher->maybeFlush();

        // The flag is cleared exactly once...
        $this->assertFalse( $this->flushPending() );

        // ...and a second pass is a no-op (no double flush, flag still absent).
        $this->flusher->maybeFlush();
        $this->assertFalse( $this->flushPending() );
    }

    public function test_init99_does_not_flush_on_front_end(): void {
        set_current_screen( 'front' ); // is_admin() === false
        update_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING, true );

        $this->flusher->maybeFlush();

        // A front-end request must leave the flag intact (the flush is deferred to
        // the next admin/cron request) — the visitor never pays for it.
        $this->assertTrue( $this->flushPending() );
    }
}
