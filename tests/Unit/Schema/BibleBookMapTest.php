<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\BibleBookMap;
use Sermonator\Schema\BibleCanon;

final class BibleBookMapTest extends TestCase {
    public function test_usfm_has_66_entries(): void {
        $this->assertCount( 66, BibleBookMap::usfm() );
    }

    public function test_usfm_keys_match_default_books_exactly(): void {
        // Drift guard: the display names MUST be derived from BibleCanon,
        // in the same order, so the two can never diverge.
        $this->assertSame(
            BibleCanon::defaultBooks(),
            array_keys( BibleBookMap::usfm() )
        );
    }

    public function test_every_usfm_value_is_a_short_code(): void {
        foreach ( BibleBookMap::usfm() as $name => $code ) {
            $this->assertMatchesRegularExpression(
                '/^[0-9A-Z]{3}$/',
                $code,
                "USFM code for {$name} is not a 3-char uppercase code"
            );
        }
    }

    public function test_known_books_map_to_correct_usfm(): void {
        $usfm = BibleBookMap::usfm();
        $this->assertSame( 'GEN', $usfm['Genesis'] );
        $this->assertSame( 'PSA', $usfm['Psalms'] );
        $this->assertSame( 'SNG', $usfm['Song of Solomon'] );
        $this->assertSame( 'JHN', $usfm['John'] );
        $this->assertSame( 'PHP', $usfm['Philippians'] );
        $this->assertSame( 'PHM', $usfm['Philemon'] );
        $this->assertSame( '1JN', $usfm['1 John'] );
        $this->assertSame( 'JUD', $usfm['Jude'] );
    }

    public function test_usfm_codes_are_unique(): void {
        $codes = array_values( BibleBookMap::usfm() );
        $this->assertSame( count( $codes ), count( array_unique( $codes ) ) );
    }

    public function test_aliases_resolve_to_valid_usfm_codes(): void {
        $valid = array_values( BibleBookMap::usfm() );
        foreach ( BibleBookMap::aliases() as $alias => $code ) {
            $this->assertContains(
                $code,
                $valid,
                "Alias '{$alias}' maps to '{$code}', which is not a canonical USFM code"
            );
        }
    }

    public function test_alias_keys_are_normalized(): void {
        // Aliases are looked up post-normalization: lowercase, no dots,
        // ordinals collapsed to digits.
        foreach ( array_keys( BibleBookMap::aliases() ) as $alias ) {
            $this->assertSame( strtolower( $alias ), $alias, "Alias '{$alias}' is not lowercase" );
            $this->assertStringNotContainsString( '.', $alias, "Alias '{$alias}' contains a dot" );
        }
    }

    public function test_common_abbreviations_disambiguate_correctly(): void {
        $aliases = BibleBookMap::aliases();
        // The J-cluster trap: 'jn' is John, not Jonah/Joel/Jude.
        $this->assertSame( 'JHN', $aliases['jn'] );
        $this->assertSame( 'GEN', $aliases['gen'] );
        $this->assertSame( 'PSA', $aliases['ps'] );
        $this->assertSame( 'MAT', $aliases['matt'] );
        $this->assertSame( 'PHP', $aliases['phil'] );
        $this->assertSame( 'PHM', $aliases['philem'] );
        $this->assertSame( '1CO', $aliases['1 cor'] );
        $this->assertSame( 'SNG', $aliases['song of songs'] );
        $this->assertSame( 'REV', $aliases['rev'] );
    }

    public function test_aliases_are_only_abbreviations_not_full_proper_names(): void {
        // Aliases carry abbreviations/variants; the canonical display names live in
        // usfm(). The only overlap permitted is the lowercased form of a name, which
        // is still useful because usfm() keys are capitalized (a normalizer lowercases).
        $names = array_keys( BibleBookMap::usfm() );
        foreach ( array_keys( BibleBookMap::aliases() ) as $alias ) {
            $this->assertNotContains(
                $alias,
                $names,
                "Alias '{$alias}' duplicates a verbatim display name already covered by usfm()"
            );
        }
    }
}
