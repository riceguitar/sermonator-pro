<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Schema\Identifiers;
use Sermonator\Schema\PodcastMetaSchema;

/**
 * Unit coverage for the podcast-settings governance schema. WordPress sanitizers are stubbed
 * (returnArg / known transforms) so we can assert the allowlist + per-key sanitize contract
 * without loading WordPress.
 */
final class PodcastMetaSchemaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Identity-ish stubs that still let us prove the RIGHT sanitizer ran per key.
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_email' )->alias( static fn( $v ) => str_replace( ' ', '', (string) $v ) );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => 'URL:' . trim( (string) $v ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- catalog -------------------------------------------------------------

    public function test_keys_catalog_exposes_the_known_identity_keys(): void {
        $keys = PodcastMetaSchema::keys();

        // The exact set the factory reads, the channel companions, plus the subscribe-link URLs
        // the PodcastSubscribeBlock reads from this same blob.
        $expected = array(
            'title', 'summary', 'description', 'author', 'owner_name', 'owner_email',
            'image', 'category', 'subcategory', 'explicit', 'copyright', 'language', 'link',
            'apple_url', 'spotify_url',
        );
        $this->assertSame( $expected, $keys );
        $this->assertContains( 'owner_email', $keys );
    }

    // --- preservation of non-identity keys -----------------------------------

    /**
     * The canonical blob is NOT identity-only: migration stores per-taxonomy term-filter SCOPE
     * keys (which sermons the feed serves) and subscribe URLs inside it. Because this sanitizer is
     * the register_post_meta sanitize_callback (fires on EVERY write, incl. migration's
     * add_post_meta), dropping unrecognized keys would clobber that data. They must SURVIVE.
     */
    public function test_sanitize_preserves_term_filter_scope_keys(): void {
        $clean = PodcastMetaSchema::sanitize( array(
            'title'               => 'Sunday Sermons',
            // Migration term-filter scope: taxonomy slug => new term id (int) or list of ids.
            'sermonator_preacher' => 7,
            'sermonator_series'   => array( 11, 12 ),
        ) );

        $this->assertArrayHasKey( 'title', $clean );
        // Int term ids pass through verbatim (lossless) — feed scope preserved.
        $this->assertSame( 7, $clean['sermonator_preacher'] );
        $this->assertSame( array( 11, 12 ), $clean['sermonator_series'] );
    }

    public function test_sanitize_hardens_unknown_string_values(): void {
        // An unrecognized key is preserved, but its scalar string value is still run through
        // sanitize_text_field (stubbed here as trim) so nothing raw is persisted.
        $clean = PodcastMetaSchema::sanitize( array( 'mystery_key' => '  raw value  ' ) );

        $this->assertArrayHasKey( 'mystery_key', $clean );
        $this->assertSame( 'raw value', $clean['mystery_key'] );
    }

    public function test_subscribe_urls_sanitized_as_url(): void {
        $clean = PodcastMetaSchema::sanitize( array(
            'apple_url'   => 'https://podcasts.apple.com/show/123',
            'spotify_url' => 'https://open.spotify.com/show/abc',
        ) );

        // esc_url_raw is stubbed as 'URL:' . trimmed input — proves the URL sanitizer ran.
        $this->assertSame( 'URL:https://podcasts.apple.com/show/123', $clean['apple_url'] );
        $this->assertSame( 'URL:https://open.spotify.com/show/abc', $clean['spotify_url'] );
    }

    public function test_sanitize_omits_absent_known_keys(): void {
        $clean = PodcastMetaSchema::sanitize( array( 'title' => 'Only Title' ) );

        $this->assertSame( array( 'title' => 'Only Title' ), $clean );
    }

    public function test_sanitize_non_array_returns_empty_array(): void {
        $this->assertSame( array(), PodcastMetaSchema::sanitize( 'not-an-array' ) );
        $this->assertSame( array(), PodcastMetaSchema::sanitize( null ) );
        $this->assertSame( array(), PodcastMetaSchema::sanitize( 42 ) );
    }

    // --- per-key sanitize ----------------------------------------------------

    public function test_owner_email_uses_sanitize_email(): void {
        $clean = PodcastMetaSchema::sanitize( array( 'owner_email' => 'pod cast@example.com' ) );
        $this->assertSame( 'podcast@example.com', $clean['owner_email'] );
    }

    public function test_image_and_link_use_esc_url_raw(): void {
        $clean = PodcastMetaSchema::sanitize( array(
            'image' => 'http://example.com/art.jpg',
            'link'  => 'http://example.com/',
        ) );
        $this->assertSame( 'URL:http://example.com/art.jpg', $clean['image'] );
        $this->assertSame( 'URL:http://example.com/', $clean['link'] );
    }

    public function test_multiline_fields_use_textarea_sanitizer(): void {
        $clean = PodcastMetaSchema::sanitize( array(
            'summary'     => "  weekly teaching  ",
            'description' => "  long form  ",
        ) );
        $this->assertSame( 'weekly teaching', $clean['summary'] );
        $this->assertSame( 'long form', $clean['description'] );
    }

    public function test_text_fields_use_text_sanitizer(): void {
        $clean = PodcastMetaSchema::sanitize( array(
            'title'      => '  Sunday Sermons  ',
            'author'     => '  Example Church  ',
            'owner_name' => '  Pastor  ',
            'copyright'  => '  (c) 2026  ',
            'language'   => '  en-US  ',
        ) );
        $this->assertSame( 'Sunday Sermons', $clean['title'] );
        $this->assertSame( 'Example Church', $clean['author'] );
        $this->assertSame( 'Pastor', $clean['owner_name'] );
        $this->assertSame( '(c) 2026', $clean['copyright'] );
        $this->assertSame( 'en-US', $clean['language'] );
    }

    /**
     * @dataProvider explicitProvider
     * @param mixed $raw
     */
    public function test_explicit_coerces_to_bool( $raw, bool $expected ): void {
        $clean = PodcastMetaSchema::sanitize( array( 'explicit' => $raw ) );
        $this->assertIsBool( $clean['explicit'] );
        $this->assertSame( $expected, $clean['explicit'] );
    }

    /** @return array<string,array{0:mixed,1:bool}> */
    public static function explicitProvider(): array {
        return array(
            'yes'        => array( 'yes', true ),
            'true'       => array( 'true', true ),
            'one-string' => array( '1', true ),
            'explicit'   => array( 'explicit', true ),
            'real-true'  => array( true, true ),
            'no'         => array( 'no', false ),
            'empty'      => array( '', false ),
            'real-false' => array( false, false ),
            'garbage'    => array( 'maybe', false ),
        );
    }

    public function test_category_persists_most_specific_valid_term(): void {
        // 'Christianity' is a valid Apple SUBcategory under 'Religion & Spirituality'. The
        // sanitizer must persist the subcategory verbatim (not collapse it to the top-level
        // category) so the read-path factory can reconstruct the nested <itunes:category>
        // subcategory by re-normalizing the stored string. Collapsing it would be a feed-fidelity
        // regression for the dominant sermon-feed case.
        $clean = PodcastMetaSchema::sanitize( array( 'category' => 'Christianity' ) );
        $this->assertSame( 'Christianity', $clean['category'] );
    }

    public function test_category_pins_unrecognized_input_to_default_top_level(): void {
        // An input with no category/subcategory match collapses to the default top-level category
        // (valid taxonomy, never feed-rejected) — the only lossy case, and only for junk input.
        $clean = PodcastMetaSchema::sanitize( array( 'category' => 'Quantum Mechanics' ) );
        $this->assertSame( 'Religion & Spirituality', $clean['category'] );
    }

    public function test_category_keeps_top_level_category_when_no_subcategory(): void {
        $clean = PodcastMetaSchema::sanitize( array( 'category' => 'Education' ) );
        $this->assertSame( 'Education', $clean['category'] );
    }

    public function test_category_preserves_blank(): void {
        // A blank category must stay blank — the factory defaults it; we must NOT pin
        // an empty user choice to the default at write time.
        $clean = PodcastMetaSchema::sanitize( array( 'category' => '   ' ) );
        $this->assertSame( '', $clean['category'] );
    }

    // --- register_post_meta contract -----------------------------------------

    public function test_register_post_meta_governance(): void {
        $captured = array();
        Functions\when( 'register_post_meta' )->alias(
            function ( string $post_type, string $meta_key, array $args ) use ( &$captured ): void {
                $captured = array( 'post_type' => $post_type, 'key' => $meta_key, 'args' => $args );
            }
        );

        PodcastMetaSchema::register();

        $this->assertSame( Identifiers::POST_TYPE_PODCAST, $captured['post_type'] );
        $this->assertSame( Identifiers::META_PODCAST_SETTINGS, $captured['key'] );
        $this->assertTrue( $captured['args']['single'] );
        $this->assertFalse( $captured['args']['show_in_rest'] );
        $this->assertIsCallable( $captured['args']['sanitize_callback'] );
        $this->assertIsCallable( $captured['args']['auth_callback'] );

        // sanitize_callback funnels through self::sanitize: identity keys typed, non-identity keys
        // PRESERVED (hardened) — never dropped, so migration scope keys survive a registered write.
        $sanitized = ( $captured['args']['sanitize_callback'] )( array( 'title' => 'X', 'bogus' => 'Y' ) );
        $this->assertSame( array( 'title' => 'X', 'bogus' => 'Y' ), $sanitized );
    }

    public function test_auth_callback_requires_manage_options(): void {
        $captured = array();
        Functions\when( 'register_post_meta' )->alias(
            function ( string $post_type, string $meta_key, array $args ) use ( &$captured ): void {
                $captured = $args;
            }
        );

        PodcastMetaSchema::register();
        $auth = $captured['auth_callback'];

        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertTrue( $auth() );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $auth() );
    }
}
