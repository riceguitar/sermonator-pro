<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\MappingContract;
use Sermonator\Migration\PodcastObjectTermScopeMapper;
use Sermonator\Schema\Identifiers as ID;
use Sermonator\Migration\LegacyIdentifiers as LID;

/**
 * Unit coverage for MUST-FIX 1's WordPress-free merge step (the REAL SM Pro per-podcast
 * feed scope lives in OBJECT-TERMS, not the sm_podcast_settings blob).
 *
 * Pins: a legacy podcast's object-term scope migrates to POPULATED new scope keys
 * (legacy taxonomy slug → new slug, legacy term id → new term id); an UNRESOLVABLE
 * scope term records the shared missing-crosswalk flag (and is never silently dropped);
 * existing blob refs and unrelated settings keys are MERGED, never clobbered; and the
 * merge is idempotent.
 */
final class PodcastObjectTermScopeMapperTest extends TestCase {
    /** A resolver that maps legacy id -> legacy id + 100, but returns null for a denylist. */
    private function resolver( array $unresolved = array() ): callable {
        return static function ( int $legacyTermId ) use ( $unresolved ): ?int {
            if ( in_array( $legacyTermId, $unresolved, true ) ) {
                return null;
            }
            return $legacyTermId + 100;
        };
    }

    public function test_object_term_scope_migrates_to_populated_scope_keys(): void {
        $result = PodcastObjectTermScopeMapper::merge(
            array(),
            array( LID::TAX_SERIES => array( 10 ) ),
            MappingContract::taxonomyMap(),
            $this->resolver()
        );

        $this->assertTrue( $result['changed'] );
        $this->assertSame( array(), $result['flags'] );
        // Legacy wpfc_sermon_series term 10 → new sermonator_series term 110.
        $this->assertSame( array( ID::TAX_SERIES => array( 110 ) ), $result['settings'] );
    }

    public function test_multi_axis_object_term_scope_maps_every_axis(): void {
        $result = PodcastObjectTermScopeMapper::merge(
            array(),
            array(
                LID::TAX_PREACHER => array( 5 ),
                LID::TAX_TOPIC    => array( 7, 8 ),
            ),
            MappingContract::taxonomyMap(),
            $this->resolver()
        );

        $this->assertTrue( $result['changed'] );
        $this->assertSame( array( 105 ), $result['settings'][ ID::TAX_PREACHER ] );
        $this->assertSame( array( 107, 108 ), $result['settings'][ ID::TAX_TOPIC ] );
    }

    public function test_unresolvable_term_sets_missing_flag_and_drops_nothing_silently(): void {
        $result = PodcastObjectTermScopeMapper::merge(
            array(),
            array( LID::TAX_SERIES => array( 999 ) ),
            MappingContract::taxonomyMap(),
            $this->resolver( array( 999 ) )
        );

        // No resolvable id on the axis → key not added, no spurious change.
        $this->assertFalse( $result['changed'] );
        $this->assertArrayNotHasKey( ID::TAX_SERIES, $result['settings'] );
        // The unresolved term is FLAGGED via the shared contract token (never a silent drop).
        $this->assertContains( Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . '999', $result['flags'] );
    }

    public function test_partial_resolution_keeps_resolved_and_flags_unresolved(): void {
        $result = PodcastObjectTermScopeMapper::merge(
            array(),
            array( LID::TAX_SERIES => array( 10, 999 ) ),
            MappingContract::taxonomyMap(),
            $this->resolver( array( 999 ) )
        );

        $this->assertTrue( $result['changed'] );
        $this->assertSame( array( 110 ), $result['settings'][ ID::TAX_SERIES ] );
        $this->assertContains( Crosswalk::MISSING_PODCAST_TERM_FLAG_PREFIX . '999', $result['flags'] );
    }

    public function test_merge_never_clobbers_existing_blob_refs_or_other_keys(): void {
        $result = PodcastObjectTermScopeMapper::merge(
            array(
                ID::TAX_SERIES => array( 200 ), // blob ref already migrated
                'title'        => 'My Show',    // channel-identity key — must survive
            ),
            array( LID::TAX_SERIES => array( 10 ) ),
            MappingContract::taxonomyMap(),
            $this->resolver()
        );

        $this->assertSame( array( 200, 110 ), $result['settings'][ ID::TAX_SERIES ] );
        $this->assertSame( 'My Show', $result['settings']['title'] );
    }

    public function test_duplicate_id_merge_is_idempotent(): void {
        // The new id is already present (e.g. a prior pass) — re-merging dedups and
        // produces the identical scope list.
        $result = PodcastObjectTermScopeMapper::merge(
            array( ID::TAX_SERIES => array( 110 ) ),
            array( LID::TAX_SERIES => array( 10 ) ),
            MappingContract::taxonomyMap(),
            $this->resolver()
        );

        $this->assertSame( array( 110 ), $result['settings'][ ID::TAX_SERIES ] );
    }

    public function test_no_object_term_scope_is_a_noop(): void {
        $result = PodcastObjectTermScopeMapper::merge(
            array( 'title' => 'My Show' ),
            array(),
            MappingContract::taxonomyMap(),
            $this->resolver()
        );

        $this->assertFalse( $result['changed'] );
        $this->assertSame( array(), $result['flags'] );
        $this->assertSame( array( 'title' => 'My Show' ), $result['settings'] );
    }

    public function test_zero_valued_scope_term_is_ignored(): void {
        // A term id of 0/empty means "not scoped to any term" in Sermon Manager.
        $result = PodcastObjectTermScopeMapper::merge(
            array(),
            array( LID::TAX_SERIES => array( 0 ) ),
            MappingContract::taxonomyMap(),
            $this->resolver()
        );

        $this->assertFalse( $result['changed'] );
        $this->assertSame( array(), $result['flags'] );
        $this->assertSame( array(), $result['settings'] );
    }
}
