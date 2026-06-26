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
 * Creating OR updating either option bumps {@see Identifiers::OPTION_BIBLE_CACHE_GEN}
 * so the warmed/normalized chapter cache is invalidated by generation rather than
 * a `DELETE … LIKE` sweep. The create path matters: on a fresh site the first
 * admin save of a translation routes through WordPress's `add_option()` (no stored
 * row yet), firing `add_option_{$option}` — never `update_option_{$option}` — so
 * both hooks must be wired or the first (common) configuration change is missed.
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

    /**
     * The migration target for {@see self::LEGACY_LINK_VERSION_OPTION}: the
     * migration prefix-swaps `sermonmanager_verse_bible_version` →
     * `sermonator_verse_bible_version` ({@see \Sermonator\Migration\MappingContract::mapOptionName})
     * and the Finalizer then deletes the `sermonmanager_*` originals. So on a
     * migrated+finalized site the church's real choice lives HERE, not at the
     * legacy name. Read-only seed for the axis-A default; never written.
     */
    private const MIGRATED_LINK_VERSION_OPTION = Identifiers::OPTION_PREFIX . 'verse_bible_version';

    public function hook(): void {
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'rest_api_init', array( $this, 'register' ) );

        // Bump the cache generation on BOTH the create (add_option_*) and the
        // update (update_option_*) paths. WordPress routes the first save of an
        // as-yet-unstored option through add_option(), which fires
        // add_option_{$option} but NOT update_option_{$option}; listening only on
        // the update hook would silently skip the bump on the common first-time
        // configuration save, serving stale cache under the new translation.
        // bumpCacheGen() takes no parameters, so it is signature-compatible with
        // the 1-arg add_option_* and 2-arg update_option_* actions alike.
        foreach (
            array(
                Identifiers::OPTION_BIBLE_LINK_VERSION,
                Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
            ) as $option
        ) {
            add_action( 'add_option_' . $option, array( $this, 'bumpCacheGen' ) );
            add_action( 'update_option_' . $option, array( $this, 'bumpCacheGen' ) );
        }
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

        // Axis A is UNCONSTRAINED (link-only, no text hosted) — accept any
        // format-valid version code verbatim, NOT only the 5-entry dropdown list,
        // so a church's real legacy verse_bible_version (e.g. NLT/CSB/AMP) is honored.
        return BibleTranslations::isValidLinkVersionCode( $value )
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
     *
     * Resolution order honors the legacy choice DURABLY across the migration
     * lifecycle: the migrated `sermonator_verse_bible_version` row (the only copy
     * that survives Finalizer's `sermonmanager_*` deletion) is consulted FIRST,
     * then the pre-finalize `sermonmanager_verse_bible_version` row. Without the
     * migrated-first read an upgraded+finalized church silently drops its KJV/NIV
     * choice to ESV — a legacy-parity loss this work is held against.
     */
    public static function defaultLinkVersion(): string {
        foreach (
            array(
                self::MIGRATED_LINK_VERSION_OPTION,
                self::LEGACY_LINK_VERSION_OPTION,
            ) as $optionName
        ) {
            $candidate = get_option( $optionName );

            if ( is_string( $candidate )
                && BibleTranslations::isValidLinkVersionCode( $candidate )
            ) {
                return $candidate;
            }
        }

        return BibleTranslations::DEFAULT_LINK_VERSION;
    }
}
