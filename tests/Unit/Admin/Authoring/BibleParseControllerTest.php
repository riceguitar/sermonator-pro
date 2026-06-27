<?php

declare(strict_types=1);

// Minimal WordPress REST stubs (shared idiom with AudioMetaControllerTest).
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
}

namespace Sermonator\Tests\Unit\Admin\Authoring {
    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use Sermonator\Admin\Authoring\BibleParseController;
    use Sermonator\Schema\Identifiers;

    /**
     * Unit coverage for {@see BibleParseController}: the read-only confirm-chip parse
     * preview. It returns ReferenceParser segments + the structurally-valid candidate
     * refs (annotated with the pure RefValidator flags), is gated by the authoring
     * guard + edit_sermons capability, and WRITES NOTHING.
     */
    final class BibleParseControllerTest extends TestCase {
        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            Functions\when( 'get_option' )->justReturn( array( 'phase' => 'none' ) );
            Functions\when( 'current_user_can' )->justReturn( true );
            Functions\when( '__' )->returnArg();
        }

        protected function tearDown(): void {
            Monkey\tearDown();
            parent::tearDown();
        }

        public function test_can_parse_requires_edit_sermons_capability(): void {
            $controller = new BibleParseController();

            Functions\when( 'current_user_can' )->alias( static function ( $cap ) {
                return $cap === 'edit_' . Identifiers::POST_TYPE_SERMON . 's';
            } );
            $this->assertTrue( $controller->canParse() );

            Functions\when( 'current_user_can' )->justReturn( false );
            $this->assertFalse( $controller->canParse() );
        }

        public function test_can_parse_blocked_during_active_migration(): void {
            Functions\when( 'get_option' )->justReturn( array( 'phase' => 'migrating' ) );

            $this->assertFalse( ( new BibleParseController() )->canParse() );
        }

        public function test_parses_passage_into_segments_and_candidate_refs(): void {
            $controller = new BibleParseController();
            $request    = new \WP_REST_Request( array( 'passage' => 'John 3:16; Romans 8:28' ) );

            $response = $controller->parse( $request );

            $this->assertInstanceOf( \WP_REST_Response::class, $response );
            $this->assertSame( 200, $response->status );
            $this->assertSame( 'John 3:16; Romans 8:28', $response->data['passage'] );

            // Two matched segments, each carrying one annotated ref.
            $this->assertCount( 2, $response->data['segments'] );

            // The flat candidate set holds both in-canon/structural refs, each with the
            // pure RefValidator flags for per-chip eligibility labels.
            $refs = $response->data['refs'];
            $this->assertCount( 2, $refs );
            $this->assertSame( 'JHN', $refs[0]['bookUSFM'] );
            $this->assertSame( 'ROM', $refs[1]['bookUSFM'] );
            $this->assertTrue( $refs[0]['validation']['inCanon'] );
            $this->assertTrue( $refs[0]['validation']['structurallyValid'] );
            $this->assertArrayHasKey( 'inlineEligible', $refs[0]['validation'] );
        }

        public function test_unparseable_passage_yields_no_candidate_refs(): void {
            $controller = new BibleParseController();
            $request    = new \WP_REST_Request( array( 'passage' => 'Welcome and announcements' ) );

            $response = $controller->parse( $request );

            $this->assertSame( array(), $response->data['refs'], 'No in-canon refs for free text.' );
        }

        public function test_empty_passage_returns_empty_shape(): void {
            $controller = new BibleParseController();
            $response   = $controller->parse( new \WP_REST_Request( array( 'passage' => '' ) ) );

            $this->assertSame( '', $response->data['passage'] );
            $this->assertSame( array(), $response->data['refs'] );
            $this->assertSame( array(), $response->data['segments'] );
        }

        public function test_parse_writes_nothing(): void {
            // The read-only contract: no meta, option, term, or transient write.
            Functions\expect( 'update_post_meta' )->never();
            Functions\expect( 'add_post_meta' )->never();
            Functions\expect( 'update_option' )->never();
            Functions\expect( 'wp_set_object_terms' )->never();
            Functions\expect( 'set_transient' )->never();

            ( new BibleParseController() )->parse( new \WP_REST_Request( array( 'passage' => 'John 3:16' ) ) );

            $this->assertTrue( true );
        }
    }
}
