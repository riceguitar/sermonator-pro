<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Compat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Sermonator\Frontend\Compat\LegacyPostResolver;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the shared legacy-post resolver (Bundle 2, T3).
 *
 * Proves the resolve-or-report, Finalize-safe contract for the `[sermons]`
 * include/exclude axes:
 *  - a legacy post id resolves PRE-Finalize via Crosswalk::findNewByLegacyId.
 *  - it returns a miss + reason POST-Finalize (the LEGACY_POST_ID back-ref is
 *    stripped) — NEVER the legacy id passed through as a new id.
 *  - the pre->post-Finalize transition flips the same id from hit to miss.
 *
 * Crosswalk::findNewByLegacyId is static and reads global $wpdb (postmeta JOIN
 * posts) directly — stubbed here exactly as the LegacyPodcastId unit test does.
 * Empty rows == "back-ref stripped / never migrated" (post-Finalize).
 */
final class LegacyPostResolverTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Install a $wpdb stub whose get_col() returns the given post-id rows, so
     * Crosswalk::findNewByLegacyId resolves to $rows (pre-Finalize) or null when
     * $rows is empty (post-Finalize: the back-ref was stripped).
     *
     * @param list<int> $rows
     */
    private function stubPostmeta( array $rows ): void {
        $stub = new class( $rows ) {
            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';
            /** @var list<int> */
            public array $rows;
            /** @param list<int> $rows */
            public function __construct( array $rows ) {
                $this->rows = $rows;
            }
            public function prepare( $query, ...$args ) {
                return $query;
            }
            public function get_col( $query ) {
                return $this->rows;
            }
        };
        $GLOBALS['wpdb'] = $stub;
    }

    public function test_resolves_pre_finalize(): void {
        $this->stubPostmeta( array( 501 ) );

        $result = ( new LegacyPostResolver() )->resolveByLegacyId( 42 );

        $this->assertTrue( $result->resolved() );
        $this->assertSame( 501, $result->newId );
        $this->assertNull( $result->reason );
    }

    public function test_returns_miss_post_finalize_when_backref_stripped(): void {
        // Post-Finalize: LEGACY_POST_ID is gone -> no rows. Must NOT return 42.
        $this->stubPostmeta( array() );

        $result = ( new LegacyPostResolver() )->resolveByLegacyId( 42 );

        $this->assertFalse( $result->resolved() );
        $this->assertNull( $result->newId );
        $this->assertStringContainsString( 'legacy_post_id_unresolved', (string) $result->reason );
        $this->assertStringContainsString( '42', (string) $result->reason );
    }

    public function test_pre_to_post_finalize_transition_flips_hit_to_miss(): void {
        $resolver = new LegacyPostResolver();

        $this->stubPostmeta( array( 501 ) );
        $pre = $resolver->resolveByLegacyId( 42 );
        $this->assertTrue( $pre->resolved() );
        $this->assertSame( 501, $pre->newId );

        $this->stubPostmeta( array() );
        $post = $resolver->resolveByLegacyId( 42 );
        $this->assertFalse( $post->resolved() );
        $this->assertNotNull( $post->reason );
    }

    public function test_non_positive_legacy_id_returns_miss_without_query(): void {
        // No $wpdb stub installed: a guard must short-circuit before any query.
        $result = ( new LegacyPostResolver() )->resolveByLegacyId( 0 );

        $this->assertFalse( $result->resolved() );
        $this->assertStringContainsString( 'invalid_legacy_post_id', (string) $result->reason );
    }

    public function test_custom_post_type_resolves(): void {
        $this->stubPostmeta( array( 88 ) );

        $result = ( new LegacyPostResolver() )->resolveByLegacyId( 3, ID::POST_TYPE_PODCAST );

        $this->assertTrue( $result->resolved() );
        $this->assertSame( 88, $result->newId );
    }
}
