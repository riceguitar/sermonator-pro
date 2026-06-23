<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Sermonator\Frontend\Feed\ItunesCategory;

final class ItunesCategoryTest extends TestCase {
    public function test_exact_category_match(): void {
        $this->assertSame(
            array( 'category' => 'Religion & Spirituality', 'subcategory' => null ),
            ItunesCategory::normalize( 'Religion & Spirituality' )
        );
    }

    public function test_subcategory_match_resolves_parent(): void {
        $this->assertSame(
            array( 'category' => 'Religion & Spirituality', 'subcategory' => 'Christianity' ),
            ItunesCategory::normalize( 'Christianity' )
        );
    }

    public function test_faith_heuristic_defaults_to_christianity(): void {
        $this->assertSame(
            array( 'category' => 'Religion & Spirituality', 'subcategory' => 'Christianity' ),
            ItunesCategory::normalize( 'Weekly Church Sermons' )
        );
    }

    public function test_empty_defaults(): void {
        $this->assertSame(
            array( 'category' => 'Religion & Spirituality', 'subcategory' => null ),
            ItunesCategory::normalize( '' )
        );
    }

    public function test_unknown_non_faith_defaults_without_subcategory(): void {
        $this->assertSame(
            array( 'category' => 'Religion & Spirituality', 'subcategory' => null ),
            ItunesCategory::normalize( 'Quantum Mechanics' )
        );
    }

    public function test_other_top_level_category(): void {
        $this->assertSame(
            array( 'category' => 'Education', 'subcategory' => null ),
            ItunesCategory::normalize( 'Education' )
        );
    }
}
