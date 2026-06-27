<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use Sermonator\Admin\Authoring\SermonMetaBox;
use Sermonator\Admin\Authoring\SermonMetaRegistrar;
use Sermonator\Admin\Authoring\SermonRefsRestSanitizer;
use Sermonator\Schema\Identifiers;
use WP_REST_Request;
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
     *
     * These checks are QUOTE-STYLE-INDEPENDENT substring guards (the minifier may rewrite
     * '…' to "…"), and they are a cheap proxy only: the load-bearing behavioral proof that
     * provenance is re-derived server-side regardless of what the client sends lives in
     * {@see self::test_chip_envelope_shape_persists_server_stamped_via_rest()} and
     * {@see self::test_classic_save_rederives_provenance_and_rejects_a_forged_stamp()}.
     */
    public function test_enqueued_bundle_does_not_self_stamp_trusted_provenance(): void {
        $bundle = $this->enqueuedBundle();
        $this->assertStringNotContainsString( 'srcVersification', $bundle, 'srcVersification* is server-stamped, never client-supplied.' );
        $this->assertStringNotContainsString( 'authoring', $bundle, 'The source stamp is server-side only.' );
        $this->assertStringNotContainsString( 'confidence', $bundle, 'The confidence floor is server-side only.' );
    }

    // -------------------------------------------------------------------------
    // Behavioral write transport: the chip's STRUCTURAL-ONLY envelope reaches
    // META_BIBLE_REFS and is server-stamped — provenance is never client-trusted.
    // (Proves the string-presence proxies above actually carry their contract.)
    // -------------------------------------------------------------------------

    /**
     * A REST meta write shaped EXACTLY like the bundle's buildRefsEnvelope output — a bare
     * `{ refs: [ {structural fields} ] }` with NO envelope version and NO provenance — must
     * persist as a JSON string whose every ref is server-stamped source=authoring,
     * confidence=exact, srcVersificationConfidence=authored (and srcVersification = the live
     * link version), with out-of-canon refs dropped and the count capped. This is the spec
     * §6 "confirm-chip save → exact ref" write contract at the transport layer.
     */
    public function test_chip_envelope_shape_persists_server_stamped_via_rest(): void {
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'ESV' );
        ( new SermonRefsRestSanitizer() )->hook();
        $this->bootRestWithMeta();

        // buildRefsEnvelope shape: structural-only, no 'v', no provenance. One bogus
        // out-of-canon ref to prove server-side drop.
        $envelope = array(
            'refs' => array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
                array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Bogus 1:1' ),
            ),
        );

        // Pass the matching passage so that IF a globally-hooked SermonRefsCapture runs its
        // per-ref clearStale on rest_after_insert, the confirmed JHN 3:16 ref survives (its
        // verse key is present in the parse) — this test isolates the sanitizer transport,
        // not clearStale.
        $id   = $this->restWriteRefs( 'John 3:16', (string) wp_json_encode( $envelope ) );
        $refs = $this->persistedRefs( $id );

        $this->assertCount( 1, $refs, 'Out-of-canon ref dropped; only the valid ref persists.' );
        $this->assertSame( 'JHN', $refs[0]['bookUSFM'] );
        $this->assertSame( 'authoring', $refs[0]['source'], 'source re-derived server-side' );
        $this->assertSame( 'exact', $refs[0]['confidence'], 'confidence floor stamped server-side' );
        $this->assertSame( 'ESV', $refs[0]['srcVersification'], 'stamped from the live link version' );
        $this->assertSame( 'authored', $refs[0]['srcVersificationConfidence'] );

        delete_option( Identifiers::OPTION_BIBLE_LINK_VERSION );
    }

    /**
     * The CLASSIC full-page save path (new sermons / pure-classic editors, where the REST
     * autosave never fires) must persist the refs envelope THROUGH the same server-side
     * authority — re-deriving provenance and discarding any forged client stamp. A crafted
     * hidden-input blob claiming source='evil-import'/confidence='exact'/srcVersification='KJV'
     * must NOT survive: that forged-exact path is exactly what would let a wrong single-verse
     * ref render inline (never-fail-WRONG). META_BIBLE_REFS is intentionally NOT in
     * editableMetaKeys, so a regression that "fixed" persistence by adding it there (bypassing
     * the sanitizer) would flip this test red.
     */
    public function test_classic_save_rederives_provenance_and_rejects_a_forged_stamp(): void {
        update_option( Identifiers::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $post_id = (int) self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );

        $forged   = array(
            'refs' => array(
                array(
                    'bookUSFM'                   => 'JHN',
                    'chapterStart'               => 3,
                    'verseStart'                 => 16,
                    'verseEnd'                   => 16,
                    'chapterEnd'                 => null,
                    'raw'                        => 'John 3:16',
                    'source'                     => 'evil-import',
                    'confidence'                 => 'exact',
                    'srcVersification'           => 'KJV',
                    'srcVersificationConfidence' => 'authored',
                ),
            ),
        );
        // Mirror the bundle: META_BIBLE_REFS holds the envelope JSON STRING, and the hidden
        // input carries JSON.stringify(meta).
        $meta_blob = array( Identifiers::META_BIBLE_REFS => (string) wp_json_encode( $forged ) );

        $_POST['sermonator_meta_box_nonce']    = wp_create_nonce( SermonMetaBox::NONCE_ACTION );
        $_POST[ SermonMetaBox::INPUT_NAME ]    = wp_slash( (string) wp_json_encode( $meta_blob ) );

        ( new SermonMetaBox() )->save( $post_id );

        unset( $_POST['sermonator_meta_box_nonce'], $_POST[ SermonMetaBox::INPUT_NAME ] );

        $refs = $this->persistedRefs( $post_id );
        $this->assertCount( 1, $refs );
        $this->assertSame( 'authoring', $refs[0]['source'], 'forged source discarded; re-derived server-side' );
        $this->assertSame( 'exact', $refs[0]['confidence'] );
        $this->assertSame( 'ESV', $refs[0]['srcVersification'], 'forged KJV discarded; stamped from the live link version' );
        $this->assertSame( 'authored', $refs[0]['srcVersificationConfidence'] );

        delete_option( Identifiers::OPTION_BIBLE_LINK_VERSION );
    }

    /**
     * The panel must be SEEDED with the author's prior curation so reopening a sermon does
     * not default every removed ref back to kept (and a re-touch cannot resurrect it as
     * exact). The enqueue therefore localizes the persisted envelope as window.sermonatorMetaBox.savedRefs.
     */
    public function test_enqueue_localizes_the_saved_refs_curation_seed(): void {
        $post_id  = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );
        $envelope = (string) wp_json_encode( array(
            'v'    => 1,
            'refs' => array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16', 'source' => 'authoring', 'confidence' => 'exact' ),
            ),
        ) );
        update_post_meta( $post_id, Identifiers::META_BIBLE_REFS, $envelope );

        $_GET['post']    = $post_id;
        $GLOBALS['post'] = get_post( $post_id );
        set_current_screen( 'post.php' );
        get_current_screen()->post_type = Identifiers::POST_TYPE_SERMON;

        ( new SermonMetaBox() )->enqueueAssets( 'post.php' );

        $data = (string) wp_scripts()->get_data( SermonMetaBox::HANDLE, 'data' );
        $this->assertStringContainsString( 'savedRefs', $data, 'The saved curation must be localized so the panel can seed prior removals.' );
        $this->assertStringContainsString( 'John 3:16', $data, 'The persisted envelope is the seed value.' );
    }

    /**
     * Boot a fresh REST server with the sermon meta registered so the /wp/v2 controller
     * exposes META_BIBLE_REFS for the write.
     */
    private function bootRestWithMeta(): void {
        global $wp_rest_server;
        $wp_rest_server = null;

        $pt = get_post_type_object( Identifiers::POST_TYPE_SERMON );
        if ( $pt ) {
            $pt->rest_controller = null;
        }

        ( new SermonMetaRegistrar() )->register();
        do_action( 'rest_api_init' );
        rest_get_server();
    }

    private function restWriteRefs( string $passage, string $envelopeJson ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/' . Identifiers::POST_TYPE_SERMON . '/' . $id );
        $request->set_body_params( array(
            'meta' => array(
                Identifiers::META_BIBLE_PASSAGE => $passage,
                Identifiers::META_BIBLE_REFS    => $envelopeJson,
            ),
        ) );
        rest_get_server()->dispatch( $request );

        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    private function persistedRefs( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, Identifiers::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
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
        // wp_localize_script JSON-encodes scalars as strings, so postId serializes as "10", not 10.
        $this->assertStringContainsString( '"postId":"' . $post_id . '"', $data, 'The current post id must be localized for the autosave write.' );
    }
}
