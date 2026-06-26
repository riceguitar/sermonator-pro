<?php

namespace {
    if ( ! \class_exists( 'WP_Post' ) ) {
        final class WP_Post {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_date_gmt = '';
            public string $post_date = '';
            public string $post_modified_gmt = '';
            public string $post_modified = '';
        }
    }
}

namespace Sermonator\Tests\Unit\Admin\Authoring {
    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use Sermonator\Admin\Authoring\SermonDateNormalizer;
    use Sermonator\Schema\Identifiers;

    final class SermonDateNormalizerTest extends TestCase {
        private int $postId = 5;

        /** @var array<string,mixed> */
        private array $meta = array();

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $this->meta = array();
            Functions\when( 'get_option' )->justReturn( array( 'phase' => 'none' ) );
            Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key, bool $single = false ) {
                return $this->meta[ $key ] ?? '';
            } );
            Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ): void {
                $this->meta[ $key ] = $value;
            } );
            Functions\when( 'get_post_time' )->alias( function ( string $format, bool $gmt, int $post_id ): int {
                return 1766307600;
            } );
            Functions\when( 'current_user_can' )->justReturn( true );
        }

        protected function tearDown(): void {
            Monkey\tearDown();
            parent::tearDown();
        }

        public function test_fills_empty_date_from_publish_date_and_sets_auto(): void {
            $post = $this->post( '2025-12-21 09:00:00', '2025-12-21 09:00:00' );

            ( new SermonDateNormalizer() )->normalize( $this->postId, $post );

            $this->assertSame( '1766307600', $this->meta[ Identifiers::META_DATE ] );
            $this->assertSame( '1', $this->meta[ Identifiers::META_DATE_AUTO ] );
        }

        public function test_explicit_date_sets_auto_to_zero_and_does_not_overwrite(): void {
            $this->meta[ Identifiers::META_DATE ] = '1734775200';
            $post = $this->post( '2025-12-21 09:00:00', '2025-12-21 09:00:00' );

            ( new SermonDateNormalizer() )->normalize( $this->postId, $post );

            $this->assertSame( '1734775200', $this->meta[ Identifiers::META_DATE ] );
            $this->assertSame( '0', $this->meta[ Identifiers::META_DATE_AUTO ] );
        }

        public function test_zero_date_is_treated_as_empty(): void {
            $this->meta[ Identifiers::META_DATE ] = '0';
            $post = $this->post( '2025-12-21 09:00:00', '2025-12-21 09:00:00' );

            ( new SermonDateNormalizer() )->normalize( $this->postId, $post );

            $this->assertSame( '1766307600', $this->meta[ Identifiers::META_DATE ] );
            $this->assertSame( '1', $this->meta[ Identifiers::META_DATE_AUTO ] );
        }

        public function test_migration_phase_blocks_writes(): void {
            Functions\when( 'get_option' )->justReturn( array( 'phase' => 'migrating' ) );
            $post = $this->post( '2025-12-21 09:00:00', '2025-12-21 09:00:00' );

            ( new SermonDateNormalizer() )->normalize( $this->postId, $post );

            $this->assertArrayNotHasKey( Identifiers::META_DATE, $this->meta );
            $this->assertArrayNotHasKey( Identifiers::META_DATE_AUTO, $this->meta );
        }

        public function test_insufficient_caps_blocks_writes(): void {
            Functions\when( 'current_user_can' )->justReturn( false );
            $post = $this->post( '2025-12-21 09:00:00', '2025-12-21 09:00:00' );

            ( new SermonDateNormalizer() )->normalize( $this->postId, $post );

            $this->assertArrayNotHasKey( Identifiers::META_DATE, $this->meta );
            $this->assertArrayNotHasKey( Identifiers::META_DATE_AUTO, $this->meta );
        }

        /**
         * @return \WP_Post
         */
        private function post( string $date_gmt, string $date ): object {
            $post                    = new \WP_Post();
            $post->ID                = $this->postId;
            $post->post_type         = Identifiers::POST_TYPE_SERMON;
            $post->post_date_gmt     = $date_gmt;
            $post->post_date         = $date;
            $post->post_modified_gmt = $date_gmt;
            $post->post_modified     = $date;
            return $post;
        }
    }
}
