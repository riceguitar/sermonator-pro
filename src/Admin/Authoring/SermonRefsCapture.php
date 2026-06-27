<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Bible\RefsCapture;
use Sermonator\Bible\ReferenceParser;
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
        $this->clearStaleAutoParseEnvelope( $post_id );

        $this->capture->captureForPost( $post_id, 'authoring' );
    }

    /**
     * On the EDITABLE authoring surface an existing AUTO-PARSED envelope must be
     * re-derived from the CURRENT passage on every save. The producer's fill-missing
     * skip is keyed on envelope EXISTENCE, so without this an edit of the passage
     * (e.g. "John 3:16" -> "Romans 5:1") is a permanent no-op and the single-sermon
     * scripture row keeps rendering the OLD reference + link (silently wrong).
     *
     * Phase 3b (Task 12) hardens this to operate PER-REF rather than all-or-nothing.
     * The earlier rule — "if ANY ref is confidence:'exact', preserve the WHOLE envelope"
     * — left an orphaned confirmed verse rendering inline TEXT after the author rewrote
     * the passage to no longer mention it (confirm "John 3:16" → render inline → rewrite
     * passage to "Romans 5:1" → John 3:16 verse text still shown). Under never-fail-WRONG
     * that orphan is the one unacceptable outcome. So we now, per ref:
     *   - auto-parse refs (confidence !== 'exact'): always DROP — the producer re-derives
     *     them from the current passage below;
     *   - author-confirmed refs (confidence === 'exact'): keep ONLY when their
     *     (book, chapter, verseStart) still appears in the CURRENT passage parse; an
     *     exact ref the passage no longer contains is an orphan and is DROPPED.
     * If no exact ref survives we delete the whole envelope so the producer re-derives a
     * fresh auto-parse; if some survive we rewrite the envelope down to exactly those
     * (the producer then no-ops on the still-present envelope, so the confirmed set stays
     * authoritative and is never auto-widened).
     *
     * Falling open (dropping a ref) is always free; the raw passage is preserved and the
     * 3a link still renders. The backfill caller does NOT clear, so its frozen-data
     * idempotency is untouched. NEVER touches META_BIBLE_PASSAGE. (Stale TAX_BOOK terms
     * may linger additively on refresh — never data loss, matching reverse()'s same
     * never-clobber trade-off.)
     */
    private function clearStaleAutoParseEnvelope( int $post_id ): void {
        $raw = (string) get_post_meta( $post_id, Identifiers::META_BIBLE_REFS, true );
        if ( $raw === '' ) {
            return;
        }

        $env  = json_decode( $raw, true );
        $refs = ( is_array( $env ) && isset( $env['refs'] ) && is_array( $env['refs'] ) ) ? $env['refs'] : array();
        if ( array() === $refs ) {
            // Malformed or empty envelope — clear so the producer re-derives cleanly.
            delete_post_meta( $post_id, Identifiers::META_BIBLE_REFS );
            return;
        }

        $passage = (string) get_post_meta( $post_id, Identifiers::META_BIBLE_PASSAGE, true );
        $present = $this->passageVerseKeys( $passage );

        $survivors = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array( $ref ) ) {
                continue;
            }
            if ( ( $ref['confidence'] ?? '' ) !== 'exact' ) {
                continue; // auto-parse ref — producer re-derives from the current passage.
            }
            if ( $this->refStillInPassage( $ref, $present ) ) {
                $survivors[] = $ref;
            }
            // else: orphaned confirmed ref — drop it (never render a verse the passage lost).
        }

        if ( array() === $survivors ) {
            delete_post_meta( $post_id, Identifiers::META_BIBLE_REFS );
            return;
        }

        // Preserve the original envelope version; keep only the surviving confirmed refs.
        $version = ( is_array( $env ) && isset( $env['v'] ) ) ? $env['v'] : RefsCapture::ENVELOPE_VERSION;
        update_post_meta(
            $post_id,
            Identifiers::META_BIBLE_REFS,
            (string) wp_json_encode(
                array(
                    'v'    => $version,
                    'refs' => array_values( $survivors ),
                )
            )
        );
    }

    /**
     * Build the set of (book, chapter, verseStart) keys the CURRENT passage parses to.
     * Only verse-specific refs are keyed — a chapter-only ref has no verse to orphan.
     *
     * @return array<string,true>
     */
    private function passageVerseKeys( string $passage ): array {
        if ( '' === trim( $passage ) ) {
            return array();
        }

        $keys = array();
        foreach ( ReferenceParser::parse( $passage )['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                if ( ! is_array( $ref ) ) {
                    continue;
                }
                $key = $this->verseKey( $ref );
                if ( null !== $key ) {
                    $keys[ $key ] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param array<string,mixed>  $ref
     * @param array<string,true>   $present
     */
    private function refStillInPassage( array $ref, array $present ): bool {
        $key = $this->verseKey( $ref );

        return null !== $key && isset( $present[ $key ] );
    }

    /**
     * The (book, chapter, verseStart) identity key for a ref, or null when it is not a
     * verse-specific reference (no book or no start verse).
     *
     * @param array<string,mixed> $ref
     */
    private function verseKey( array $ref ): ?string {
        $book  = is_string( $ref['bookUSFM'] ?? null ) ? $ref['bookUSFM'] : '';
        $verse = ( isset( $ref['verseStart'] ) && null !== $ref['verseStart'] ) ? (int) $ref['verseStart'] : null;

        if ( '' === $book || null === $verse ) {
            return null;
        }

        $chapter = (int) ( $ref['chapterStart'] ?? 0 );

        return $book . '|' . $chapter . '|' . $verse;
    }
}
