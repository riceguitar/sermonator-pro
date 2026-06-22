<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\BibleCanon;

final class BibleCanonTest extends TestCase {
    public function test_default_canon_has_66_books(): void {
        $this->assertCount( 66, BibleCanon::defaultBooks() );
    }

    public function test_starts_with_genesis_ends_with_revelation(): void {
        $books = BibleCanon::defaultBooks();
        $this->assertSame( 'Genesis', $books[0] );
        $this->assertSame( 'Revelation', $books[ count( $books ) - 1 ] );
    }

    public function test_book_names_are_unique(): void {
        $books = BibleCanon::defaultBooks();
        $this->assertSame( count( $books ), count( array_unique( $books ) ) );
    }

    public function test_does_not_include_deuterocanon_by_default(): void {
        // Deuterocanon is added by admins, not seeded.
        $this->assertNotContains( 'Tobit', BibleCanon::defaultBooks() );
    }
}
