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

    /**
     * Sentinel offset for a SURPLUS migrated post counterpart — a migrated record
     * whose legacy back-ref is not in the manifest's enumerated legacy set, or a
     * duplicate counterpart for one legacy id. Recorded in missing[] (a list<int>)
     * so an over-migrated target cannot verify clean on a balanced count.
     */
    private const SURPLUS_BASE = -3000000000;

    /** Sentinel for the default-podcast pointer remap (a single, distinct slot). */
    private const DEFAULT_PODCAST_SENTINEL = -4000000000;

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
            'sermons'         => 0,
            'podcasts'        => 0,
            'terms'           => 0,
            'options'         => 0,
            'default_podcast' => 0,
        );

        // (1)+(2) Posts: source-fixity drift + legacy→target completeness.
        //
        // Enumeration is driven from the MANIFEST's recorded legacy ids (not the
        // current live DB) so a legacy post deleted AFTER detect is still asserted
        // (and flagged missing), while a legacy post inserted AFTER detect is caught
        // by the live↔manifest cross-check below. Sermons are checksummed
        // individually; podcasts are not (the manifest only records their count), so
        // for podcasts the manifest "expected set" is the live legacy set and the
        // recorded count is the surplus/shortfall oracle.
        $this->verifyPostType(
            LegacyIdentifiers::POST_TYPE_SERMON,
            Identifiers::POST_TYPE_SERMON,
            'sermons',
            $m->checksummedLegacyIds(),
            $m->count( 'sermons' ),
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
            $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST ),
            $m->count( 'podcasts' ),
            $m,
            $drift,
            $missing,
            $openFlags,
            $counts
        );

        // (3) Terms: completeness via the crosswalk + field-by-field fixity drift.
        $this->verifyTerms( $m, $drift, $missing, $openFlags, $counts );

        // Options: completeness + value drift over the sermonmanager_* set.
        $this->verifyOptions( $m, $drift, $missing, $counts );

        // The default-podcast pointer is INTENTIONALLY remapped (legacy podcast id →
        // new podcast post id), carries no sermonmanager_ prefix, and so is verified
        // explicitly OUTSIDE the sermonmanager_ scan — proving the remap landed on a
        // live migrated podcast before Finalize is ever authorized to delete it.
        $this->verifyDefaultPodcast( $drift, $missing, $counts );

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
     * Verify one post type against the MANIFEST's expected legacy id set.
     *
     * For each EXPECTED legacy id: fold a checksum mismatch into drift[], assert
     * EXACTLY ONE migrated counterpart (a duplicate >1 lands in missing[] via a
     * surplus sentinel — Crosswalk silently collapses >1 to the lowest id, so we
     * cannot rely on it), and fold a missing/dirty counterpart into missing[] (its
     * open failure flags into openFlags[]). A CLEAN, unique counterpart increments
     * the per-type count.
     *
     * Then two completeness cross-checks the bare legacy→target loop cannot see:
     *  - LIVE↔MANIFEST: a live legacy id absent from the manifest was inserted AFTER
     *    detect (the manifest's source no longer matches the DB) → missing sentinel.
     *  - SURPLUS: a migrated counterpart whose legacy back-ref is NOT an expected id
     *    (an orphan/over-migrated record), or the verified-clean count exceeding the
     *    manifest's recorded count → surplus sentinel. This defeats an offsetting
     *    "skip one + duplicate another" that balances a bare count.
     *
     * @param list<int>          $expectedLegacyIds The manifest's legacy ids for this type.
     * @param int                $manifestCount     The manifest's recorded count for this type.
     * @param array<string,int>  $counts
     * @param list<int>          $drift
     * @param list<int>          $missing
     * @param list<string>       $openFlags
     */
    private function verifyPostType(
        string $legacyType,
        string $newType,
        string $countKey,
        array $expectedLegacyIds,
        int $manifestCount,
        Manifest $m,
        array &$drift,
        array &$missing,
        array &$openFlags,
        array &$counts
    ): void {
        $expectedSet = array();
        foreach ( $expectedLegacyIds as $legacyId ) {
            $expectedSet[ (int) $legacyId ] = true;
        }

        foreach ( array_keys( $expectedSet ) as $legacyId ) {
            // (1) Source-fixity drift — only for ids the manifest checksummed.
            $expected = $m->checksum( $legacyId );
            if ( $expected !== null && LegacyChecksum::forPost( $legacyId ) !== $expected ) {
                $drift[] = $legacyId;
            }

            // (2) Legacy→target completeness: EXACTLY one counterpart, no failure flag.
            $counterparts = Crosswalk::countNewByLegacyId( $legacyId, $newType );
            if ( $counterparts === 0 ) {
                $missing[] = $legacyId;
                continue;
            }
            if ( $counterparts > 1 ) {
                // A duplicate counterpart for one legacy id — the lowest id may even
                // be clean, but the surplus copy means the target is over-migrated.
                $missing[] = $legacyId;
                $missing[] = self::SURPLUS_BASE - $legacyId;
                continue;
            }

            $newId    = Crosswalk::findNewByLegacyId( $legacyId, $newType );
            $failures = $newId === null ? array() : $this->openFailureFlags( $this->postFlags( $newId ) );
            if ( $newId === null || $failures !== array() ) {
                // A counterpart carrying an open failure flag is NOT clean — count it
                // as missing AND surface the flags.
                $missing[] = $legacyId;
                $openFlags = array_merge( $openFlags, $failures );
                continue;
            }

            $counts[ $countKey ]++;
        }

        // LIVE↔MANIFEST: a live legacy id the manifest never recorded was inserted
        // AFTER detect — the manifest no longer describes the source.
        foreach ( $this->legacyPostIds( $legacyType ) as $liveId ) {
            if ( ! isset( $expectedSet[ $liveId ] ) ) {
                $missing[] = self::SURPLUS_BASE - $liveId;
            }
        }

        // SURPLUS: any migrated counterpart pointing at a legacy id NOT in the
        // manifest set is an orphan/over-migrated record.
        foreach ( Crosswalk::migratedPostIds( $newType ) as $migratedId ) {
            $backRef = (int) get_post_meta( $migratedId, Crosswalk::LEGACY_POST_ID, true );
            if ( ! isset( $expectedSet[ $backRef ] ) ) {
                $missing[] = self::SURPLUS_BASE - $migratedId;
            }
        }

        // Count guard: the verified-clean count MUST equal the manifest's recorded
        // count. A SURPLUS (more clean than recorded) is over-migration the per-id
        // "exactly one" loop cannot express on its own. A SHORTFALL (fewer clean than
        // recorded) catches a manifest legacy id whose enumeration is not individually
        // driven — notably PODCASTS, which the manifest records only by count (no
        // per-id checksum), so a podcast deleted AFTER detect shrinks the live set
        // silently and is caught here.
        if ( $counts[ $countKey ] !== $manifestCount ) {
            $missing[] = self::SURPLUS_BASE - ( $manifestCount + 1 );
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
    private function verifyTerms( Manifest $m, array &$drift, array &$missing, array &$openFlags, array &$counts ): void {
        $taxonomyMap   = MappingContract::taxonomyMap();
        $expectedTerms = 0;

        foreach ( LegacyIdentifiers::sermonTaxonomies() as $legacyTaxonomy ) {
            $expectedTerms += $m->count( 'terms_' . $legacyTaxonomy );
        }

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

        // COUNT guard: verified-clean terms MUST equal the manifest's recorded term
        // total. A surplus is an orphan/duplicate migrated term; a shortfall catches
        // a legacy term deleted AFTER detect (the live get_terms scan would no longer
        // enumerate it) — both block completeness.
        if ( $counts['terms'] !== $expectedTerms ) {
            $missing[] = self::TERM_DRIFT_BASE - ( $expectedTerms + 1 );
        }
    }

    /**
     * Verify the migrated sermonmanager_* options: each legacy option maps via
     * mapOptionName() to a target option that must exist with the EXPECTED value.
     * The default-podcast pointer carries NO sermonmanager_ prefix, so it is NOT in
     * this scan — it is verified separately by verifyDefaultPodcast().
     *
     * For ordinary options the expected target value IS the legacy value (verbatim
     * prefix swap). For the CLOSED set of id-bearing default-term options
     * (sermonator_default_series / _preacher / _service_type / _book / _topic) the
     * value is INTENTIONALLY transformed: OptionWriter remaps the embedded legacy
     * term id to the NEW term id. Comparing such an option against the raw legacy
     * value would false-positive every real install (legacy term id != new term id)
     * — flagging spurious drift AND under-counting the option, which would
     * permanently block Finalize on a correct migration. So we compute the EXPECTED
     * remapped value via the SHARED OptionIdRemapper (the exact transform OptionWriter
     * applied, so the two cannot drift) and compare the target against THAT.
     *
     * An option whose remap is still PENDING (the embedded term id was unresolvable
     * at migrate time, so OptionIdRemapper would re-emit a missing_option_id_crosswalk
     * flag) is NOT verifiable as clean — the value legitimately still holds the legacy
     * id awaiting self-heal. We treat a pending remap as drift so completeness is
     * withheld until the option is re-migrated against the live crosswalk.
     *
     * @param array<string,int> $counts
     * @param list<int>          $drift
     * @param list<int>          $missing
     */
    private function verifyOptions( Manifest $m, array &$drift, array &$missing, array &$counts ): void {
        $crosswalk = new TermCrosswalk();

        foreach ( $this->legacyOptionNames() as $legacyName ) {
            $targetName = MappingContract::mapOptionName( $legacyName );
            if ( $targetName === null ) {
                continue;
            }

            // The EXPECTED target value: the legacy value put through the SAME embedded
            // id remap OptionWriter applied (a no-op for non-id-bearing options).
            $legacyValue = get_option( $legacyName );
            $remapped    = OptionIdRemapper::remap( $targetName, $legacyValue, $crosswalk );

            // A still-pending remap (unresolvable embedded id) cannot verify clean —
            // the value legitimately differs from BOTH legacy and the eventual new id.
            if ( $remapped['flags'] !== array() ) {
                $drift[] = $this->optionSentinel( $legacyName );
                continue;
            }

            $expectedValue = $remapped['value'];
            $marker        = '__sermonator_option_absent__';
            $newValue      = get_option( $targetName, $marker );
            if ( $newValue === $marker || $newValue != $expectedValue ) { // loose: serialized round-trip
                $drift[] = $this->optionSentinel( $legacyName );
                continue;
            }

            $counts['options']++;
        }

        // COUNT guard: verified-clean options MUST equal the manifest's recorded
        // sermonmanager_* count (default-podcast is excluded by both this scan and
        // Detector::countLegacyOptions's LIKE). A surplus is an orphan target option;
        // a shortfall catches a sermonmanager_* option deleted AFTER detect (no longer
        // enumerated by the live LIKE scan) — both block completeness.
        if ( $counts['options'] !== $m->count( 'options' ) ) {
            $missing[] = self::OPTION_DRIFT_BASE - ( $m->count( 'options' ) + 1 );
        }
    }

    /**
     * Verify the default-podcast pointer remap EXPLICITLY, outside the
     * sermonmanager_ LIKE scan (the legacy option is wpfc_sm_default_podcast — no
     * sermonmanager_ prefix — and mapOptionName() returns null for it, so the old
     * in-loop branch was unreachable dead code).
     *
     * When the legacy pointer is set, the migrated target option MUST equal
     * Crosswalk::findNewByLegacyId(legacyPodcastId, PODCAST) AND that new id MUST
     * resolve to a LIVE migrated podcast post. A mismatch — or a pointer to a podcast
     * that never migrated — pushes a drift sentinel so the silently-failed remap
     * cannot verify 'complete' and let Finalize delete the legacy option, losing the
     * pointer.
     *
     * @param array<string,int> $counts
     * @param list<int>          $drift
     * @param list<int>          $missing
     */
    private function verifyDefaultPodcast( array &$drift, array &$missing, array &$counts ): void {
        $legacy = get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST );
        if ( false === $legacy || '' === $legacy || null === $legacy ) {
            return; // No legacy pointer → nothing to remap, nothing to verify.
        }

        $legacyPodcastId = (int) $legacy;
        $expectedNewId   = Crosswalk::findNewByLegacyId( $legacyPodcastId, Identifiers::POST_TYPE_PODCAST );
        $marker          = '__sermonator_option_absent__';
        $actual          = get_option( Identifiers::OPTION_DEFAULT_PODCAST, $marker );

        // The target option must be present, equal to the remapped new id, and that
        // new id must point at a LIVE migrated podcast (not a stale/deleted post).
        if (
            $expectedNewId === null
            || $actual === $marker
            || (int) $actual !== (int) $expectedNewId
            || get_post_type( (int) $expectedNewId ) !== Identifiers::POST_TYPE_PODCAST
        ) {
            $drift[]   = self::DEFAULT_PODCAST_SENTINEL;
            $missing[] = self::DEFAULT_PODCAST_SENTINEL;
            return;
        }

        $counts['default_podcast']++;
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
