<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\DateScope;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2 / T2 integration coverage for {@see SermonQuery}'s {@see DateScope} modes, orderby,
 * NUMERIC date-range bounds, and post__in/post__not_in — exercised against a real WP_Query.
 *
 * NOTE: requires wp-env (Docker). Not run in this environment (no Docker); authored per the spec
 * test strategy and to be run in CI.
 */
final class SermonQueryDateScopeTest extends WP_UnitTestCase {
    /** A fixed "now" so the PREACHED `<= now()` future-cap is deterministic. (~2033) */
    private const NOW = 2_000_000_000;

    private function sermon( ?int $ts, string $title ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => $title,
        ) );
        if ( $ts !== null ) {
            update_post_meta( $id, ID::META_DATE, (string) $ts );
        }
        return $id;
    }

    private function ids( $result ): array {
        return array_map( static fn( $v ) => $v->id, $result->sermons );
    }

    /**
     * The no-regression pin: an explicit dateScope=inclusive returns the EXACT same set and order
     * as the bare native call — dateless listed last, future included.
     */
    public function test_inclusive_matches_native_default(): void {
        $past     = $this->sermon( self::NOW - 10 * 86400, 'Past' );
        $future   = $this->sermon( self::NOW + 10 * 86400, 'Future' );
        $dateless = $this->sermon( null, 'Dateless' );

        $native    = ( new SermonQuery( self::NOW ) )->run();
        $inclusive = ( new SermonQuery( self::NOW ) )->run( array( 'dateScope' => DateScope::INCLUSIVE ) );

        $this->assertSame( $this->ids( $native ), $this->ids( $inclusive ), 'Explicit inclusive must equal the native default.' );
        $this->assertSame( 3, $native->total, 'Inclusive lists future AND dateless.' );
        $this->assertContains( $future, $this->ids( $native ) );
        $this->assertSame( $dateless, $this->ids( $native )[2], 'Dateless sorts last.' );
        $this->assertSame( $future, $this->ids( $native )[0], 'Future (newest) sorts first on the inclusive branch.' );
        $this->assertSame( $past, $this->ids( $native )[1] );
    }

    public function test_preached_drops_future_and_dateless(): void {
        $past     = $this->sermon( self::NOW - 10 * 86400, 'Past' );
        $future   = $this->sermon( self::NOW + 10 * 86400, 'Future' );
        $dateless = $this->sermon( null, 'Dateless' );

        $result = ( new SermonQuery( self::NOW ) )->run( array( 'dateScope' => DateScope::PREACHED ) );

        $this->assertSame( array( $past ), $this->ids( $result ), 'Preached drops BOTH the future and the dateless row.' );
        $this->assertNotContains( $future, $this->ids( $result ) );
        $this->assertNotContains( $dateless, $this->ids( $result ) );
    }

    public function test_none_includes_dateless_without_future_filter(): void {
        $past     = $this->sermon( self::NOW - 10 * 86400, 'Past' );
        $future   = $this->sermon( self::NOW + 10 * 86400, 'Future' );
        $dateless = $this->sermon( null, 'Dateless' );

        $result = ( new SermonQuery( self::NOW ) )->run( array(
            'dateScope' => DateScope::NONE,
            'orderby'   => 'title',
            'order'     => 'ASC',
        ) );

        $ids = $this->ids( $result );
        $this->assertCount( 3, $ids, 'None applies no date filter: dateless included, future included.' );
        $this->assertContains( $dateless, $ids );
        $this->assertContains( $future, $ids );
        $this->assertContains( $past, $ids );
        // orderby=title ASC: Dateless, Future, Past.
        $this->assertSame( array( $dateless, $future, $past ), $ids );
    }

    public function test_pre_1970_negative_timestamp_sorts_correctly(): void {
        // META_DATE is a SIGNED unix ts; a 1965 sermon is negative and must sort BEFORE a 1985 one.
        $sermon1965 = $this->sermon( -157766400, '1965' ); // ~1965
        $sermon1985 = $this->sermon( 481000000, '1985' );  // ~1985

        $asc = ( new SermonQuery( self::NOW ) )->run( array( 'dateScope' => DateScope::PREACHED, 'order' => 'ASC' ) );
        $this->assertSame( array( $sermon1965, $sermon1985 ), $this->ids( $asc ), 'Negative ts sorts before positive ascending.' );

        $desc = ( new SermonQuery( self::NOW ) )->run( array( 'dateScope' => DateScope::PREACHED, 'order' => 'DESC' ) );
        $this->assertSame( array( $sermon1985, $sermon1965 ), $this->ids( $desc ) );

        // The pre-1970 sermon is included (not dropped) — both are <= now and EXIST.
        $this->assertContains( $sermon1965, $this->ids( $desc ) );
    }

    public function test_date_range_excludes_dateless(): void {
        $in2020   = $this->sermon( 1_590_000_000, 'In2020' );   // ~May 2020
        $in2015   = $this->sermon( 1_440_000_000, 'In2015' );   // ~Aug 2015 (outside the range)
        $dateless = $this->sermon( null, 'Dateless' );

        // year-style BETWEEN bound (capFuture off, mirroring the mapper's year/month handling).
        $result = ( new SermonQuery( self::NOW ) )->run( array(
            'dateScope' => DateScope::PREACHED,
            'dateRange' => array( 'min' => 1_577_836_800, 'max' => 1_609_459_199, 'capFuture' => false ), // all of 2020
        ) );

        $ids = $this->ids( $result );
        $this->assertSame( array( $in2020 ), $ids, 'Only the in-range dated sermon survives.' );
        $this->assertNotContains( $dateless, $ids, 'A range excludes the dateless row.' );
        $this->assertNotContains( $in2015, $ids );
    }

    public function test_before_bound_caps_upper(): void {
        $early = $this->sermon( 1_500_000_000, 'Early' );
        $late  = $this->sermon( 1_900_000_000, 'Late' );

        $result = ( new SermonQuery( self::NOW ) )->run( array(
            'dateScope' => DateScope::PREACHED,
            'dateRange' => array( 'max' => 1_600_000_000 ),
        ) );

        $this->assertSame( array( $early ), $this->ids( $result ) );
        $this->assertNotContains( $late, $this->ids( $result ) );
    }

    public function test_after_exact_equality_matches_only_that_day(): void {
        $exact = $this->sermon( 1_600_000_000, 'Exact' );
        $other = $this->sermon( 1_600_000_001, 'Other' );

        $result = ( new SermonQuery( self::NOW ) )->run( array(
            'dateScope' => DateScope::PREACHED,
            'dateRange' => array( 'equals' => 1_600_000_000 ),
        ) );

        $this->assertSame( array( $exact ), $this->ids( $result ), 'after is bug-for-bug exact-equality.' );
        $this->assertNotContains( $other, $this->ids( $result ) );
    }

    public function test_post_in_and_not_in(): void {
        $a = $this->sermon( self::NOW - 30 * 86400, 'A' );
        $b = $this->sermon( self::NOW - 20 * 86400, 'B' );
        $c = $this->sermon( self::NOW - 10 * 86400, 'C' );

        $in = ( new SermonQuery( self::NOW ) )->run( array( 'postIn' => array( $a, $c ) ) );
        $this->assertEqualsCanonicalizing( array( $a, $c ), $this->ids( $in ) );

        $notIn = ( new SermonQuery( self::NOW ) )->run( array( 'postNotIn' => array( $b ) ) );
        $ids   = $this->ids( $notIn );
        $this->assertNotContains( $b, $ids );
        $this->assertContains( $a, $ids );
        $this->assertContains( $c, $ids );
    }

    public function test_orderby_id(): void {
        $first  = $this->sermon( self::NOW - 10 * 86400, 'First' );
        $second = $this->sermon( self::NOW - 5 * 86400, 'Second' );

        $result = ( new SermonQuery( self::NOW ) )->run( array(
            'dateScope' => DateScope::NONE,
            'orderby'   => 'id',
            'order'     => 'ASC',
        ) );
        $this->assertSame( array( $first, $second ), $this->ids( $result ), 'orderby=id orders by post ID.' );
    }
}
