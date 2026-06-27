<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier;
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
     * (STRICT single-segment) is the offerable widening; `derived-exact-perseg` (per-ref)
     * is the WIDEST and is selectable ONLY behind the axis-2 spot-check ack
     * ({@see Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK}). `probable` is deliberately NOT
     * offerable through settings (it requires a documented misparse-risk acceptance).
     *
     * The value strings are the {@see DerivedExactClassifier::FLOOR_*} constants verbatim
     * (single source) so the settings whitelist can never drift from the classifier that
     * consumes the floor at render/audit time.
     */
    public const DEFAULT_CONFIDENCE_FLOOR = DerivedExactClassifier::FLOOR_EXACT;

    /**
     * The whitelist for {@see Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR}, ALWAYS
     * selectable. Order is widest-trust-LAST; anything outside it (and outside the
     * ack-gated perseg floor) floors to {@see self::DEFAULT_CONFIDENCE_FLOOR}.
     *
     * @var list<string>
     */
    private const ALLOWED_CONFIDENCE_FLOORS = array(
        DerivedExactClassifier::FLOOR_EXACT,
        DerivedExactClassifier::FLOOR_DERIVED_EXACT,
    );

    /**
     * Snapshot-completeness oracle for the un-enableable hard-gate. Injected so the
     * enable sanitize is unit-testable without a real uploads filesystem; defaults to the
     * disk-only {@see BibleChapterVendor::isSnapshotComplete()} (design §3.4).
     *
     * @var callable(string):bool
     */
    private $snapshotComplete;

    /**
     * Live inline corpus-gate audit oracle for the heterogeneity attest-disable and the
     * enable soft-gate (design §3.6). Returns the {@see CoverageAudit::inlineReport()} shape
     * (`inline_eligible`, `unmodeled_pair_wrong_text`, `heterogeneous`, `generated_at`, …).
     * Injected so both gates are unit-testable without a live `WP_Query`/corpus; defaults to
     * a fresh, READ-ONLY {@see CoverageAudit::inlineReport()} (no write-on-save beyond the
     * recon-gen stamp the enable success path performs).
     *
     * @var callable():array<string,mixed>
     */
    private $inlineAudit;

    /**
     * @param callable(string):bool|null      $snapshotComplete Override the snapshot-complete
     *        oracle (tests); defaults to {@see BibleChapterVendor::isSnapshotComplete()}.
     * @param callable():array<string,mixed>|null $inlineAudit   Override the live inline-audit
     *        oracle (tests); defaults to a fresh {@see CoverageAudit::inlineReport()}.
     */
    public function __construct( ?callable $snapshotComplete = null, ?callable $inlineAudit = null ) {
        $this->snapshotComplete = $snapshotComplete ?? array( BibleChapterVendor::class, 'isSnapshotComplete' );
        $this->inlineAudit      = $inlineAudit ?? static fn(): array => ( new CoverageAudit() )->inlineReport();
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
        // Turning the feature OFF is always permitted and needs no vendor/audit check.
        if ( ! self::toBool( $value ) ) {
            return false;
        }

        // No-op re-save guard (adversarial-review fix): WordPress runs this sanitize_callback
        // on EVERY save of the shared {@see Identifiers::OPTION_GROUP_SETTINGS} group — a
        // checked checkbox is always re-submitted, and update_option() runs the registered
        // sanitize_callback BEFORE its old==new short-circuit. Without this guard, an unrelated
        // settings save (e.g. only the link version) while inline is ALREADY enabled would, on
        // every save: re-run the full corpus audit; re-stamp OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN
        // (erasing the enable-moment baseline T-K's drift warning reconciles against); thrash the
        // warmed-chapter cache via bumpCacheGen(); and — worst — SILENTLY flip inline OFF if the
        // corpus had drifted since enable (heterogeneous / inline_eligible==0 / wrong-text>0).
        // The hard/soft gates, recon stamp, and cache-gen bump must run ONLY on the genuine
        // false->true enable transition; post-enable corpus drift is a Site-Health WARNING (T-K),
        // never a side effect of saving an unrelated field. Reading the stored value first makes
        // this independent of the add/update_option_ hook wiring (the intended explicit bump on
        // the real transition is preserved below).
        if ( self::toBool( get_option( Identifiers::OPTION_BIBLE_INLINE_ENABLED, false ) ) ) {
            return true;
        }

        $translation = $this->currentInlineTranslation();

        // Hard-gate (design §3.4): the offline snapshot must be fully vendored + warmed,
        // so inline rendering can never ship half-on / dark.
        if ( ! ( $this->snapshotComplete )( $translation ) ) {
            add_settings_error(
                Identifiers::OPTION_BIBLE_INLINE_ENABLED,
                'sermonator_bible_inline_not_vendored',
                __( 'Inline Bible verse text cannot be enabled yet: the offline chapter snapshot is not fully vendored and warmed. Run "wp sermonator bible vendor" and "wp sermonator bible warm" until the snapshot is complete, then enable. Inline rendering stays off until then.', 'sermonator' ),
                'error'
            );
            return false;
        }

        // Soft-gate (design §3.6, decision 6): refuse enable unless a FRESH inline audit over
        // THIS corpus shows it would actually render something safely — inline_eligible > 0
        // (else "enabled but dark = looks like a bug"), zero unmodeled-pair wrong-text exposure,
        // and a single source-versification tradition (heterogeneity makes the site-wide
        // attestation unsafe). The only override is the logged CLI escape hatch, which bypasses
        // this sanitize entirely. On success the audit GENERATION is stamped (T-K drift warning)
        // and the cache generation is bumped so the warmed cache reflects the reconciled corpus.
        $audit = ( $this->inlineAudit )();

        $eligible      = (int) ( $audit['inline_eligible'] ?? 0 );
        $wrongText     = (int) ( $audit['unmodeled_pair_wrong_text'] ?? 0 );
        $heterogeneous = ! empty( $audit['heterogeneous'] );

        if ( $eligible <= 0 || $wrongText > 0 || $heterogeneous ) {
            add_settings_error(
                Identifiers::OPTION_BIBLE_INLINE_ENABLED,
                'sermonator_bible_inline_audit_unreconciled',
                __( 'Inline Bible verse text cannot be enabled yet: a fresh coverage audit must show at least one inline-eligible reference, zero references on an unmodeled versification pair, and a single source-versification tradition. Run "wp sermonator bible audit --inline" and resolve the warnings (or use the logged CLI override) before enabling. Inline rendering stays off until then.', 'sermonator' ),
                'error'
            );
            return false;
        }

        // Reconciliation stamp: record the CORPUS-CONTENT signature this enable reconciled
        // against (NOT the wall-clock `generated_at`, which advances on every routine re-audit
        // and would make T-K's drift advisory a permanent false positive — adversarial-review
        // fix). {@see CoverageAudit::inlineSignature()} hashes the safety-relevant fields, so a
        // later cron/on-save recompute over an UNCHANGED corpus reproduces the SAME signature
        // (drift stays silent) while a genuine corpus change advances it. Then bump the cache
        // generation so warmed chapters built under the prior mode drop.
        update_option(
            Identifiers::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN,
            CoverageAudit::inlineSignature( $audit )
        );
        $this->bumpCacheGen();

        return true;
    }

    /**
     * Attestation sanitize — the admin affirms (or withdraws) that every reference uses one
     * English-tradition link version; this is the L6 gate for `site-default` provenance refs.
     * Withdrawing (false) is always honored. SETTING TRUE is HARD-DISABLED (design §4) when
     * the live inline audit reports `heterogeneous == true` (>1 source-versification family
     * bucket): the single-tradition premise is then provably false, so attesting would surface
     * real-but-wrong verses for the minority tradition. The only override is the logged CLI
     * escape hatch (`wp sermonator bible attest --force`, T-I), which bypasses this sanitize
     * entirely — never a silent UI bypass. The audit is consulted ONLY when setting true.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeAttestation( $value ): bool {
        if ( ! self::toBool( $value ) ) {
            return false;
        }

        // No-op re-save guard (adversarial-review fix), mirroring sanitizeInlineEnabled: the
        // shared settings group re-submits the checked attestation box on EVERY save, and the
        // sanitize_callback runs before update_option()'s old==new short-circuit. Without this,
        // an unrelated save while attestation is ALREADY true re-runs the full corpus audit and
        // SILENTLY withdraws attestation if the corpus has since drifted heterogeneous. Post-attest
        // drift is surfaced via the audit/Site Health, never auto-withdrawn on an unrelated save;
        // the heterogeneity hard-disable below applies only to the genuine false->true transition.
        if ( self::toBool( get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false ) ) ) {
            return true;
        }

        if ( ! empty( ( $this->inlineAudit )()['heterogeneous'] ) ) {
            add_settings_error(
                Identifiers::OPTION_BIBLE_INLINE_ATTESTATION,
                'sermonator_bible_inline_attest_heterogeneous',
                __( 'Attestation refused: the sermon corpus mixes more than one source-versification tradition, so the single-tradition affirmation is not true. Attesting would let inline rendering surface real-but-wrong verses for the minority tradition. Resolve the heterogeneity (or use the logged CLI override) first.', 'sermonator' ),
                'error'
            );
            return false;
        }

        return true;
    }

    /**
     * Confidence-floor sanitize — a strict enum whitelist (L2) with the THIRD gate on the
     * widest floor (design §3.3/§3.6). `exact` and STRICT `derived-exact` are always
     * selectable. The per-ref `derived-exact-perseg` floor is selectable ONLY when the
     * axis-2 spot-check ack ({@see Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK}) is set;
     * submitting it WITHOUT the ack floors back to STRICT `derived-exact` (not all the way to
     * `exact`) and registers a settings error pointing at the spot-check CLI. Anything else
     * (a non-string or the deliberately un-offerable `probable`) floors conservatively to
     * {@see self::DEFAULT_CONFIDENCE_FLOOR}.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeConfidenceFloor( $value ): string {
        $value = is_string( $value ) ? $value : '';

        // The widest floor is gated behind the axis-2 human spot-check ack.
        if ( DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG === $value ) {
            if ( self::persegAcknowledged() ) {
                return DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG;
            }

            add_settings_error(
                Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR,
                'sermonator_bible_inline_perseg_unacked',
                __( 'Per-reference inline scripture (derived-exact-perseg) requires the human spot-check first. Run "wp sermonator bible audit --inline --sample=N", verify the promoted references against their raw text, then record the acknowledgement with "wp sermonator bible ack-perseg --confirm" and re-select it. The floor was set to the stricter single-segment "derived-exact" for now.', 'sermonator' ),
                'error'
            );

            return DerivedExactClassifier::FLOOR_DERIVED_EXACT;
        }

        return in_array( $value, self::ALLOWED_CONFIDENCE_FLOORS, true )
            ? $value
            : self::DEFAULT_CONFIDENCE_FLOOR;
    }

    /**
     * Whether the axis-2 per-ref spot-check has been acknowledged (the perseg floor's third
     * gate). Read-only coercion of {@see Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK}, which
     * is set only by the dedicated logged CLI ack step ("wp sermonator bible ack-perseg
     * --confirm", T-I — see {@see \Sermonator\Cli\BibleCommand::ackPerseg()}), never a
     * Settings-API field. The read-only "audit --inline --sample=N" spot-check it follows
     * deliberately writes nothing, so the ack is always an explicit, separate confirmation.
     */
    private static function persegAcknowledged(): bool {
        return self::toBool( get_option( Identifiers::OPTION_BIBLE_INLINE_PERSEG_ACK, false ) );
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
