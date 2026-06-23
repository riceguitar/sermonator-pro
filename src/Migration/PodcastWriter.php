<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Writes one legacy wpfc_sm_podcast into a new sermonator_podcast, non-
 * destructively, idempotently, and crash-safely — reusing the SermonWriter
 * disciplines for the podcast post type.
 *
 * write() is the full pipeline:
 *  - the idempotency gate (resolve via Crosswalk::findNewByLegacyId scoped to the
 *    PODCAST post type, status-agnostic) distinguishes a COMPLETE record (a no-op
 *    skip) from a stamped-but-PARTIAL record (resume — re-enter on the existing
 *    post, never insert a second one);
 *  - the post is inserted preserving the legacy post columns, wp_slash'd and run
 *    with KSES DISABLED so an iframe/shortcode in the feed body survives verbatim,
 *    then KSES is restored;
 *  - the legacy back-ref (LEGACY_POST_ID) is stamped IMMEDIATELY after insert
 *    (crash-safety spine) so allMigratedPostIds()/rollback cover the podcast;
 *  - meta is applied from the per-key UNSERIALIZED values so sm_podcast_settings
 *    round-trips as an array (core re-serializes) rather than being double-
 *    serialized. The settings key is renamed sm_podcast_settings →
 *    sermonator_podcast_settings, and any taxonomy/term reference inside it is
 *    remapped through TermCrosswalk (legacy taxonomy slug → new taxonomy slug,
 *    legacy term id → new term id);
 *  - MIGRATION_COMPLETE is written LAST, after every step, so an abort anywhere
 *    before it leaves a stamped-but-partial post the gate resumes.
 *
 * Legacy data (posts, meta) is read READ-ONLY; shared attachment posts are
 * referenced by id and never mutated.
 */
final class PodcastWriter {
    private TermCrosswalk $terms;

    public function __construct( ?TermCrosswalk $terms = null ) {
        $this->terms = $terms ?? new TermCrosswalk();
    }

    public function write( int $legacyId ): WriteResult {
        // --- Idempotency gate (status-agnostic, authoritative via back-ref) ---
        $existing = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_PODCAST );
        if ( null !== $existing ) {
            $legacyForResume = get_post( $legacyId );
            if ( ! $legacyForResume instanceof \WP_Post ) {
                // Legacy source vanished after a partial stamp — surface, do not crash.
                return new WriteResult( $existing, false, $this->readFlags( $existing ), false );
            }

            if ( $this->isComplete( $existing ) ) {
                // COMPLETE — a no-op skip. A podcast has no self-healing term/comment
                // steps (its term references live inside settings meta, already
                // remapped on the writing pass), so a completed podcast is left
                // entirely untouched.
                return new WriteResult( $existing, false, $this->readFlags( $existing ), false );
            }

            // Stamped but PARTIAL — RESUME on the existing post (never insert).
            $flags = $this->applyPostInsertSpine( $existing, $legacyId, $this->readFlags( $existing ) );
            $this->markComplete( $existing );

            return new WriteResult( $existing, false, $flags, true );
        }

        // --- Insert a fresh post ---
        $legacy = get_post( $legacyId );
        if ( ! $legacy instanceof \WP_Post ) {
            return new WriteResult( 0, false, array( 'legacy_post_missing:' . $legacyId ) );
        }

        $postarr = array(
            'post_type'      => Identifiers::POST_TYPE_PODCAST,
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
            'post_content'   => $legacy->post_content,
        );

        $newId = $this->insertKsesSafe( $postarr );
        if ( 0 === $newId ) {
            return new WriteResult( 0, false, array( 'insert_failed:' . $legacyId ) );
        }

        // --- Crash-safety spine: back-ref FIRST, then idempotent meta. ---
        $flags = $this->applyPostInsertSpine( $newId, $legacyId, array() );

        // MIGRATION_COMPLETE is written LAST.
        $this->markComplete( $newId );

        return new WriteResult( $newId, true, $flags );
    }

    /**
     * The crash-safety spine + idempotent meta, applied to a new post id whether it
     * was just inserted OR is being resumed. Every write here is single-row /
     * delete-then-re-add, so re-driving it on an existing post produces zero
     * duplicate rows.
     *
     *  - back-ref FIRST (markLegacy uses unique=true: a no-op if already stamped);
     *  - record LEGACY_SLUG + a slug_changed flag on drift (unique);
     *  - meta application (settings rename + term remap, verbatim others);
     *  - persist the canonical MIGRATION_FLAGS row (replace semantics).
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function applyPostInsertSpine( int $newId, int $legacyId, array $flags ): array {
        // Back-ref FIRST, immediately after insert (unique → idempotent on resume).
        Crosswalk::markLegacy( $newId, $legacyId );

        // Slug drift: WP may uniquify post_name on insert. Record the original
        // legacy slug and flag the change.
        $legacy = get_post( $legacyId );
        if ( $legacy instanceof \WP_Post ) {
            $originalSlug = (string) $legacy->post_name;
            $insertedSlug = (string) get_post_field( 'post_name', $newId );
            if ( '' !== $originalSlug ) {
                add_post_meta( $newId, Crosswalk::LEGACY_SLUG, $originalSlug, true );
                if ( $insertedSlug !== $originalSlug ) {
                    $flags[] = 'slug_changed';
                }
            }
        }

        $flags = array_merge( $flags, $this->applyMeta( $newId, $legacyId ) );

        $this->writeFlags( $newId, $flags );

        return array_values( array_unique( $flags ) );
    }

    /**
     * Apply the legacy podcast's post meta onto the new post, idempotently.
     *
     *  - the WRITE VALUES are sourced from the per-key UNSERIALIZED
     *    get_post_meta($id,$key,false) form (never the no-key raw-serialized
     *    values), so sm_podcast_settings round-trips as an array;
     *  - sm_podcast_settings is renamed to sermonator_podcast_settings, with every
     *    taxonomy/term reference inside it remapped via the term crosswalk;
     *  - every other key passes through verbatim under the same key;
     *  - every key is rewritten with delete-then-re-add of the FULL multiset, so a
     *    resume re-run produces zero duplicate / accumulated rows.
     *
     * Legacy meta is read READ-ONLY throughout.
     *
     * @return list<string>
     */
    private function applyMeta( int $newId, int $legacyId ): array {
        $flags    = array();
        $rawByKey = get_post_meta( $legacyId );
        $rawByKey = is_array( $rawByKey ) ? $rawByKey : array();

        foreach ( array_keys( $rawByKey ) as $legacyKey ) {
            // Never carry our OWN back-ref / state rows across (a podcast being
            // resumed already has them; the legacy podcast never does).
            if ( $this->isOwnMetaKey( (string) $legacyKey ) ) {
                continue;
            }

            $isSettings = ( LegacyIdentifiers::META_PODCAST_SETTINGS === $legacyKey );
            $newKey     = $isSettings ? Identifiers::META_PODCAST_SETTINGS : $legacyKey;

            // UNSERIALIZED per-key values.
            $values = get_post_meta( $legacyId, $legacyKey, false );
            $values = is_array( $values ) ? array_values( $values ) : array();

            delete_post_meta( $newId, $newKey );
            foreach ( $values as $value ) {
                if ( $isSettings && is_array( $value ) ) {
                    $value = $this->remapSettingsTerms( $value );
                }
                add_post_meta( $newId, $newKey, $value );
            }
        }

        return array_values( array_unique( $flags ) );
    }

    /**
     * Remap any taxonomy/term reference inside a sm_podcast_settings array via the
     * term crosswalk. A Sermon Manager podcast feed can be scoped to a taxonomy
     * term: the settings carry the LEGACY taxonomy slug as a key whose value is a
     * legacy term id (or a list of them). We:
     *
     *  - rename each legacy taxonomy-slug key to its NEW taxonomy slug;
     *  - translate each legacy term id to its NEW term id via TermCrosswalk;
     *  - leave an unresolved term id (never migrated) in place so the feed scope is
     *    not silently dropped — it is recoverable, and a non-term key passes
     *    through verbatim.
     *
     * Non-taxonomy keys (itunes_author, explicit, …) are copied unchanged.
     *
     * @param array<mixed> $settings
     * @return array<mixed>
     */
    private function remapSettingsTerms( array $settings ): array {
        $taxonomyMap = MappingContract::taxonomyMap();
        $out         = array();

        foreach ( $settings as $key => $value ) {
            if ( is_string( $key ) && isset( $taxonomyMap[ $key ] ) ) {
                $newKey      = $taxonomyMap[ $key ];
                $out[ $newKey ] = $this->remapTermValue( $value );
                continue;
            }
            $out[ $key ] = $value;
        }

        return $out;
    }

    /**
     * Translate a legacy term-id reference (scalar or list) to the new term id(s).
     * An unresolved id is left as-is (never a silent drop).
     *
     * @param mixed $value
     * @return mixed
     */
    private function remapTermValue( $value ) {
        if ( is_array( $value ) ) {
            $mapped = array();
            foreach ( $value as $k => $v ) {
                $mapped[ $k ] = $this->remapTermValue( $v );
            }
            return $mapped;
        }

        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            $new = $this->terms->newTermId( (int) $value );
            if ( null !== $new ) {
                return is_string( $value ) ? (string) $new : $new;
            }
        }

        return $value;
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

    /** Whether a meta key is one of OUR back-ref / state rows (never copied from legacy). */
    private function isOwnMetaKey( string $key ): bool {
        return in_array(
            $key,
            array(
                Crosswalk::LEGACY_POST_ID,
                Crosswalk::LEGACY_SLUG,
                Crosswalk::MIGRATION_COMPLETE,
                Crosswalk::MIGRATION_FLAGS,
                Crosswalk::LEGACY_POST_CONTENT,
            ),
            true
        );
    }

    /** Write the MIGRATION_COMPLETE flag LAST (replace/unique — idempotent). */
    private function markComplete( int $newId ): void {
        update_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, '1' );
    }

    /** Whether a migrated post has been marked complete. */
    private function isComplete( int $newId ): bool {
        return '' !== (string) get_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, true );
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
            delete_post_meta( $newId, Crosswalk::MIGRATION_FLAGS );
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
