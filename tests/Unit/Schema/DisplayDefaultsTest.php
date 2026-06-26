<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Schema\DisplayDefaults;

/**
 * Seed-resolution order proof for the Bundle 4 display defaults: migrated
 * prefix-swap container FIRST, then legacy `sermonmanager_*` container, then the
 * hard constant. Mirrors {@see \Sermonator\Admin\SettingsRegistrar::defaultLinkVersion()}.
 *
 * Sermon Manager persists each CMB2 settings TAB as a single serialized array
 * option (`sermonmanager_general` holds `archive_slug` / `preacher_label`;
 * `sermonmanager_display` holds `default_image` / `default_image_id`), and the
 * migration prefix-swaps that container NAME verbatim (`sermonator_general` /
 * `sermonator_display`). These stubs therefore seed the values where they actually
 * live — as array keys inside the tab container — not as (nonexistent) discrete
 * top-level options.
 */
final class DisplayDefaultsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Route get_option to a per-key store; an absent key returns false (the WP
     * default when no second arg is passed, which these resolvers rely on).
     *
     * @param array<string,mixed> $store
     */
    private function stubOptions( array $store ): void {
        Functions\when( 'get_option' )->alias(
            static function ( $name, $default = false ) use ( $store ) {
                return array_key_exists( $name, $store ) ? $store[ $name ] : $default;
            }
        );
    }

    // --- archive slug --------------------------------------------------------

    public function test_archive_slug_falls_back_to_hard_constant(): void {
        $this->stubOptions( array() );

        $this->assertSame( 'sermons', DisplayDefaults::defaultArchiveSlug() );
        $this->assertSame( DisplayDefaults::HARD_ARCHIVE_SLUG, DisplayDefaults::defaultArchiveSlug() );
    }

    public function test_archive_slug_prefers_migrated_over_legacy_and_hard(): void {
        $this->stubOptions(
            array(
                'sermonator_general'   => array( 'archive_slug' => 'messages' ),
                'sermonmanager_general' => array( 'archive_slug' => 'legacy-sermons' ),
            )
        );

        $this->assertSame( 'messages', DisplayDefaults::defaultArchiveSlug() );
    }

    public function test_archive_slug_uses_legacy_when_migrated_absent(): void {
        $this->stubOptions( array( 'sermonmanager_general' => array( 'archive_slug' => 'legacy-sermons' ) ) );

        $this->assertSame( 'legacy-sermons', DisplayDefaults::defaultArchiveSlug() );
    }

    public function test_archive_slug_skips_blank_migrated_row(): void {
        // An empty/whitespace migrated value must not shadow a real legacy value.
        $this->stubOptions(
            array(
                'sermonator_general'   => array( 'archive_slug' => '   ' ),
                'sermonmanager_general' => array( 'archive_slug' => 'legacy-sermons' ),
            )
        );

        $this->assertSame( 'legacy-sermons', DisplayDefaults::defaultArchiveSlug() );
    }

    // --- default image id ----------------------------------------------------

    public function test_image_id_falls_back_to_zero(): void {
        $this->stubOptions( array() );

        $this->assertSame( 0, DisplayDefaults::defaultImageId() );
        $this->assertSame( DisplayDefaults::HARD_IMAGE_ID, DisplayDefaults::defaultImageId() );
    }

    public function test_image_id_prefers_migrated_id_over_everything(): void {
        $this->stubOptions(
            array(
                'sermonator_display'   => array( 'default_image_id' => 42, 'default_image' => 7 ),
                'sermonmanager_display' => array( 'default_image_id' => 99, 'default_image' => 13 ),
            )
        );

        $this->assertSame( 42, DisplayDefaults::defaultImageId() );
    }

    public function test_image_id_walks_to_bare_migrated_then_legacy_rows(): void {
        $this->stubOptions( array( 'sermonator_display' => array( 'default_image' => '7' ) ) );
        $this->assertSame( 7, DisplayDefaults::defaultImageId() );

        $this->stubOptions( array( 'sermonmanager_display' => array( 'default_image_id' => 99 ) ) );
        $this->assertSame( 99, DisplayDefaults::defaultImageId() );

        $this->stubOptions( array( 'sermonmanager_display' => array( 'default_image' => 13 ) ) );
        $this->assertSame( 13, DisplayDefaults::defaultImageId() );
    }

    public function test_image_id_skips_non_id_url_and_zero_rows(): void {
        // A legacy URL-valued default_image is not an attachment id and is skipped
        // (its URL->id resolution is the write-path's job, not the seed's); a 0 /
        // negative value is likewise not a usable id. Within a provenance level the
        // id-named key is tried before the bare URL key.
        $this->stubOptions(
            array(
                'sermonator_display'   => array(
                    'default_image_id' => 0,
                    'default_image'    => 'https://example.test/wp-content/uploads/x.png',
                ),
                'sermonmanager_display' => array(
                    'default_image_id' => '-5',
                    'default_image'    => '13',
                ),
            )
        );

        $this->assertSame( 13, DisplayDefaults::defaultImageId() );
    }

    // --- preacher label ------------------------------------------------------

    public function test_preacher_label_falls_back_to_hard_constant(): void {
        $this->stubOptions( array() );

        $this->assertSame( 'Preacher', DisplayDefaults::preacherLabel() );
        $this->assertSame( DisplayDefaults::HARD_PREACHER_LABEL, DisplayDefaults::preacherLabel() );
    }

    public function test_preacher_label_prefers_migrated_over_legacy(): void {
        $this->stubOptions(
            array(
                'sermonator_general'   => array( 'preacher_label' => 'Speaker' ),
                'sermonmanager_general' => array( 'preacher_label' => 'Minister' ),
            )
        );

        $this->assertSame( 'Speaker', DisplayDefaults::preacherLabel() );
    }

    public function test_preacher_label_uses_legacy_when_migrated_absent(): void {
        $this->stubOptions( array( 'sermonmanager_general' => array( 'preacher_label' => 'Minister' ) ) );

        $this->assertSame( 'Minister', DisplayDefaults::preacherLabel() );
    }

    // --- container robustness ------------------------------------------------

    public function test_resolvers_ignore_non_array_or_keyless_containers(): void {
        // A container option that is not an array (or lacks the field key) must
        // not blow up — the resolver falls through to the next provenance level.
        $this->stubOptions(
            array(
                'sermonator_general'   => 'not-an-array',
                'sermonmanager_general' => array( 'unrelated' => 'x' ),
                'sermonator_display'   => 0,
                'sermonmanager_display' => array(),
            )
        );

        $this->assertSame( DisplayDefaults::HARD_ARCHIVE_SLUG, DisplayDefaults::defaultArchiveSlug() );
        $this->assertSame( DisplayDefaults::HARD_PREACHER_LABEL, DisplayDefaults::preacherLabel() );
        $this->assertSame( DisplayDefaults::HARD_IMAGE_ID, DisplayDefaults::defaultImageId() );
    }
}
