<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Compat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Compat\LegacyTermResolver;
use Sermonator\Migration\TermCrosswalk;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the shared legacy-term resolver (Bundle 2, T3).
 *
 * Proves the resolve-or-report, Finalize-safe contract:
 *  - SLUG resolution is durable (works regardless of Finalize state).
 *  - NUMERIC legacy term id resolves PRE-Finalize via TermCrosswalk.
 *  - NUMERIC returns a miss + reason POST-Finalize (the LEGACY_TERM_ID back-ref
 *    is stripped) — NEVER the legacy id passed through as a new id.
 *  - the pre->post-Finalize transition flips the same id from hit to miss.
 *
 * The numeric path drives TermCrosswalk::newTermId, which reads global $wpdb
 * (termmeta) directly — stubbed here exactly as the LegacyPodcastId unit test
 * stubs $wpdb. Empty rows == "back-ref stripped / never migrated" (post-Finalize).
 */
final class LegacyTermResolverTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'is_wp_error' )->justReturn( false );

        if ( ! class_exists( 'WP_Term' ) ) {
            eval( 'class WP_Term { public $term_id = 0; }' );
        }
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Install a $wpdb stub whose get_col() returns the given term-id rows, so
     * TermCrosswalk::newTermId resolves to $rows (pre-Finalize) or to null when
     * $rows is empty (post-Finalize: the back-ref was stripped).
     *
     * @param list<int> $rows
     */
    private function stubTermmeta( array $rows ): void {
        $stub = new class( $rows ) {
            public string $termmeta = 'wp_termmeta';
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

    // --- SLUG path (durable across Finalize) ---------------------------------

    public function test_slug_resolves_to_new_term_id(): void {
        $term          = new \WP_Term();
        $term->term_id = 77;
        Functions\when( 'get_term_by' )->justReturn( $term );

        $result = ( new LegacyTermResolver() )->resolveBySlug( ID::TAX_SERIES, 'grace' );

        $this->assertTrue( $result->resolved() );
        $this->assertSame( 77, $result->newId );
        $this->assertNull( $result->reason );
    }

    public function test_unknown_slug_returns_miss_with_reason(): void {
        Functions\when( 'get_term_by' )->justReturn( false );

        $result = ( new LegacyTermResolver() )->resolveBySlug( ID::TAX_SERIES, 'nope' );

        $this->assertFalse( $result->resolved() );
        $this->assertNull( $result->newId );
        $this->assertStringContainsString( 'slug_not_found', (string) $result->reason );
        $this->assertStringContainsString( 'nope', (string) $result->reason );
    }

    public function test_empty_slug_returns_miss(): void {
        $result = ( new LegacyTermResolver() )->resolveBySlug( ID::TAX_SERIES, '   ' );

        $this->assertFalse( $result->resolved() );
        $this->assertSame( 'empty_slug_reference', $result->reason );
    }

    // --- NUMERIC path (pre-Finalize only via TermCrosswalk) ------------------

    public function test_numeric_resolves_pre_finalize(): void {
        $this->stubTermmeta( array( 42 ) );

        $result = ( new LegacyTermResolver( new TermCrosswalk() ) )->resolveByLegacyId( 9 );

        $this->assertTrue( $result->resolved() );
        $this->assertSame( 42, $result->newId );
        $this->assertNull( $result->reason );
    }

    public function test_numeric_returns_miss_post_finalize_when_backref_stripped(): void {
        // Post-Finalize: the LEGACY_TERM_ID back-ref is gone, so the crosswalk
        // query returns no rows. Must NOT pass legacy id 9 through as a new id.
        $this->stubTermmeta( array() );

        $result = ( new LegacyTermResolver( new TermCrosswalk() ) )->resolveByLegacyId( 9 );

        $this->assertFalse( $result->resolved() );
        $this->assertNull( $result->newId );
        $this->assertStringContainsString( 'legacy_term_id_unresolved', (string) $result->reason );
        $this->assertStringContainsString( '9', (string) $result->reason );
    }

    public function test_pre_to_post_finalize_transition_flips_hit_to_miss(): void {
        $resolver = new LegacyTermResolver( new TermCrosswalk() );

        // Pre-Finalize: the back-ref is present.
        $this->stubTermmeta( array( 42 ) );
        $pre = $resolver->resolveByLegacyId( 9 );
        $this->assertTrue( $pre->resolved() );
        $this->assertSame( 42, $pre->newId );

        // Finalize strips the back-ref -> the very same id now misses.
        $this->stubTermmeta( array() );
        $post = $resolver->resolveByLegacyId( 9 );
        $this->assertFalse( $post->resolved() );
        $this->assertNotNull( $post->reason );
    }

    public function test_non_positive_legacy_id_returns_miss_without_query(): void {
        // No $wpdb stub installed: a guard must short-circuit before any query.
        $result = ( new LegacyTermResolver( new TermCrosswalk() ) )->resolveByLegacyId( 0 );

        $this->assertFalse( $result->resolved() );
        $this->assertStringContainsString( 'invalid_legacy_term_id', (string) $result->reason );
    }
}
