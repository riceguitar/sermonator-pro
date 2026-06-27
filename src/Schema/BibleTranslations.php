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
 *     unambiguous public-domain default and — as of Bundle 3 Phase 3b — the
 *     SINGLE audited inline target (its versification-divergence table is the
 *     only one enumerated). ENGKJV is public-domain but inline-INELIGIBLE: its
 *     divergences from the modern critical text are different/opposite and
 *     UNAUDITED, so rendering its words for an ESV/NASB-sourced church would be
 *     a false-positive inline — the one unacceptable outcome. It stays link-only
 *     until separately audited. BSB is license-ambiguous and therefore
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
                'inlineEligible' => false,
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

    /**
     * The one versification family Phase 3b models: the modern English-Protestant
     * tradition (ESV / NIV / NASB / NKJV / NLT / CSB / KJV / WEB / NET, incl. their
     * anglicised UK editions). ENGWEBP — the single inline target — belongs to it,
     * so a source link version that normalizes to THIS family is the necessary
     * (not sufficient) precondition for the {@see \Sermonator\Bible\VersificationGate}
     * same-family fast path. Any other tradition (Spanish Reina-Valera, the
     * Septuagint/Vulgate-numbered Catholic/Orthodox canons, or a blank/unknown
     * code) is deliberately UNmodeled and must fall open to the link.
     */
    public const FAMILY_ENGLISH_PROTESTANT = 'eng-protestant';

    /**
     * The modern English-Protestant link-version codes that map to
     * {@see FAMILY_ENGLISH_PROTESTANT}, after case-folding and UK-suffix stripping.
     *
     * @var list<string>
     */
    private const ENGLISH_PROTESTANT_CODES = array(
        'ESV',
        'NIV',
        'NASB',
        'NKJV',
        'NLT',
        'CSB',
        'KJV',
        'WEB',
        'NET',
    );

    /**
     * Normalize an axis-A LINK-version code to a versification FAMILY code.
     *
     * Folds case, strips anglicised UK suffixes (`NIVUK`, `ESVANGL` → `NIV`,
     * `ESV`), and maps every recognized modern English-Protestant alias to
     * {@see FAMILY_ENGLISH_PROTESTANT}. Anything outside that single modeled
     * tradition — Spanish (`RVR1960`), LXX/Vulgate-numbered canons, or a
     * blank/unrecognized code — returns the EMPTY STRING.
     *
     * This is the conservative, fail-open primitive behind the L4 step of the
     * 3b inline predicate: an empty family code means `src-versification-
     * unsupported`, i.e. fall open to the 3a link rather than risk rendering
     * real-but-wrong verse text. The set is intentionally small; an unmodeled
     * English version (e.g. AMP, MSG) also returns empty and links, never inlines.
     *
     * Pure: no I/O, no WP, deterministic.
     */
    public static function familyCode( string $linkVersion ): string {
        $code = strtoupper( trim( $linkVersion ) );

        if ( '' === $code ) {
            return '';
        }

        // Strip a single anglicised-edition suffix (NIVUK, ESVANGL, NETUK, …)
        // so the underlying base code drives the family lookup.
        foreach ( array( 'ANGL', 'UK' ) as $suffix ) {
            $len = strlen( $suffix );
            if ( strlen( $code ) > $len && substr( $code, -$len ) === $suffix ) {
                $code = substr( $code, 0, -$len );
                break;
            }
        }

        if ( in_array( $code, self::ENGLISH_PROTESTANT_CODES, true ) ) {
            return self::FAMILY_ENGLISH_PROTESTANT;
        }

        return '';
    }
}
