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
        $this->assertStringContainsString( 'wp-image-' . $thumbId, $html );
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
        $this->assertStringContainsString( 'wp-image-' . $defaultId, $html );
    }

    public function test_no_thumbnail_no_default_renders_nothing(): void {
        $postId = $this->newSermon();

        $view = ( new TemplateData() )->sermon( $postId );

        $this->assertSame( 0, $view->effectiveImageId );
        $this->assertSame( '', ( new Renderer() )->featuredImage( $view ) );
    }

    public function test_legacy_url_default_image_resolves_once_and_persists_into_live_key(): void {
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
}
