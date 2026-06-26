<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin\Authoring {
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use Sermonator\Admin\Authoring\MigrationGuard;
	use Sermonator\Admin\Authoring\SermonMetaBox;
	use Sermonator\Schema\Identifiers;

	final class SermonMetaBoxTest extends TestCase {
		private int $postId = 7;

		/** @var array<string,mixed> */
		private array $saved = array();

		/** @var array<string,mixed> */
		private array $post = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->saved = array();
			$this->post  = array();

			Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-value' );
			Functions\when( 'wp_verify_nonce' )->justReturn( true );
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'rest_url' )->justReturn( 'https://example.com/wp-json/' );
			Functions\when( 'get_option' )->justReturn( array( 'phase' => 'none' ) );
			Functions\when( 'esc_url_raw' )->returnArg();
			Functions\when( 'esc_attr' )->returnArg();
			Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
				return is_string( $value ) ? trim( strip_tags( $value ) ) : (string) $value;
			} );
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'wp_nonce_field' )->justReturn( '' );
			Functions\when( 'wp_json_encode' )->alias( function ( $data ): string {
				return json_encode( $data ) ?: '';
			} );
			Functions\when( '__' )->returnArg();
			Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) {
				$values = array(
					Identifiers::META_DATE           => '1734775200',
					Identifiers::META_DATE_AUTO      => '0',
					Identifiers::META_BIBLE_PASSAGE  => 'Romans 8:28',
					Identifiers::META_AUDIO          => 'https://example.com/audio.mp3',
					Identifiers::META_AUDIO_ID       => '42',
					Identifiers::META_AUDIO_DURATION => '00:35:00',
					Identifiers::META_AUDIO_SIZE     => '12345678',
					Identifiers::META_VIDEO_URL      => 'https://example.com/video',
					Identifiers::META_VIDEO_EMBED    => '<iframe src="https://example.com/embed"></iframe>',
					Identifiers::META_NOTES          => 'https://example.com/notes.pdf',
					Identifiers::META_BULLETIN       => 'https://example.com/bulletin.pdf',
				);
				return $values[ $key ] ?? '';
			} );
			Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ): void {
				$this->saved[ $key ] = $value;
			} );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			unset( $_POST['sermonator_meta_box_nonce'], $_POST['sermonator_meta_json'] );
			parent::tearDown();
		}

		public function test_render_outputs_nonce_container_and_hidden_input(): void {
			$post                    = new \WP_Post();
			$post->ID                = $this->postId;
			$post->post_type         = Identifiers::POST_TYPE_SERMON;
			$post->post_date_gmt     = '2025-12-21 09:00:00';
			$post->post_date         = '2025-12-21 09:00:00';
			$post->post_modified_gmt = '2025-12-21 09:00:00';
			$post->post_modified     = '2025-12-21 09:00:00';

			ob_start();
			( new SermonMetaBox() )->render( $post );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'id="sermonator-meta-box-root"', $html );
			$this->assertStringContainsString( 'name="sermonator_meta_json"', $html );
			$this->assertStringContainsString( 'id="sermonator_meta_json"', $html );
			$this->assertStringContainsString( 'Romans 8:28', $html );
		}

		public function test_save_parses_json_and_writes_meta(): void {
			$_POST['sermonator_meta_box_nonce'] = 'valid-nonce';
			$_POST['sermonator_meta_json']      = wp_json_encode( array(
				Identifiers::META_BIBLE_PASSAGE => 'Genesis 1:1',
				Identifiers::META_AUDIO         => 'https://example.com/new-audio.mp3',
				Identifiers::META_AUDIO_ID      => 99,
				Identifiers::META_DATE          => 1700000000,
				Identifiers::META_DATE_AUTO     => 1,
			) );

			( new SermonMetaBox() )->save( $this->postId );

			$this->assertSame( 'Genesis 1:1', $this->saved[ Identifiers::META_BIBLE_PASSAGE ] );
			$this->assertSame( 'https://example.com/new-audio.mp3', $this->saved[ Identifiers::META_AUDIO ] );
			$this->assertSame( 99, $this->saved[ Identifiers::META_AUDIO_ID ] );
			$this->assertSame( 1700000000, $this->saved[ Identifiers::META_DATE ] );
			$this->assertSame( 1, $this->saved[ Identifiers::META_DATE_AUTO ] );
		}

		public function test_save_does_not_write_views(): void {
			$_POST['sermonator_meta_box_nonce'] = 'valid-nonce';
			$_POST['sermonator_meta_json']      = wp_json_encode( array(
				Identifiers::META_VIEWS => 9999,
			) );

			( new SermonMetaBox() )->save( $this->postId );

			$this->assertArrayNotHasKey( Identifiers::META_VIEWS, $this->saved );
		}

		public function test_save_bails_when_migration_active(): void {
			Functions\when( 'get_option' )->justReturn( array( 'phase' => 'migrating' ) );
			$_POST['sermonator_meta_box_nonce'] = 'valid-nonce';
			$_POST['sermonator_meta_json']      = wp_json_encode( array(
				Identifiers::META_BIBLE_PASSAGE => 'Should not save',
			) );

			( new SermonMetaBox() )->save( $this->postId );

			$this->assertSame( array(), $this->saved );
		}

		public function test_save_bails_on_invalid_nonce(): void {
			Functions\when( 'wp_verify_nonce' )->justReturn( false );
			$_POST['sermonator_meta_box_nonce'] = 'invalid-nonce';
			$_POST['sermonator_meta_json']      = wp_json_encode( array(
				Identifiers::META_BIBLE_PASSAGE => 'Should not save',
			) );

			( new SermonMetaBox() )->save( $this->postId );

			$this->assertSame( array(), $this->saved );
		}

		/**
		 * Fix 1: the iframe inside sermonator_video_embed must survive the save round-trip.
		 * The previous sanitize_text_field() call would strip_tags() the JSON blob,
		 * destroying the <iframe> before json_decode. The fix only wp_unslash()es the blob.
		 */
		public function test_save_preserves_iframe_embed_in_video_embed(): void {
			$iframe = '<iframe src="https://example.com/embed" width="560" height="315"></iframe>';

			Functions\when( 'wp_kses_allowed_html' )->justReturn( array() );
			Functions\when( 'wp_kses' )->alias( function ( $value, $allowed ): string {
				// Simulate wp_kses allowing iframes (VideoEmbedPolicy::allowed() includes iframe).
				return is_string( $value ) ? $value : '';
			} );

			$_POST['sermonator_meta_box_nonce'] = 'valid-nonce';
			$_POST['sermonator_meta_json']      = json_encode( array(
				Identifiers::META_VIDEO_EMBED => $iframe,
			) );

			( new SermonMetaBox() )->save( $this->postId );

			$this->assertArrayHasKey( Identifiers::META_VIDEO_EMBED, $this->saved );
			$this->assertStringContainsString( '<iframe', $this->saved[ Identifiers::META_VIDEO_EMBED ] );
			$this->assertStringContainsString( 'https://example.com/embed', $this->saved[ Identifiers::META_VIDEO_EMBED ] );
		}

		public function test_register_adds_meta_box_in_advanced_context(): void {
			$called = false;
			Functions\when( 'add_meta_box' )->alias( function ( $id, $title, $callback, $screen, $context, $priority ) use ( &$called ): void {
				$called = true;
				$this->assertSame( 'sermonator_meta_box', $id );
				$this->assertSame( Identifiers::POST_TYPE_SERMON, $screen );
				$this->assertSame( 'advanced', $context );
				$this->assertSame( 'high', $priority );
				$this->assertIsCallable( $callback );
			} );

			( new SermonMetaBox() )->register();

			$this->assertTrue( $called );
		}
	}
}

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
