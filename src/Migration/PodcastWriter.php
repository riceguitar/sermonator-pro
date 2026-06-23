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
 *    legacy term id → new term id). A string-valued (serialized) settings row is
 *    maybe_unserialize()d first so the legacy serialized-string shape is remapped
 *    and re-stored as an array rather than copied verbatim with dangling refs;
 *  - MIGRATION_COMPLETE is written LAST, after every step, but is WITHHELD while a
 *    missing_podcast_term_crosswalk:* flag is open (a feed scoped to a not-yet-
 *    migrated legacy term): the record stays stamped-but-PARTIAL so the gate
 *    resumes it and applyMeta() re-remaps the term once it is migrated (self-heal),
 *    clearing the flag — never a feed scoped to a dead legacy term forever.
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
                // COMPLETE — normally a no-op skip. But a podcast's feed-scope term
                // references live inside settings meta; if a record was somehow
                // stamped COMPLETE while a filter-term crosswalk was still missing
                // (e.g. an older writer that did not withhold COMPLETE), re-run ONLY
                // the meta remap so the now-available term self-heals and the open
                // missing_podcast_term_crosswalk flag clears. A completed record with
                // NO open term flag is left entirely untouched (no re-write).
                $persisted = $this->readFlags( $existing );
                if ( $this->hasOpenTermCrosswalkFlag( $persisted ) ) {
                    $flags = array_merge(
                        $this->stripTermCrosswalkFlags( $persisted ),
                        $this->applyMeta( $existing, $legacyId )
                    );
                    $flags = array_values( array_unique( $flags ) );
                    $this->writeFlags( $existing, $flags );
                    return new WriteResult( $existing, false, $flags, false );
                }
                return new WriteResult( $existing, false, $persisted, false );
            }

            // Stamped but PARTIAL — RESUME on the existing post (never insert).
            $flags = $this->applyPostInsertSpine( $existing, $legacyId, $this->readFlags( $existing ) );
            // COMPLETE is WITHHELD while a podcast filter term is still unresolved
            // so the record stays in this resume leg and applyMeta() re-remaps it on
            // the next write (self-heal) once the term is migrated — never a feed
            // scoped to a dead legacy term, stamped-complete-and-skipped-forever.
            $this->markCompleteUnlessTermCrosswalkOpen( $existing, $flags );

            return new WriteResult( $existing, false, $flags, true );
        }

        // --- Insert a fresh post ---
        $legacy = get_post( $legacyId );
        if ( ! $legacy instanceof \WP_Post ) {
            return new WriteResult( 0, false, array( 'legacy_post_missing:' . $legacyId ) );
        }

        // POST-LEVEL CRASH-ORPHAN RECOVERY (mirrors SermonWriter). The fresh insert
        // below writes the LEGACY_POST_ID back-ref ATOMICALLY via meta_input, so
        // the post-then-back-ref window is closed going forward. A podcast left
        // back-ref-less by an OLDER writer is invisible to the authoritative
        // back-ref probe above and would otherwise be DUPLICATED. Probe for our own
        // back-ref-less orphan matching this legacy identity and ADOPT it (stamp the
        // back-ref, re-enter the spine) rather than minting a second feed post.
        $orphanId = $this->findBackRefLessPostByLegacyIdentity( $legacy );
        if ( null !== $orphanId ) {
            Crosswalk::markLegacy( $orphanId, $legacyId );
            $flags = $this->applyPostInsertSpine( $orphanId, $legacyId, $this->readFlags( $orphanId ) );
            $this->markCompleteUnlessTermCrosswalkOpen( $orphanId, $flags );

            return new WriteResult( $orphanId, false, $flags, true );
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
            // ATOMIC back-ref: write LEGACY_POST_ID in the SAME insert call so post
            // existence and the back-ref are one operation — a crash can no longer
            // leave a back-ref-less, duplicate-prone orphan. markLegacy still runs
            // in the spine (unique=true → no-op here, idempotent on resume).
            'meta_input'     => array( Crosswalk::LEGACY_POST_ID => $legacyId ),
        );

        $newId = $this->insertKsesSafe( $postarr );
        if ( 0 === $newId ) {
            return new WriteResult( 0, false, array( 'insert_failed:' . $legacyId ) );
        }

        // --- Crash-safety spine: back-ref FIRST, then idempotent meta. ---
        $flags = $this->applyPostInsertSpine( $newId, $legacyId, array() );

        // MIGRATION_COMPLETE is written LAST — but WITHHELD while a podcast filter
        // term is still unresolved (missing_podcast_term_crosswalk:*). Stamping
        // COMPLETE with that flag open would scope the feed to a dead legacy term id
        // forever and route every re-run to the no-op COMPLETE branch (the term
        // would never self-heal). Leaving the record stamped-but-PARTIAL keeps it in
        // the resume leg so applyMeta() re-remaps it once the term is migrated.
        $this->markCompleteUnlessTermCrosswalkOpen( $newId, $flags );

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

        // Strip any prior missing_podcast_term_crosswalk:* flags before re-deriving
        // them from this pass's remap, so a term that has since been migrated clears
        // its open flag (self-heal) — mirroring SermonWriter's term-flag strip.
        $flags = $this->stripTermCrosswalkFlags( $flags );
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
                if ( $isSettings ) {
                    // SM historically stores sm_podcast_settings as a SERIALIZED
                    // STRING, not an array. get_post_meta(...,false) returns that
                    // verbatim string (WP auto-unserializes only one level). If the
                    // string maybe_unserialize()s to an array, remap + re-store as an
                    // array (core re-serializes); otherwise record a flag so the
                    // unremapped settings are not silently shipped with dangling refs.
                    if ( is_string( $value ) ) {
                        $maybe = maybe_unserialize( $value );
                        if ( is_array( $maybe ) ) {
                            $value = $this->remapSettingsTerms( $maybe, $flags );
                        } else {
                            $flags[] = 'podcast_settings_unremapped';
                        }
                    } elseif ( is_array( $value ) ) {
                        $value = $this->remapSettingsTerms( $value, $flags );
                    }
                }
                // get_post_meta(...,false) values are UNSLASHED; add_post_meta()'s
                // add_metadata() wp_unslash()es its input, so we MUST wp_slash() here
                // or a backslash level is stripped (enclosure/audio UNC paths, escaped
                // quotes in itunes_* fields, serialized-string values). For the settings
                // path the remap above runs FIRST, then we wp_slash the resulting value;
                // wp_slash() recurses into arrays so the remapped settings array
                // round-trips byte-exact. Mirrors the SermonWriter post-meta discipline.
                add_post_meta( $newId, $newKey, wp_slash( $value ) );
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
     *  - an unresolved term id (never migrated) is left in place so the feed scope
     *    is not silently dropped (recoverable) AND records a
     *    missing_podcast_term_crosswalk:<id> flag so the record can be WITHHELD from
     *    MIGRATION_COMPLETE and self-healed once the term is migrated. A non-term
     *    key passes through verbatim.
     *
     * Non-taxonomy keys (itunes_author, explicit, …) are copied unchanged.
     *
     * @param array<mixed>  $settings
     * @param list<string>  $flags    Threaded by reference; unresolved term ids append a flag.
     * @return array<mixed>
     */
    private function remapSettingsTerms( array $settings, array &$flags ): array {
        $taxonomyMap = MappingContract::taxonomyMap();
        $out         = array();

        foreach ( $settings as $key => $value ) {
            if ( is_string( $key ) && isset( $taxonomyMap[ $key ] ) ) {
                $newKey      = $taxonomyMap[ $key ];
                $out[ $newKey ] = $this->remapTermValue( $value, $flags );
                continue;
            }
            $out[ $key ] = $value;
        }

        return $out;
    }

    /**
     * Translate a legacy term-id reference (scalar or list) to the new term id(s).
     * An unresolved id is left as-is (never a silent drop) AND records a
     * missing_podcast_term_crosswalk:<id> flag so the caller can withhold COMPLETE
     * and self-heal it on a later pass once the term is migrated.
     *
     * @param mixed        $value
     * @param list<string> $flags Threaded by reference.
     * @return mixed
     */
    private function remapTermValue( $value, array &$flags ) {
        if ( is_array( $value ) ) {
            $mapped = array();
            foreach ( $value as $k => $v ) {
                $mapped[ $k ] = $this->remapTermValue( $v, $flags );
            }
            return $mapped;
        }

        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            $new = $this->terms->newTermId( (int) $value );
            if ( null !== $new ) {
                return is_string( $value ) ? (string) $new : $new;
            }
            // Unresolved — never a silent drop. Leave the legacy id in place and
            // flag it so COMPLETE is withheld and the id self-heals later.
            $flags[] = 'missing_podcast_term_crosswalk:' . (int) $value;
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

    /**
     * Stamp MIGRATION_COMPLETE only when no missing_podcast_term_crosswalk:* flag
     * is open. A podcast feed scoped to an unresolved (not-yet-migrated) legacy
     * term must NOT be stamped complete: doing so would scope the feed to a dead
     * legacy term id forever (undetectable by the Verifier) and route every re-run
     * to the no-op COMPLETE branch so the term never self-heals. Leaving the record
     * stamped-but-PARTIAL keeps it in the resume leg so applyMeta() re-remaps it on
     * the next write once the term is migrated — mirroring SermonWriter's
     * complete-unless-open-flag discipline.
     *
     * @param list<string> $flags
     */
    private function markCompleteUnlessTermCrosswalkOpen( int $newId, array $flags ): void {
        if ( $this->hasOpenTermCrosswalkFlag( $flags ) ) {
            return;
        }
        $this->markComplete( $newId );
    }

    /**
     * Whether any OPEN podcast filter-term crosswalk flag is present
     * (missing_podcast_term_crosswalk:*). Gates COMPLETE so a feed scoped to a
     * dead legacy term is never stamped-complete-and-skipped-forever.
     *
     * @param list<string> $flags
     */
    private function hasOpenTermCrosswalkFlag( array $flags ): bool {
        foreach ( $flags as $flag ) {
            if ( str_starts_with( (string) $flag, 'missing_podcast_term_crosswalk:' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop previously-derived missing_podcast_term_crosswalk:* flags so a remap
     * pass can re-derive them from the current crosswalk state (a term that has
     * since been migrated clears its open flag — self-heal).
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function stripTermCrosswalkFlags( array $flags ): array {
        return array_values( array_filter(
            $flags,
            static function ( $flag ): bool {
                return ! str_starts_with( (string) $flag, 'missing_podcast_term_crosswalk:' );
            }
        ) );
    }

    /**
     * Find OUR post-level crash orphan: a sermonator_podcast post matching the
     * legacy source's identity (GMT date + title) but carrying NO LEGACY_POST_ID
     * back-ref — the signature of a post inserted under an older writer whose run
     * died before the separate markLegacy stamp. post_name is excluded (WordPress
     * may uniquify the slug). Reads $wpdb directly (cache-safe). Strict to avoid
     * mis-adopting a distinct podcast; an adopt onto a byte-identical native post
     * only stamps a back-ref (no content loss).
     *
     * @return int|null The orphan post id to adopt, or null if none.
     */
    private function findBackRefLessPostByLegacyIdentity( \WP_Post $legacy ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p"
                . " LEFT JOIN {$wpdb->postmeta} backref"
                . "   ON backref.post_id = p.ID AND backref.meta_key = %s"
                . " WHERE p.post_type = %s"
                . "   AND p.post_date_gmt = %s"
                . "   AND p.post_title = %s"
                . "   AND backref.meta_id IS NULL"
                . " ORDER BY p.ID ASC",
                Crosswalk::LEGACY_POST_ID,
                Identifiers::POST_TYPE_PODCAST,
                $legacy->post_date_gmt,
                $legacy->post_title
            )
        );

        $ids = array_values( array_map( 'intval', (array) $ids ) );

        return $ids === array() ? null : $ids[0];
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
