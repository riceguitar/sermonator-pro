<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin\Authoring;

use Sermonator\Admin\Authoring\AuthoringServiceProvider;
use Sermonator\Admin\Authoring\SermonMetaBox;
use Sermonator\Schema\Identifiers;
use WP_UnitTestCase;

final class SermonMetaBoxTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		delete_option( Identifiers::OPTION_MIGRATION_STATE );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		( new AuthoringServiceProvider() )->hook();
	}

	protected function tearDown(): void {
		delete_option( Identifiers::OPTION_MIGRATION_STATE );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_meta_box_is_registered_for_sermon_post_type(): void {
		global $wp_meta_boxes;

		// Force meta-box registration on the current screen.
		add_meta_boxes( Identifiers::POST_TYPE_SERMON, get_post( self::factory()->post->create( array(
			'post_type' => Identifiers::POST_TYPE_SERMON,
		) ) ) );

		$this->assertIsArray( $wp_meta_boxes[ Identifiers::POST_TYPE_SERMON ]['advanced']['high']['sermonator_meta_box'] ?? null );
	}

	public function test_save_persists_sanitized_meta_from_json_input(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );

		$_POST['sermonator_meta_box_nonce'] = wp_create_nonce( SermonMetaBox::NONCE_ACTION );
		$_POST['sermonator_meta_json']      = wp_json_encode( array(
			Identifiers::META_BIBLE_PASSAGE => 'John 3:16',
			Identifiers::META_AUDIO         => 'https://example.com/audio.mp3',
			Identifiers::META_AUDIO_ID      => 42,
			Identifiers::META_AUDIO_SIZE    => 12345678,
			Identifiers::META_DATE          => 1700000000,
			Identifiers::META_DATE_AUTO     => 1,
			Identifiers::META_VIDEO_EMBED   => '<iframe src="https://example.com/embed"></iframe>',
			Identifiers::META_VIEWS         => 9999,
		) );

		( new SermonMetaBox() )->save( $post_id );

		$this->assertSame( 'John 3:16', get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true ) );
		$this->assertSame( 'https://example.com/audio.mp3', get_post_meta( $post_id, Identifiers::META_AUDIO, true ) );
		$this->assertSame( '42', get_post_meta( $post_id, Identifiers::META_AUDIO_ID, true ) );
		$this->assertSame( '12345678', get_post_meta( $post_id, Identifiers::META_AUDIO_SIZE, true ) );
		$this->assertSame( '1700000000', get_post_meta( $post_id, Identifiers::META_DATE, true ) );
		$this->assertSame( '1', get_post_meta( $post_id, Identifiers::META_DATE_AUTO, true ) );
		$this->assertSame( '<iframe src="https://example.com/embed"></iframe>', get_post_meta( $post_id, Identifiers::META_VIDEO_EMBED, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Identifiers::META_VIEWS, true ) );
	}

	public function test_save_is_blocked_during_migration(): void {
		update_option( Identifiers::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

		$post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );

		$_POST['sermonator_meta_box_nonce'] = wp_create_nonce( SermonMetaBox::NONCE_ACTION );
		$_POST['sermonator_meta_json']      = wp_json_encode( array(
			Identifiers::META_BIBLE_PASSAGE => 'Should not persist',
		) );

		( new SermonMetaBox() )->save( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true ) );
	}

	public function test_save_is_blocked_for_unauthorized_user(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$post_id = self::factory()->post->create( array( 'post_type' => Identifiers::POST_TYPE_SERMON ) );

		$_POST['sermonator_meta_box_nonce'] = wp_create_nonce( SermonMetaBox::NONCE_ACTION );
		$_POST['sermonator_meta_json']      = wp_json_encode( array(
			Identifiers::META_BIBLE_PASSAGE => 'Should not persist',
		) );

		( new SermonMetaBox() )->save( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true ) );
	}
}
