<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Schema;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;
use Sermonator\Schema\PodcastMetaSchema;

/**
 * Boot-wiring coverage for {@see PodcastMetaSchema} — the gap the Task 7 adversarial review
 * found: the schema's governance is INERT unless something actually calls
 * {@see PodcastMetaSchema::register()} on `init`. Unlike {@see PodcastMetaSchemaTest}, this test
 * deliberately does NOT call register() itself; it asserts that {@see \Sermonator\Plugin::boot()}
 * (run by the integration bootstrap) wired the registration unconditionally on `init`, so the
 * auth_callback + sanitize-at-write hardening is live in the front-end/cron feed read path and on
 * the migration's own add_post_meta() — neither of which runs under is_admin().
 *
 * NOT run in this environment (no Docker / wp-env) — authored to run under wp-env later.
 */
final class PodcastMetaSchemaBootWiringTest extends WP_UnitTestCase {
    /**
     * The plugin booted at bootstrap; its init@10 handler must have called register_post_meta for
     * the podcast settings key. Proven WITHOUT calling register() here — purely the boot wiring.
     */
    public function test_plugin_boot_registers_podcast_settings_meta(): void {
        $registered = get_registered_meta_keys( 'post', ID::POST_TYPE_PODCAST );

        $this->assertArrayHasKey(
            ID::META_PODCAST_SETTINGS,
            $registered,
            'Plugin::boot() must register the podcast settings post meta on init; the auth/sanitize governance is inert otherwise.'
        );

        // show_in_rest=false closes the REST injection vector (spec §1.6 / invariant #6).
        $this->assertFalse( $registered[ ID::META_PODCAST_SETTINGS ]['show_in_rest'] );
        // single=true: the canonical identity blob is one array, not a multi-value meta.
        $this->assertTrue( $registered[ ID::META_PODCAST_SETTINGS ]['single'] );
    }

    /**
     * The sanitize_callback is wired by the boot registration alone: a non-admin-context
     * update_post_meta() (e.g. the migration writer, which runs under WP-CLI / cron, NOT is_admin)
     * is hardened by WordPress itself. We do NOT call PodcastMetaSchema::register() in this test —
     * only the plugin's boot wiring can make this pass.
     */
    public function test_boot_wired_sanitize_callback_hardens_a_raw_write(): void {
        $podcastId = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_status' => 'publish',
        ) );

        update_post_meta( $podcastId, ID::META_PODCAST_SETTINGS, array(
            'owner_email' => 'pod cast@example.com',
            'explicit'    => 'yes',
            // A migration term-filter scope key MUST survive the sanitized write (data preservation).
            'sermonator_preacher' => 7,
        ) );

        $stored = get_post_meta( $podcastId, ID::META_PODCAST_SETTINGS, true );

        $this->assertIsArray( $stored );
        $this->assertSame( 'podcast@example.com', $stored['owner_email'] );
        $this->assertTrue( $stored['explicit'] );
        $this->assertSame( 7, $stored['sermonator_preacher'] );
    }
}
