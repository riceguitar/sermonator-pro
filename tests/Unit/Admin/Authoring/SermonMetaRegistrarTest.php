<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin\Authoring;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Admin\Authoring\SermonMetaRegistrar;
use Sermonator\Schema\Identifiers;

/**
 * Unit tests for the meta registration contract. register_post_meta is stubbed so we can
 * inspect the declared shape of every field without loading WordPress.
 */
final class SermonMetaRegistrarTest extends TestCase {
    /** @var list<array{key:string,args:array<string,mixed>}> */
    private array $registered = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->registered = array();
        Functions\when( 'register_post_meta' )->alias( function ( string $post_type, string $meta_key, array $args ): void {
            $this->registered[] = array( 'post_type' => $post_type, 'key' => $meta_key, 'args' => $args );
        } );
        Functions\when( 'get_option' )->justReturn( array( 'phase' => 'none' ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_registers_every_editable_field(): void {
        ( new SermonMetaRegistrar() )->register();

        $keys = array_column( $this->registered, 'key' );
        $expected = array(
            Identifiers::META_DATE,
            Identifiers::META_DATE_AUTO,
            Identifiers::META_BIBLE_PASSAGE,
            Identifiers::META_AUDIO,
            Identifiers::META_AUDIO_ID,
            Identifiers::META_AUDIO_DURATION,
            Identifiers::META_AUDIO_SIZE,
            Identifiers::META_VIDEO_URL,
            Identifiers::META_VIDEO_EMBED,
            Identifiers::META_NOTES,
            Identifiers::META_BULLETIN,
        );
        $this->assertSame( $expected, $keys );
    }

    public function test_all_fields_are_single_on_sermon_post_type_and_exposed_to_rest(): void {
        ( new SermonMetaRegistrar() )->register();

        foreach ( $this->registered as $r ) {
            $this->assertSame( Identifiers::POST_TYPE_SERMON, $r['post_type'] );
            $this->assertTrue( $r['args']['single'] );
            $this->assertNotEmpty( $r['args']['show_in_rest'] );
            $this->assertIsCallable( $r['args']['auth_callback'] );
            $this->assertArrayNotHasKey( 'sanitize_callback', $r['args'], 'No global sanitizer — migration writes raw values.' );
        }
    }

    public function test_auth_callback_requires_edit_post_and_migration_guard(): void {
        ( new SermonMetaRegistrar() )->register();
        $auth = $this->callbackFor( Identifiers::META_BIBLE_PASSAGE, 'auth_callback' );

        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertTrue( $auth( true, Identifiers::META_BIBLE_PASSAGE, 5 ) );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $auth( true, Identifiers::META_BIBLE_PASSAGE, 5 ) );
    }

    public function test_integer_fields_expose_integer_schema(): void {
        ( new SermonMetaRegistrar() )->register();

        foreach ( array( Identifiers::META_DATE, Identifiers::META_DATE_AUTO, Identifiers::META_AUDIO_ID, Identifiers::META_AUDIO_SIZE ) as $key ) {
            $found = $this->find( $key );
            $this->assertNotNull( $found );
            $this->assertSame( array( 'schema' => array( 'type' => 'integer' ) ), $found['args']['show_in_rest'] );
            $this->assertSame( '0', $found['args']['default'] );
        }
    }

    public function test_string_fields_expose_string_schema(): void {
        ( new SermonMetaRegistrar() )->register();

        foreach ( array( Identifiers::META_BIBLE_PASSAGE, Identifiers::META_AUDIO, Identifiers::META_VIDEO_URL, Identifiers::META_VIDEO_EMBED, Identifiers::META_NOTES, Identifiers::META_BULLETIN ) as $key ) {
            $found = $this->find( $key );
            $this->assertNotNull( $found );
            $this->assertTrue( $found['args']['show_in_rest'] );
            $this->assertSame( '', $found['args']['default'] );
        }
    }

    public function test_protected_audio_meta_exposed_to_rest(): void {
        ( new SermonMetaRegistrar() )->register();

        $duration = $this->find( Identifiers::META_AUDIO_DURATION );
        $size     = $this->find( Identifiers::META_AUDIO_SIZE );

        $this->assertNotNull( $duration );
        $this->assertNotNull( $size );
        $this->assertArrayNotHasKey( 'type', $duration['args'] );
        $this->assertTrue( $duration['args']['show_in_rest'] );
        $this->assertSame( array( 'schema' => array( 'type' => 'integer' ) ), $size['args']['show_in_rest'] );
    }

    /**
     * @return callable
     */
    private function callbackFor( string $key, string $name ): callable {
        $found = $this->find( $key );
        $this->assertNotNull( $found, "Meta {$key} not registered." );
        $cb = $found['args'][ $name ] ?? null;
        $this->assertIsCallable( $cb );
        return $cb;
    }

    /**
     * @return array{post_type:string,key:string,args:array<string,mixed>}|null
     */
    private function find( string $key ): ?array {
        foreach ( $this->registered as $r ) {
            if ( $r['key'] === $key ) {
                return $r;
            }
        }
        return null;
    }
}
