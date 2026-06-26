<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Schema\Identifiers;
use Sermonator\Schema\VideoEmbedPolicy;

/**
 * Shared sanitizer for editable sermon meta keys.
 *
 * Centralizing the rules lets both the REST write path and the classic meta-box write path
 * apply the exact same sanitization without duplicating the per-key rules.
 */
final class SermonMetaSanitizer {
	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function sanitize( string $key, $value ) {
		switch ( $key ) {
			// Fix 4: META_DATE must accept signed Unix timestamps (negative values for
			// pre-1970 sermons). A plain (int) cast coerces "123abc" → 123 silently
			// and also accepts "-" alone. Use a proper signed-digit validator instead.
			// META_AUDIO_ID and META_AUDIO_SIZE are always non-negative IDs/byte-counts
			// and correctly stay as a simple (int) cast.
			case Identifiers::META_DATE:
				$str = trim( (string) $value );
				return ( $str !== '' && $str !== '-' && ctype_digit( ltrim( $str, '-' ) ) ) ? (int) $str : 0;

			case Identifiers::META_AUDIO_ID:
			case Identifiers::META_AUDIO_SIZE:
				return (int) $value;

			case Identifiers::META_DATE_AUTO:
				return (int) (bool) $value;

			case Identifiers::META_BIBLE_PASSAGE:
			case Identifiers::META_AUDIO_DURATION:
				return sanitize_text_field( (string) $value );

			case Identifiers::META_AUDIO:
			case Identifiers::META_VIDEO_URL:
			// TODO(parity-followup): esc_url_raw below blanks non-http/https/ftp URLs
			// for META_NOTES and META_BULLETIN (e.g. relative paths, custom schemes).
			// The correct scheme whitelist depends on what schemes churches actually
			// store in practice — this is a URL-scheme judgment call that needs a
			// product decision before fixing.
			case Identifiers::META_NOTES:
			case Identifiers::META_BULLETIN:
				return esc_url_raw( (string) $value );

			case Identifiers::META_VIDEO_EMBED:
				return wp_kses( (string) $value, VideoEmbedPolicy::allowed() );

			default:
				return $value;
		}
	}
}
