<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\OptionWriter;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;

/**
 * Bundle 2 Task 1 (review fix, finding #2): the legacy archive ordering settings
 * MUST survive migration so the bare `[sermons]` shim can use them as the default
 * `order`/`orderby` and resolve `orderby=date` exactly as SM's display_sermons() did.
 *
 * They are NOT captured by a bespoke code path — OptionWriter migrates EVERY
 * `sermonmanager_*` option wholesale (OptionMapper prefix-swap, value/type verbatim).
 * This test pins that `sermonmanager_archive_orderby`/`sermonmanager_archive_order`
 * land at `sermonator_archive_orderby`/`sermonator_archive_order` with their values
 * intact, anchoring the named Identifiers::OPTION_ARCHIVE_ORDERBY/OPTION_ARCHIVE_ORDER
 * constants the ledger references.
 *
 * NOTE: integration suite — requires wp-env (Docker). NOT run in this environment
 * (no Docker available); written as the pinned contract for the migration capture.
 */
final class OptionWriterArchiveOrderingTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        foreach ( array(
            LegacyIdentifiers::OPTION_ARCHIVE_ORDERBY,
            LegacyIdentifiers::OPTION_ARCHIVE_ORDER,
            Identifiers::OPTION_ARCHIVE_ORDERBY,
            Identifiers::OPTION_ARCHIVE_ORDER,
        ) as $opt ) {
            delete_option( $opt );
        }
        parent::tearDown();
    }

    public function test_archive_ordering_options_migrate_verbatim_into_the_new_namespace(): void {
        // A site explicitly configured AWAY from SM's defaults — the exact case the
        // naive "DESC + preached" assumption would silently mis-render.
        add_option( LegacyIdentifiers::OPTION_ARCHIVE_ORDERBY, 'title' );
        add_option( LegacyIdentifiers::OPTION_ARCHIVE_ORDER, 'asc' );

        ( new OptionWriter() )->migrate();

        $this->assertSame( 'title', get_option( Identifiers::OPTION_ARCHIVE_ORDERBY ),
            'archive_orderby must migrate verbatim so the bare [sermons] default is faithful' );
        $this->assertSame( 'asc', get_option( Identifiers::OPTION_ARCHIVE_ORDER ),
            'archive_order must migrate verbatim so the bare [sermons] default is faithful' );
    }

    public function test_orderby_date_deciding_option_is_preserved(): void {
        // The value that decides whether `orderby=date` resolves to published vs preached.
        add_option( LegacyIdentifiers::OPTION_ARCHIVE_ORDERBY, 'date' );

        ( new OptionWriter() )->migrate();

        $this->assertSame( 'date', get_option( Identifiers::OPTION_ARCHIVE_ORDERBY ),
            'archive_orderby===date is the ONLY case where orderby=date maps to published date' );
    }

    public function test_absent_legacy_options_leave_no_new_options_so_sm_defaults_apply(): void {
        // SM relied on getOption() returning its hardcoded defaults when the option was
        // never saved. With nothing to migrate, the new keys stay absent and the shim
        // must fall back to LegacyIdentifiers::ARCHIVE_*_DEFAULT.
        ( new OptionWriter() )->migrate();

        $this->assertFalse( get_option( Identifiers::OPTION_ARCHIVE_ORDERBY, false ) );
        $this->assertFalse( get_option( Identifiers::OPTION_ARCHIVE_ORDER, false ) );
        $this->assertSame( 'date_preached', LegacyIdentifiers::ARCHIVE_ORDERBY_DEFAULT );
        $this->assertSame( 'desc', LegacyIdentifiers::ARCHIVE_ORDER_DEFAULT );
    }
}
