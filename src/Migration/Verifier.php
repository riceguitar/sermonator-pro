<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * The migration's completeness + source-fixity oracle (design-notes item 17).
 *
 * verify(Manifest) proves the migrated result against the detect-time manifest and,
 * ONLY when fully clean, advances the durable state to 'verified' (the precondition
 * the Finalizer re-checks before its sole destructive step). It is strictly
 * READ-ONLY: it never mutates legacy OR migrated primary data, only the phase row.
 *
 * Three independent proofs, each a closed adversarial hole:
 *
 *  (1) SOURCE-FIXITY DRIFT (gating-3). For every legacy post id the manifest
 *      checksummed, recompute LegacyChecksum::forPost and compare to the stored
 *      manifest checksum. A mismatch means the legacy source was edited between
 *      detect and migrate — the migrated copy no longer reflects current source —
 *      and lands the legacy id in drift[]. Extended to TERMS (name + slug +
 *      description) and OPTIONS (value), so an edited term/option is caught too
 *      (recorded via negative sentinel ids so drift[] stays list<int>).
 *
 *  (2) LEGACY→TARGET COMPLETENESS (the critical direction). We enumerate EVERY
 *      legacy id (sermons + podcasts) and assert exactly one migrated counterpart
 *      via Crosswalk::findNewByLegacyId AND that the counterpart carries NO open
 *      FAILURE flag. A legacy id with no counterpart — or a counterpart that was
 *      skipped/left divergent (open failure flag) — lands in missing[]. This is
 *      what defeats an offsetting "skip one + duplicate another" that would satisfy
 *      a bare count match: counting target→target can balance, but every individual
 *      legacy id must still resolve to a CLEAN counterpart.
 *
 *  (3) TERM/OPTION COMPLETENESS via the crosswalk, paired by
 *      MappingContract::taxonomyMap() / mapOptionName() (NOT same-key). For a term
 *      flagged slug_collision the new slug is compared against its LEGACY_SLUG
 *      (exact) — never legacy==new — so a deliberately disambiguated slug is not a
 *      false mismatch.
 *
 * complete = drift==[] && missing==[] && openFlags==[]. The advisory flags that
 * carry NO data loss (legacy_nonnumeric_date — raw preserved + normalized companion;
 * post_content_preserved — old body preserved + flagged; slug_collision — resolved
 * via deterministic suffix + LEGACY_SLUG) are NOT failure flags and never block.
 */
final class Verifier {
    /**
     * Open FAILURE-flag prefixes — flags that signal an UNRESOLVED / incomplete
     * migration of a record (a dependency that never migrated, a genuine collision,
     * a human-review divergence). Verification is not GREEN while any of these is
     * open. Advisory flags (no data loss) are deliberately excluded.
     *
     * @var list<string>
     */
    private const FAILURE_FLAG_PREFIXES = array(
        'missing_term_crosswalk',
        'missing_podcast_term_crosswalk',
        'missing_option_id_crosswalk',
        'legacy_taxonomy_unreadable',
        'meta_key_collision',
        'post_parent_unresolved',
        'post_content_divergence',
    );

    /**
     * Sentinel offset so a drifted TERM is recorded in drift[] (a list<int>) without
     * colliding with a real legacy post id. term_id is subtracted from this base, so
     * a drifted term yields a large negative pseudo-id (deterministic, distinct).
     */
    private const TERM_DRIFT_BASE = -1000000000;

    /** Sentinel offset for a drifted OPTION (keyed by a stable hash of the name). */
    private const OPTION_DRIFT_BASE = -2000000000;

    private MigrationState $state;

    public function __construct( ?MigrationState $state = null ) {
        $this->state = $state ?? new MigrationState();
    }

    public function verify( Manifest $m ): VerifyReport {
        // Legacy reads must work with the legacy plugin DEACTIVATED.
        LegacySchemaRegistrar::ensureRegistered();

        $drift     = array();
        $missing   = array();
        $openFlags = array();
        $counts    = array(
            'sermons'  => 0,
            'podcasts' => 0,
            'terms'    => 0,
            'options'  => 0,
        );

        // (1)+(2) Posts: source-fixity drift + legacy→target completeness.
        $this->verifyPostType(
            LegacyIdentifiers::POST_TYPE_SERMON,
            Identifiers::POST_TYPE_SERMON,
            'sermons',
            $m,
            $drift,
            $missing,
            $openFlags,
            $counts
        );
        $this->verifyPostType(
            LegacyIdentifiers::POST_TYPE_PODCAST,
            Identifiers::POST_TYPE_PODCAST,
            'podcasts',
            $m,
            $drift,
            $missing,
            $openFlags,
            $counts
        );

        // (3) Terms: completeness via the crosswalk + field-by-field fixity drift.
        $this->verifyTerms( $drift, $missing, $openFlags, $counts );

        // Options: completeness + value drift (default-podcast is intentionally remapped).
        $this->verifyOptions( $drift, $counts );

        $drift     = array_values( array_unique( $drift ) );
        $missing   = array_values( array_unique( $missing ) );
        $openFlags = array_values( array_unique( $openFlags ) );

        $complete = $drift === array() && $missing === array() && $openFlags === array();

        if ( $complete ) {
            // The ONLY phase the Verifier may advance: migrated → verified. Idempotent
            // if already verified; a no-op (silently) if the state is not at migrated
            // (the monotonic guard would otherwise throw — the caller should only
            // verify a migrated run, but we stay defensive and never crash a report).
            if ( $this->state->phase() === 'migrated' ) {
                $this->state->set( 'verified' );
            }
        }

        return new VerifyReport( $complete, $drift, $missing, $openFlags, $counts );
    }

    /**
     * Verify one post type: per legacy id, fold a checksum mismatch into drift[] and
     * a missing/dirty counterpart into missing[] (with its open failure flags into
     * openFlags[]). A CLEAN counterpart increments the per-type count.
     *
     * @param array<string,int> $counts
     * @param list<int>          $drift
     * @param list<int>          $missing
     * @param list<string>       $openFlags
     */
    private function verifyPostType(
        string $legacyType,
        string $newType,
        string $countKey,
        Manifest $m,
        array &$drift,
        array &$missing,
        array &$openFlags,
        array &$counts
    ): void {
        foreach ( $this->legacyPostIds( $legacyType ) as $legacyId ) {
            // (1) Source-fixity drift — only for ids the manifest checksummed.
            $expected = $m->checksum( $legacyId );
            if ( $expected !== null && LegacyChecksum::forPost( $legacyId ) !== $expected ) {
                $drift[] = $legacyId;
            }

            // (2) Legacy→target completeness: exactly one counterpart, no failure flag.
            $newId = Crosswalk::findNewByLegacyId( $legacyId, $newType );
            if ( $newId === null ) {
                $missing[] = $legacyId;
                continue;
            }

            $failures = $this->openFailureFlags( $this->postFlags( $newId ) );
            if ( $failures !== array() ) {
                // A counterpart carrying an open failure flag is NOT clean — count it
                // as missing AND surface the flags.
                $missing[]  = $legacyId;
                $openFlags  = array_merge( $openFlags, $failures );
                continue;
            }

            $counts[ $countKey ]++;
        }
    }

    /**
     * Verify terms across every sermon-referenced taxonomy, paired via taxonomyMap()
     * (legacy → target, NOT same-key). Each legacy term must resolve to exactly one
     * migrated counterpart (else missing via a term sentinel) whose name, description
     * and slug match field-by-field — with slug compared against LEGACY_SLUG (so a
     * deliberately disambiguated slug_collision term is not a false mismatch). Any
     * open failure flag on a term counterpart blocks completeness too.
     *
     * @param array<string,int> $counts
     * @param list<int>          $drift
     * @param list<int>          $missing
     * @param list<string>       $openFlags
     */
    private function verifyTerms( array &$drift, array &$missing, array &$openFlags, array &$counts ): void {
        $taxonomyMap = MappingContract::taxonomyMap();

        foreach ( LegacyIdentifiers::sermonTaxonomies() as $legacyTaxonomy ) {
            $targetTaxonomy = $taxonomyMap[ $legacyTaxonomy ] ?? null;
            if ( $targetTaxonomy === null ) {
                continue;
            }

            $legacyTerms = get_terms( array(
                'taxonomy'   => $legacyTaxonomy,
                'hide_empty' => false,
            ) );

            if ( is_wp_error( $legacyTerms ) ) {
                // An unreadable legacy taxonomy is a hard gap — surface it.
                $openFlags[] = 'legacy_taxonomy_unreadable:' . $legacyTaxonomy;
                continue;
            }

            foreach ( (array) $legacyTerms as $legacyTerm ) {
                $legacyTermId = (int) $legacyTerm->term_id;
                $newTermId    = Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy );

                if ( $newTermId === null ) {
                    $missing[] = self::TERM_DRIFT_BASE - $legacyTermId;
                    continue;
                }

                $failures = $this->openFailureFlags( $this->termFlags( $newTermId ) );
                if ( $failures !== array() ) {
                    $missing[] = self::TERM_DRIFT_BASE - $legacyTermId;
                    $openFlags = array_merge( $openFlags, $failures );
                    continue;
                }

                $newTerm = get_term( $newTermId, $targetTaxonomy );
                if ( ! ( $newTerm instanceof \WP_Term ) ) {
                    $missing[] = self::TERM_DRIFT_BASE - $legacyTermId;
                    continue;
                }

                // Field-by-field fixity. Name + description compared directly; the
                // slug is compared against LEGACY_SLUG (the preserved ORIGINAL legacy
                // slug) so a deliberately disambiguated slug_collision term — whose
                // new slug differs from legacy by design — is verified by its origin,
                // never legacy==new. When LEGACY_SLUG is absent (no collision) we
                // fall back to comparing the new slug to the legacy slug directly.
                $legacySlug  = (string) get_term_meta( $newTermId, Crosswalk::LEGACY_SLUG, true );
                $nameMatches = (string) $newTerm->name === (string) $legacyTerm->name;
                $descMatches = (string) $newTerm->description === (string) $legacyTerm->description;
                $slugOk      = $legacySlug !== ''
                    ? ( (string) $legacyTerm->slug === $legacySlug )           // origin preserved
                    : ( (string) $newTerm->slug === (string) $legacyTerm->slug ); // no collision

                if ( ! $nameMatches || ! $descMatches || ! $slugOk ) {
                    $drift[] = self::TERM_DRIFT_BASE - $legacyTermId;
                    continue;
                }

                $counts['terms']++;
            }
        }
    }

    /**
     * Verify the migrated sermonmanager_* options: each legacy option maps via
     * mapOptionName() to a target option that must exist with an EQUAL value —
     * except the default-podcast pointer, which is intentionally remapped from the
     * legacy podcast id to the new post id (value differs by design, so we assert it
     * resolves to a real migrated podcast instead).
     *
     * @param array<string,int> $counts
     * @param list<int>          $drift
     */
    private function verifyOptions( array &$drift, array &$counts ): void {
        foreach ( $this->legacyOptionNames() as $legacyName ) {
            $targetName = MappingContract::mapOptionName( $legacyName );
            if ( $targetName === null ) {
                continue;
            }

            if ( $legacyName === LegacyIdentifiers::OPTION_DEFAULT_PODCAST ) {
                // Intentionally remapped: the new value is the NEW podcast post id.
                $legacyPodcastId = (int) get_option( $legacyName );
                $expectedNewId   = Crosswalk::findNewByLegacyId( $legacyPodcastId, Identifiers::POST_TYPE_PODCAST );
                $actual          = (int) get_option( $targetName, 0 );
                if ( $expectedNewId === null || $actual !== (int) $expectedNewId ) {
                    $drift[] = $this->optionSentinel( $legacyName );
                    continue;
                }
                $counts['options']++;
                continue;
            }

            // Default value-equality: the migrated option must carry the legacy value.
            $legacyValue = get_option( $legacyName );
            $marker      = '__sermonator_option_absent__';
            $newValue    = get_option( $targetName, $marker );
            if ( $newValue === $marker || $newValue != $legacyValue ) { // loose: serialized round-trip
                $drift[] = $this->optionSentinel( $legacyName );
                continue;
            }

            $counts['options']++;
        }
    }

    // -------------------------------------------------------------------------
    // Flag classification
    // -------------------------------------------------------------------------

    /**
     * Filter a record's flags down to the OPEN failure flags (advisory flags
     * excluded). A flag is a failure when it matches a FAILURE_FLAG_PREFIXES entry
     * exactly or as a "prefix:detail" form.
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function openFailureFlags( array $flags ): array {
        $failures = array();
        foreach ( $flags as $flag ) {
            $flag = (string) $flag;
            foreach ( self::FAILURE_FLAG_PREFIXES as $prefix ) {
                if ( $flag === $prefix || str_starts_with( $flag, $prefix . ':' ) ) {
                    $failures[] = $flag;
                    break;
                }
            }
        }
        return array_values( array_unique( $failures ) );
    }

    /**
     * A migrated POST's flags — stored as a single canonical MIGRATION_FLAGS array
     * row (SermonWriter::writeFlags replace semantics).
     *
     * @return list<string>
     */
    private function postFlags( int $newId ): array {
        $stored = get_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, true );
        return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
    }

    /**
     * A migrated TERM's flags — stored as MULTIPLE MIGRATION_FLAGS rows (TermWriter
     * add_term_meta per flag).
     *
     * @return list<string>
     */
    private function termFlags( int $newTermId ): array {
        $rows = get_term_meta( $newTermId, Crosswalk::MIGRATION_FLAGS, false );
        return is_array( $rows ) ? array_values( array_map( 'strval', $rows ) ) : array();
    }

    // -------------------------------------------------------------------------
    // Legacy enumeration (read-only)
    // -------------------------------------------------------------------------

    /**
     * All legacy post ids of a type, ascending, status-agnostic. Read-only.
     *
     * @return list<int>
     */
    private function legacyPostIds( string $legacyType ): array {
        LegacySchemaRegistrar::ensureRegistered();

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
     * Every live sermonmanager_* option name. Read-only.
     *
     * @return list<string>
     */
    private function legacyOptionNames(): array {
        global $wpdb;

        $like  = $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%';
        $names = $wpdb->get_col(
            $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
        );

        return array_values( array_map( 'strval', (array) $names ) );
    }

    /** A deterministic negative sentinel id for a drifted option (keyed by name). */
    private function optionSentinel( string $legacyName ): int {
        return self::OPTION_DRIFT_BASE - ( (int) sprintf( '%u', crc32( $legacyName ) ) % 100000000 );
    }
}
