<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * The sole gated DESTRUCTIVE step — the migration's point of no return
 * (design-notes item 19).
 *
 * Finalize is the ONLY operation in the lifecycle that deletes legacy
 * (wpfc_* / sermonmanager_*) data. Everything before it is non-destructive and
 * reversible by Rollback; once Finalize succeeds the migration is irreversible.
 * Because of that, it is HARD-GATED on three independent conditions, and unless ALL
 * hold it returns a `refused` reason and deletes NOTHING:
 *
 *   1. MigrationState::phase() === 'verified' — a Verifier pass already proved the
 *      migration complete (every manifest legacy id has exactly one clean migrated
 *      counterpart, no drift, no open failure flag).
 *   2. A FRESH Verifier-style drift rescan still reports `complete` — re-running the
 *      Verifier against the detect-time manifest must STILL be clean, so a legacy
 *      edit, a re-opened failure flag (e.g. a human-review post_content_divergence),
 *      or a newly-inserted live legacy id between verification and finalize aborts
 *      the destructive step. This is what makes "verified an hour ago" insufficient.
 *   3. $confirmed === true — an explicit operator confirmation (the CLI passes this
 *      only behind --yes).
 *
 * On success it deletes legacy data PER VERIFIED COUNTERPART ONLY (never on a bare
 * cardinality match):
 *   - for each legacy POST id whose counterpart was field-by-field verified, delete
 *     the legacy post (wp_delete_post(true)) and strip ONLY the pure back-ref
 *     allowlist (Crosswalk::strippableBackRefs()) from the migrated counterpart —
 *     NEVER LEGACY_POST_CONTENT (the preserved divergent body) and never a
 *     MIGRATION_FLAGS row carrying an unresolved divergence flag (defence-in-depth:
 *     the fresh rescan already guarantees none is open);
 *   - delete the migrated legacy OPTIONS (the live sermonmanager_* set + the legacy
 *     artwork options + the legacy default-podcast pointer), since their target
 *     sermonator_* counterparts were verified present;
 *   - recount the deferred native shared term_taxonomy_ids exactly ONCE
 *     (B2a forward constraint) so the church's shared counts settle to their true
 *     live value at the point of no return.
 *
 * Deleting legacy TERMS is INTENTIONALLY DEFERRED (not done here). A migrated legacy
 * term may still be a native/shared term the church relies on, and the conservative,
 * data-preserving choice is to leave the legacy terms in place — their migrated
 * counterparts are the church's authoritative records and the orphaned legacy terms
 * carry no live posts once the legacy posts are deleted. This is documented as the
 * deliberate conservative choice (see the must_handle).
 *
 * After success, state → 'finalized'; Rollback then refuses outright.
 */
final class Finalizer {
    private MigrationState $state;
    private Verifier $verifier;

    public function __construct( ?MigrationState $state = null, ?Verifier $verifier = null ) {
        $this->state    = $state ?? new MigrationState();
        $this->verifier = $verifier ?? new Verifier( $this->state );
    }

    /**
     * The pure back-ref keys safe to strip from migrated records at finalize. Equal
     * to Crosswalk::strippableBackRefs() — excludes LEGACY_POST_CONTENT (preserved
     * divergent body) and MIGRATION_FLAGS (review data), which must NEVER be stripped.
     *
     * @return list<string>
     */
    public static function stripAllowlist(): array {
        return Crosswalk::strippableBackRefs();
    }

    /**
     * The sole destructive step. Hard-gated; idempotent on the refusal paths.
     *
     * @param bool $confirmed Explicit operator confirmation (CLI: behind --yes).
     * @return array{deleted:array{posts:list<int>, options:list<string>}, stripped:int, refused:?string}
     */
    public function run( bool $confirmed = false ): array {
        // Legacy reads must work with the legacy plugin DEACTIVATED.
        LegacySchemaRegistrar::ensureRegistered();

        $deleted = array(
            'posts'   => array(),
            'options' => array(),
        );

        // GATE 1: state must be 'verified'.
        if ( $this->state->phase() !== 'verified' ) {
            return $this->refuse(
                $deleted,
                sprintf(
                    'Finalize refused: migration phase is "%s", not "verified" (run verify first).',
                    $this->state->phase()
                )
            );
        }

        // GATE 3 (cheap, check before the rescan): explicit confirmation required.
        if ( $confirmed !== true ) {
            return $this->refuse(
                $deleted,
                'Finalize refused: explicit confirmation required (pass confirmed=true / --yes).'
            );
        }

        // GATE 2: a FRESH drift rescan must STILL be clean. The Verifier is read-only
        // and, run from the 'verified' phase, does not advance state (it only ever
        // advances migrated → verified) — so a re-run here is a pure oracle. Any drift,
        // re-opened failure flag, or newly-inserted live legacy id makes this report
        // incomplete and aborts the destructive step.
        $manifest = $this->state->manifest();
        if ( $manifest === null ) {
            return $this->refuse(
                $deleted,
                'Finalize refused: no detect-time manifest is stored (cannot prove fixity).'
            );
        }

        $report = $this->verifier->verify( $manifest );
        if ( ! $report->complete ) {
            return $this->refuse(
                $deleted,
                sprintf(
                    'Finalize refused: a fresh rescan is not clean (drift=%d, missing=%d, openFlags=%d) — resolve before finalizing.',
                    count( $report->drift ),
                    count( $report->missing ),
                    count( $report->openFlags )
                )
            );
        }

        // --- All gates passed. Begin the irreversible destructive step. ------------

        $stripped = 0;

        // (1) Per verified POST counterpart: delete the legacy post + strip allowlist
        // back-refs from the migrated counterpart. Driven from the manifest's
        // enumerated legacy id set (sermons) plus the live legacy podcast set (the
        // manifest records podcasts by count only). Because the fresh rescan was clean,
        // every enumerated legacy id resolves to EXACTLY ONE clean counterpart — so we
        // never delete a legacy id that lacks a verified counterpart.
        $sermonLegacyIds  = $manifest->checksummedLegacyIds();
        $podcastLegacyIds = $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST );

        foreach ( $sermonLegacyIds as $legacyId ) {
            $stripped += $this->finalizeCounterpart( (int) $legacyId, Identifiers::POST_TYPE_SERMON, $deleted );
        }
        foreach ( $podcastLegacyIds as $legacyId ) {
            $stripped += $this->finalizeCounterpart( (int) $legacyId, Identifiers::POST_TYPE_PODCAST, $deleted );
        }

        // (2) Delete the migrated legacy OPTIONS. Their sermonator_* targets were
        // verified present, so the legacy originals can go. This is the live
        // sermonmanager_* set + the legacy artwork options + the legacy default-podcast
        // pointer. (The migration NEVER wrote these — they are pure legacy.)
        foreach ( $this->legacyOptionsToDelete() as $optionName ) {
            if ( get_option( $optionName, '__sermonator_absent__' ) !== '__sermonator_absent__' ) {
                delete_option( $optionName );
                $deleted['options'][] = $optionName;
            }
        }

        // (3) Recount the deferred native shared tt_ids exactly once so the church's
        // shared counts settle to their TRUE live value at the point of no return.
        $this->recountNativeTtIds();

        // Done — the point of no return.
        $this->state->set( 'finalized' );

        return array(
            'deleted'  => array(
                'posts'   => array_values( array_unique( $deleted['posts'] ) ),
                'options' => array_values( array_unique( $deleted['options'] ) ),
            ),
            'stripped' => $stripped,
            'refused'  => null,
        );
    }

    /**
     * Finalize one verified legacy→target counterpart: delete the legacy post and
     * strip the allowlist back-refs from its migrated counterpart. Returns the number
     * of back-ref rows stripped (so the caller can total `stripped`).
     *
     * Defence-in-depth: even though the fresh rescan guarantees no open failure flag,
     * a migrated record whose MIGRATION_FLAGS row carries an unresolved divergence
     * flag is left fully intact (no strip, legacy NOT deleted) — divergence is only
     * cleared by a human, never silently by Finalize.
     *
     * @param array{posts:list<int>, options:list<string>} $deleted
     */
    private function finalizeCounterpart( int $legacyId, string $newType, array &$deleted ): int {
        $newId = Crosswalk::findNewByLegacyId( $legacyId, $newType );
        if ( $newId === null ) {
            // No counterpart — never delete a legacy id without a verified counterpart.
            return 0;
        }

        // Defence-in-depth: an unresolved divergence flag blocks both the legacy
        // delete and the back-ref strip for this record (human-clear required).
        if ( $this->hasUnresolvedDivergence( $newId ) ) {
            return 0;
        }

        // Delete the verified legacy source (force — past trash).
        if ( get_post( $legacyId ) instanceof \WP_Post ) {
            wp_delete_post( $legacyId, true );
            $deleted['posts'][] = $legacyId;
        }

        // Strip ONLY the allowlist back-refs from the migrated counterpart. Never
        // LEGACY_POST_CONTENT, never the MIGRATION_FLAGS row.
        $stripped = 0;
        foreach ( self::stripAllowlist() as $key ) {
            // Count the rows that actually existed before deletion (a record may not
            // carry every allowlist key, e.g. a podcast vs a sermon).
            $existing = get_post_meta( $newId, $key, false );
            if ( is_array( $existing ) && $existing !== array() ) {
                $stripped += count( $existing );
            }
            delete_post_meta( $newId, $key );
        }

        return $stripped;
    }

    /**
     * Whether a migrated post's canonical MIGRATION_FLAGS row carries an unresolved
     * post_content_divergence flag — the one flag that must be HUMAN-cleared before
     * its row may be touched.
     */
    private function hasUnresolvedDivergence( int $newId ): bool {
        $flags = get_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, true );
        if ( ! is_array( $flags ) ) {
            return false;
        }
        foreach ( $flags as $flag ) {
            $flag = (string) $flag;
            if ( $flag === 'post_content_divergence' || str_starts_with( $flag, 'post_content_divergence:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The legacy option names to delete at finalize — the live sermonmanager_* set,
     * the legacy artwork options, and the legacy default-podcast pointer. These are
     * pure legacy options the migration NEVER wrote; their sermonator_* targets were
     * verified present, so the originals can go.
     *
     * @return list<string>
     */
    private function legacyOptionsToDelete(): array {
        global $wpdb;

        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%'
            )
        );
        $names = array_values( array_map( 'strval', (array) $names ) );

        $names[] = LegacyIdentifiers::OPTION_TERM_IMAGES;
        $names[] = LegacyIdentifiers::OPTION_TERM_IMAGES_SETTINGS;
        $names[] = LegacyIdentifiers::OPTION_DEFAULT_PODCAST;

        return array_values( array_unique( $names ) );
    }

    /**
     * Recount the deferred native shared term_taxonomy_ids exactly once
     * (native_term_recount_tt_ids recorded by SermonWriter::mirrorNativeTaxonomies).
     * mirrorNativeTaxonomies inserted those native relationship rows WITHOUT bumping
     * the shared wp_term_taxonomy.count; at the point of no return we settle the
     * shared count to its TRUE live value so the church's own counts are correct.
     * Only existing tt_ids are recounted.
     */
    private function recountNativeTtIds(): void {
        global $wpdb;

        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress )
            || ! isset( $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] )
            || ! is_array( $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids'] ) ) {
            return;
        }

        $ttIds = array_values( array_unique( array_map(
            'intval',
            $progress[ SermonWriter::PROGRESS_KEY ]['native_term_recount_tt_ids']
        ) ) );

        foreach ( $ttIds as $ttId ) {
            $taxonomy = $wpdb->get_var(
                $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
            );
            if ( $taxonomy === null ) {
                continue;
            }
            wp_update_term_count_now( array( $ttId ), (string) $taxonomy );
        }
    }

    /**
     * All legacy post ids of a type, ascending, status-agnostic. Read-only.
     *
     * @return list<int>
     */
    private function legacyPostIds( string $legacyType ): array {
        $ids = get_posts( array(
            'post_type'      => $legacyType,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ) );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /**
     * Build a refusal result that deletes nothing.
     *
     * @param array{posts:list<int>, options:list<string>} $deleted
     * @return array{deleted:array{posts:list<int>, options:list<string>}, stripped:int, refused:string}
     */
    private function refuse( array $deleted, string $reason ): array {
        return array(
            'deleted'  => $deleted,
            'stripped' => 0,
            'refused'  => $reason,
        );
    }
}
