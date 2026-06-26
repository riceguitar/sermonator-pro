<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Admin\SettingsRegistrar;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers;

/**
 * Resolves the two configured translation axes from options into a single,
 * already-validated value object.
 *
 * Each axis is read from its option, validated against the curated allowlist in
 * {@see BibleTranslations}, and falls back to the license-clean default if the
 * stored value is unknown (fail-safe: we never carry an unvalidated slug toward
 * the render path). The inline axis additionally exposes a
 * `sermonator_bible_translation` filter as the final, trusted override (the WP
 * extensibility convention) so a site can opt into a translation outside the
 * shipped allowlist.
 *
 * Impure only in that it calls `get_option` + `apply_filters`; it holds no other
 * state and performs no network/disk I/O. Called from TemplateData/save/backfill
 * — never from the pure Renderer.
 */
final class TranslationRegistry {
    private string $inlineTranslation;
    private string $linkVersion;

    private function __construct( string $inlineTranslation, string $linkVersion ) {
        $this->inlineTranslation = $inlineTranslation;
        $this->linkVersion       = $linkVersion;
    }

    public static function current(): self {
        return new self( self::resolveInline(), self::resolveLink() );
    }

    /** Axis B: the validated inline-text translation id (e.g. ENGWEBP). */
    public function inlineTranslation(): string {
        return $this->inlineTranslation;
    }

    /** Axis A: the validated link-target version code (e.g. ESV). */
    public function linkVersion(): string {
        return $this->linkVersion;
    }

    private static function resolveInline(): string {
        $stored  = (string) get_option(
            Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
            BibleTranslations::DEFAULT_INLINE
        );
        $value = array_key_exists( $stored, BibleTranslations::curatedInline() )
            ? $stored
            : BibleTranslations::DEFAULT_INLINE;

        /**
         * Filters the resolved inline-text translation id (axis B). Trusted,
         * final override for sites needing a translation outside the curated
         * allowlist; runs after option validation/fallback.
         *
         * @param string $value Validated translation id.
         */
        return (string) apply_filters( 'sermonator_bible_translation', $value );
    }

    private static function resolveLink(): string {
        $stored = (string) get_option( Identifiers::OPTION_BIBLE_LINK_VERSION, '' );

        // Axis A is UNCONSTRAINED — any format-valid stored version code (incl. one
        // outside the 5-entry dropdown list, e.g. a migrated NLT) renders verbatim.
        if ( BibleTranslations::isValidLinkVersionCode( $stored ) ) {
            return $stored;
        }

        // No valid stored row — e.g. a fresh or just-migrated site where the
        // axis-A option has never been explicitly saved. Route the fallback
        // through the SAME legacy-seeded default the admin form uses, instead of
        // the hardcoded ESV constant. This is what carries an upgraded church's
        // KJV/NIV choice onto the FRONT-END link path: register_setting's
        // default_option_* filter only applies in admin/REST requests where
        // register() ran, never on a normal page render, so the render path must
        // consult the legacy seed itself rather than re-flooring to ESV.
        return SettingsRegistrar::defaultLinkVersion();
    }
}
