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

        // The exact set the factory reads, plus the channel companions.
        $expected = array(
            'title', 'summary', 'description', 'author', 'owner_name', 'owner_email',
            'image', 'category', 'subcategory', 'explicit', 'copyright', 'language', 'link',
        );
        $this->assertSame( $expected, $keys );
        $this->assertContains( 'owner_email', $keys );
    }

    // --- allowlist drop ------------------------------------------------------

    public function test_sanitize_drops_unknown_keys(): void {
        $clean = PodcastMetaSchema::sanitize( array(
            'title'                  => 'Sunday Sermons',
            'evil_script'            => '<script>alert(1)</script>',
            'sermonator_injection'   => 'malicious',
        ) );

        $this->assertArrayHasKey( 'title', $clean );
        $this->assertArrayNotHasKey( 'evil_script', $clean );
        $this->assertArrayNotHasKey( 'sermonator_injection', $clean );
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

    public function test_category_normalizes_against_apple_taxonomy(): void {
        // ItunesCategory::normalize maps a faith term onto the fixed Apple category.
        $clean = PodcastMetaSchema::sanitize( array( 'category' => 'Christianity' ) );
        $this->assertSame( 'Religion & Spirituality', $clean['category'] );
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

        // sanitize_callback funnels through self::sanitize (drops unknown keys).
        $sanitized = ( $captured['args']['sanitize_callback'] )( array( 'title' => 'X', 'bogus' => 'Y' ) );
        $this->assertSame( array( 'title' => 'X' ), $sanitized );
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
