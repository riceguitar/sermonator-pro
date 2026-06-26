<?php

declare(strict_types=1);

namespace Sermonator\Schema;

/**
 * Pure data: maps Bible book names and common abbreviations to standard USFM
 * book codes (the codes helloao's data set is keyed by).
 *
 * `usfm()` is DERIVED from {@see BibleCanon::defaultBooks()} by zipping that
 * ordered 66-name list against the ordered USFM list below, so the display
 * names are never re-listed here and the two cannot drift apart. `aliases()`
 * is a small, extensible table of abbreviations/variants only — full names are
 * already covered by `usfm()`. Sibling of {@see BibleCanon} / {@see VideoEmbedPolicy}.
 */
final class BibleBookMap {
    /**
     * Standard USFM book codes in {@see BibleCanon::defaultBooks()} order
     * (Genesis..Revelation, 66). helloao keys its data on these exact codes.
     *
     * @var list<string>
     */
    private const USFM_ORDER = array(
        'GEN', 'EXO', 'LEV', 'NUM', 'DEU',
        'JOS', 'JDG', 'RUT', '1SA', '2SA',
        '1KI', '2KI', '1CH', '2CH', 'EZR',
        'NEH', 'EST', 'JOB', 'PSA', 'PRO',
        'ECC', 'SNG', 'ISA', 'JER', 'LAM',
        'EZK', 'DAN', 'HOS', 'JOL', 'AMO',
        'OBA', 'JON', 'MIC', 'NAM', 'HAB',
        'ZEP', 'HAG', 'ZEC', 'MAL',
        'MAT', 'MRK', 'LUK', 'JHN', 'ACT',
        'ROM', '1CO', '2CO', 'GAL', 'EPH',
        'PHP', 'COL', '1TH', '2TH', '1TI',
        '2TI', 'TIT', 'PHM', 'HEB', 'JAS',
        '1PE', '2PE', '1JN', '2JN', '3JN',
        'JUD', 'REV',
    );

    /**
     * Display name => USFM code, derived from BibleCanon so it cannot drift.
     *
     * @return array<string,string>
     */
    public static function usfm(): array {
        return array_combine( BibleCanon::defaultBooks(), self::USFM_ORDER );
    }

    /**
     * Normalized abbreviation/variant => USFM code.
     *
     * Keys are already normalized the way the future reference normalizer will
     * present them: lowercased, dots stripped, and ordinals collapsed to digits
     * (I/First/1st -> 1), so only digit-prefixed forms appear here. This is a
     * curated common set, sized against the real corpus later; it is intentionally
     * a clean, extensible data table of abbreviations only.
     *
     * @return array<string,string>
     */
    public static function aliases(): array {
        return array(
            // Pentateuch.
            'gen'   => 'GEN',
            'ge'    => 'GEN',
            'gn'    => 'GEN',
            'ex'    => 'EXO',
            'exod'  => 'EXO',
            'lev'   => 'LEV',
            'lv'    => 'LEV',
            'num'   => 'NUM',
            'nm'    => 'NUM',
            'deut'  => 'DEU',
            'dt'    => 'DEU',

            // History.
            'josh'  => 'JOS',
            'jdg'   => 'JDG',
            'judg'  => 'JDG',
            'ru'    => 'RUT',
            'rth'   => 'RUT',
            '1 sam' => '1SA',
            '1sam'  => '1SA',
            '2 sam' => '2SA',
            '2sam'  => '2SA',
            '1 kgs' => '1KI',
            '1kgs'  => '1KI',
            '2 kgs' => '2KI',
            '2kgs'  => '2KI',
            '1 chr' => '1CH',
            '1chr'  => '1CH',
            '2 chr' => '2CH',
            '2chr'  => '2CH',
            'ezr'   => 'EZR',
            'neh'   => 'NEH',
            'est'   => 'EST',
            'esth'  => 'EST',

            // Wisdom.
            'ps'        => 'PSA',
            'psa'       => 'PSA',
            'psalm'     => 'PSA',
            'psalms'    => 'PSA',
            'pss'       => 'PSA',
            'prov'      => 'PRO',
            'pro'       => 'PRO',
            'pr'        => 'PRO',
            'eccl'      => 'ECC',
            'ecc'       => 'ECC',
            'qoh'       => 'ECC',
            'song'      => 'SNG',
            'sos'       => 'SNG',
            'song of songs' => 'SNG',

            // Major prophets.
            'isa'   => 'ISA',
            'jer'   => 'JER',
            'lam'   => 'LAM',
            'ezek'  => 'EZK',
            'ezk'   => 'EZK',
            'dan'   => 'DAN',

            // Minor prophets.
            'hos'   => 'HOS',
            'joel'  => 'JOL',
            'jol'   => 'JOL',
            'am'    => 'AMO',
            'amos'  => 'AMO',
            'obad'  => 'OBA',
            'oba'   => 'OBA',
            'jonah' => 'JON',
            'jon'   => 'JON',
            'mic'   => 'MIC',
            'nah'   => 'NAM',
            'hab'   => 'HAB',
            'zeph'  => 'ZEP',
            'hag'   => 'HAG',
            'zech'  => 'ZEC',
            'zec'   => 'ZEC',
            'mal'   => 'MAL',

            // Gospels + Acts.
            'matt'  => 'MAT',
            'mt'    => 'MAT',
            'mk'    => 'MRK',
            'mrk'   => 'MRK',
            'mar'   => 'MRK',
            'lk'    => 'LUK',
            'luke'  => 'LUK',
            'jn'    => 'JHN',
            'jhn'   => 'JHN',
            'joh'   => 'JHN',
            'ac'    => 'ACT',
            'acts'  => 'ACT',

            // Pauline epistles.
            'rom'            => 'ROM',
            '1 cor'          => '1CO',
            '1cor'           => '1CO',
            '1 corinthians'  => '1CO',
            '2 cor'          => '2CO',
            '2cor'           => '2CO',
            '2 corinthians'  => '2CO',
            'gal'            => 'GAL',
            'eph'            => 'EPH',
            'phil'           => 'PHP',
            'php'            => 'PHP',
            'col'            => 'COL',
            '1 thess'        => '1TH',
            '1thess'         => '1TH',
            '1 th'           => '1TH',
            '2 thess'        => '2TH',
            '2thess'         => '2TH',
            '2 th'           => '2TH',
            '1 tim'          => '1TI',
            '1tim'           => '1TI',
            '2 tim'          => '2TI',
            '2tim'           => '2TI',
            'tit'            => 'TIT',
            'philem'         => 'PHM',
            'phm'            => 'PHM',

            // General epistles + Revelation.
            'heb'     => 'HEB',
            'jas'     => 'JAS',
            'jam'     => 'JAS',
            'jms'     => 'JAS',
            '1 pet'   => '1PE',
            '1pet'    => '1PE',
            '1 pt'    => '1PE',
            '2 pet'   => '2PE',
            '2pet'    => '2PE',
            '2 pt'    => '2PE',
            '1 jn'    => '1JN',
            '1jn'     => '1JN',
            '1 john'  => '1JN',
            '2 jn'    => '2JN',
            '2jn'     => '2JN',
            '2 john'  => '2JN',
            '3 jn'    => '3JN',
            '3jn'     => '3JN',
            '3 john'  => '3JN',
            'jude'    => 'JUD',
            'rev'     => 'REV',
            'rv'      => 'REV',
        );
    }
}
