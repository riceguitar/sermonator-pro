<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Frontend\Compat\LegacyPostResolver;
use Sermonator\Migration\Crosswalk;
use Sermonator\Model\Registrar;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2, T3 — integration pins for the shared legacy-post resolver against a
 * real WordPress post store + the real Crosswalk back-ref.
 *
 * Proves end-to-end:
 *  - a legacy post id resolves PRE-Finalize via the LEGACY_POST_ID back-ref.
 *  - stripping that back-ref (what the Finalizer does to strippableBackRefs())
 *    flips the resolver to a miss — the legacy id is NEVER passed through as a
 *    new id (which would address a DIFFERENT new post).
 *  - the post-type scope is honored (a sermon legacy id does not resolve against
 *    the podcast type).
 *
 * Finalize is simulated by deleting the strippable back-ref postmeta directly
 * (delete_post_meta), exactly as the Finalizer strips it.
 *
 * NOTE: integration suite — requires wp-env (Docker). NOT run in this
 * environment (no Docker available); written as the pinned spec.
 */
final class LegacyPostResolverTest extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        ( new Registrar() )->register();
    }

    /**
     * Create a NEW-system post carrying a legacy back-ref, mirroring a migrated
     * record. Returns [newPostId, legacyPostId].
     *
     * @return array{0:int,1:int}
     */
    private function migratedPost( string $postType, int $legacyPostId ): array {
        $newPostId = (int) self::factory()->post->create( array( 'post_type' => $postType ) );
        Crosswalk::markLegacy( $newPostId, $legacyPostId );

        return array( $newPostId, $legacyPostId );
    }

    public function test_resolves_pre_finalize_then_misses_post_finalize(): void {
        [ $newPostId, $legacyId ] = $this->migratedPost( ID::POST_TYPE_SERMON, 42 );
        $resolver                 = new LegacyPostResolver();

        // Pre-Finalize: the back-ref resolves the legacy id to the new post.
        $pre = $resolver->resolveByLegacyId( $legacyId );
        $this->assertTrue( $pre->resolved() );
        $this->assertSame( $newPostId, $pre->newId );

        // Finalize strips the strippable back-ref.
        delete_post_meta( $newPostId, Crosswalk::LEGACY_POST_ID );

        // Post-Finalize: the same legacy id now MISSES (never passed through).
        $post = $resolver->resolveByLegacyId( $legacyId );
        $this->assertFalse( $post->resolved() );
        $this->assertNull( $post->newId );
        $this->assertStringContainsString( 'legacy_post_id_unresolved', (string) $post->reason );
    }

    public function test_unmigrated_legacy_id_returns_miss(): void {
        $result = ( new LegacyPostResolver() )->resolveByLegacyId( 987654 );

        $this->assertFalse( $result->resolved() );
        $this->assertStringContainsString( 'legacy_post_id_unresolved', (string) $result->reason );
    }

    public function test_post_type_scope_is_honored(): void {
        // A legacy id migrated as a SERMON must not resolve against the PODCAST
        // type — the back-ref query is post-type scoped.
        [ , $legacyId ] = $this->migratedPost( ID::POST_TYPE_SERMON, 314 );

        $resolver = new LegacyPostResolver();
        $this->assertTrue( $resolver->resolveByLegacyId( $legacyId, ID::POST_TYPE_SERMON )->resolved() );
        $this->assertFalse( $resolver->resolveByLegacyId( $legacyId, ID::POST_TYPE_PODCAST )->resolved() );
    }

    public function test_non_positive_legacy_id_returns_miss(): void {
        $result = ( new LegacyPostResolver() )->resolveByLegacyId( 0 );

        $this->assertFalse( $result->resolved() );
        $this->assertStringContainsString( 'invalid_legacy_post_id', (string) $result->reason );
    }
}
