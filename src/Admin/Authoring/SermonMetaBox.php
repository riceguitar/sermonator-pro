<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Schema\Identifiers;
use WP_Post;
use WP_Screen;

/**
 * Renders the Sermon Details meta box below the sermon editor and saves the React-authored
 * values from a single hidden JSON input.
 *
 * Pattern A: the React UI mounts into #sermonator-meta-box-root, reads the current values from
 * window.sermonatorMetaBox, and keeps the hidden input #sermonator_meta_json in sync. PHP parses
 * and sanitizes that input on save.
 */
final class SermonMetaBox {
	public const HANDLE        = 'sermonator-meta-box';
	public const NONCE_ACTION  = 'sermonator_save_meta_box';
	public const INPUT_NAME    = 'sermonator_meta_json';
	public const CONTAINER_ID  = 'sermonator-meta-box-root';
	public const JS_GLOBAL     = 'sermonatorMetaBox';
	public const SCREEN_HOOKS  = array( 'post.php', 'post-new.php' );

	public function hook(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post_' . Identifiers::POST_TYPE_SERMON, array( $this, 'save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	public function register(): void {
		add_meta_box(
			'sermonator_meta_box',
			__( 'Sermon Details', 'sermonator' ),
			array( $this, 'render' ),
			Identifiers::POST_TYPE_SERMON,
			'advanced',
			'high'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, 'sermonator_meta_box_nonce' );

		$initial_data = $this->initialData( $post );

		printf(
			'<div id="%1$s"></div>' . "\n" .
			'<input type="hidden" name="%2$s" id="%2$s" value="%3$s" />',
			esc_attr( self::CONTAINER_ID ),
			esc_attr( self::INPUT_NAME ),
			esc_attr( wp_json_encode( $initial_data ) )
		);
	}

	public function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['sermonator_meta_box_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['sermonator_meta_box_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! MigrationGuard::editingAllowed() ) {
			return;
		}

		if ( ! isset( $_POST[ self::INPUT_NAME ] ) ) {
			return;
		}

		// Fix 1: do NOT sanitize_text_field the raw blob — strip_tags would destroy
		// the <iframe> inside sermonator_video_embed before json_decode. Only unslash,
		// validate it is a non-empty string, then decode. Per-key sanitization happens
		// inside SermonMetaSanitizer::sanitize(), which is the correct place.
		$raw  = $_POST[ self::INPUT_NAME ];
		$json = is_string( $raw ) ? wp_unslash( $raw ) : '';
		if ( '' === $json ) {
			return;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			error_log( sprintf( 'Sermonator: json_decode failed on post %d: %s', $post_id, json_last_error_msg() ) );
			return;
		}

		foreach ( $this->editableMetaKeys() as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			// Fix 2: capture update_post_meta return; a false return means the write
			// failed (e.g. permissions or DB error) — log it so it is never silent.
			$result = update_post_meta( $post_id, $key, SermonMetaSanitizer::sanitize( $key, $data[ $key ] ) );
			if ( false === $result ) {
				error_log( sprintf( 'Sermonator: update_post_meta failed for post %d, key %s', $post_id, $key ) );
			}
		}
	}

	public function enqueueAssets( string $hook ): void {
		if ( ! in_array( $hook, self::SCREEN_HOOKS, true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		if ( $screen->post_type !== Identifiers::POST_TYPE_SERMON ) {
			return;
		}

		$asset_file = SERMONATOR_PATH . 'build/meta-box/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_style(
			self::HANDLE,
			SERMONATOR_PLUGIN_URL . 'assets/meta-box.css',
			array(),
			SERMONATOR_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			SERMONATOR_PLUGIN_URL . 'build/meta-box/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? SERMONATOR_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'sermonator' );

		$post_id = $this->currentPostId();
		if ( $post_id < 1 ) {
			// post-new.php: no post exists yet. Localize with empty data so the React UI still mounts.
			wp_localize_script(
				self::HANDLE,
				self::JS_GLOBAL,
				array(
					'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
					'restRoot'       => esc_url_raw( rest_url() ),
					'restNonce'      => wp_create_nonce( 'wp_rest' ),
					'postId'         => 0,
					'editingAllowed' => MigrationGuard::editingAllowed(),
					'initialData'    => array(),
				)
			);
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		wp_localize_script(
			self::HANDLE,
			self::JS_GLOBAL,
			array(
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'restRoot'       => esc_url_raw( rest_url() ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'postId'         => $post->ID,
				'editingAllowed' => MigrationGuard::editingAllowed(),
				'initialData'    => $this->initialData( $post ),
			)
		);
	}

	private function currentPostId(): int {
		if ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
			return (int) $_GET['post'];
		}

		$post = get_post();
		return $post instanceof WP_Post ? $post->ID : 0;
	}

	/** @return array<string,mixed> */
	private function initialData( WP_Post $post ): array {
		$data = array();
		foreach ( $this->editableMetaKeys() as $key ) {
			$value        = get_post_meta( $post->ID, $key, true );
			$data[ $key ] = $this->normalizeInitialValue( $key, $value );
		}
		return $data;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalizeInitialValue( string $key, $value ) {
		if ( '' === $value ) {
			return $this->defaultValue( $key );
		}
		return $value;
	}

	/** @return mixed */
	private function defaultValue( string $key ) {
		switch ( $key ) {
			case Identifiers::META_DATE:
			case Identifiers::META_AUDIO_ID:
			case Identifiers::META_AUDIO_SIZE:
				return 0;

			case Identifiers::META_DATE_AUTO:
				return 1;

			default:
				return '';
		}
	}

	/** @return list<string> */
	private function editableMetaKeys(): array {
		return array(
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
	}
}
