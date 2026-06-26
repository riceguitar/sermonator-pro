<?php

declare(strict_types=1);

namespace Sermonator\Schema;

/**
 * Pure data: the curated Bible-translation allowlist for both translation axes.
 *
 * helloao exposes ~1000 translation slugs; surfacing all of them in settings is
 * hostile. This is a small, license-aware allowlist so the inline-translation
 * setting is a sane list, and so {@see \Sermonator\Bible\TranslationRegistry}
 * has a fixed set to validate stored options against.
 *
 * Two independent axes (see the Bundle 3 design §3, "Two-axis translation"):
 *
 *   Axis B — inline TEXT translation ({@see all()} / {@see curatedInline()}).
 *     We vendor and redistribute this text inside a GPL-2.0+ plugin to many
 *     sites, so each entry carries a `license` status and an `inlineEligible`
 *     flag. Only `inlineEligible` (license-clean) translations may ever have
 *     their verse text rendered. ENGWEBP (World English Bible) is the
 *     unambiguous public-domain default; BSB is license-ambiguous and therefore
 *     inline-INELIGIBLE (link-only).
 *
 *   Axis A — LINK-target version ({@see curatedLinkVersions()}). These are
 *     version codes used ONLY to build external reference links (mirrors legacy
 *     `verse_bible_version`). No text is ever hosted, so licensing is N/A and
 *     the list is unconstrained (incl. copyrighted ESV/NIV/NASB). ESV default.
 *
 * Sibling of {@see BibleCanon} / {@see VideoEmbedPolicy} — pure data, no I/O.
 */
final class BibleTranslations {
    /** Axis B default: the license-clean inline-text translation. */
    public const DEFAULT_INLINE = 'ENGWEBP';

    /** Axis A default: mirrors legacy `verse_bible_version`. */
    public const DEFAULT_LINK_VERSION = 'ESV';

    /**
     * The curated inline-text translation allowlist (axis B).
     *
     * @return list<array{id:string,label:string,license:string,inlineEligible:bool}>
     */
    public static function all(): array {
        return array(
            array(
                'id'             => 'ENGWEBP',
                'label'          => 'World English Bible',
                'license'        => 'public-domain',
                'inlineEligible' => true,
            ),
            array(
                'id'             => 'ENGKJV',
                'label'          => 'King James Version',
                'license'        => 'public-domain',
                'inlineEligible' => true,
            ),
            array(
                'id'             => 'BSB',
                'label'          => 'Berean Standard Bible',
                'license'        => 'ambiguous',
                'inlineEligible' => false,
            ),
        );
    }

    /**
     * Inline-eligible translations as an id => label map, for the axis-B
     * settings control and for option validation.
     *
     * @return array<string,string>
     */
    public static function curatedInline(): array {
        $inline = array();
        foreach ( self::all() as $entry ) {
            if ( $entry['inlineEligible'] ) {
                $inline[ $entry['id'] ] = $entry['label'];
            }
        }
        return $inline;
    }

    /**
     * The axis-A LINK-target version list as a code => label map. These codes
     * build external reference links only (no text hosted), so license is N/A
     * and the list is unconstrained. Mirrors legacy `verse_bible_version`.
     *
     * @return array<string,string>
     */
    public static function curatedLinkVersions(): array {
        return array(
            'ESV'  => 'English Standard Version',
            'NIV'  => 'New International Version',
            'KJV'  => 'King James Version',
            'NASB' => 'New American Standard Bible',
            'NKJV' => 'New King James Version',
        );
    }

    /**
     * Whether a string is a structurally-valid axis-A link version CODE. Axis A is
     * UNCONSTRAINED (it only builds external reference links — no text is hosted), so any
     * BibleGateway-style alphanumeric code is accepted verbatim (ESV, NLT, CSB, AMP, MSG,
     * NET, NIVUK, RVR1960, …). {@see curatedLinkVersions()} is ONLY the settings-dropdown
     * source, NEVER the validation gate — gating axis A on that 5-entry list would
     * silently floor a migrated church's real legacy `verse_bible_version` (e.g. NLT) to
     * ESV on the front-end link path, a legacy-parity loss. The reference is rawurlencode'd
     * into the link, so a format-valid code is render-safe.
     */
    public static function isValidLinkVersionCode( string $code ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9]{1,20}$/', $code );
    }
}
