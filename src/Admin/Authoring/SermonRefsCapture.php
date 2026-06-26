<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Bible\RefsCapture;
use Sermonator\Schema\Identifiers;

/**
 * Save-time structured-reference auto-capture for authored sermons.
 *
 * Mirrors {@see SermonDateNormalizer}: it runs on save_post and REST insert so that a
 * sermon authored in wp-admin or via the block editor gains the structured
 * {@see Identifiers::META_BIBLE_REFS} envelope (+ {@see Identifiers::TAX_BOOK} terms)
 * derived from its {@see Identifiers::META_BIBLE_PASSAGE} free text — instead of those
 * refs existing ONLY for sermons the migration backfill happened to touch.
 *
 * Without this hook the bounded backfill parser stays the permanent primary path for
 * 100% of sermons (design §2, Risk #2). With it, the structured envelope is the norm
 * and the backfill is the shrinking tail it was meant to be.
 *
 * It is a thin, gated wrapper around the SINGLE shared producer {@see RefsCapture}
 * (one schema, multiple producers — design §3): all envelope/sentinel/term logic
 * lives there, so a save and a backfill emit byte-identical refs for the same passage
 * (modulo the per-ref `source` tag). The guards here are the same two the rest of the
 * authoring write surface uses:
 *  - {@see MigrationGuard::editingAllowed()} — inert during an active migration so a
 *    stray native-meta write cannot diverge a record from the Verifier's fixity manifest.
 *  - `current_user_can('edit_post')` — a save-time auto-write must respect post caps.
 *
 * The producer is fill-missing / never-overwrite-authoring / never-mutates the
 * passage, so re-saving a sermon whose refs were author-confirmed (Phase 3b) leaves
 * them untouched. NEVER touches `bible_passage` — the preserved label and parser input
 * (#1 data preservation). A save-time auto-parse is deliberately low-stakes: a
 * mis-parsed LINK is fail-open-able and the raw passage is preserved; the human
 * confirm-chip flow where inline TEXT is at stake is Phase 3b.
 */
final class SermonRefsCapture {
    private RefsCapture $capture;

    public function __construct( ?RefsCapture $capture = null ) {
        $this->capture = $capture ?? new RefsCapture();
    }

    public function hook(): void {
        // Priority 20 — AFTER SermonMetaBox::save() (priority 10) on the classic
        // full-page POST path. The submitted passage is one of the metabox's
        // editableMetaKeys, so it is only persisted once SermonMetaBox::save() runs;
        // capturing at the same priority (and registered earlier) would read a stale
        // passage and noop, forcing a second save before the envelope appears. Unlike
        // SermonDateNormalizer (whose input, the publish date, already exists on the
        // WP_Post before any save_post handler), refsCapture's input is written by a
        // sibling handler — so it must run after it. The rest_after_insert path needs
        // no ordering fix: META_BIBLE_PASSAGE is REST-registered and already persisted
        // by the time rest_after_insert fires.
        add_action(
            'save_post_' . Identifiers::POST_TYPE_SERMON,
            array( $this, 'capture' ),
            20,
            2
        );
        add_action(
            'rest_after_insert_' . Identifiers::POST_TYPE_SERMON,
            array( $this, 'captureRest' ),
            10,
            1
        );
    }

    public function capture( int $post_id, \WP_Post $post ): void {
        if ( $post->post_type !== Identifiers::POST_TYPE_SERMON ) {
            return;
        }
        $this->apply( $post_id );
    }

    public function captureRest( \WP_Post $post ): void {
        if ( $post->post_type !== Identifiers::POST_TYPE_SERMON ) {
            return;
        }
        $this->apply( $post->ID );
    }

    private function apply( int $post_id ): void {
        if ( ! MigrationGuard::editingAllowed() ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // The UNPARSEABLE sentinel was designed for the one-shot backfill over FROZEN
        // legacy passages, where the input never changes, so an in-producer sentinel
        // check is correctly eternal-idempotent. On the live authoring surface the
        // passage is EDITABLE: if an author first saves a passage that parses to zero
        // refs (a typo, "see bulletin") the producer stamps the sentinel, and without
        // this clear the producer's sentinel short-circuit would permanently trap the
        // post — a later correction to a valid reference would never produce structured
        // refs / TAX_BOOK terms / the inline LINK. Clearing it here re-evaluates the
        // CURRENT passage on every authoring save while leaving the backfill's
        // idempotency over frozen data untouched (it never deletes the sentinel first).
        // Net effect for a still-unparseable passage is unchanged: the producer simply
        // re-stamps it. NEVER touches META_BIBLE_PASSAGE or an existing REFS envelope.
        delete_post_meta( $post_id, Identifiers::META_BIBLE_REFS_UNPARSEABLE );

        $this->capture->captureForPost( $post_id, 'authoring' );
    }
}
