<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\SlugRewriteFlusher;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;

/**
 * Unit coverage for the deferred, change-only archive-slug rewrite flush
 * (Bundle 4, spec §1.4 / Task 3).
 *
 * Proves: the write listeners schedule a flush ONLY on a real value change (a
 * no-op re-save schedules nothing); the first-save (add) path compares against
 * the {@see DisplayDefaults} seed; and the `init@99` handler flushes EXACTLY
 * ONCE then clears the flag, but never on a front-end (non-admin/non-cron)
 * request.
 */
final class SlugRewriteFlusherTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // DisplayDefaults::defaultArchiveSlug() reads the migrated/legacy
        // containers; with no rows it resolves to the hard 'sermons' seed.
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) => $default
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function flusher(): SlugRewriteFlusher {
        return new SlugRewriteFlusher();
    }

    // --- hook wiring ---------------------------------------------------------

    public function test_hook_registers_add_update_and_init99_listeners(): void {
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'add_option_' . Identifiers::OPTION_ARCHIVE_SLUG, \Mockery::type( 'array' ), 10, 2 );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'update_option_' . Identifiers::OPTION_ARCHIVE_SLUG, \Mockery::type( 'array' ), 10, 2 );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'init', \Mockery::type( 'array' ), 99 );

        $this->flusher()->hook();
        $this->addToAssertionCount( 1 );
    }

    // --- write path: update --------------------------------------------------

    public function test_update_with_changed_value_sets_pending(): void {
        Functions\expect( 'update_option' )
            ->once()
            ->with( Identifiers::OPTION_REWRITE_FLUSH_PENDING, true );

        $this->flusher()->onSlugUpdated( 'sermons', 'messages' );
        $this->addToAssertionCount( 1 );
    }

    public function test_noop_update_does_not_set_pending(): void {
        // Same old/new value: no flush is scheduled (the change-only contract).
        Functions\expect( 'update_option' )->never();

        $this->flusher()->onSlugUpdated( 'sermons', 'sermons' );
        $this->addToAssertionCount( 1 );
    }

    public function test_update_normalizes_scalar_type_drift_to_no_change(): void {
        // A benign int/string drift of the same slug must not schedule a flush.
        Functions\expect( 'update_option' )->never();

        $this->flusher()->onSlugUpdated( '123', 123 );
        $this->addToAssertionCount( 1 );
    }

    // --- write path: add (first save) ---------------------------------------

    public function test_add_diverging_from_seed_sets_pending(): void {
        // get_option returns the default → seed is the hard 'sermons'. A first
        // save of a DIFFERENT slug diverges from the routing the CPT registered
        // with, so a flush is scheduled.
        Functions\expect( 'update_option' )
            ->once()
            ->with( Identifiers::OPTION_REWRITE_FLUSH_PENDING, true );

        $this->flusher()->onSlugAdded( Identifiers::OPTION_ARCHIVE_SLUG, 'messages' );
        $this->addToAssertionCount( 1 );
    }

    public function test_add_matching_seed_does_not_set_pending(): void {
        // A first save that merely persists the seed value changes no routing.
        Functions\expect( 'update_option' )->never();

        $this->flusher()->onSlugAdded( Identifiers::OPTION_ARCHIVE_SLUG, DisplayDefaults::HARD_ARCHIVE_SLUG );
        $this->addToAssertionCount( 1 );
    }

    // --- flush path ----------------------------------------------------------

    public function test_maybe_flush_flushes_once_and_clears_when_pending_in_admin(): void {
        Functions\when( 'is_admin' )->justReturn( true );
        Functions\when( 'wp_doing_cron' )->justReturn( false );
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                Identifiers::OPTION_REWRITE_FLUSH_PENDING === $name ? true : $default
        );

        Functions\expect( 'delete_option' )
            ->once()
            ->with( Identifiers::OPTION_REWRITE_FLUSH_PENDING );
        Functions\expect( 'flush_rewrite_rules' )->once();

        $this->flusher()->maybeFlush();
        $this->addToAssertionCount( 1 );
    }

    public function test_maybe_flush_runs_under_cron(): void {
        Functions\when( 'is_admin' )->justReturn( false );
        Functions\when( 'wp_doing_cron' )->justReturn( true );
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                Identifiers::OPTION_REWRITE_FLUSH_PENDING === $name ? true : $default
        );

        Functions\expect( 'delete_option' )->once();
        Functions\expect( 'flush_rewrite_rules' )->once();

        $this->flusher()->maybeFlush();
        $this->addToAssertionCount( 1 );
    }

    public function test_maybe_flush_noop_when_not_pending(): void {
        Functions\when( 'is_admin' )->justReturn( true );
        Functions\when( 'wp_doing_cron' )->justReturn( false );
        // get_option (from setUp) returns the default false → not pending.
        Functions\expect( 'flush_rewrite_rules' )->never();
        Functions\expect( 'delete_option' )->never();

        $this->flusher()->maybeFlush();
        $this->addToAssertionCount( 1 );
    }

    public function test_maybe_flush_noop_on_front_end_even_when_pending(): void {
        Functions\when( 'is_admin' )->justReturn( false );
        Functions\when( 'wp_doing_cron' )->justReturn( false );
        // Pending is set, but a front-end visitor must never flush — the context
        // guard short-circuits BEFORE the flag is even read.
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                Identifiers::OPTION_REWRITE_FLUSH_PENDING === $name ? true : $default
        );
        Functions\expect( 'flush_rewrite_rules' )->never();
        Functions\expect( 'delete_option' )->never();

        $this->flusher()->maybeFlush();
        $this->addToAssertionCount( 1 );
    }
}
