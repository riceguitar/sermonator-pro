<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Schema;

use WP_UnitTestCase;
use Sermonator\Migration\OptionWriter;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * #1 data-preservation proof for the Bundle 4 display-default SEED resolvers
 * against the migration's REAL artifact shape.
 *
 * Sermon Manager stores its CMB2 settings tabs as serialized ARRAY options
 * (`sermonmanager_general` => array( 'archive_slug', 'preacher_label', … );
 * `sermonmanager_display` => array( 'default_image', 'default_image_id', … )),
 * NOT as discrete top-level options. The migration's OptionWriter reads every
 * `sermonmanager_%` row and prefix-swaps the NAME only — producing
 * `sermonator_general` / `sermonator_display` ARRAYS, never a discrete
 * `sermonator_archive_slug`.
 *
 * The adversarial review caught the original resolvers reading discrete flat keys
 * that DO NOT EXIST for any real (or migrated) church, so they silently returned
 * the hard constants: archive_slug reset 'messages'→'sermons' (breaking every
 * permalink + archive URL), preacher_label reset to 'Preacher', default_image
 * reset to 0. This test seeds the church's values where they actually live and
 * proves the resolvers preserve them BEFORE and AFTER the migration — and that the
 * migration carries the container verbatim. It is the integration counterpart to
 * the unit stubs (which can only assert against the container shape, not the real
 * prefix-swap artifact OptionWriter produces).
 */
final class DisplayDefaultsMigrationTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    /** @var int A real attachment id seeded into the display container. */
    private int $defaultImageId;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        $this->clearOptions();

        $this->defaultImageId = (int) self::factory()->post->create( array(
            'post_type'   => 'attachment',
            'post_title'  => 'Default Sermon Artwork',
            'post_status' => 'inherit',
        ) );
    }

    protected function tearDown(): void {
        $this->clearOptions();
        parent::tearDown();
    }

    private function clearOptions(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( 'sermonmanager_general' );
        delete_option( 'sermonmanager_display' );
        delete_option( Identifiers::OPTION_PREFIX . 'general' );
        delete_option( Identifiers::OPTION_PREFIX . 'display' );
    }

    /**
     * Seed the legacy CMB2 tab containers the way Sermon Manager actually stores
     * them: one serialized array per settings tab.
     */
    private function seedLegacyContainers(): void {
        $this->fixture->setOption(
            'sermonmanager_general',
            array(
                'player'         => 'plyr',
                'archive_slug'   => 'messages',
                'preacher_label' => 'Speaker',
            )
        );
        $this->fixture->setOption(
            'sermonmanager_display',
            array(
                // CMB2 image field: URL under the bare key, attachment id under _id.
                'default_image'    => 'https://example.test/wp-content/uploads/cover.png',
                'default_image_id' => $this->defaultImageId,
            )
        );
    }

    public function test_resolvers_read_the_legacy_container_before_migration(): void {
        $this->seedLegacyContainers();

        // Pre-migration: the church's values come from the legacy `sermonmanager_*`
        // tab containers, NOT the hard constants.
        $this->assertSame( 'messages', DisplayDefaults::defaultArchiveSlug() );
        $this->assertSame( 'Speaker', DisplayDefaults::preacherLabel() );
        $this->assertSame( $this->defaultImageId, DisplayDefaults::defaultImageId() );

        // Regression guard: the values were NEVER stored as discrete flat options,
        // so the old code path (which read those) would have missed them.
        $this->assertFalse( get_option( 'sermonmanager_archive_slug', false ) );
        $this->assertFalse( get_option( 'sermonmanager_preacher_label', false ) );
        $this->assertFalse( get_option( 'sermonmanager_default_image', false ) );
        $this->assertFalse( get_option( 'sermonmanager_default_image_id', false ) );
    }

    public function test_resolvers_survive_the_real_migration_prefix_swap(): void {
        $this->seedLegacyContainers();

        ( new OptionWriter() )->migrate();

        // The migration prefix-swaps the container NAME and copies the array value
        // verbatim — there is NO discrete `sermonator_archive_slug` artifact.
        $migratedGeneral = get_option( Identifiers::OPTION_PREFIX . 'general' );
        $migratedDisplay = get_option( Identifiers::OPTION_PREFIX . 'display' );

        $this->assertIsArray( $migratedGeneral );
        $this->assertSame( 'messages', $migratedGeneral['archive_slug'] );
        $this->assertSame( 'Speaker', $migratedGeneral['preacher_label'] );
        $this->assertIsArray( $migratedDisplay );
        $this->assertSame( $this->defaultImageId, $migratedDisplay['default_image_id'] );

        $this->assertFalse( get_option( Identifiers::OPTION_PREFIX . 'archive_slug', false ),
            'No discrete sermonator_archive_slug artifact should be produced.' );

        // The resolvers must still return the church's values from the MIGRATED
        // container (this is the only copy that survives the Finalizer's deletion
        // of the `sermonmanager_*` rows).
        $this->assertSame( 'messages', DisplayDefaults::defaultArchiveSlug() );
        $this->assertSame( 'Speaker', DisplayDefaults::preacherLabel() );
        $this->assertSame( $this->defaultImageId, DisplayDefaults::defaultImageId() );
    }

    public function test_migrated_container_wins_over_a_diverging_legacy_container(): void {
        $this->seedLegacyContainers();
        ( new OptionWriter() )->migrate();

        // Simulate a pre-finalize state where BOTH the migrated and the (not-yet
        // deleted) legacy containers exist but diverge: the migrated copy wins.
        $this->fixture->setOption(
            'sermonmanager_general',
            array( 'archive_slug' => 'stale-legacy', 'preacher_label' => 'StaleLabel' )
        );

        $this->assertSame( 'messages', DisplayDefaults::defaultArchiveSlug() );
        $this->assertSame( 'Speaker', DisplayDefaults::preacherLabel() );
    }

    public function test_resolvers_fall_back_to_hard_constants_on_a_fresh_site(): void {
        // No legacy/migrated containers at all — a genuinely fresh install.
        $this->assertSame( DisplayDefaults::HARD_ARCHIVE_SLUG, DisplayDefaults::defaultArchiveSlug() );
        $this->assertSame( DisplayDefaults::HARD_PREACHER_LABEL, DisplayDefaults::preacherLabel() );
        $this->assertSame( DisplayDefaults::HARD_IMAGE_ID, DisplayDefaults::defaultImageId() );
    }
}
