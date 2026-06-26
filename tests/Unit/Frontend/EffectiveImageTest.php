<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\EffectiveImage;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the shared default-image resolver (Bundle 4, spec §1.7 /
 * Task 5).
 *
 * Proves the resolution order: a real post thumbnail always wins; otherwise the
 * configured site-wide default image id (live key, explicit DisplayDefaults
 * fallback); otherwise 0. Plus the one-time legacy-URL→id resolution that
 * persists into the live key so it never becomes a per-render lookup.
 */
final class EffectiveImageTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Default: every option absent → callers receive their explicit fallback.
        // (DisplayDefaults::defaultImageId() reads the migrated/legacy containers;
        // with no rows it resolves to the hard 0 seed.)
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) => $default
        );
        // The one-time legacy-URL→id scan + persist is gated to a privileged
        // context (is_admin()||wp_doing_cron()); default these resolution-order
        // tests to an admin request so they exercise the real resolution path.
        // The dedicated gate test below flips both to false (the front-end case).
        Functions\when( 'is_admin' )->justReturn( true );
        Functions\when( 'wp_doing_cron' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_real_thumbnail_wins(): void {
        // A set thumbnail short-circuits before any option/attachment lookup.
        $this->assertSame( 42, ( new EffectiveImage() )->resolve( 42 ) );
    }

    public function test_falls_back_to_live_default_when_no_thumbnail(): void {
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_DEFAULT_IMAGE_ID === $name ? 7 : $default
        );

        $this->assertSame( 7, ( new EffectiveImage() )->resolve( 0 ) );
    }

    public function test_zero_when_no_thumbnail_no_default_no_legacy_url(): void {
        // No thumbnail, no live key, no id-keyed seed, no legacy URL container:
        // the one-time URL resolution must NOT write anything.
        Functions\expect( 'update_option' )->never();

        $this->assertSame( 0, ( new EffectiveImage() )->resolve( 0 ) );
    }

    public function test_one_time_legacy_url_resolution_persists_into_live_key(): void {
        // Live key + id-keyed seed both absent, but a migrated CMB2 display
        // container holds a bare URL-valued default_image: it resolves ONCE and is
        // persisted into the live id key.
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                if ( ID::OPTION_PREFIX . 'display' === $name ) {
                    return array( 'default_image' => 'http://example.test/wp-content/uploads/default.jpg' );
                }
                return $default;
            }
        );
        Functions\when( 'attachment_url_to_postid' )->justReturn( 99 );
        Functions\expect( 'update_option' )
            ->once()
            ->with( ID::OPTION_DEFAULT_IMAGE_ID, 99 );

        $this->assertSame( 99, ( new EffectiveImage() )->resolve( 0 ) );
    }

    public function test_unresolvable_legacy_url_returns_zero_without_persisting(): void {
        // A legacy URL that no longer maps to an attachment must NOT poison the
        // live key (so a later migration can still seed it).
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                if ( 'sermonmanager_display' === $name ) {
                    return array( 'default_image' => 'http://example.test/missing.jpg' );
                }
                return $default;
            }
        );
        Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
        Functions\expect( 'update_option' )->never();

        $this->assertSame( 0, ( new EffectiveImage() )->resolve( 0 ) );
    }

    public function test_explicit_stored_zero_is_honored_not_resurrected(): void {
        // The admin deliberately cleared the live key to 0 ("no fallback image",
        // a value the sanitize boundary persists). get_option returns that stored
        // 0 (the option ROW exists), so it must be honored verbatim — NOT treated
        // as "unset" and overwritten by a surviving migrated/legacy default_image.
        // No id-keyed seed read, no URL scan, no DB write (#1 data preservation).
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_DEFAULT_IMAGE_ID === $name ? 0 : $default
        );
        Functions\expect( 'attachment_url_to_postid' )->never();
        Functions\expect( 'update_option' )->never();

        $this->assertSame( 0, ( new EffectiveImage() )->resolve( 0 ) );
    }

    public function test_front_end_never_scans_or_persists_legacy_url(): void {
        // Front-end (unauthenticated) context: the live key + id-keyed seed are
        // absent and a legacy URL container exists, but the scan + persist are
        // gated to admin/cron — so the visitor gets 0 with NO postmeta scan and NO
        // DB write (deferred to the next admin/cron pass).
        Functions\when( 'is_admin' )->justReturn( false );
        Functions\when( 'wp_doing_cron' )->justReturn( false );
        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) {
                if ( ID::OPTION_PREFIX . 'display' === $name ) {
                    return array( 'default_image' => 'http://example.test/wp-content/uploads/default.jpg' );
                }
                return $default;
            }
        );
        Functions\expect( 'attachment_url_to_postid' )->never();
        Functions\expect( 'update_option' )->never();

        $this->assertSame( 0, ( new EffectiveImage() )->resolve( 0 ) );
    }
}
