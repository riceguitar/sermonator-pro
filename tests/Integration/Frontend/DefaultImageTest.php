<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\EffectiveImage;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers;

/**
 * Integration coverage for the wired default sermon image (Bundle 4, spec §1.7 /
 * Task 5): a sermon with NO real thumbnail renders the configured site-wide
 * default image, restoring legacy `default_image` parity. A sermon WITH a real
 * thumbnail always wins. Exercises the REAL TemplateData option read +
 * EffectiveImage resolver + Renderer against a live DB and the media library.
 *
 * Also pins the one-time legacy-URL→id resolution: a migrated CMB2 display
 * container that holds only a URL-valued `default_image` (no companion id) is
 * resolved to an attachment id via attachment_url_to_postid() exactly once and
 * PERSISTED into the distinct live key — never a per-render lookup, and never the
 * migration prefix-swap artifact (so a re-run cannot clobber it).
 *
 * NOTE: requires the wp-env integration harness (WP_UnitTestCase + a live DB +
 * the media library). It is NOT run in this environment (no Docker) — authored
 * per the task brief.
 */
final class DefaultImageTest extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        delete_option( Identifiers::OPTION_DEFAULT_IMAGE_ID );
        delete_option( Identifiers::OPTION_PREFIX . 'display' );
        delete_option( 'sermonmanager_display' );
    }

    public function tear_down(): void {
        delete_option( Identifiers::OPTION_DEFAULT_IMAGE_ID );
        delete_option( Identifiers::OPTION_PREFIX . 'display' );
        delete_option( 'sermonmanager_display' );
        parent::tear_down();
    }

    private function newSermon(): int {
        return (int) self::factory()->post->create(
            array( 'post_type' => Identifiers::POST_TYPE_SERMON, 'post_title' => 'Test Sermon' )
        );
    }

    /** Create a real attachment in the media library and return its id. */
    private function newAttachment(): int {
        return (int) self::factory()->attachment->create_object(
            'default.jpg',
            0,
            array( 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' )
        );
    }

    public function test_real_thumbnail_wins_over_configured_default(): void {
        $defaultId = $this->newAttachment();
        update_option( Identifiers::OPTION_DEFAULT_IMAGE_ID, $defaultId );

        $thumbId = $this->newAttachment();
        $postId  = $this->newSermon();
        set_post_thumbnail( $postId, $thumbId );

        $view = ( new TemplateData() )->sermon( $postId );

        $this->assertSame( $thumbId, $view->imageId );
        $this->assertSame( $thumbId, $view->effectiveImageId, 'a real thumbnail always wins' );

        $html = ( new Renderer() )->featuredImage( $view );
        $this->assertStringContainsString( 'sermonator-single__media', $html );
        // The attachment src URL is the token WP's wp_get_attachment_image()/
        // get_the_post_thumbnail() actually emit (the caller's explicit `class`
        // attr OVERRIDES core's default attrs, and 'wp-image-<id>' is an
        // editor/content artifact never produced by these template APIs). It is
        // also the only token that proves WHICH attachment rendered.
        $this->assertStringContainsString( (string) wp_get_attachment_url( $thumbId ), $html );
        $this->assertStringNotContainsString(
            (string) wp_get_attachment_url( $defaultId ),
            $html,
            'the configured default must not appear when a real thumbnail wins'
        );
    }

    public function test_no_thumbnail_falls_back_to_configured_default(): void {
        $defaultId = $this->newAttachment();
        update_option( Identifiers::OPTION_DEFAULT_IMAGE_ID, $defaultId );

        $postId = $this->newSermon(); // no thumbnail set

        $view = ( new TemplateData() )->sermon( $postId );

        $this->assertSame( 0, $view->imageId );
        $this->assertSame( $defaultId, $view->effectiveImageId, 'the configured default fills the gap' );

        $html = ( new Renderer() )->featuredImage( $view );
        $this->assertStringContainsString( 'sermonator-single__media', $html );
        // Assert on the attachment src URL WP actually emits (see the sibling
        // thumbnail test) — proving the configured default attachment rendered.
        $this->assertStringContainsString( (string) wp_get_attachment_url( $defaultId ), $html );
    }

    public function test_no_thumbnail_no_default_renders_nothing(): void {
        $postId = $this->newSermon();

        $view = ( new TemplateData() )->sermon( $postId );

        $this->assertSame( 0, $view->effectiveImageId );
        $this->assertSame( '', ( new Renderer() )->featuredImage( $view ) );
    }

    public function test_legacy_url_default_image_resolves_once_and_persists_into_live_key(): void {
        // The one-time URL→id scan + persist is gated to a privileged context
        // (is_admin()||wp_doing_cron()) so the front end never pays; set an admin
        // screen so this proof exercises the real resolution path.
        set_current_screen( 'edit-' . Identifiers::POST_TYPE_SERMON );

        // A migrated CMB2 display container that holds ONLY a URL-valued
        // default_image (companion id absent) — the case DisplayDefaults skips.
        $attachmentId = $this->newAttachment();
        $url          = (string) wp_get_attachment_url( $attachmentId );
        $this->assertNotEmpty( $url );

        update_option( Identifiers::OPTION_PREFIX . 'display', array( 'default_image' => $url ) );

        // First resolution: URL→id, persisted into the DISTINCT live key.
        $resolved = ( new EffectiveImage() )->defaultImageId();
        $this->assertSame( $attachmentId, $resolved );
        $this->assertSame(
            $attachmentId,
            (int) get_option( Identifiers::OPTION_DEFAULT_IMAGE_ID ),
            'the resolution is persisted into the live id key (one-time, not per-render)'
        );

        // Second resolution reads the live key directly — no second URL lookup.
        $this->assertSame( $attachmentId, ( new EffectiveImage() )->defaultImageId() );
    }

    public function test_unresolvable_legacy_url_does_not_poison_the_live_key(): void {
        set_current_screen( 'edit-' . Identifiers::POST_TYPE_SERMON );

        update_option(
            'sermonmanager_display',
            array( 'default_image' => 'http://example.test/never-existed.jpg' )
        );

        $this->assertSame( 0, ( new EffectiveImage() )->defaultImageId() );
        $this->assertFalse(
            get_option( Identifiers::OPTION_DEFAULT_IMAGE_ID ),
            'an unresolvable URL leaves the live key unset so a later migration can still seed it'
        );
    }

    /**
     * The one-time URL→id scan + persist must NOT fire on the public render path
     * (front end never pays): an unauthenticated visitor reads only the live key +
     * id-keyed seed, never triggering attachment_url_to_postid() or a DB write.
     */
    public function test_front_end_does_not_scan_or_persist_legacy_url(): void {
        // No admin/cron context: is_admin() is false in the default test request,
        // which is exactly the unauthenticated front-end case the gate protects.
        $attachmentId = $this->newAttachment();
        $url          = (string) wp_get_attachment_url( $attachmentId );
        update_option( Identifiers::OPTION_PREFIX . 'display', array( 'default_image' => $url ) );

        $this->assertSame(
            0,
            ( new EffectiveImage() )->defaultImageId(),
            'the front end never resolves the legacy URL'
        );
        $this->assertFalse(
            get_option( Identifiers::OPTION_DEFAULT_IMAGE_ID ),
            'the front end never persists into the live key (deferred to admin/cron)'
        );
    }

    /**
     * An explicitly stored live `0` ("no fallback image", a deliberate admin
     * choice the sanitize boundary persists) must be honored verbatim — never
     * resurrected/overridden by a surviving migrated/legacy default_image, even on
     * an admin request where the URL→id resolution would otherwise run. This pins
     * the #1 data-preservation fix: distinguishing a stored 0 from an absent row.
     */
    public function test_explicit_zero_is_honored_over_migrated_default(): void {
        set_current_screen( 'edit-' . Identifiers::POST_TYPE_SERMON );

        // A migrated default exists (both an id-keyed seed AND a URL fallback)…
        $migratedId = $this->newAttachment();
        update_option(
            Identifiers::OPTION_PREFIX . 'display',
            array(
                'default_image_id' => $migratedId,
                'default_image'    => (string) wp_get_attachment_url( $migratedId ),
            )
        );

        // …but the admin has deliberately cleared the live key to 0.
        update_option( Identifiers::OPTION_DEFAULT_IMAGE_ID, 0 );

        $this->assertSame(
            0,
            ( new EffectiveImage() )->defaultImageId(),
            'a deliberate "no fallback image" choice is never clobbered by a migration artifact'
        );
        $this->assertSame(
            0,
            (int) get_option( Identifiers::OPTION_DEFAULT_IMAGE_ID ),
            'the stored 0 is left intact (not overwritten by a resurrected legacy id)'
        );
    }
}
