<?php

// Minimal WordPress REST stubs for unit testing the controller in isolation.
namespace {
    if ( ! \class_exists( 'WP_REST_Request' ) ) {
        final class WP_REST_Request {
            private array $params = array();
            public function __construct( array $params = array() ) {
                $this->params = $params;
            }
            public function get_param( string $key ) {
                return $this->params[ $key ] ?? null;
            }
        }
    }
    if ( ! \class_exists( 'WP_REST_Response' ) ) {
        final class WP_REST_Response {
            public mixed $data;
            public int $status;
            public function __construct( mixed $data, int $status = 200 ) {
                $this->data   = $data;
                $this->status = $status;
            }
        }
    }
    if ( ! \class_exists( 'WP_Error' ) ) {
        final class WP_Error {
            public string $code;
            public function __construct( string $code, string $message, array $data = array() ) {
                $this->code = $code;
            }
        }
    }
}

namespace Sermonator\Tests\Unit\Admin\Authoring {
    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use Sermonator\Admin\Authoring\AudioMetaController;
    use Sermonator\Schema\Identifiers;

    final class AudioMetaControllerTest extends TestCase {
        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            Functions\when( 'get_option' )->justReturn( array( 'phase' => 'none' ) );
            Functions\when( 'current_user_can' )->justReturn( true );
            Functions\when( 'wp_get_attachment_metadata' )->justReturn( array() );
            Functions\when( '__' )->returnArg();
        }

        protected function tearDown(): void {
            Monkey\tearDown();
            parent::tearDown();
        }

        public function test_can_resolve_requires_edit_sermons_capability(): void {
            $controller = new AudioMetaController();

            Functions\when( 'current_user_can' )->alias( function ( $cap ) {
                return $cap === 'edit_' . Identifiers::POST_TYPE_SERMON . 's';
            } );
            $this->assertTrue( $controller->canResolve() );

            Functions\when( 'current_user_can' )->justReturn( false );
            $this->assertFalse( $controller->canResolve() );
        }

        public function test_resolve_attachment_returns_metadata(): void {
            Functions\when( 'wp_get_attachment_metadata' )->justReturn( array(
                'length_formatted' => '00:42:00',
                'filesize'         => 12345,
                'mime_type'        => 'audio/mpeg',
            ) );

            $controller = new AudioMetaController();
            $request    = new \WP_REST_Request( array( 'attachmentId' => 7 ) );
            $response   = $controller->resolve( $request );

            $this->assertInstanceOf( \WP_REST_Response::class, $response );
            $this->assertSame( array(
                'duration' => '00:42:00',
                'size'     => 12345,
                'mime'     => 'audio/mpeg',
            ), $response->data );
        }

        public function test_resolve_url_uses_head_probe(): void {
            Functions\when( 'wp_parse_url' )->justReturn( 'https' );
            Functions\when( 'wp_remote_head' )->justReturn( array() );
            Functions\when( 'is_wp_error' )->justReturn( false );
            Functions\when( 'wp_remote_retrieve_header' )->alias( function ( $response, string $header ) {
                return 'content-length' === $header ? '999' : 'audio/mp3';
            } );

            $controller = new AudioMetaController();
            $request    = new \WP_REST_Request( array( 'url' => 'https://x/a.mp3' ) );
            $response   = $controller->resolve( $request );

            $this->assertInstanceOf( \WP_REST_Response::class, $response );
            $this->assertSame( '', $response->data['duration'] );
            $this->assertSame( 999, $response->data['size'] );
            $this->assertSame( 'audio/mp3', $response->data['mime'] );
        }

        public function test_resolve_requires_input(): void {
            $controller = new AudioMetaController();
            $response   = $controller->resolve( new \WP_REST_Request( array() ) );

            $this->assertInstanceOf( \WP_Error::class, $response );
            $this->assertSame( 'sermonator_audio_metadata_missing_input', $response->code );
        }

        /**
         * Fix 3: when wp_get_attachment_metadata() is not an array, the controller must
         * return a 404 WP_Error so the editor does not clobber stored audio meta with zeros.
         */
        public function test_resolve_attachment_not_found_returns_404_error(): void {
            Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );

            $controller = new AudioMetaController();
            $request    = new \WP_REST_Request( array( 'attachmentId' => 99 ) );
            $response   = $controller->resolve( $request );

            $this->assertInstanceOf( \WP_Error::class, $response );
            $this->assertSame( 'sermonator_attachment_not_found', $response->code );
        }

        /**
         * Fix 3: when the audio HEAD probe returns both size=null and mime=null (failed
         * probe), the controller must return a 502 WP_Error rather than coercing nulls
         * to 0/'' and clobbering the editor's stored values.
         */
        public function test_resolve_url_probe_failure_returns_502_error(): void {
            Functions\when( 'wp_parse_url' )->justReturn( 'https' );
            Functions\when( 'wp_remote_head' )->justReturn( array() );
            Functions\when( 'is_wp_error' )->justReturn( false );
            // Simulate a probe that yields no Content-Length and no Content-Type.
            Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

            $controller = new AudioMetaController();
            $request    = new \WP_REST_Request( array( 'url' => 'https://x/a.mp3' ) );
            $response   = $controller->resolve( $request );

            $this->assertInstanceOf( \WP_Error::class, $response );
            $this->assertSame( 'sermonator_audio_probe_failed', $response->code );
        }
    }
}
