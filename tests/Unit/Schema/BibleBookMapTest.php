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

    public function test_usfm_maps_every_book_to_its_correct_code(): void {
        // Real alignment guard. usfm() pairs BibleCanon::defaultBooks() against
        // USFM_ORDER positionally, so a same-count reorder of defaultBooks (e.g.
        // swapping two adjacent minor prophets) would silently re-pair names to
        // the wrong code. This expected map is hardcoded independently of that
        // construction, so any such reorder breaks this test. assertSame also
        // checks ordering, so a transposition that preserves every pairing but
        // not the sequence is caught too.
        $expected = array(
            'Genesis' => 'GEN', 'Exodus' => 'EXO', 'Leviticus' => 'LEV',
            'Numbers' => 'NUM', 'Deuteronomy' => 'DEU',
            'Joshua' => 'JOS', 'Judges' => 'JDG', 'Ruth' => 'RUT',
            '1 Samuel' => '1SA', '2 Samuel' => '2SA',
            '1 Kings' => '1KI', '2 Kings' => '2KI',
            '1 Chronicles' => '1CH', '2 Chronicles' => '2CH', 'Ezra' => 'EZR',
            'Nehemiah' => 'NEH', 'Esther' => 'EST', 'Job' => 'JOB',
            'Psalms' => 'PSA', 'Proverbs' => 'PRO',
            'Ecclesiastes' => 'ECC', 'Song of Solomon' => 'SNG', 'Isaiah' => 'ISA',
            'Jeremiah' => 'JER', 'Lamentations' => 'LAM',
            'Ezekiel' => 'EZK', 'Daniel' => 'DAN', 'Hosea' => 'HOS',
            'Joel' => 'JOL', 'Amos' => 'AMO',
            'Obadiah' => 'OBA', 'Jonah' => 'JON', 'Micah' => 'MIC',
            'Nahum' => 'NAM', 'Habakkuk' => 'HAB',
            'Zephaniah' => 'ZEP', 'Haggai' => 'HAG', 'Zechariah' => 'ZEC',
            'Malachi' => 'MAL',
            'Matthew' => 'MAT', 'Mark' => 'MRK', 'Luke' => 'LUK',
            'John' => 'JHN', 'Acts' => 'ACT',
            'Romans' => 'ROM', '1 Corinthians' => '1CO', '2 Corinthians' => '2CO',
            'Galatians' => 'GAL', 'Ephesians' => 'EPH',
            'Philippians' => 'PHP', 'Colossians' => 'COL', '1 Thessalonians' => '1TH',
            '2 Thessalonians' => '2TH', '1 Timothy' => '1TI',
            '2 Timothy' => '2TI', 'Titus' => 'TIT', 'Philemon' => 'PHM',
            'Hebrews' => 'HEB', 'James' => 'JAS',
            '1 Peter' => '1PE', '2 Peter' => '2PE', '1 John' => '1JN',
            '2 John' => '2JN', '3 John' => '3JN',
            'Jude' => 'JUD', 'Revelation' => 'REV',
        );

        $this->assertSame( $expected, BibleBookMap::usfm() );
    }

    public function test_usfm_keys_match_default_books_exactly(): void {
        // Secondary structural assertion: the display names are derived from
        // BibleCanon, in the same order. The pairing-correctness guard lives in
        // test_usfm_maps_every_book_to_its_correct_code; this only pins that the
        // key set and order track BibleCanon::defaultBooks().
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
