<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Writes one legacy wpfc_sermon into a new sermonator_sermon, non-destructively,
 * idempotently, and crash-safely.
 *
 * THIS TASK (12) covers the post/body/idempotency facet of write():
 *  - the idempotency gate (resolve via Crosswalk::findNewByLegacyId, status-
 *    agnostic) distinguishes a COMPLETE record (skip — return existing,
 *    created=false) from a stamped-but-PARTIAL record (resume — re-enter on the
 *    existing post, never insert a second one);
 *  - the post is inserted preserving the legacy post columns, with the body
 *    reconciled from (post_content, sermon_description, post_content_temp);
 *  - the insert is wp_slash'd and run with KSES DISABLED so iframes/shortcodes
 *    in preserved content survive verbatim, then KSES is restored;
 *  - the legacy back-ref is stamped IMMEDIATELY after insert (crash-safety spine);
 *  - MIGRATION_COMPLETE is NOT written here — Tasks 13/14 add meta/terms/comments
 *    and write COMPLETE LAST, so an abort between insert and COMPLETE is resumed.
 *
 * Legacy data is read READ-ONLY; shared attachment posts are referenced by id and
 * never mutated.
 */
final class SermonWriter {
    private ?DateNormalizer $dates;

    public function __construct( ?DateNormalizer $dates = null ) {
        // DateNormalizer is a pure static helper (non-instantiable); the optional
        // instance is accepted for dependency-injection symmetry per the
        // documented interface and is unused while normalize() is called
        // statically (Task 13 wires the date companion rows).
        $this->dates = $dates;
    }

    public function write( int $legacyId ): WriteResult {
        // --- Idempotency gate (status-agnostic, authoritative via back-ref) ---
        //
        // Three observably-distinct outcomes (see WriteResult):
        //   1. no back-ref            -> insert a fresh post (created=true)
        //   2. back-ref + COMPLETE    -> skip (created=false, resumed=false): a
        //                                 no-op here; self-healing steps in later
        //                                 tasks may still re-run, but never a
        //                                 second insert.
        //   3. back-ref but NOT done  -> resume (created=false, resumed=true):
        //                                 re-enter the post-insert / self-healing
        //                                 block on the EXISTING post id; never a
        //                                 second insert.
        $existing = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON );
        if ( null !== $existing ) {
            if ( $this->isComplete( $existing ) ) {
                // COMPLETE — skip; do NOT touch the post (already self-healed).
                return new WriteResult( $existing, false, $this->readFlags( $existing ), false );
            }

            // Stamped but PARTIAL — RESUME on the existing post (never insert).
            // Re-enter the same back-ref-first / self-healing block we run after a
            // fresh insert, idempotently re-driving it on $existing. Tasks 13/14
            // extend this resume path (meta/terms/comments) without retrofitting
            // the gate: they hook the post-insert block, which the resume branch
            // already routes through.
            $legacyForResume = get_post( $legacyId );
            if ( ! $legacyForResume instanceof \WP_Post ) {
                // Legacy source vanished after a partial stamp — surface, do not crash.
                return new WriteResult( $existing, false, $this->readFlags( $existing ), false );
            }

            // Seed from the persisted flags so replace-semantics on writeFlags()
            // never drops a prior flag (e.g. post_parent_unresolved) that this
            // resume pass does not re-derive.
            $reconciledForResume = $this->reconcileBody( $legacyId, $legacyForResume );
            $flags               = $this->applyPostInsertSpine( $existing, $legacyId, $legacyForResume, $reconciledForResume, $this->readFlags( $existing ) );

            return new WriteResult( $existing, false, $flags, true );
        }

        // --- Insert a fresh post ---
        $legacy = get_post( $legacyId );
        if ( ! $legacy instanceof \WP_Post ) {
            // Nothing to migrate; surface a deterministic, non-crashing result.
            return new WriteResult( 0, false, array( 'legacy_post_missing:' . $legacyId ) );
        }

        $flags = array();

        // Body reconciliation from (post_content, sermon_description, post_content_temp).
        $reconciled = $this->reconcileBody( $legacyId, $legacy );

        // post_parent translation: a non-zero legacy parent is translated through
        // the crosswalk; an untranslatable parent collapses to 0 + a flag (never a
        // dangling legacy id).
        $newParent = 0;
        $legacyParent = (int) $legacy->post_parent;
        if ( 0 !== $legacyParent ) {
            $translated = Crosswalk::findNewByLegacyId( $legacyParent, Identifiers::POST_TYPE_SERMON );
            if ( null !== $translated ) {
                $newParent = $translated;
            } else {
                $flags[] = 'post_parent_unresolved:' . $legacyParent;
            }
        }

        $postarr = array(
            'post_type'      => Identifiers::POST_TYPE_SERMON,
            'post_title'     => $legacy->post_title,
            'post_author'    => $legacy->post_author,
            'post_date'      => $legacy->post_date,
            'post_date_gmt'  => $legacy->post_date_gmt,
            'post_status'    => $legacy->post_status,
            'post_name'      => $legacy->post_name,
            'comment_status' => $legacy->comment_status,
            'ping_status'    => $legacy->ping_status,
            'menu_order'     => $legacy->menu_order,
            'post_excerpt'   => $legacy->post_excerpt,
            'post_password'  => $legacy->post_password,
            'post_parent'    => $newParent,
            'post_content'   => $reconciled['content'],
        );

        $newId = $this->insertKsesSafe( $postarr );
        if ( 0 === $newId ) {
            return new WriteResult( 0, false, array( 'insert_failed:' . $legacyId ) );
        }

        // --- Crash-safety spine: back-ref FIRST, then idempotent self-healing. ---
        $flags = $this->applyPostInsertSpine( $newId, $legacyId, $legacy, $reconciled, $flags );

        // NOTE: MIGRATION_COMPLETE is intentionally NOT written here (Task 14).

        return new WriteResult( $newId, true, $flags );
    }

    /**
     * Reconcile the body from (post_content, sermon_description, post_content_temp),
     * reading the two companion meta rows READ-ONLY.
     *
     * @return array{content: string, backup: ?string, flag: bool}
     */
    private function reconcileBody( int $legacyId, \WP_Post $legacy ): array {
        $description     = $this->readSingleMeta( $legacyId, LegacyIdentifiers::META_DESCRIPTION );
        $postContentTemp = $this->readSingleMeta( $legacyId, LegacyIdentifiers::META_POST_CONTENT_TEMP );

        return PostContentReconciler::reconcile(
            (string) $legacy->post_content,
            $description,
            $postContentTemp
        );
    }

    /**
     * The crash-safety spine + idempotent self-healing, applied to a new post id
     * whether it was just inserted OR is being resumed (re-entered on a stamped-
     * but-partial post). Every write here is single-row / unique, so re-driving it
     * on an existing post produces zero duplicate meta rows.
     *
     *  - back-ref FIRST (markLegacy uses unique=true: a no-op if already stamped);
     *  - preserve the reconciler's backup body into LEGACY_POST_CONTENT (unique);
     *  - record LEGACY_SLUG + a slug_changed flag on drift (unique);
     *  - persist the canonical MIGRATION_FLAGS row (replace semantics).
     *
     * Tasks 13/14 extend THIS block (meta/terms/comments) so both the fresh-insert
     * and the resume path drive the same steps forward.
     *
     * @param array{content: string, backup: ?string, flag: bool} $reconciled
     * @param list<string>                                         $flags
     * @return list<string>
     */
    private function applyPostInsertSpine( int $newId, int $legacyId, \WP_Post $legacy, array $reconciled, array $flags = array() ): array {
        // Back-ref FIRST, immediately after insert (unique → idempotent on resume).
        Crosswalk::markLegacy( $newId, $legacyId );

        // Preserve any substantive old body the reconciler routed to backup. The
        // unique=true guard means a resume re-entry never adds a duplicate row;
        // re-derive the flag from the persisted row so a resume reports it too.
        if ( null !== $reconciled['backup'] ) {
            add_post_meta( $newId, Crosswalk::LEGACY_POST_CONTENT, $reconciled['backup'], true );
        }
        if ( '' !== (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_CONTENT, true ) ) {
            $flags[] = 'post_content_preserved';
        }

        // Slug drift: WP may uniquify post_name on insert. Record the original
        // legacy slug and flag the change.
        $insertedSlug = (string) get_post_field( 'post_name', $newId );
        $originalSlug = (string) $legacy->post_name;
        if ( '' !== $originalSlug ) {
            add_post_meta( $newId, Crosswalk::LEGACY_SLUG, $originalSlug, true );
            if ( $insertedSlug !== $originalSlug ) {
                $flags[] = 'slug_changed';
            }
        }

        $this->writeFlags( $newId, $flags );

        return array_values( array_unique( $flags ) );
    }

    /**
     * Insert a post with KSES disabled so structural/media HTML (iframes, etc.)
     * survives verbatim, then restore KSES. The post array is wp_slash'd so
     * quotes/backslashes/unicode are not corrupted by wp_insert_post's unslash.
     */
    private function insertKsesSafe( array $postarr ): int {
        kses_remove_filters();
        try {
            $newId = wp_insert_post( wp_slash( $postarr ), true );
        } finally {
            kses_init_filters();
        }

        if ( is_wp_error( $newId ) ) {
            return 0;
        }

        return (int) $newId;
    }

    /** Whether a migrated post has been marked complete. */
    private function isComplete( int $newId ): bool {
        return '' !== (string) get_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, true );
    }

    /** Read a single legacy meta value (first value), READ-ONLY. */
    private function readSingleMeta( int $legacyId, string $key ): ?string {
        $values = get_post_meta( $legacyId, $key, false );
        if ( ! is_array( $values ) || $values === array() ) {
            return null;
        }
        return is_string( $values[0] ) ? $values[0] : (string) $values[0];
    }

    /**
     * Persist the migration flags as a single canonical MIGRATION_FLAGS row
     * (replace semantics — idempotent, never accumulates duplicate rows).
     *
     * @param list<string> $flags
     */
    private function writeFlags( int $newId, array $flags ): void {
        $flags = array_values( array_unique( $flags ) );
        if ( $flags === array() ) {
            return;
        }
        update_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, $flags );
    }

    /** @return list<string> */
    private function readFlags( int $newId ): array {
        $stored = get_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, true );
        return is_array( $stored ) ? array_values( $stored ) : array();
    }
}
