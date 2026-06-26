<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin\Authoring;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Admin\Authoring\SermonMetaSanitizer;
use Sermonator\Schema\Identifiers;

final class SermonMetaSanitizerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
			if ( ! is_string( $value ) ) {
				return (string) $value;
			}
			$value = strip_tags( $value );
			$value = preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? $value;
			return trim( $value );
		} );
		Functions\when( 'esc_url_raw' )->alias( function ( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			if ( ! preg_match( '~^(https?|ftp)://~i', $value ) ) {
				return '';
			}
			return $value;
		} );
		Functions\when( 'wp_kses_allowed_html' )->justReturn( array() );
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed ) {
			return is_string( $value ) ? strip_tags( $value, '<iframe><video><source>' ) : (string) $value;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_integer_keys_are_cast_to_int(): void {
		$this->assertSame( 123, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '123' ) );
		$this->assertSame( 0, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '' ) );
		$this->assertSame( 5, SermonMetaSanitizer::sanitize( Identifiers::META_AUDIO_ID, '5' ) );
		$this->assertSame( 1024, SermonMetaSanitizer::sanitize( Identifiers::META_AUDIO_SIZE, '1024' ) );
	}

	/**
	 * Fix 4: META_DATE must validate signed Unix timestamps — negative values are
	 * valid (pre-1970 sermons) and "123abc" must be rejected, not silently truncated.
	 */
	public function test_meta_date_signed_timestamp_validation(): void {
		// Alphanumeric mixed string → 0 (rejected, not silently truncated to 123)
		$this->assertSame( 0, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '123abc' ) );

		// Negative (pre-1970) timestamp → preserved
		$this->assertSame( -946684800, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '-946684800' ) );

		// Future timestamp → preserved
		$this->assertSame( 1766307600, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '1766307600' ) );

		// Bare minus sign → 0 (invalid)
		$this->assertSame( 0, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '-' ) );

		// Empty string → 0
		$this->assertSame( 0, SermonMetaSanitizer::sanitize( Identifiers::META_DATE, '' ) );
	}

	public function test_date_auto_is_normalized_to_zero_or_one(): void {
		$this->assertSame( 1, SermonMetaSanitizer::sanitize( Identifiers::META_DATE_AUTO, true ) );
		$this->assertSame( 1, SermonMetaSanitizer::sanitize( Identifiers::META_DATE_AUTO, 'yes' ) );
		$this->assertSame( 0, SermonMetaSanitizer::sanitize( Identifiers::META_DATE_AUTO, false ) );
		$this->assertSame( 0, SermonMetaSanitizer::sanitize( Identifiers::META_DATE_AUTO, 0 ) );
	}

	public function test_text_fields_are_sanitized(): void {
		$this->assertSame(
			'John 3:16',
			SermonMetaSanitizer::sanitize( Identifiers::META_BIBLE_PASSAGE, 'John <script>3:16</script>' )
		);
		$this->assertSame(
			'00:42:00 extra',
			SermonMetaSanitizer::sanitize( Identifiers::META_AUDIO_DURATION, "00:42:00\nextra" )
		);
	}

	public function test_urls_are_escaped(): void {
		$this->assertSame(
			'https://example.com/sermon.mp3',
			SermonMetaSanitizer::sanitize( Identifiers::META_AUDIO, 'https://example.com/sermon.mp3' )
		);
		$this->assertSame(
			'',
			SermonMetaSanitizer::sanitize( Identifiers::META_VIDEO_URL, 'javascript:alert(1)' )
		);
	}

	public function test_unknown_key_passes_through_unchanged(): void {
		$this->assertSame( 'keep me', SermonMetaSanitizer::sanitize( 'some_random_key', 'keep me' ) );
	}
}
