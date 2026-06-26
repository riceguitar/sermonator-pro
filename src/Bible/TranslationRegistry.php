<?php

declare(strict_types=1);

namespace Sermonator\Bible;

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
        $stored = (string) get_option(
            Identifiers::OPTION_BIBLE_LINK_VERSION,
            BibleTranslations::DEFAULT_LINK_VERSION
        );
        return array_key_exists( $stored, BibleTranslations::curatedLinkVersions() )
            ? $stored
            : BibleTranslations::DEFAULT_LINK_VERSION;
    }
}
