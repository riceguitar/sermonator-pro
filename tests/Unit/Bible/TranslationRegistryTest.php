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

    public function test_falls_back_to_engwebp_on_real_but_inline_ineligible_translation(): void {
        // ENGKJV is a real translation in BibleTranslations::all() but, as of 3b,
        // inline-INELIGIBLE (unaudited divergences). The registry gates on the
        // curatedInline() allowlist, not all(), so a stored ENGKJV option must
        // fall back to the ENGWEBP default rather than render unaudited text.
        $this->stubOptions(
            array( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION => 'ENGKJV' )
        );
        $this->assertSame( 'ENGWEBP', TranslationRegistry::current()->inlineTranslation() );
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

    public function test_link_version_falls_back_to_migrated_legacy_seed_on_frontend(): void {
        // Regression (Bundle 3 review #3/#4/#5): an upgraded church configured for
        // KJV links. The new axis-A option was never explicitly saved, but the
        // migration persisted sermonator_verse_bible_version. The FRONT-END render
        // path (TranslationRegistry, not the admin Settings API) must resolve KJV
        // rather than flooring to ESV — register_setting's default does not apply
        // on a normal page render.
        $this->stubOptions(
            array( Identifiers::OPTION_PREFIX . 'verse_bible_version' => 'KJV' )
        );
        $this->assertSame( 'KJV', TranslationRegistry::current()->linkVersion() );
    }

    public function test_link_version_prefers_stored_option_over_migrated_seed(): void {
        // An explicit admin save of the new option wins over the legacy seed.
        $this->stubOptions(
            array(
                Identifiers::OPTION_BIBLE_LINK_VERSION             => 'NIV',
                Identifiers::OPTION_PREFIX . 'verse_bible_version' => 'KJV',
            )
        );
        $this->assertSame( 'NIV', TranslationRegistry::current()->linkVersion() );
    }
}
