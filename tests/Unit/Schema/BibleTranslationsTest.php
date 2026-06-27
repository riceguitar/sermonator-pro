<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\BibleTranslations;

final class BibleTranslationsTest extends TestCase {
    public function test_every_entry_has_the_four_required_fields(): void {
        foreach ( BibleTranslations::all() as $entry ) {
            $this->assertArrayHasKey( 'id', $entry );
            $this->assertArrayHasKey( 'label', $entry );
            $this->assertArrayHasKey( 'license', $entry );
            $this->assertArrayHasKey( 'inlineEligible', $entry );
            $this->assertIsString( $entry['id'] );
            $this->assertIsString( $entry['label'] );
            $this->assertContains(
                $entry['license'],
                array( 'public-domain', 'ambiguous', 'unconfirmed' )
            );
            $this->assertIsBool( $entry['inlineEligible'] );
        }
    }

    public function test_engwebp_is_public_domain_and_inline_eligible(): void {
        $entry = $this->entry( 'ENGWEBP' );
        $this->assertNotNull( $entry, 'ENGWEBP must be in the allowlist' );
        $this->assertSame( 'public-domain', $entry['license'] );
        $this->assertTrue( $entry['inlineEligible'] );
    }

    public function test_engwebp_is_the_inline_default(): void {
        $this->assertSame( 'ENGWEBP', BibleTranslations::DEFAULT_INLINE );
        $this->assertArrayHasKey( 'ENGWEBP', BibleTranslations::curatedInline() );
    }

    public function test_engkjv_is_public_domain_but_inline_ineligible_pending_audit(): void {
        // 3b ships ENGWEBP as the SINGLE audited inline target. ENGKJV's
        // divergences are different/opposite and unaudited, so it stays
        // link-only despite being public domain.
        $entry = $this->entry( 'ENGKJV' );
        $this->assertNotNull( $entry, 'ENGKJV must be in the allowlist' );
        $this->assertSame( 'public-domain', $entry['license'] );
        $this->assertFalse( $entry['inlineEligible'] );
        $this->assertArrayNotHasKey( 'ENGKJV', BibleTranslations::curatedInline() );
    }

    public function test_engwebp_is_the_only_inline_eligible_translation(): void {
        $this->assertSame(
            array( 'ENGWEBP' ),
            array_keys( BibleTranslations::curatedInline() ),
            'ENGWEBP must be the single inline-eligible target in 3b'
        );
    }

    public function test_bsb_is_ambiguous_and_inline_ineligible(): void {
        $entry = $this->entry( 'BSB' );
        $this->assertNotNull( $entry, 'BSB must be in the allowlist' );
        $this->assertSame( 'ambiguous', $entry['license'] );
        $this->assertFalse( $entry['inlineEligible'] );
        $this->assertArrayNotHasKey( 'BSB', BibleTranslations::curatedInline() );
    }

    public function test_curated_inline_is_an_id_to_label_map_of_eligible_only(): void {
        $inline = BibleTranslations::curatedInline();
        $this->assertNotEmpty( $inline );
        foreach ( $inline as $id => $label ) {
            $this->assertIsString( $id );
            $this->assertIsString( $label );
            $entry = $this->entry( $id );
            $this->assertNotNull( $entry );
            $this->assertTrue(
                $entry['inlineEligible'],
                "curatedInline must only contain inline-eligible ids; {$id} is not"
            );
            $this->assertSame( $entry['label'], $label );
        }
    }

    public function test_curated_link_versions_include_legacy_set_and_default_esv(): void {
        $links = BibleTranslations::curatedLinkVersions();
        $this->assertSame( 'ESV', BibleTranslations::DEFAULT_LINK_VERSION );
        $this->assertArrayHasKey( 'ESV', $links );
        foreach ( array( 'ESV', 'NIV', 'KJV', 'NASB', 'NKJV' ) as $code ) {
            $this->assertArrayHasKey( $code, $links );
        }
        foreach ( $links as $code => $label ) {
            $this->assertIsString( $code );
            $this->assertIsString( $label );
        }
    }

    /**
     * @dataProvider englishProtestantCodeProvider
     */
    public function test_family_code_normalizes_english_protestant_aliases( string $input ): void {
        $this->assertSame(
            BibleTranslations::FAMILY_ENGLISH_PROTESTANT,
            BibleTranslations::familyCode( $input ),
            "{$input} must normalize to the English-Protestant family"
        );
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function englishProtestantCodeProvider(): iterable {
        // Each modern English-Protestant alias, plus case + UK-suffix variants.
        $codes = array( 'ESV', 'NIV', 'NASB', 'NKJV', 'NLT', 'CSB', 'KJV', 'WEB', 'NET' );
        foreach ( $codes as $code ) {
            yield $code => array( $code );
        }
        yield 'lowercase esv' => array( 'esv' );
        yield 'mixed-case NiV' => array( 'NiV' );
        yield 'whitespace-padded' => array( '  ESV  ' );
        yield 'UK suffix NIVUK' => array( 'NIVUK' );
        yield 'UK suffix lowercase nivuk' => array( 'nivuk' );
        yield 'ANGL suffix ESVANGL' => array( 'ESVANGL' );
        yield 'UK suffix NETUK' => array( 'NETUK' );
    }

    /**
     * @dataProvider unmodeledCodeProvider
     */
    public function test_family_code_returns_empty_for_unmodeled_or_blank( string $input ): void {
        $this->assertSame(
            '',
            BibleTranslations::familyCode( $input ),
            "{$input} must NOT map to a modeled family (fail open to link)"
        );
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function unmodeledCodeProvider(): iterable {
        yield 'empty string' => array( '' );
        yield 'whitespace only' => array( '   ' );
        yield 'Spanish Reina-Valera' => array( 'RVR1960' );
        yield 'Septuagint' => array( 'LXX' );
        yield 'Vulgate' => array( 'VULGATE' );
        yield 'unmodeled English AMP' => array( 'AMP' );
        yield 'unmodeled English MSG' => array( 'MSG' );
        yield 'garbage' => array( 'ZZZ' );
        // A bare suffix must not be mistaken for a real code after stripping.
        yield 'bare UK suffix' => array( 'UK' );
        yield 'bare ANGL suffix' => array( 'ANGL' );
    }

    /**
     * @return array{id:string,label:string,license:string,inlineEligible:bool}|null
     */
    private function entry( string $id ): ?array {
        foreach ( BibleTranslations::all() as $entry ) {
            if ( $entry['id'] === $id ) {
                return $entry;
            }
        }
        return null;
    }
}
