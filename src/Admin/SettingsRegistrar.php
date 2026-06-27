<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Migration\BibleChapterVendor;
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
 * Phase 3b adds three more options on the same shared group (spec Task 15):
 *   - {@see Identifiers::OPTION_BIBLE_INLINE_ENABLED} (bool master kill-switch) —
 *     PHYSICALLY un-enableable: the sanitize_callback refuses to store TRUE until the
 *     configured inline translation's per-chapter snapshot is present + complete
 *     ({@see BibleChapterVendor::isSnapshotComplete()}), so inline rendering can never
 *     ship half-on / dark (design §3.4). Disabling is always honored.
 *   - {@see Identifiers::OPTION_BIBLE_INLINE_ATTESTATION} (bool) — the admin affirms all
 *     references use one English-tradition link version (the L6 gate for `site-default`
 *     provenance refs).
 *   - {@see Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR} (enum `exact|derived-exact`,
 *     default `exact`) — the L2 confidence floor an inline-eligible ref must clear.
 *
 * Creating OR updating a cache-affecting option (the link version, the inline translation,
 * or the inline enable toggle) bumps {@see Identifiers::OPTION_BIBLE_CACHE_GEN}
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

    /**
     * The default — and conservative — confidence floor an inline-eligible ref must
     * clear (design §2 L2 / §3.5). `exact` = author-confirmed chips only; `derived-exact`
     * is the only widening an admin may opt into here. `probable` is deliberately NOT
     * offerable through settings (it requires a documented misparse-risk acceptance).
     */
    public const DEFAULT_CONFIDENCE_FLOOR = 'exact';

    /**
     * The whitelist for {@see Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR}. Order is
     * widest-trust-LAST; anything outside it floors to {@see self::DEFAULT_CONFIDENCE_FLOOR}.
     *
     * @var list<string>
     */
    private const ALLOWED_CONFIDENCE_FLOORS = array( 'exact', 'derived-exact' );

    /**
     * Snapshot-completeness oracle for the un-enableable hard-gate. Injected so the
     * enable sanitize is unit-testable without a real uploads filesystem; defaults to the
     * disk-only {@see BibleChapterVendor::isSnapshotComplete()} (design §3.4).
     *
     * @var callable(string):bool
     */
    private $snapshotComplete;

    /**
     * @param callable(string):bool|null $snapshotComplete Override the snapshot-complete
     *        oracle (tests); defaults to {@see BibleChapterVendor::isSnapshotComplete()}.
     */
    public function __construct( ?callable $snapshotComplete = null ) {
        $this->snapshotComplete = $snapshotComplete ?? array( BibleChapterVendor::class, 'isSnapshotComplete' );
    }

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
        // The enable toggle joins the translation/link-version axes on the bump list:
        // flipping inline rendering on or off must invalidate any warmed chapter cache
        // built under the prior mode, the same generation-bump mechanism the axes reuse.
        foreach (
            array(
                Identifiers::OPTION_BIBLE_LINK_VERSION,
                Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
                Identifiers::OPTION_BIBLE_INLINE_ENABLED,
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

        // Phase 3b master kill-switch. Default FALSE; the sanitize_callback makes TRUE
        // physically un-settable until the inline translation's snapshot is fully
        // vendored + warmed (no half-on / ships-dark — design §3.4).
        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_BIBLE_INLINE_ENABLED,
            array(
                'type'              => 'boolean',
                'default'           => false,
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeInlineEnabled' ),
            )
        );

        // Admin attestation that ALL references use one English-tradition link version —
        // the L6 gate for `site-default` (backfill/absent) provenance refs. Default FALSE.
        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_BIBLE_INLINE_ATTESTATION,
            array(
                'type'              => 'boolean',
                'default'           => false,
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeAttestation' ),
            )
        );

        // Confidence floor an inline-eligible ref must clear (L2). Default `exact`;
        // admin may opt into `derived-exact`. Anything else floors to `exact`.
        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR,
            array(
                'type'              => 'string',
                'default'           => self::DEFAULT_CONFIDENCE_FLOOR,
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeConfidenceFloor' ),
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
     * Enable-toggle sanitize — the un-enableable hard-gate (design §3.4). Disabling is
     * always honored. ENABLING is REFUSED (sanitized back to false + an
     * `add_settings_error`) unless the configured inline translation's ENGWEBP per-chapter
     * snapshot is present AND complete on disk, so inline rendering can never ship half-on
     * (the master switch flipped while the render path has no offline text to serve, which
     * would silently dark-launch the feature). The completeness probe is disk-only — no
     * network — and never throws on this admin save path.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeInlineEnabled( $value ): bool {
        // Turning the feature OFF is always permitted and needs no vendor check.
        if ( ! self::toBool( $value ) ) {
            return false;
        }

        $translation = $this->currentInlineTranslation();

        if ( ! ( $this->snapshotComplete )( $translation ) ) {
            add_settings_error(
                Identifiers::OPTION_BIBLE_INLINE_ENABLED,
                'sermonator_bible_inline_not_vendored',
                __( 'Inline Bible verse text cannot be enabled yet: the offline chapter snapshot is not fully vendored and warmed. Run "wp sermonator bible vendor" and "wp sermonator bible warm" until the snapshot is complete, then enable. Inline rendering stays off until then.', 'sermonator' ),
                'error'
            );
            return false;
        }

        return true;
    }

    /**
     * Attestation sanitize — a plain boolean coercion. The admin affirms (or withdraws)
     * that every reference uses one English-tradition link version; this is the L6 gate for
     * `site-default` provenance refs. No vendor precondition: the attestation can be set
     * independently of (and before) enabling, and only takes effect once inline is enabled.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeAttestation( $value ): bool {
        return self::toBool( $value );
    }

    /**
     * Confidence-floor sanitize — a strict enum whitelist (L2). Keeps `exact` or the
     * widened `derived-exact`; anything else (including a non-string or the deliberately
     * un-offerable `probable`) floors conservatively to {@see self::DEFAULT_CONFIDENCE_FLOOR}.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeConfidenceFloor( $value ): string {
        $value = is_string( $value ) ? $value : '';

        return in_array( $value, self::ALLOWED_CONFIDENCE_FLOORS, true )
            ? $value
            : self::DEFAULT_CONFIDENCE_FLOOR;
    }

    /**
     * The currently-configured inline translation (the one whose snapshot the enable gate
     * probes), floored to {@see BibleTranslations::DEFAULT_INLINE} when unset or no longer
     * inline-eligible — mirroring {@see self::sanitizeInlineTranslation()}.
     */
    private function currentInlineTranslation(): string {
        $value = get_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, BibleTranslations::DEFAULT_INLINE );

        return ( is_string( $value ) && array_key_exists( $value, BibleTranslations::curatedInline() ) )
            ? $value
            : BibleTranslations::DEFAULT_INLINE;
    }

    /**
     * Coerce a Settings-API / REST boolean payload to a real bool. WordPress submits
     * checkbox state as the strings `'1'`/`'0'`/`''` (and REST as JSON `true`/`false`);
     * a bare `(bool)` cast would treat `'0'` and `'false'` as TRUE, so the truthy tokens
     * are whitelisted explicitly.
     *
     * @param mixed $value Raw submitted value.
     */
    private static function toBool( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'yes' ), true );
        }
        if ( is_int( $value ) ) {
            return 0 !== $value;
        }

        return false;
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
