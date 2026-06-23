<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use WP_Term;

/**
 * Writes legacy taxonomy terms into the new sermonator_* taxonomies.
 *
 * Data-preservation contract:
 * - Legacy terms are READ-ONLY. This writer never updates a wpfc_* term.
 * - Idempotency is gated on the authoritative back-ref probe
 *   (Crosswalk::findNewTermByLegacyId), which reads $wpdb directly so a term
 *   inserted moments earlier on a resumed run is found by the very next call
 *   with no stale term-cache miss. A re-run returns the same id and creates no
 *   duplicate term, back-ref, or flag rows.
 * - NEVER adopt a native term. If the target taxonomy already contains a term
 *   with the same slug (a church's own term), we do NOT stamp our back-ref onto
 *   it — we create a NEW term with a DETERMINISTIC suffix slug
 *   ($slug.'-legacy-'.$legacyTermId), flag slug_collision, and leave the native
 *   term byte-for-byte untouched. Determinism is what makes the collision branch
 *   itself resumable. We detect the collision by probing the slug DIRECTLY
 *   (term_exists) BEFORE the first insert, not by relying on a wp_insert_term
 *   term_exists WP_Error: in a non-hierarchical taxonomy that error only fires
 *   when the NAME also matches, so a same-slug/different-name native term would
 *   otherwise slip through to wp_unique_term_slug and get a silent, order-
 *   dependent '-2' suffix with no flag.
 * - DO adopt OUR OWN crash orphan. A term must exist before its back-ref can be
 *   stamped, so the window between wp_insert_term and the back-ref write is
 *   unavoidable. To make that window recoverable, the LEGACY_SLUG ownership
 *   marker (== the ORIGINAL legacy slug) is written FIRST, before the back-ref.
 *   A same-slug term carrying LEGACY_SLUG but NO back-ref is therefore
 *   unambiguously ours (a native term never carries LEGACY_SLUG): on resume we
 *   ADOPT it (stamp the back-ref onto it) instead of misreading it as a native
 *   collision and minting a duplicate '-legacy-{id}' term with a FALSE
 *   slug_collision flag. This crash-orphan probe runs BEFORE the native-collision
 *   probe and again in the residual term_exists fallback.
 * - DO adopt OUR EARLIER-WINDOW crash orphan too (CRITICAL #3). A term that died
 *   in the window between wp_insert_term and the LEGACY_SLUG stamp carries the
 *   legacy NAME and legacy SLUG but NO markers at all, so the LEGACY_SLUG-joined
 *   probe above cannot see it. In a non-hierarchical taxonomy a blind re-insert
 *   collides on NAME and either THROWS (wedging the whole migration — terms run
 *   first) or mints a duplicate '-legacy-{id}' term with a false slug_collision
 *   flag. So before the native-collision decision we probe for a back-ref-less
 *   term matching BOTH the legacy NAME AND the legacy SLUG and ADOPT it (stamp the
 *   back-ref + LEGACY_SLUG, never editing the term) — exactly one term, no false
 *   flag. The match is narrowed to NAME-AND-SLUG (not name alone) so a church's
 *   NATIVE term that shares only the SLUG (a DIFFERENT name) is still protected by
 *   the deterministic-suffix branch and never adopted. A residual no-throw guard
 *   in the term_exists fallback covers WP builds that raise on a NAME-only match,
 *   but ONLY adopts when the candidate carries a LEGACY_SLUG ownership marker —
 *   a native same-name/different-slug term (no marker) routes to the deterministic
 *   suffix instead of being stamped with our back-refs (CRITICAL guard).
 * - Write ordering (crash-safety): LEGACY_SLUG ownership marker FIRST, then the
 *   back-refs (term_id + tt_id), then flags (COMPLETE-LAST) — all only after a
 *   confirmed, non-error insert (never before the is_wp_error guard).
 * - $name and $description are wp_slash'd at EVERY insert site: wp_insert_term
 *   wp_unslash()es its inputs, so an unslashed legacy value carrying literal
 *   backslashes/escaped quotes would lose a backslash level and diverge from the
 *   legacy source.
 */
final class TermWriter {
    /**
     * Migrate one legacy term into its mapped target taxonomy.
     *
     * @param string  $legacyTaxonomy A legacy taxonomy slug (e.g. wpfc_preacher).
     * @param WP_Term $legacyTerm     The legacy term to copy (read-only).
     * @return int The new term id (existing id on a resumed/idempotent re-run).
     */
    public function migrateTerm( string $legacyTaxonomy, WP_Term $legacyTerm ): int {
        $targetTaxonomy = MappingContract::taxonomyMap()[ $legacyTaxonomy ];
        $legacyTermId   = (int) $legacyTerm->term_id;

        // Cache-safe idempotency probe: already migrated? Return the existing id.
        $existing = Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy );
        if ( $existing !== null ) {
            return $existing;
        }

        $name        = $legacyTerm->name;
        $legacySlug  = $legacyTerm->slug;
        $description = (string) $legacyTerm->description;

        $flags = array();

        // CRASH-ORPHAN RECOVERY (back-ref-FIRST is impossible here: a term must
        // exist before its back-ref can be stamped). If the run died in the
        // window between this writer stamping its LEGACY_SLUG ownership marker
        // and Crosswalk::markLegacyTerm writing the back-ref, a NEW term carrying
        // the legacy slug AND the LEGACY_SLUG marker but NO LEGACY_TERM_ID
        // back-ref is left behind. The authoritative back-ref probe above could
        // not see it (no back-ref), and the native-collision probe below WOULD
        // misclassify it as a church's native term — producing a duplicate
        // '-legacy-{id}' term plus a FALSE slug_collision flag, or wedging the
        // resume. So BEFORE the collision probe we look for our own orphan — a
        // same-slug term in the target taxonomy carrying our LEGACY_SLUG marker
        // but NO back-ref (a native term never carries LEGACY_SLUG) — and ADOPT
        // it: stamp the back-ref onto it rather than insert anew.
        $orphanTermId = $this->findBackRefLessTermBySlug( $legacySlug, $targetTaxonomy );
        if ( $orphanTermId !== null ) {
            return $this->adoptTerm( $orphanTermId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id, $legacySlug, $flags );
        }

        // CRASH-WEDGE RECOVERY — the EARLIER window (CRITICAL #3). The marker probe
        // above sees only orphans that reached the LEGACY_SLUG stamp. A term that
        // died in the window between wp_insert_term and that stamp carries the
        // legacy NAME and legacy SLUG but NO markers at all, so it is invisible to
        // the marker-joined probe. In a non-hierarchical taxonomy wp_insert_term
        // raises term_exists on a NAME match, so a blind re-insert would collide on
        // NAME and THROW — permanently wedging the whole migration (terms run first
        // in the orchestrator). A duplicate '-legacy-{id}' term with a FALSE
        // slug_collision flag is the other failure mode. So BEFORE the native-
        // collision decision we look for a back-ref-less term matching BOTH the
        // legacy NAME AND the legacy SLUG and ADOPT it (stamp the back-ref +
        // LEGACY_SLUG onto it, never editing the term) — yielding exactly one term
        // and no spurious slug_collision flag (finding #3/#11). The match is
        // narrowed to NAME-AND-SLUG so a church's NATIVE term that shares only the
        // SLUG (a DIFFERENT name) is still protected by the deterministic-suffix
        // branch below and never adopted.
        $sameNameSlugOrphanId = $this->findBackRefLessTermByNameAndSlug( $name, $legacySlug, $targetTaxonomy );
        if ( $sameNameSlugOrphanId !== null ) {
            return $this->adoptTerm( $sameNameSlugOrphanId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id, $legacySlug, $flags );
        }

        // Detect a NATIVE-term slug collision BEFORE the first insert. The
        // back-ref probe above already returned null, and the crash-orphan probe
        // found no back-ref-less term of ours, so any term already occupying this
        // slug in the target taxonomy is NOT one of ours — it is a church's
        // NATIVE term we must never adopt.
        //
        // We cannot rely on a wp_insert_term term_exists WP_Error for this: in a
        // non-hierarchical taxonomy WordPress only raises term_exists when the
        // NAME also matches. For a same-slug/different-name native term,
        // wp_insert_term silently routes through wp_unique_term_slug and appends
        // an order-dependent '-2'/'-3' suffix — yielding a non-deterministic
        // slug and NO collision flag. So we probe the slug directly and take the
        // deterministic-suffix branch unconditionally whenever the slug is taken.
        $slugIsTaken = term_exists( $legacySlug, $targetTaxonomy ) !== null;

        if ( $slugIsTaken ) {
            $insertSlug = $legacySlug . '-legacy-' . $legacyTermId;
            $flags[]    = 'slug_collision';

            // COLLISION-ORPHAN RECOVERY (MUST-FIX #2). The collision branch inserts
            // at the SUFFIXED slug but stamps LEGACY_SLUG with the ORIGINAL slug. A
            // crash between that insert and markLegacyTerm leaves a back-ref-less
            // term whose OWN slug is the suffixed slug yet whose LEGACY_SLUG marker
            // is the ORIGINAL — invisible to every probe above (they key on the
            // original slug). Without recovery, a resume re-runs the collision insert
            // and either mints a duplicate '-legacy-{id}-2' or trips the guard. Probe
            // for that orphan by its SUFFIXED slug (LEGACY_SLUG == original) and ADOPT
            // it. The residual-collision branch below also re-checks it after a
            // term_exists error (defence-in-depth on the suffixed re-insert).
            $collisionOrphanId = $this->findBackRefLessTermBySlugMarker( $insertSlug, $legacySlug, $targetTaxonomy );
            if ( $collisionOrphanId !== null ) {
                return $this->adoptTerm( $collisionOrphanId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id, $legacySlug, $flags );
            }
        } else {
            $insertSlug = $legacySlug;
        }

        // wp_slash name + description: wp_insert_term wp_unslash()es its inputs,
        // so an UNSLASHED legacy value (literal backslashes/escaped quotes) would
        // lose a backslash level on insert. Slashing here makes the migrated
        // term byte-identical to the legacy source.
        $result = wp_insert_term(
            wp_slash( $name ),
            $targetTaxonomy,
            array(
                'slug'        => $insertSlug,
                'description' => wp_slash( $description ),
            )
        );

        // A residual term_exists collision (e.g. a same-NAME native term whose
        // own slug differs, so the slug probe above missed it) still routes to
        // the deterministic suffix — never adopt a native term, never silently
        // '-2'. But re-check for OUR crash orphan first: a same-slug back-ref-less
        // term that appeared between the probe above and now (or that the first
        // insert's own unique-slug path collided with) must be adopted, not
        // duplicated.
        if ( is_wp_error( $result ) && in_array( 'term_exists', $result->get_error_codes(), true ) ) {
            $orphanTermId = $this->findBackRefLessTermBySlug( $legacySlug, $targetTaxonomy );
            if ( $orphanTermId !== null ) {
                return $this->adoptTerm( $orphanTermId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id, $legacySlug, $flags );
            }

            // MUST-FIX #2 (defence-in-depth): re-check the SUFFIXED-slug collision
            // orphan here too — the first insert routed to the suffixed slug may have
            // collided with our own back-ref-less collision orphan (LEGACY_SLUG ==
            // original). Adopt it rather than re-suffix to '-legacy-{id}-2'.
            $collisionOrphanId = $this->findBackRefLessTermBySlugMarker( $legacySlug . '-legacy-' . $legacyTermId, $legacySlug, $targetTaxonomy );
            if ( $collisionOrphanId !== null ) {
                if ( ! in_array( 'slug_collision', $flags, true ) ) {
                    $flags[] = 'slug_collision';
                }
                return $this->adoptTerm( $collisionOrphanId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id, $legacySlug, $flags );
            }

            // Residual no-throw guard (CRITICAL #3, defence-in-depth). The up-front
            // NAME+SLUG orphan probe already adopts a marker-less crash orphan at its
            // original slug, so this branch is unreachable for that case in current
            // WordPress. But on a WP build that raises term_exists on a NAME-only
            // match (no slug match) the deterministic re-insert below could THROW and
            // wedge the migration. Rather than throw, adopt the colliding back-ref-
            // less SAME-NAME term as a last resort — BUT ONLY if it carries our
            // LEGACY_SLUG ownership marker (the unambiguous 'ours' signal; a native
            // term NEVER carries LEGACY_SLUG). Without this guard the fallback would
            // stamp LEGACY_TERM_ID/LEGACY_TERM_TT_ID/LEGACY_SLUG onto a church's
            // OWN term whose name matches but whose slug differs — violating the
            // invariant "never mutate a native term" and conflating two distinct
            // entities irreversibly after Finalize strips the back-refs.
            // A native same-name term (no marker) falls through to the deterministic
            // suffix branch below — a recoverable collision is always safer than an
            // unrecoverable native-term mutation.
            $sameNameOrphanId = $this->findBackRefLessTermByName( $name, $targetTaxonomy );
            if ( $sameNameOrphanId !== null ) {
                $hasOwnershipMarker = '' !== (string) get_term_meta( $sameNameOrphanId, Crosswalk::LEGACY_SLUG, true );
                if ( $hasOwnershipMarker ) {
                    // OUR crash orphan — safe to adopt.
                    $orphanSlug    = (string) get_term( $sameNameOrphanId, $targetTaxonomy )->slug;
                    $adoptionFlags = $flags;
                    if ( $orphanSlug === $legacySlug ) {
                        $adoptionFlags = array_values( array_filter(
                            $adoptionFlags,
                            static function ( $flag ): bool {
                                return $flag !== 'slug_collision';
                            }
                        ) );
                    }
                    return $this->adoptTerm( $sameNameOrphanId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id, $legacySlug, $adoptionFlags );
                }
                // No LEGACY_SLUG marker — this is a NATIVE same-name term.
                // Fall through to the deterministic '$slug-legacy-$id' suffix branch.
            }

            $deterministicSlug = $legacySlug . '-legacy-' . $legacyTermId;
            if ( ! in_array( 'slug_collision', $flags, true ) ) {
                $flags[] = 'slug_collision';
            }

            $result = wp_insert_term(
                wp_slash( $name ),
                $targetTaxonomy,
                array(
                    'slug'        => $deterministicSlug,
                    'description' => wp_slash( $description ),
                )
            );
        }

        // A WP_Error here is a genuine failure — do NOT proceed to add_term_meta.
        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( sprintf(
                'TermWriter: failed to insert term "%s" into %s: %s',
                $name,
                $targetTaxonomy,
                $result->get_error_message()
            ) );
        }

        $newTermId = (int) $result['term_id'];

        // OWNERSHIP MARKER FIRST, then back-ref, then flags (COMPLETE-LAST).
        //
        // LEGACY_SLUG is our ownership stamp: it records the ORIGINAL legacy slug
        // (never a suffixed collision slug) and — crucially — distinguishes a
        // term WE inserted from a church's NATIVE term that merely shares the
        // slug. A native term never carries LEGACY_SLUG; so a same-slug term that
        // carries LEGACY_SLUG but NO back-ref is unambiguously OUR crash orphan
        // (inserted here, then the run died before markLegacyTerm) and is safe to
        // adopt without ever touching a native term. It is therefore written
        // BEFORE the back-ref: the one-statement window between the insert and
        // this stamp is the only unrecoverable gap, and a term left in it carries
        // no markers at all (looking native) — far narrower than stamping the
        // back-ref first, which would make the orphan invisible to the back-ref
        // probe yet indistinguishable from native to the slug probe.
        add_term_meta( $newTermId, Crosswalk::LEGACY_SLUG, $legacySlug, true );
        Crosswalk::markLegacyTerm( $newTermId, $legacyTermId, (int) $legacyTerm->term_taxonomy_id );

        foreach ( $flags as $flag ) {
            add_term_meta( $newTermId, Crosswalk::MIGRATION_FLAGS, $flag );
        }

        return $newTermId;
    }

    /**
     * Migrate EVERY legacy term across all five legacy taxonomies into their
     * mapped target taxonomies.
     *
     * Iteration order is canonical (LegacyIdentifiers::sermonTaxonomies()).
     * Orphan terms — attached to no posts — are included via hide_empty=false,
     * so nothing is silently left behind. Each term is delegated to
     * migrateTerm(), which is itself idempotent on the cache-safe back-ref
     * probe, making migrateAll fully resumable: a second run skips every
     * already-crosswalked term and creates zero duplicate terms, back-refs, or
     * flag rows.
     *
     * HARD UNIQUENESS GUARD: after processing each legacy term we re-query the
     * authoritative back-ref directly and assert it maps to EXACTLY one new term
     * in the target taxonomy. A >1 state means a prior run (or external
     * corruption) produced a duplicate crosswalk — we stop loudly with a
     * reconciliation error rather than let a divergent mapping propagate into
     * the artwork/term-assignment writers that depend on a single deterministic
     * target id.
     *
     * @return array{migrated:int, skipped:int, flags:list<string>}
     */
    public function migrateAll(): array {
        // MUST-FIX #1: re-register the legacy schema so a DEACTIVATED legacy plugin
        // does not make get_terms() return an empty/WP_Error over legacy term rows
        // that still exist. Idempotent; a no-op when the legacy plugin is active.
        LegacySchemaRegistrar::ensureRegistered();

        $migrated = 0;
        $skipped  = 0;
        $flags    = array();

        foreach ( LegacyIdentifiers::sermonTaxonomies() as $legacyTaxonomy ) {
            $targetTaxonomy = MappingContract::taxonomyMap()[ $legacyTaxonomy ];

            $terms = get_terms(
                array(
                    'taxonomy'   => $legacyTaxonomy,
                    'hide_empty' => false,
                )
            );

            if ( is_wp_error( $terms ) ) {
                throw new \RuntimeException( sprintf(
                    'TermWriter::migrateAll: failed to read legacy taxonomy %s: %s',
                    $legacyTaxonomy,
                    $terms->get_error_message()
                ) );
            }

            foreach ( $terms as $legacyTerm ) {
                $legacyTermId = (int) $legacyTerm->term_id;

                // Was this legacy term already crosswalked before we touched it?
                // (status of the probe BEFORE delegating decides migrated/skipped).
                $alreadyMigrated = Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy ) !== null;

                $this->migrateTerm( $legacyTaxonomy, $legacyTerm );

                if ( $alreadyMigrated ) {
                    $skipped++;
                } else {
                    $migrated++;
                }

                // Hard uniqueness guard: re-probe the raw back-ref count. Run for
                // EVERY processed term (migrated or skipped) so a pre-existing
                // duplicate crosswalk — which migrateTerm short-circuits on — is
                // still caught. ALSO count any back-ref-less term still carrying
                // the legacy slug: when the legacy term was already crosswalked,
                // migrateTerm short-circuits on the back-ref probe and never
                // adopts a lingering crash orphan, so a back-ref'd target PLUS an
                // un-adopted same-slug orphan is a divergent >1 mapping the
                // downstream writers must not see.
                $mappedCount = $this->countNewTermsForLegacyId( $legacyTermId, $targetTaxonomy );
                if ( $this->findBackRefLessTermBySlug( (string) $legacyTerm->slug, $targetTaxonomy ) !== null ) {
                    $mappedCount++;
                }
                // MUST-FIX #2: union the SUFFIXED collision slug. A back-ref-less
                // collision orphan ('$slug-legacy-$id', LEGACY_SLUG == original slug)
                // is also an un-adopted divergent mapping that must trip the guard.
                if ( $this->findBackRefLessTermBySlugMarker(
                    (string) $legacyTerm->slug . '-legacy-' . $legacyTermId,
                    (string) $legacyTerm->slug,
                    $targetTaxonomy
                ) !== null ) {
                    $mappedCount++;
                }
                if ( $mappedCount > 1 ) {
                    throw new \RuntimeException( sprintf(
                        'TermWriter::migrateAll: reconciliation error — legacy term id %d in %s maps to %d new terms in %s.',
                        $legacyTermId,
                        $legacyTaxonomy,
                        $mappedCount,
                        $targetTaxonomy
                    ) );
                }

                foreach ( get_term_meta( $this->resolveNewTermId( $legacyTermId, $targetTaxonomy ), Crosswalk::MIGRATION_FLAGS, false ) as $flag ) {
                    $flags[] = (string) $flag;
                }
            }
        }

        return array(
            'migrated' => $migrated,
            'skipped'  => $skipped,
            'flags'    => array_values( $flags ),
        );
    }

    /**
     * Count the distinct new terms carrying the legacy back-ref in a target
     * taxonomy. Reads $wpdb directly (cache-safe) so a term inserted moments
     * earlier on this same run is counted — the uniqueness guard cannot rely on
     * a stale term cache.
     */
    private function countNewTermsForLegacyId( int $legacyTermId, string $targetTaxonomy ): int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tm.term_id FROM {$wpdb->termmeta} tm"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id"
                . " WHERE tm.meta_key = %s AND tm.meta_value = %d AND tt.taxonomy = %s",
                Crosswalk::LEGACY_TERM_ID,
                $legacyTermId,
                $targetTaxonomy
            )
        );

        return count( (array) $ids );
    }

    private function resolveNewTermId( int $legacyTermId, string $targetTaxonomy ): int {
        return (int) Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy );
    }

    /**
     * Find OUR crash orphan: a term in the target taxonomy whose slug equals the
     * ORIGINAL legacy slug and which carries our LEGACY_SLUG ownership stamp
     * (== that legacy slug) but NO LEGACY_TERM_ID back-ref. That signature can
     * ONLY arise from a term we inserted (which stamps LEGACY_SLUG first) whose
     * run then died before markLegacyTerm wrote the back-ref. A church's NATIVE
     * term never carries LEGACY_SLUG, so it is never matched here and never
     * adopted; an already-crosswalked term carries the back-ref, so it is
     * excluded too. Reads $wpdb directly (cache-safe) so a term stamped moments
     * earlier on this same run is visible.
     *
     * @return int|null The orphan term id to adopt, or null if none.
     */
    private function findBackRefLessTermBySlug( string $legacySlug, string $targetTaxonomy ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " INNER JOIN {$wpdb->termmeta} owned"
                . "   ON owned.term_id = t.term_id AND owned.meta_key = %s AND owned.meta_value = %s"
                . " LEFT JOIN {$wpdb->termmeta} backref"
                . "   ON backref.term_id = t.term_id AND backref.meta_key = %s"
                . " WHERE t.slug = %s AND tt.taxonomy = %s AND backref.meta_id IS NULL"
                . " ORDER BY t.term_id ASC",
                Crosswalk::LEGACY_SLUG,
                $legacySlug,
                Crosswalk::LEGACY_TERM_ID,
                $legacySlug,
                $targetTaxonomy
            )
        );

        $ids = array_values( array_map( 'intval', (array) $ids ) );

        return $ids === array() ? null : $ids[0];
    }

    /**
     * Find OUR COLLISION crash orphan (MUST-FIX #2): a term whose OWN slug is the
     * SUFFIXED collision slug ('$legacySlug-legacy-$id') and which carries our
     * LEGACY_SLUG ownership marker set to the ORIGINAL legacy slug, but NO back-ref.
     * That signature arises ONLY from the native-collision branch (which inserts at
     * the suffixed slug yet stamps LEGACY_SLUG with the original) whose run died
     * before markLegacyTerm. The original-slug-keyed probes cannot see it because
     * the term's own slug is the suffixed one. A native term never carries
     * LEGACY_SLUG, so a church term sharing the suffixed slug is never matched.
     * Reads $wpdb directly (cache-safe).
     *
     * @return int|null The orphan term id to adopt, or null if none.
     */
    private function findBackRefLessTermBySlugMarker( string $termSlug, string $legacySlugMarker, string $targetTaxonomy ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " INNER JOIN {$wpdb->termmeta} owned"
                . "   ON owned.term_id = t.term_id AND owned.meta_key = %s AND owned.meta_value = %s"
                . " LEFT JOIN {$wpdb->termmeta} backref"
                . "   ON backref.term_id = t.term_id AND backref.meta_key = %s"
                . " WHERE t.slug = %s AND tt.taxonomy = %s AND backref.meta_id IS NULL"
                . " ORDER BY t.term_id ASC",
                Crosswalk::LEGACY_SLUG,
                $legacySlugMarker,
                Crosswalk::LEGACY_TERM_ID,
                $termSlug,
                $targetTaxonomy
            )
        );

        $ids = array_values( array_map( 'intval', (array) $ids ) );

        return $ids === array() ? null : $ids[0];
    }

    /**
     * Find a back-ref-less term in the target taxonomy matching BOTH the legacy
     * NAME AND the legacy SLUG — the marker-less crash orphan (CRITICAL #3): a term
     * that died in the window between wp_insert_term and the LEGACY_SLUG ownership
     * stamp, so it carries the legacy name + slug but NO markers and is invisible to
     * the slug-marker-joined orphan probe. Matching on NAME-AND-SLUG (not name
     * alone) keeps a church's NATIVE term that shares only the slug (DIFFERENT name)
     * safe — that case still routes to the deterministic-suffix collision branch and
     * is never adopted. A term carrying the back-ref is excluded (already migrated).
     * Reads $wpdb directly (cache-safe) so a term inserted moments earlier on this
     * same run is visible.
     *
     * @return int|null The orphan term id to adopt, or null if none.
     */
    private function findBackRefLessTermByNameAndSlug( string $name, string $legacySlug, string $targetTaxonomy ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " LEFT JOIN {$wpdb->termmeta} backref"
                . "   ON backref.term_id = t.term_id AND backref.meta_key = %s"
                . " WHERE t.name = %s AND t.slug = %s AND tt.taxonomy = %s AND backref.meta_id IS NULL"
                . " ORDER BY t.term_id ASC",
                Crosswalk::LEGACY_TERM_ID,
                $name,
                $legacySlug,
                $targetTaxonomy
            )
        );

        $ids = array_values( array_map( 'intval', (array) $ids ) );

        return $ids === array() ? null : $ids[0];
    }

    /**
     * Find a back-ref-less term in the target taxonomy by NAME alone — the residual
     * no-throw guard for the EARLIER crash window on a WordPress build that raises
     * term_exists on a NAME-only match. A term carrying the LEGACY_TERM_ID back-ref
     * is excluded (already migrated). The top-of-method back-ref probe already
     * guarantees the legacy term is not yet crosswalked, so a back-ref-less same-
     * NAME term here is either our crash orphan or — at worst — a native same-name
     * term we adopt by stamping a back-ref only (never editing it), strictly better
     * than wedging the migration. Reads $wpdb directly (cache-safe).
     *
     * @return int|null The term id to adopt, or null if none.
     */
    private function findBackRefLessTermByName( string $name, string $targetTaxonomy ): ?int {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " LEFT JOIN {$wpdb->termmeta} backref"
                . "   ON backref.term_id = t.term_id AND backref.meta_key = %s"
                . " WHERE t.name = %s AND tt.taxonomy = %s AND backref.meta_id IS NULL"
                . " ORDER BY t.term_id ASC",
                Crosswalk::LEGACY_TERM_ID,
                $name,
                $targetTaxonomy
            )
        );

        $ids = array_values( array_map( 'intval', (array) $ids ) );

        return $ids === array() ? null : $ids[0];
    }

    /**
     * Adopt an existing back-ref-less term as the migration target: stamp the
     * back-refs + LEGACY_SLUG (and any flags) onto it. Idempotent — guards each
     * single-row meta so a re-run never accumulates duplicate rows. The term's
     * own slug/name/description are left untouched (it already carries the
     * legacy slug; we never re-edit it).
     *
     * @param list<string> $flags
     */
    private function adoptTerm( int $termId, int $legacyTermId, int $legacyTtId, string $legacySlug, array $flags ): int {
        if ( get_term_meta( $termId, Crosswalk::LEGACY_TERM_ID, true ) === '' ) {
            Crosswalk::markLegacyTerm( $termId, $legacyTermId, $legacyTtId );
        }
        if ( get_term_meta( $termId, Crosswalk::LEGACY_SLUG, true ) === '' ) {
            add_term_meta( $termId, Crosswalk::LEGACY_SLUG, $legacySlug, true );
        }

        $existingFlags = array_map( 'strval', get_term_meta( $termId, Crosswalk::MIGRATION_FLAGS, false ) );
        foreach ( $flags as $flag ) {
            if ( ! in_array( $flag, $existingFlags, true ) ) {
                add_term_meta( $termId, Crosswalk::MIGRATION_FLAGS, $flag );
                $existingFlags[] = $flag;
            }
        }

        return $termId;
    }
}
