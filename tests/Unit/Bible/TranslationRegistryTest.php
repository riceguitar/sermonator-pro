<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Bible\TranslationRegistry;
use Sermonator\Schema\Identifiers;

final class TranslationRegistryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Identity filter by default; individual tests may override.
        Functions\when( 'apply_filters' )->alias(
            static function ( string $hook, $value ) {
                return $value;
            }
        );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['__sermonator_test_options'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $options
     */
    private function stubOptions( array $options ): void {
        $GLOBALS['__sermonator_test_options'] = $options;
        Functions\when( 'get_option' )->alias(
            static function ( $option, $default = false ) {
                return $GLOBALS['__sermonator_test_options'][ $option ] ?? $default;
            }
        );
    }

    public function test_reads_configured_inline_translation_when_curated(): void {
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION => 'ENGWEBP' )
        );
        $this->assertSame( 'ENGWEBP', TranslationRegistry::current()->inlineTranslation() );
    }

    public function test_reads_non_default_curated_inline_translation(): void {
        // ENGKJV != DEFAULT_INLINE (ENGWEBP), so this proves resolveInline()
        // reads its own option rather than collapsing to the fallback.
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION => 'ENGKJV' )
        );
        $this->assertSame( 'ENGKJV', TranslationRegistry::current()->inlineTranslation() );
    }

    public function test_falls_back_to_engwebp_on_unknown_inline_translation(): void {
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION => 'NOT_A_REAL_SLUG' )
        );
        $this->assertSame( 'ENGWEBP', TranslationRegistry::current()->inlineTranslation() );
    }

    public function test_falls_back_to_engwebp_when_inline_translation_unset(): void {
        $this->stubOptions( array() );
        $this->assertSame( 'ENGWEBP', TranslationRegistry::current()->inlineTranslation() );
    }

    public function test_inline_translation_filter_can_override(): void {
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION => 'ENGWEBP' )
        );
        Functions\when( 'apply_filters' )->alias(
            static function ( string $hook, $value ) {
                return $hook === 'sermonator_bible_translation' ? 'BSB' : $value;
            }
        );
        $this->assertSame( 'BSB', TranslationRegistry::current()->inlineTranslation() );
    }

    public function test_reads_configured_link_version_when_curated(): void {
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_LINK_VERSION => 'NIV' )
        );
        $this->assertSame( 'NIV', TranslationRegistry::current()->linkVersion() );
    }

    public function test_falls_back_to_default_link_version_on_unknown(): void {
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_LINK_VERSION => 'BOGUS_VERSION' )
        );
        $this->assertSame( 'ESV', TranslationRegistry::current()->linkVersion() );
    }

    public function test_falls_back_to_default_link_version_when_unset(): void {
        $this->stubOptions( array() );
        $this->assertSame( 'ESV', TranslationRegistry::current()->linkVersion() );
    }
}
