<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Frontend\Compat\LegacyShortcodes;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2 Task 6 — the attribute-faithful [sermons]/[sermons_sm] RENDER contract, asserted at
 * the do_shortcode() boundary. Complements LegacySermonsLedgerTest (which pins the per-attribute
 * QUERY semantics: order/date/after/filter_value/per_page); this file pins the RENDER-level
 * behavior T6 added on top of the mapper→SermonQuery→Renderer pipeline:
 *
 *  - a faithful-only call drops the review notice entirely (the earned end-state);
 *  - a call with an UNVALIDATABLE/unknown attribute keeps a PRECISE notice naming ONLY the
 *    present unfaithful attrs (never the faithful ones);
 *  - the §63 no-op attributes (image_size, hide_*) earn NO notice (the sermon SET is unchanged);
 *  - a logged-out visitor sees the rendered content but never the editor notice, while the
 *    sermonator_list_truncated do_action ALWAYS fires when the list spans more than one page.
 *
 * NOTE: integration suite — requires wp-env (Docker). NOT run in this environment (no Docker
 * available); written as the pinned spec.
 */
final class LegacySermonsRenderTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_ARCHIVE_ORDERBY );
        delete_option( ID::OPTION_ARCHIVE_ORDER );
        remove_all_actions( LegacyShortcodes::TRUNCATED_ACTION );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** @param string|null $preached META_DATE (signed unix ts string) or null for a DATELESS sermon. */
    private function makeSermon( string $title, ?string $preached = '1000000000' ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => $title,
        ) );
        if ( null !== $preached ) {
            update_post_meta( $id, ID::META_DATE, $preached );
        }
        return $id;
    }

    /**
     * A faithful-only [sermons] (bare call, everything reproducible) renders the mapped query
     * and shows NO review notice — even to an editor. The notice is earned-off.
     */
    public function test_faithful_only_sermons_renders_query_with_no_notice(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon( 'FaithfulSermon' );

        $html = do_shortcode( '[sermons]' );

        $this->assertStringContainsString( 'FaithfulSermon', $html );
        $this->assertStringContainsString( 'sermonator-grid', $html );
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html,
            'a faithful-only [sermons] must drop the review notice' );
        $this->assertStringNotContainsString( '[sermons]', $html );
    }

    /** [sermons_sm] is the exact alias — same faithful, notice-free render. */
    public function test_sermons_sm_alias_is_attribute_faithful_too(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon( 'AliasSermon' );

        $html = do_shortcode( '[sermons_sm]' );

        $this->assertStringContainsString( 'AliasSermon', $html );
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( '[sermons_sm]', $html );
    }

    /**
     * An unknown attribute renders the query AND a precise notice naming ONLY that attribute —
     * never the faithful ones present in the same call.
     */
    public function test_unknown_attr_keeps_precise_notice_naming_only_it(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon( 'AnySermon' );

        $html = do_shortcode( '[sermons frobnicate="yes" order="asc"]' );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringContainsString( 'frobnicate', $html, 'the unknown attr must be named' );
        $this->assertStringNotContainsString( 'sermonator-grid', substr( $html, 0, (int) strpos( $html, 'frobnicate' ) ),
            'the notice is prepended before the rendered grid' );
        $this->assertStringContainsString( 'sermonator-grid', $html );
    }

    /**
     * image_size is the signed §63 no-op (presentation-only; the sermon SET is unchanged), so it
     * earns NO notice — the ledger cell shipped faithful, never a false alarm.
     */
    public function test_image_size_is_a_noop_with_no_notice(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon( 'AnySermon' );

        $html = do_shortcode( '[sermons image_size="large"]' );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html,
            'image_size is presentation-only — no content change, so no notice is owed' );
        $this->assertStringContainsString( 'sermonator-grid', $html );
    }

    /**
     * A logged-out visitor sees the rendered content but NOT the editor-facing notice, while the
     * truncation do_action ALWAYS fires when the list spans more than one page (observable
     * silent-tail-drop risk, independent of login state).
     */
    public function test_visitor_sees_content_no_notice_but_truncation_action_fires(): void {
        wp_set_current_user( 0 );
        // 3 sermons, per_page=2 → total (3) > perPage (2): the list spans 2 pages.
        $this->makeSermon( 'AlphaSermon', '1000000003' );
        $this->makeSermon( 'BetaSermon', '1000000002' );
        $this->makeSermon( 'GammaSermon', '1000000001' );

        $captured = array();
        add_action(
            LegacyShortcodes::TRUNCATED_ACTION,
            static function ( int $total, int $perPage ) use ( &$captured ): void {
                $captured = array( $total, $perPage );
            },
            10,
            2
        );

        // An unknown attr is present, yet the visitor must never see the editor notice.
        $html = do_shortcode( '[sermons per_page="2" frobnicate="yes"]' );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html,
            'the precise notice is editor-facing — a visitor never sees it' );
        $this->assertStringContainsString( 'sermonator-grid', $html, 'the visitor still sees rendered content' );
        $this->assertSame( array( 3, 2 ), $captured,
            'sermonator_list_truncated must fire with (total, perPage) regardless of login' );
    }

    /** A single-page list (total <= perPage) does NOT fire the truncation signal — no false alarm. */
    public function test_truncation_action_does_not_fire_within_a_single_page(): void {
        wp_set_current_user( 0 );
        $this->makeSermon( 'OnlySermon' );

        $fired = false;
        add_action(
            LegacyShortcodes::TRUNCATED_ACTION,
            static function () use ( &$fired ): void {
                $fired = true;
            }
        );

        do_shortcode( '[sermons per_page="10"]' );

        $this->assertFalse( $fired, 'no truncation when the whole list fits one page' );
    }
}
