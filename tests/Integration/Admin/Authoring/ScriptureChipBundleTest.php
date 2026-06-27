<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use Sermonator\Admin\Authoring\SermonMetaBox;
use Sermonator\Schema\Identifiers;
use WP_UnitTestCase;

/**
 * T13 — confirm-chip Scripture authoring panel ships in the ENQUEUED meta-box bundle.
 *
 * The spine of Task 13 (design §3.8, risk #7) is that the chip UI must live in the
 * bundle SermonMetaBox actually enqueues (build/meta-box, window.sermonatorMetaBox) and
 * NOT in the dormant build/sermon-details bundle, which has no PHP enqueue and would ship
 * invisible. These tests assert that placement on the COMMITTED build artifacts and that
 * the enqueue wiring exposes the REST surface the chips drive (the read-only bible-parse
 * preview + the server-stamped META_BIBLE_REFS write).
 *
 * They are written to run under wp-env (Docker) and are NOT executed in this environment.
 *
 * @runInSeparateProcess is intentionally NOT used: these are filesystem + enqueue reads.
 */
final class ScriptureChipBundleTest extends WP_UnitTestCase {
    private const ENQUEUED_BUNDLE = 'build/meta-box/index.js';
    private const DORMANT_BUNDLE  = 'build/sermon-details/index.js';

    protected function setUp(): void {
        parent::setUp();
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        unset( $_GET['post'] );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function enqueuedBundle(): string {
        $path = SERMONATOR_PATH . self::ENQUEUED_BUNDLE;
        $this->assertFileExists( $path, 'The enqueued meta-box bundle must be built and committed.' );
        return (string) file_get_contents( $path );
    }

    private function dormantBundle(): string {
        $path = SERMONATOR_PATH . self::DORMANT_BUNDLE;
        $this->assertFileExists( $path, 'The sermon-details bundle must exist.' );
        return (string) file_get_contents( $path );
    }

    // -------------------------------------------------------------------------
    // The chip wiring ships in the ENQUEUED bundle.
    // -------------------------------------------------------------------------

    public function test_enqueued_bundle_calls_the_readonly_bible_parse_preview(): void {
        $this->assertStringContainsString(
            'sermonator/v1/bible-parse',
            $this->enqueuedBundle(),
            'The confirm-chip panel must fetch the live parse from the read-only bible-parse endpoint.'
        );
    }

    public function test_enqueued_bundle_feeds_the_refs_envelope_meta_key(): void {
        $this->assertStringContainsString(
            Identifiers::META_BIBLE_REFS,
            $this->enqueuedBundle(),
            'Kept chips must feed META_BIBLE_REFS so the server sanitizer can stamp exact/authoring/authored.'
        );
    }

    public function test_enqueued_bundle_renders_chip_markup(): void {
        $this->assertStringContainsString(
            'sermonator-scripture-chip',
            $this->enqueuedBundle(),
            'The panel must render the Scripture references as confirm chips.'
        );
    }

    /**
     * The panel must only send STRUCTURAL fields; the server (SermonRefsRestSanitizer) is
     * the sole authority for provenance. A bundle that shipped a client-side `confidence`
     * or `srcVersification` stamp would violate the never-fail-WRONG trust contract.
     */
    public function test_enqueued_bundle_does_not_self_stamp_trusted_provenance(): void {
        $bundle = $this->enqueuedBundle();
        $this->assertStringNotContainsString( 'srcVersificationConfidence', $bundle, 'Provenance is server-stamped, never client-supplied.' );
        $this->assertStringNotContainsString( "'authoring'", $bundle, 'The source stamp is server-side only.' );
    }

    // -------------------------------------------------------------------------
    // The chip wiring must NOT leak into the dormant (un-enqueued) bundle.
    // -------------------------------------------------------------------------

    public function test_dormant_bundle_does_not_carry_the_chip_panel(): void {
        $bundle = $this->dormantBundle();
        $this->assertStringNotContainsString(
            'sermonator/v1/bible-parse',
            $bundle,
            'The chip panel must not ship in the dormant sermon-details bundle (it has no PHP enqueue — risk #7).'
        );
        $this->assertStringNotContainsString( 'sermonator-scripture-chip', $bundle );
    }

    // -------------------------------------------------------------------------
    // The enqueue wiring exposes the surface the chips drive.
    // -------------------------------------------------------------------------

    public function test_enqueue_registers_the_meta_box_bundle_and_localizes_its_global(): void {
        $post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );

        // Drive a sermon edit screen so enqueueAssets does not early-return.
        $_GET['post']    = $post_id;
        $GLOBALS['post'] = get_post( $post_id );
        set_current_screen( 'post.php' );
        get_current_screen()->post_type = Identifiers::POST_TYPE_SERMON;

        ( new SermonMetaBox() )->enqueueAssets( 'post.php' );

        $scripts = wp_scripts();
        $this->assertTrue(
            isset( $scripts->registered[ SermonMetaBox::HANDLE ] ),
            'The meta-box bundle must be enqueued on the sermon edit screen.'
        );

        $src = (string) $scripts->registered[ SermonMetaBox::HANDLE ]->src;
        $this->assertStringContainsString(
            self::ENQUEUED_BUNDLE,
            $src,
            'The enqueued handle must point at build/meta-box/index.js, not the dormant bundle.'
        );

        $data = (string) $scripts->get_data( SermonMetaBox::HANDLE, 'data' );
        $this->assertStringContainsString( SermonMetaBox::JS_GLOBAL, $data, 'window.sermonatorMetaBox must be localized.' );
        $this->assertStringContainsString( 'restRoot', $data, 'The REST root is needed for the apiFetch bible-parse call.' );
        $this->assertStringContainsString( '"postId":' . $post_id, $data, 'The current post id must be localized for the autosave write.' );
    }
}
