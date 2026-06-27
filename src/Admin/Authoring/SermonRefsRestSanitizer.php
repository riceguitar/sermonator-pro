<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Bible\RefValidator;
use Sermonator\Bible\RefsCapture;
use Sermonator\Bible\TranslationRegistry;
use Sermonator\Schema\Identifiers;
use WP_REST_Request;

/**
 * Server-side authority for the author-confirmed {@see Identifiers::META_BIBLE_REFS}
 * envelope submitted through the block-editor REST write (the confirm-chip flow,
 * design §3.8 / Task 12).
 *
 * The confirm chips are the ONLY path that promotes a ref to `confidence:'exact'` — the
 * floor that lets a ref render inline verse TEXT (never-fail-WRONG spine). Because that
 * is the highest-trust signal in the system, NONE of its provenance may be taken from
 * the client. The author saving confirmed chips IS the confirmation; the client merely
 * names which structural refs were confirmed. This sanitizer therefore re-derives the
 * trusted fields SERVER-SIDE on every submitted ref:
 *   - `source`                     => `authoring`
 *   - `confidence`                 => `exact`
 *   - `srcVersification`           => the site's CURRENT link version (authored live)
 *   - `srcVersificationConfidence` => `authored` (skips the L6 attestation gate)
 * Any client-supplied value for these is discarded. Only the whitelisted STRUCTURAL
 * fields (book/chapter/verse span + raw label) survive, so a client cannot inject extra
 * keys or smuggle a forged provenance stamp.
 *
 * It also enforces two integrity bounds before stamping:
 *   - DROP any ref the pure {@see RefValidator} rejects (out-of-canon or structurally
 *     invalid) — a confirmed-but-bogus ref must never reach storage.
 *   - CAP the surviving ref count at {@see self::MAX_REFS} so a runaway/abusive submit
 *     cannot bloat the envelope.
 *
 * Storage stays a JSON STRING (matching the shared {@see RefsCapture} producer, the
 * migration backfill, and every downstream consumer + the Verifier's fixity manifest —
 * #1 data preservation). META_BIBLE_REFS is therefore registered show_in_rest as a
 * string; the object/envelope semantics are owned HERE, not by register_post_meta. We
 * re-encode the cleaned envelope back into the request meta so core's meta handler
 * persists the exact server-authored string. It NEVER touches META_BIBLE_PASSAGE.
 *
 * Mirrors {@see SermonMetaRestSanitizer}: rest_pre_insert only (the migration engine's
 * direct writes stay byte-preserving) and inert while a migration is active.
 */
final class SermonRefsRestSanitizer {
    /** Hard upper bound on confirmed refs persisted per sermon. */
    public const MAX_REFS = 50;

    public function hook(): void {
        // Priority 11: after the general SermonMetaRestSanitizer (priority 10), which
        // passes META_BIBLE_REFS through unchanged. The order is not load-bearing (the
        // two touch disjoint keys) but keeping the refs envelope last is deterministic.
        add_filter(
            'rest_pre_insert_' . Identifiers::POST_TYPE_SERMON,
            array( $this, 'sanitize' ),
            11,
            2
        );
    }

    /**
     * @param object          $prepared_post WP_Post or stdClass prepared by the controller.
     * @param WP_REST_Request $request
     * @return object
     */
    public function sanitize( $prepared_post, WP_REST_Request $request ): object {
        if ( ! MigrationGuard::editingAllowed() ) {
            // Inert during an active migration so a stray native-meta write cannot
            // diverge a record from the Verifier's fixity manifest.
            return $prepared_post;
        }

        $meta = $request['meta'] ?? array();
        if ( ! is_array( $meta ) || ! array_key_exists( Identifiers::META_BIBLE_REFS, $meta ) ) {
            return $prepared_post;
        }

        $meta[ Identifiers::META_BIBLE_REFS ] = $this->stamp( $meta[ Identifiers::META_BIBLE_REFS ] );
        $request->set_param( 'meta', $meta );

        return $prepared_post;
    }

    /**
     * Re-decode the submitted envelope, drop invalid refs, cap, and re-stamp the
     * trusted provenance server-side. Returns the cleaned envelope as a JSON STRING, or
     * '' when nothing valid remains (an empty value reads as "no envelope", so the
     * save-time auto-parse path may re-derive from the passage — a safe fall-open).
     *
     * @param mixed $submitted Either a JSON-string envelope or an already-decoded array.
     */
    public function stamp( $submitted ): string {
        $env  = $this->decode( $submitted );
        $refs = ( is_array( $env ) && isset( $env['refs'] ) && is_array( $env['refs'] ) ) ? $env['refs'] : array();

        $srcVersification = TranslationRegistry::current()->linkVersion();
        $clean            = array();

        foreach ( $refs as $ref ) {
            if ( count( $clean ) >= self::MAX_REFS ) {
                break;
            }
            if ( ! is_array( $ref ) ) {
                continue;
            }

            // Whitelist ONLY structural fields — never trust client provenance/extra keys.
            $structural = array(
                'bookUSFM'     => is_string( $ref['bookUSFM'] ?? null ) ? $ref['bookUSFM'] : '',
                'chapterStart' => $this->intOrZero( $ref['chapterStart'] ?? null ),
                'verseStart'   => $this->nullableInt( $ref['verseStart'] ?? null ),
                'verseEnd'     => $this->nullableInt( $ref['verseEnd'] ?? null ),
                'chapterEnd'   => $this->nullableInt( $ref['chapterEnd'] ?? null ),
                'raw'          => is_string( $ref['raw'] ?? null ) ? $ref['raw'] : '',
            );

            $flags = RefValidator::validate( $structural );
            if ( ! $flags['inCanon'] || ! $flags['structurallyValid'] ) {
                continue; // confirmed-but-bogus ref never reaches storage
            }

            // Server-authored provenance — overwrites whatever the client sent. The
            // client's submitted `confidence` is NEVER trusted: a forged
            // `confidence:derived-exact*` (the de-stored render-time tier) is discarded
            // here and the confirm-chip's `exact` is stamped instead (server-side stamp
            // wins, design §3.4). The stamp is additionally routed through the producer's
            // de-store normalizer so a floor-only tier can never reach storage even if this
            // literal ever regressed — the classifier stays the only promotion path.
            $structural['source']                     = 'authoring';
            $structural['confidence']                 = RefsCapture::normalizeStoredConfidence( RefsCapture::STORED_CONFIDENCE_EXACT );
            $structural['srcVersification']           = $srcVersification;
            $structural['srcVersificationConfidence'] = RefsCapture::SRC_VERSIFICATION_CONFIDENCE_AUTHORED;

            $clean[] = $structural;
        }

        if ( array() === $clean ) {
            return '';
        }

        return (string) wp_json_encode(
            array(
                'v'    => RefsCapture::ENVELOPE_VERSION,
                'refs' => array_values( $clean ),
            )
        );
    }

    /**
     * Decode a submitted envelope that may arrive as a JSON string (the editor sends
     * JSON.stringify(envelope)) or as an already-decoded associative array.
     *
     * @param mixed $submitted
     * @return array<string,mixed>|null
     */
    private function decode( $submitted ): ?array {
        if ( is_array( $submitted ) ) {
            return $submitted;
        }
        if ( is_string( $submitted ) && '' !== $submitted ) {
            $decoded = json_decode( $submitted, true );
            return is_array( $decoded ) ? $decoded : null;
        }
        return null;
    }

    /** @param mixed $value */
    private function intOrZero( $value ): int {
        return is_numeric( $value ) ? (int) $value : 0;
    }

    /** @param mixed $value */
    private function nullableInt( $value ): ?int {
        if ( null === $value || '' === $value ) {
            return null;
        }
        return is_numeric( $value ) ? (int) $value : null;
    }
}
