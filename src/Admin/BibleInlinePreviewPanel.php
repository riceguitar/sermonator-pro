<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier as DEC;
use Sermonator\Schema\Identifiers;

/**
 * Read-only LIVE per-site inline audit-preview panel (design §4 live preview / spec T-H).
 *
 * Rendered inside the settings page's Bible section (Form 1 / options.php), this panel is a
 * pure DISPLAY surface over {@see CoverageAudit::promotionPreview()} (T-E): it shows the
 * operator, over THEIR OWN corpus and BEFORE they save, the would-promote count + the
 * inline-eligible% under EACH of the three confidence floors
 * (`exact` / strict `derived-exact` / `derived-exact-perseg`) in one pass, the
 * withheld-by-reason breakdown for the pending floor, and the two wrong-text canaries
 * (corpus heterogeneity + the unmodeled-versification-pair count).
 *
 * ## The assume-attested ceiling + the "0% until you attest" state (design §4)
 *
 * The three-floor table reports the assume-attested CEILING ({@see CoverageAudit::promotionPreview()}
 * with `assumeAttested = true`) — i.e. what WOULD promote if the admin attested — so the
 * recall of each floor is visible BEFORE attesting (the whole point of a pre-save preview).
 * The panel then reads the REAL attestation state ({@see Identifiers::OPTION_BIBLE_INLINE_ATTESTATION});
 * when it is OFF, site-default refs fail L6 by construction, so the panel surfaces the explicit
 * "0% until you attest" banner (louder when the pending floor is a derived floor) — the numbers
 * are a ceiling, not the current live recall.
 *
 * ## READ-ONLY (the #1 standard is RENDER-TIME only)
 *
 * The panel NEVER writes: {@see CoverageAudit::promotionPreview()} queries + classifies and
 * persists nothing, and every other read here is a plain `get_option`. No write-on-GET / no
 * write-on-render. The ONE form control it owns — the attestation checkbox — posts to the
 * SettingsRegistrar-owned {@see Identifiers::OPTION_BIBLE_INLINE_ATTESTATION} option through the
 * Settings API; the panel registers nothing and writes nothing itself. The checkbox is
 * hard-disabled when the live preview reports `heterogeneous == true` (>1 source-versification
 * family bucket), mirroring {@see SettingsRegistrar::sanitizeAttestation()}'s hard-disable so the
 * UI cannot offer an attestation the write boundary would refuse.
 *
 * All output is escaped at this boundary.
 */
final class BibleInlinePreviewPanel {
    /**
     * The VERBATIM theological attestation claim (design §4). Shown as the checkbox label so the
     * operator affirms the single-English-tradition premise the L6 gate rides on — and is warned
     * off it for Septuagint/Vulgate/Catholic-Psalm numbering.
     *
     * Single-source PIN: WordPress i18n needs a string LITERAL inside {@see esc_html__()} for
     * extraction, so {@see self::renderAttestationField()} repeats the wording in its
     * `esc_html__()` call rather than interpolating this constant. To keep that literal from
     * silently drifting from this constant, the test suite asserts both (a) this constant equals
     * the verbatim design §4 copy and (b) the rendered field CONTAINS this constant
     * (BibleInlinePreviewPanelTest::test_attestation_claim_constant_matches_design_copy +
     * ::test_rendered_attestation_field_contains_the_claim_constant) — so any drift fails a test.
     */
    public const ATTESTATION_CLAIM = 'I affirm every sermon\'s reference uses the same English versification tradition (ESV/NIV/NASB/NKJV/KJV/WEB number identically). If you have Septuagint/Vulgate/Catholic-canon-Psalm-numbered references, do NOT attest — inline could show real-but-wrong verses.';

    /**
     * Resolves the assume-attested would-promote preview for the current corpus. Injected so the
     * panel is testable without a live `WP_Query`/corpus; defaults to a fresh, READ-ONLY
     * {@see CoverageAudit::promotionPreview()} (writes nothing).
     *
     * @var callable(bool):array<string,mixed>
     */
    private $previewProvider;

    /**
     * Per-render memo of the assume-attested ceiling preview, so the attestation field's
     * heterogeneity disable and the preview table share ONE corpus pass per page render.
     *
     * @var array<string,mixed>|null
     */
    private ?array $ceilingMemo = null;

    /**
     * @param callable(bool):array<string,mixed>|null $previewProvider Override the preview oracle
     *        (tests); defaults to {@see CoverageAudit::promotionPreview()}.
     */
    public function __construct( ?callable $previewProvider = null ) {
        $this->previewProvider = $previewProvider ?? static fn( bool $assumeAttested ): array => ( new CoverageAudit() )->promotionPreview( $assumeAttested );
    }

    /**
     * The assume-attested ceiling preview, computed at most once per render (memoized). Always
     * `assumeAttested = true` — the ceiling lever — so the table shows each floor's full potential
     * recall; the "0% until you attest" reality is overlaid separately from the stored attestation
     * option. Never writes.
     *
     * @return array<string,mixed>
     */
    private function ceiling(): array {
        if ( null === $this->ceilingMemo ) {
            $this->ceilingMemo = ( $this->previewProvider )( true );
        }

        return $this->ceilingMemo;
    }

    /**
     * Render the read-only three-floor coverage preview: the per-floor recall table, the
     * withheld-by-reason breakdown for the pending floor, the wrong-text canaries, and the
     * "0% until you attest" state when attestation is off. Pure display — no writes.
     */
    public function renderPreview(): void {
        $preview  = $this->ceiling();
        $attested = $this->attestationEnabled();
        $floor    = $this->pendingFloor();

        $refsTotal = isset( $preview['refs_total'] ) ? (int) $preview['refs_total'] : 0;

        echo '<div class="sermonator-bible-inline-preview">';
        echo '<h3>' . esc_html__( 'Inline scripture — live coverage preview', 'sermonator' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Computed over your own published sermon corpus. These figures are the assume-attested ceiling — what would render inline IF you attest below — under each confidence floor. Nothing here is saved; it is a read-only preview.', 'sermonator' ) . '</p>';

        if ( $refsTotal <= 0 ) {
            echo '<p>' . esc_html__( 'No in-canon, structurally-valid scripture references were found in the corpus, so there is nothing to preview yet.', 'sermonator' ) . '</p>';
            echo '</div>';
            return;
        }

        // Wrong-text canaries first (the #1 standard: a green recall headline must never hide a
        // mis-versification). Attestation-independent.
        $this->renderCanaries( $preview );

        // The "0% until you attest" reality overlay: the table is a ceiling; site-default refs are
        // withheld at L6 until attestation is on.
        if ( ! $attested ) {
            $derivedPending = in_array( $floor, array( DEC::FLOOR_DERIVED_EXACT, DEC::FLOOR_DERIVED_EXACT_PERSEG ), true );
            $class          = $derivedPending ? 'notice notice-warning inline' : 'notice notice-info inline';
            echo '<div class="' . esc_attr( $class ) . '"><p>';
            echo '<strong>' . esc_html__( '0% inline until you attest.', 'sermonator' ) . '</strong> ';
            echo esc_html__( 'Attestation is currently off, so every site-default reference is withheld at the single-tradition gate (L6) and renders as a link. The figures below are the ceiling you would reach AFTER attesting — they are not the current live recall.', 'sermonator' );
            echo '</p></div>';
        }

        $this->renderFloorTable( $preview, $floor );
        $this->renderWithheldBreakdown( $preview, $floor );

        echo '</div>';
    }

    /**
     * Render the attestation checkbox — the ONE form control this panel owns. It posts to the
     * SettingsRegistrar-owned {@see Identifiers::OPTION_BIBLE_INLINE_ATTESTATION} through the
     * Settings API (this panel registers nothing). The label is the VERBATIM theological claim
     * ({@see self::ATTESTATION_CLAIM}). Hard-disabled when the live preview reports the corpus is
     * heterogeneous, mirroring {@see SettingsRegistrar::sanitizeAttestation()} so the UI never
     * offers an attestation the write boundary would refuse.
     *
     * The hidden companion ALWAYS posts a value (never suppressed). This is a Form-1 /
     * options.php (Settings API) field, and options.php iterates EVERY whitelisted option in the
     * group and calls update_option($option, null) for any key ABSENT from POST — it does NOT
     * skip absent options (core options.php, the save loop). A disabled checkbox submits nothing,
     * so without a companion the key is absent → update_option(option, null) →
     * sanitizeAttestation(null) coerces to false at its toBool early-return, BEFORE the no-op
     * guard — silently withdrawing a previously-set attestation (including one set via the
     * sanctioned, logged `wp sermonator bible attest --force` override, design §4) on the very
     * next unrelated save. (The cited podcast checkbox's "untouched on absence" property is
     * specific to PodcastIdentityController's admin-post.php array_key_exists merge — Form 2 —
     * and does NOT transfer to the Settings API.) So:
     *   - Enabled (homogeneous): standard companion value="0" — a checked box posts "1" and wins,
     *     an unchecked box falls through to "0" (the operator can un-attest).
     *   - Disabled (heterogeneous): the box submits nothing, so the companion REFLECTS THE STORED
     *     VALUE — a force-attested true posts "1", round-trips through sanitizeAttestation's no-op
     *     guard (stored true → returns true) and is PRESERVED; a stored false posts "0" and stays
     *     false (the disabled box still prevents the operator flipping it on). This upholds
     *     {@see SettingsRegistrar::sanitizeAttestation()}'s "never auto-withdrawn on an unrelated
     *     save" invariant under the Settings API. The clearing it prevents was always the SAFE
     *     direction (un-attest withholds inline → never-fail-WRONG holds), but it silently undid a
     *     deliberate, logged admin decision.
     */
    public function renderAttestationField(): void {
        $option        = Identifiers::OPTION_BIBLE_INLINE_ATTESTATION;
        $attested      = $this->attestationEnabled();
        $heterogeneous = ! empty( $this->ceiling()['heterogeneous'] );

        // Always emit a companion (see method docblock): enabled → "0" (standard last-value-wins
        // un-attest); disabled → reflect the stored value so a Form-1 save round-trips it through
        // sanitize rather than clearing it via options.php's null-write-for-absent-key behavior.
        $companion = $heterogeneous ? ( $attested ? '1' : '0' ) : '0';
        echo '<input type="hidden" name="' . esc_attr( $option ) . '" value="' . esc_attr( $companion ) . '">';

        echo '<label><input type="checkbox" id="' . esc_attr( $option ) . '" name="' . esc_attr( $option ) . '" value="1"'
            . checked( $attested, true, false )
            . disabled( $heterogeneous, true, false )
            . '> '
            . esc_html__( 'I affirm every sermon\'s reference uses the same English versification tradition (ESV/NIV/NASB/NKJV/KJV/WEB number identically). If you have Septuagint/Vulgate/Catholic-canon-Psalm-numbered references, do NOT attest — inline could show real-but-wrong verses.', 'sermonator' )
            . '</label>';

        if ( $heterogeneous ) {
            echo '<p class="description notice notice-error inline"><strong>' . esc_html__( 'Attestation disabled:', 'sermonator' ) . '</strong> '
                . esc_html__( 'the live preview shows your corpus mixes more than one source-versification tradition, so the single-tradition affirmation is not true. Attesting would let inline rendering surface real-but-wrong verses for the minority tradition. Resolve the heterogeneity (or use "wp sermonator bible attest --force") first.', 'sermonator' )
                . '</p>';
        }
    }

    /**
     * The two attestation-independent wrong-text canaries: the unmodeled-versification-pair count
     * (proof the modeled set is incomplete for this corpus) and corpus heterogeneity (proof the
     * single site-wide attestation premise is false).
     *
     * @param array<string,mixed> $preview
     */
    private function renderCanaries( array $preview ): void {
        $wrongText = isset( $preview['unmodeled_pair_wrong_text'] ) ? (int) $preview['unmodeled_pair_wrong_text'] : 0;
        if ( $wrongText > 0 ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(
                sprintf(
                    /* translators: %d: count of references on an unmodeled source/target versification pair. */
                    __( 'WARNING: %d reference(s) use an unmodeled source/target versification pair — direct proof the divergent-zone table is incomplete for this corpus. Do NOT enable inline scripture at scale until these are modeled.', 'sermonator' ),
                    $wrongText
                )
            ) . '</p></div>';
        }

        if ( ! empty( $preview['heterogeneous'] ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__(
                'WARNING: the corpus mixes more than one source-versification tradition. The single site-wide attestation is unsafe; inline rendering could surface real-but-wrong verses for the minority tradition.',
                'sermonator'
            ) . '</p></div>';
        }
    }

    /**
     * The per-floor recall table: would-promote count, inline-eligible count, and inline-eligible%
     * under each of the three floors. The pending (configured) floor is marked.
     *
     * @param array<string,mixed> $preview
     */
    private function renderFloorTable( array $preview, string $pendingFloor ): void {
        $floors = isset( $preview['floors'] ) && is_array( $preview['floors'] ) ? $preview['floors'] : array();
        $total  = isset( $preview['refs_total'] ) ? (int) $preview['refs_total'] : 0;

        echo '<table class="widefat striped sermonator-bible-inline-floors">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Confidence floor', 'sermonator' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Would promote', 'sermonator' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Inline-eligible', 'sermonator' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Inline-eligible %', 'sermonator' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( self::floorOrder() as $code ) {
            $report      = isset( $floors[ $code ] ) && is_array( $floors[ $code ] ) ? $floors[ $code ] : array();
            $wouldPromote = isset( $report['would_promote'] ) ? (int) $report['would_promote'] : 0;
            $eligible     = isset( $report['inline_eligible'] ) ? (int) $report['inline_eligible'] : 0;
            $pct          = isset( $report['inline_eligible_pct'] ) ? (float) $report['inline_eligible_pct'] : 0.0;
            $isPending    = ( $code === $pendingFloor );

            echo '<tr' . ( $isPending ? ' class="sermonator-floor-pending"' : '' ) . '>';
            echo '<th scope="row">' . esc_html( self::floorLabel( $code ) );
            if ( $isPending ) {
                echo ' <span class="description">' . esc_html__( '(pending)', 'sermonator' ) . '</span>';
            }
            echo '</th>';
            echo '<td>' . esc_html( (string) $wouldPromote ) . '</td>';
            echo '<td>' . esc_html( $eligible . ' / ' . $total ) . '</td>';
            echo '<td>' . esc_html( self::formatPercent( $pct ) . '%' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * The withheld-by-reason breakdown for the PENDING floor (the one a save would apply), so the
     * operator sees WHY references are not inline-eligible. Only non-zero reasons are listed.
     *
     * @param array<string,mixed> $preview
     */
    private function renderWithheldBreakdown( array $preview, string $pendingFloor ): void {
        $floors = isset( $preview['floors'] ) && is_array( $preview['floors'] ) ? $preview['floors'] : array();
        $report = isset( $floors[ $pendingFloor ] ) && is_array( $floors[ $pendingFloor ] ) ? $floors[ $pendingFloor ] : array();
        $withheld = isset( $report['withheld'] ) && is_array( $report['withheld'] ) ? $report['withheld'] : array();

        $labels = self::withheldLabels();

        $rows = array();
        foreach ( $withheld as $reason => $count ) {
            $count = (int) $count;
            if ( $count <= 0 ) {
                continue;
            }
            $label  = $labels[ $reason ] ?? (string) $reason;
            $rows[] = '<li>' . esc_html( $label . ': ' . $count ) . '</li>';
        }

        echo '<h4>' . esc_html(
            sprintf(
                /* translators: %s: the pending confidence-floor label. */
                __( 'Withheld references under the pending floor (%s)', 'sermonator' ),
                self::floorLabel( $pendingFloor )
            )
        ) . '</h4>';

        if ( array() === $rows ) {
            echo '<p>' . esc_html__( 'No references are withheld under this floor.', 'sermonator' ) . '</p>';
            return;
        }

        echo '<ul class="sermonator-bible-inline-withheld">' . implode( '', $rows ) . '</ul>';
    }

    /** Whether the admin has attested (read-only coercion of the stored option). */
    private function attestationEnabled(): bool {
        return self::toBool( get_option( Identifiers::OPTION_BIBLE_INLINE_ATTESTATION, false ) );
    }

    /**
     * The pending (configured) L2 confidence floor, normalized to the de-stored floor vocabulary
     * `{exact,derived-exact,derived-exact-perseg}` (an unknown/legacy value floors to `exact`),
     * byte-lockstep with {@see CoverageAudit} / {@see SettingsRegistrar}.
     */
    private function pendingFloor(): string {
        $stored = get_option( Identifiers::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, DEC::FLOOR_EXACT );
        $stored = is_string( $stored ) ? $stored : DEC::FLOOR_EXACT;

        return in_array( $stored, self::floorOrder(), true ) ? $stored : DEC::FLOOR_EXACT;
    }

    /**
     * The three floors in display order (widest-trust last), referencing the classifier constants
     * so the panel can never drift from the floor vocabulary it previews.
     *
     * @return list<string>
     */
    private static function floorOrder(): array {
        return array(
            DEC::FLOOR_EXACT,
            DEC::FLOOR_DERIVED_EXACT,
            DEC::FLOOR_DERIVED_EXACT_PERSEG,
        );
    }

    /** A human label for a floor code. */
    private static function floorLabel( string $code ): string {
        switch ( $code ) {
            case DEC::FLOOR_DERIVED_EXACT:
                return __( 'Derived-exact (strict, single-segment)', 'sermonator' );
            case DEC::FLOOR_DERIVED_EXACT_PERSEG:
                return __( 'Derived-exact per-reference', 'sermonator' );
            case DEC::FLOOR_EXACT:
            default:
                return __( 'Exact (author-confirmed only)', 'sermonator' );
        }
    }

    /**
     * Human labels for the withheld-by-reason buckets, keyed by the {@see CoverageAudit}
     * INLINE_REASON_* constants (single source — never the raw strings).
     *
     * @return array<string,string>
     */
    private static function withheldLabels(): array {
        return array(
            CoverageAudit::INLINE_REASON_NOT_INLINE_ELIGIBLE    => __( 'Not inline-shaped (chapter-only or cross-chapter)', 'sermonator' ),
            CoverageAudit::INLINE_REASON_LOW_CONFIDENCE         => __( 'Below the confidence floor', 'sermonator' ),
            CoverageAudit::INLINE_REASON_TRANSLATION_INELIGIBLE => __( 'Inline translation not eligible', 'sermonator' ),
            CoverageAudit::INLINE_REASON_SRC_UNSUPPORTED        => __( 'Source versification unsupported', 'sermonator' ),
            CoverageAudit::INLINE_REASON_SRC_HETEROGENEOUS      => __( 'Minority tradition in a mixed corpus', 'sermonator' ),
            CoverageAudit::INLINE_REASON_UNMODELED_PAIR         => __( 'Unmodeled versification pair', 'sermonator' ),
            CoverageAudit::INLINE_REASON_UNATTESTED             => __( 'Withheld until you attest (site-default, L6)', 'sermonator' ),
            CoverageAudit::INLINE_REASON_DIVERGENT              => __( 'Divergent versification zone', 'sermonator' ),
            CoverageAudit::INLINE_REASON_COLD_UNWARMED          => __( 'Chapter not yet vendored/warmed', 'sermonator' ),
            CoverageAudit::INLINE_REASON_VERSE_OUT_OF_RANGE     => __( 'Verse out of chapter range', 'sermonator' ),
        );
    }

    /** Trim a one-decimal percentage to an integer-looking string when whole (49 not 49.0). */
    private static function formatPercent( float $percent ): string {
        if ( floor( $percent ) === $percent ) {
            return (string) (int) $percent;
        }

        return rtrim( rtrim( number_format( $percent, 1, '.', '' ), '0' ), '.' );
    }

    /**
     * Coerce a Settings-API / REST boolean payload to a real bool — mirrors
     * {@see SettingsRegistrar} so `'0'`/`'false'` read as false (a bare cast would not).
     *
     * @param mixed $value
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
}
