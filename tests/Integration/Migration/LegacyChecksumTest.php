<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Detector;
use Sermonator\Migration\LegacyChecksum;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 5: LegacyChecksum::forPost is the single source of legacy fixity. It must
 * equal the exact hash Detector previously computed inline (Detector now
 * delegates so the two cannot drift), respond to meta changes, and remain
 * encoding-hardened so invalid-UTF-8 meta still contributes to the digest.
 */
final class LegacyChecksumTest extends WP_UnitTestCase {
    private LegacyFixture $fx;

    public function set_up(): void {
        parent::set_up();
        $this->fx = new LegacyFixture();
        $this->fx->registerLegacySchema();
    }

    public function test_for_post_equals_detector_checksum_for_same_sermon(): void {
        $id = $this->fx->createSermon();

        // Detector's checksum is exposed only through the Manifest; capture it
        // there so this assertion pins LegacyChecksum to Detector's contract.
        $detectorChecksum = ( new Detector() )->detect()->checksum( $id );

        $this->assertNotNull( $detectorChecksum );
        $this->assertSame( $detectorChecksum, LegacyChecksum::forPost( $id ) );
    }

    /**
     * Golden-value guard for the digest formula itself.
     *
     * The Detector-equality test above proves Detector delegates to
     * LegacyChecksum, but because both sides now share that one code path it
     * cannot catch a regression in the formula's *composition* (dropping ksort,
     * reordering content vs meta, or changing the encode flag). This test pins
     * the formula against an EXPECTED value recomputed locally from known
     * inputs — never by calling back into LegacyChecksum/Detector — so any
     * change to md5 / ksort / concatenation order / encode flag fails loudly.
     */
    public function test_for_post_matches_independently_recomputed_digest(): void {
        $rawContent = 'A precisely known sermon body for the golden digest.';
        $id         = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Golden Sermon',
            'post_status'  => 'publish',
            'post_content' => $rawContent,
        ) );

        // Read back the persisted content rather than assuming it round-trips:
        // KSES/sanitization could differ for a capless test user, and the digest
        // is computed from the *stored* post_content. This keeps the expected
        // value independent of LegacyChecksum while staying robust.
        $content = get_post( $id )->post_content;

        // A deliberately UN-sorted-by-insertion meta set with a multi-value key,
        // so the recomputation actually exercises ksort and multiset ordering.
        $inserted = array(
            'zeta_key'      => array( 'last alphabetically' ),
            'bible_passage' => array( 'John 3:16' ),
            'alpha_key'     => array( 'first', 'second' ),
        );
        foreach ( $inserted as $key => $values ) {
            foreach ( $values as $value ) {
                add_post_meta( $id, $key, $value );
            }
        }

        // Recompute get_post_meta($id)'s no-key shape from known inputs:
        // array<string, list<string>>, with values in insertion order per key.
        $expectedMeta = $inserted;
        ksort( $expectedMeta );

        $expectedEncoded = wp_json_encode( $expectedMeta, JSON_INVALID_UTF8_SUBSTITUTE );
        $expected        = md5( $content . $expectedEncoded );

        $this->assertSame( $expected, LegacyChecksum::forPost( $id ) );
        // Sanity-check the recomputation produced a real 32-hex digest, so a
        // bug that makes BOTH sides equally wrong (e.g. md5(false)) is visible.
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $expected );
    }

    public function test_changing_a_meta_value_changes_the_hash(): void {
        $id = $this->fx->createSermon();
        $before = LegacyChecksum::forPost( $id );

        update_post_meta( $id, 'bible_passage', 'Romans 8:28' );
        $after = LegacyChecksum::forPost( $id );

        $this->assertNotSame( $before, $after );
    }

    public function test_changing_post_content_changes_the_hash(): void {
        $id = $this->fx->createSermon();
        $before = LegacyChecksum::forPost( $id );

        wp_update_post( array( 'ID' => $id, 'post_content' => 'A different blob entirely' ) );
        $after = LegacyChecksum::forPost( $id );

        $this->assertNotSame( $before, $after );
    }

    public function test_invalid_utf8_meta_still_contributes_to_the_hash(): void {
        // WordPress strips invalid UTF-8 on the way into the DB, so persisted
        // meta is always valid UTF-8. Invalid-UTF-8 bytes can still reach
        // LegacyChecksum::forPost in memory (e.g. a plugin filtering
        // get_post_meta, or unserialized binary payloads). We simulate exactly
        // that via the get_post_metadata filter on the no-key form forPost uses,
        // and prove the encoding-hardened hash both stays non-empty AND changes
        // when the invalid-UTF-8 value changes (so it is genuinely folded in,
        // not silently dropped by a wp_json_encode that returned false).
        $id = $this->fx->createSermon();

        $invalidA = "valid-prefix\xC3\x28";
        $invalidB = "valid-prefix\xC3\x29";

        $filter = $this->injectNoKeyMeta( $id, array( 'sermon_audio' => array( $invalidA ) ) );
        $withA  = LegacyChecksum::forPost( $id );
        remove_filter( 'get_post_metadata', $filter, 10 );

        $this->assertNotEmpty( $withA, 'hash must be non-empty even with invalid-UTF-8 meta' );

        $filter = $this->injectNoKeyMeta( $id, array( 'sermon_audio' => array( $invalidB ) ) );
        $withB  = LegacyChecksum::forPost( $id );
        remove_filter( 'get_post_metadata', $filter, 10 );

        $this->assertNotSame( $withA, $withB, 'invalid-UTF-8 meta must contribute to the digest' );
    }

    public function test_unencodable_meta_falls_back_to_serialize_non_empty(): void {
        // A recursive array makes wp_json_encode() return false even with the
        // substitute flag, exercising the serialize() fallback. The hash must
        // still be a normal non-empty md5 digest.
        $id = $this->fx->createSermon();

        $recursive          = array( 'x' => 1 );
        $recursive['self']  = &$recursive;

        $filter = $this->injectNoKeyMeta( $id, array( 'sermon_audio' => array( $recursive ) ) );
        $hash   = LegacyChecksum::forPost( $id );
        remove_filter( 'get_post_metadata', $filter, 10 );

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $hash );
    }

    /**
     * Force the no-key get_post_meta($id) call inside forPost() to return an
     * arbitrary in-memory meta map (bypassing storage sanitization).
     *
     * @param array<string, list<mixed>> $metaMap
     */
    private function injectNoKeyMeta( int $id, array $metaMap ): callable {
        $filter = static function ( $value, $objectId, $metaKey ) use ( $id, $metaMap ) {
            if ( $objectId === $id && '' === $metaKey ) {
                return $metaMap;
            }
            return $value;
        };
        add_filter( 'get_post_metadata', $filter, 10, 3 );
        return $filter;
    }

    public function test_for_post_is_stable_across_calls(): void {
        $id = $this->fx->createSermon();
        $this->assertSame( LegacyChecksum::forPost( $id ), LegacyChecksum::forPost( $id ) );
    }
}
