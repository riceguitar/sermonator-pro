<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers;

/**
 * Registers the two-axis Bible settings (Bundle 3, spec Task 14) against the
 * shared {@see Identifiers::OPTION_GROUP_SETTINGS} settings group.
 *
 *   Axis A — link version ({@see Identifiers::OPTION_BIBLE_LINK_VERSION}): the
 *     external reference-link target. Default mirrors the legacy
 *     `sermonmanager_verse_bible_version` setting if it is a curated value,
 *     otherwise {@see BibleTranslations::DEFAULT_LINK_VERSION} (ESV). Sanitize
 *     whitelists against {@see BibleTranslations::curatedLinkVersions()}.
 *
 *   Axis B — inline text translation ({@see Identifiers::OPTION_BIBLE_INLINE_TRANSLATION}):
 *     the license-clean translation whose verse TEXT may be rendered. Default
 *     {@see BibleTranslations::DEFAULT_INLINE} (ENGWEBP). Sanitize whitelists
 *     against {@see BibleTranslations::curatedInline()} (inline-eligible only,
 *     so an ambiguous slug like BSB is never accepted here).
 *
 * Updating either option bumps {@see Identifiers::OPTION_BIBLE_CACHE_GEN} so the
 * warmed/normalized chapter cache is invalidated by generation rather than a
 * `DELETE … LIKE` sweep.
 *
 * Registration runs on both `admin_init` (Settings API) and `rest_api_init`
 * (so `show_in_rest` exposure works inside the non-admin REST context the block
 * editor uses). The class never resolves or escapes anything itself — that lives
 * in {@see \Sermonator\Bible\TranslationRegistry} and the Renderer respectively.
 */
final class SettingsRegistrar {
    /**
     * Legacy Sermon Manager verse-version option (CMB2, all options prefixed
     * `sermonmanager_`). Read-only seed for the axis-A default; never written.
     */
    private const LEGACY_LINK_VERSION_OPTION = 'sermonmanager_verse_bible_version';

    public function hook(): void {
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'rest_api_init', array( $this, 'register' ) );

        add_action(
            'update_option_' . Identifiers::OPTION_BIBLE_LINK_VERSION,
            array( $this, 'bumpCacheGen' )
        );
        add_action(
            'update_option_' . Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
            array( $this, 'bumpCacheGen' )
        );
    }

    public function register(): void {
        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_BIBLE_LINK_VERSION,
            array(
                'type'              => 'string',
                'default'           => self::defaultLinkVersion(),
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeLinkVersion' ),
            )
        );

        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
            array(
                'type'              => 'string',
                'default'           => BibleTranslations::DEFAULT_INLINE,
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeInlineTranslation' ),
            )
        );
    }

    /**
     * Axis A sanitize: keep a curated link version, else fall back to the
     * registered default (legacy-seeded or ESV).
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeLinkVersion( $value ): string {
        $value = is_string( $value ) ? $value : '';

        return array_key_exists( $value, BibleTranslations::curatedLinkVersions() )
            ? $value
            : self::defaultLinkVersion();
    }

    /**
     * Axis B sanitize: keep a curated inline-ELIGIBLE translation, else fall back
     * to ENGWEBP. An inline-ineligible slug (e.g. BSB) is rejected here because it
     * is absent from {@see BibleTranslations::curatedInline()}.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeInlineTranslation( $value ): string {
        $value = is_string( $value ) ? $value : '';

        return array_key_exists( $value, BibleTranslations::curatedInline() )
            ? $value
            : BibleTranslations::DEFAULT_INLINE;
    }

    /**
     * Bump the integer cache generation, invalidating every warmed chapter cache
     * entry without touching the transient store directly.
     */
    public function bumpCacheGen(): void {
        $current = (int) get_option( Identifiers::OPTION_BIBLE_CACHE_GEN, 0 );
        update_option( Identifiers::OPTION_BIBLE_CACHE_GEN, $current + 1 );
    }

    /**
     * The axis-A default: the legacy `verse_bible_version` value when it is a
     * curated link version, otherwise {@see BibleTranslations::DEFAULT_LINK_VERSION}.
     * Validating against the curated list keeps the default in sync with the
     * settings dropdown and the sanitize whitelist.
     */
    public static function defaultLinkVersion(): string {
        $legacy = get_option( self::LEGACY_LINK_VERSION_OPTION );

        if ( is_string( $legacy ) && array_key_exists( $legacy, BibleTranslations::curatedLinkVersions() ) ) {
            return $legacy;
        }

        return BibleTranslations::DEFAULT_LINK_VERSION;
    }
}
