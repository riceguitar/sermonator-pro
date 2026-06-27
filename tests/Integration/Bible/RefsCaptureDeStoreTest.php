<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\DerivedExactClassifier;
use Sermonator\Bible\RefsCapture;
use Sermonator\Migration\BibleRefsBackfill;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the de-store ENFORCEMENT (design §3.4, T-D): the producers
 * NEVER persist the render-time floor tier `derived-exact` / `derived-exact-perseg`. The
 * stored-confidence vocabulary {exact,probable,ambiguous} and the floor vocabulary
 * {derived-exact,derived-exact-perseg} are DISJOINT — "derived-exact" is computed at
 * render time by {@see DerivedExactClassifier}, never written. A pre-stamped tier would
 * smuggle past the classifier and clear the inline floor without re-parse-identity; this
 * pins that it cannot happen through the real backfill / save-time producer path.
 *
 * Drives the actual {@see BibleRefsBackfill} and {@see RefsCapture} producers against
 * real post-meta, including an inline-SHAPED passage the classifier WOULD promote at
 * render time but which must persist as plain `probable`.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env).
 */
final class RefsCaptureDeStoreTest extends WP_UnitTestCase {
    /** @var list<string> the de-stored render-time floor tiers that must never persist */
    private array $deStoredTiers;

    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $this->deStoredTiers = array(
            DerivedExactClassifier::FLOOR_DERIVED_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
        );
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        parent::tearDown();
    }

    private function sermon( string $passage ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        return $id;
    }

    /** @return array<int,array<string,mixed>> decoded refs persisted on a post */
    private function refs( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
    }

    private function assertNoDeStoredTier( int $id, string $context ): void {
        $refs = $this->refs( $id );
        $this->assertNotEmpty( $refs, $context . ': produced at least one ref.' );
        foreach ( $refs as $ref ) {
            $this->assertContains(
                $ref['confidence'],
                array( 'exact', 'probable', 'ambiguous' ),
                $context . ': only a stored-confidence tier is persisted.'
            );
            $this->assertNotContains(
                $ref['confidence'],
                $this->deStoredTiers,
                $context . ': a de-stored render-time floor tier is NEVER persisted.'
            );
        }
    }

    public function test_save_time_producer_never_persists_a_derived_exact_tier(): void {
        // 'John 3:16' is L1-shaped (verseStart set, chapterEnd null) — exactly the ref the
        // classifier WOULD promote at render time, so the most important case to pin as
        // persisting plain `probable`.
        $inline   = $this->sermon( 'John 3:16' );
        $compound = $this->sermon( 'John 3:16; Romans 8:28; Genesis 1:1' );

        ( new RefsCapture() )->captureForPost( $inline, 'authoring' );
        ( new RefsCapture() )->captureForPost( $compound, 'authoring' );

        $this->assertNoDeStoredTier( $inline, 'save-time inline-shaped' );
        $this->assertNoDeStoredTier( $compound, 'save-time compound' );
        $this->assertSame( 'probable', $this->refs( $inline )[0]['confidence'] );
    }

    public function test_migration_backfill_producer_never_persists_a_derived_exact_tier(): void {
        $inline = $this->sermon( 'John 3:16' );

        ( new BibleRefsBackfill() )->run( 0, false );

        $this->assertNoDeStoredTier( $inline, 'backfill inline-shaped' );
        $this->assertSame( 'probable', $this->refs( $inline )[0]['confidence'] );
    }
}
