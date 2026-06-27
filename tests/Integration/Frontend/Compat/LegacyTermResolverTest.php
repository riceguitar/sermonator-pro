<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Frontend\Compat\LegacyTermResolver;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\TermCrosswalk;
use Sermonator\Model\Registrar;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2, T3 — integration pins for the shared legacy-term resolver against a
 * real WordPress term store + the real TermCrosswalk.
 *
 * Proves end-to-end:
 *  - SLUG resolution is DURABLE: it resolves identically whether or not the
 *    LEGACY_TERM_ID back-ref is present (i.e. before AND after Finalize), because
 *    the migrated NEW term itself persists.
 *  - NUMERIC resolution works PRE-Finalize via the LEGACY_TERM_ID back-ref.
 *  - Stripping that back-ref (what the Finalizer does to strippableBackRefs())
 *    flips the numeric path to a miss — the legacy id is NEVER passed through.
 *
 * Finalize is simulated by deleting the strippable back-ref termmeta directly
 * (delete_term_meta), exactly as the Finalizer strips it.
 *
 * NOTE: integration suite — requires wp-env (Docker). NOT run in this
 * environment (no Docker available); written as the pinned spec.
 */
final class LegacyTermResolverTest extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        ( new Registrar() )->register();
    }

    /**
     * Create a NEW-system term carrying a legacy back-ref, mirroring a migrated
     * term. Returns [newTermId, legacyTermId].
     *
     * @return array{0:int,1:int}
     */
    private function migratedTerm( string $taxonomy, string $name, string $slug, int $legacyTermId ): array {
        $created   = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
        $newTermId = (int) $created['term_id'];
        add_term_meta( $newTermId, Crosswalk::LEGACY_TERM_ID, $legacyTermId, true );

        return array( $newTermId, $legacyTermId );
    }

    public function test_slug_resolves_to_new_term_id(): void {
        [ $newTermId ] = $this->migratedTerm( ID::TAX_SERIES, 'Grace Alone', 'grace-alone', 4242 );

        $result = ( new LegacyTermResolver() )->resolveBySlug( ID::TAX_SERIES, 'grace-alone' );

        $this->assertTrue( $result->resolved() );
        $this->assertSame( $newTermId, $result->newId );
        $this->assertNull( $result->reason );
    }

    public function test_slug_resolution_is_durable_across_finalize(): void {
        [ $newTermId ] = $this->migratedTerm( ID::TAX_SERIES, 'Advent', 'advent', 99 );
        $resolver      = new LegacyTermResolver();

        // Pre-Finalize.
        $this->assertSame( $newTermId, $resolver->resolveBySlug( ID::TAX_SERIES, 'advent' )->newId );

        // Finalize strips the back-ref — the slug path is unaffected (durable).
        delete_term_meta( $newTermId, Crosswalk::LEGACY_TERM_ID );
        $this->assertSame( $newTermId, $resolver->resolveBySlug( ID::TAX_SERIES, 'advent' )->newId );
    }

    public function test_unknown_slug_returns_miss(): void {
        $result = ( new LegacyTermResolver() )->resolveBySlug( ID::TAX_SERIES, 'no-such-series' );

        $this->assertFalse( $result->resolved() );
        $this->assertStringContainsString( 'slug_not_found', (string) $result->reason );
    }

    public function test_numeric_resolves_pre_finalize_then_misses_post_finalize(): void {
        [ $newTermId, $legacyId ] = $this->migratedTerm( ID::TAX_TOPIC, 'Hope', 'hope', 707 );
        $resolver                 = new LegacyTermResolver( new TermCrosswalk() );

        // Pre-Finalize: the back-ref resolves the legacy id to the new term.
        $pre = $resolver->resolveByLegacyId( $legacyId );
        $this->assertTrue( $pre->resolved() );
        $this->assertSame( $newTermId, $pre->newId );

        // Finalize strips the strippable back-ref.
        delete_term_meta( $newTermId, Crosswalk::LEGACY_TERM_ID );

        // Post-Finalize: the same legacy id now MISSES (never passed through).
        $post = $resolver->resolveByLegacyId( $legacyId );
        $this->assertFalse( $post->resolved() );
        $this->assertNull( $post->newId );
        $this->assertStringContainsString( 'legacy_term_id_unresolved', (string) $post->reason );
    }

    public function test_unmigrated_numeric_id_returns_miss(): void {
        $result = ( new LegacyTermResolver( new TermCrosswalk() ) )->resolveByLegacyId( 987654 );

        $this->assertFalse( $result->resolved() );
        $this->assertStringContainsString( 'legacy_term_id_unresolved', (string) $result->reason );
    }
}
