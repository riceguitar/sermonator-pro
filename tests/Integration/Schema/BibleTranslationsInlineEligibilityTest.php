<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Schema;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Bible\TranslationRegistry;

/**
 * Integration coverage for the Bundle 3 Phase 3b inline-eligibility contract,
 * driving the REAL WordPress options API (no Brain Monkey).
 *
 * NOT run in this environment (no Docker / wp-env) — authored to run under
 * wp-env later, per the orchestrator instruction.
 *
 * The unit suite proves the pure data (only ENGWEBP is inline-eligible; ENGKJV
 * flipped; family normalization). This test exercises what the unit test cannot:
 * that the eligibility allowlist actually gates the LIVE option-resolution path,
 * so a real, persisted `OPTION_BIBLE_INLINE_TRANSLATION` of a now-ineligible
 * translation falls open to the audited ENGWEBP default through `get_option()`
 * rather than ever rendering unaudited verse text — the never-fail-WRONG spine.
 */
final class BibleTranslationsInlineEligibilityTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_BIBLE_INLINE_TRANSLATION );
        parent::tearDown();
    }

    public function test_engwebp_is_the_single_inline_eligible_target(): void {
        $this->assertSame(
            array( 'ENGWEBP' ),
            array_keys( BibleTranslations::curatedInline() ),
            'ENGWEBP must be the only inline-eligible translation in 3b'
        );
    }

    public function test_stored_engwebp_option_resolves_through_live_options_api(): void {
        update_option( ID::OPTION_BIBLE_INLINE_TRANSLATION, 'ENGWEBP' );

        $this->assertSame(
            'ENGWEBP',
            TranslationRegistry::current()->inlineTranslation()
        );
    }

    public function test_real_but_ineligible_engkjv_option_falls_open_to_engwebp(): void {
        // ENGKJV is a real translation in BibleTranslations::all() but is now
        // inline-INELIGIBLE. Persisted as the live option, it must resolve to the
        // audited ENGWEBP default — proving the gate is the curatedInline()
        // allowlist, enforced over a genuinely stored WP option, not the unit stub.
        update_option( ID::OPTION_BIBLE_INLINE_TRANSLATION, 'ENGKJV' );

        $this->assertSame(
            'ENGWEBP',
            TranslationRegistry::current()->inlineTranslation()
        );
    }

    /**
     * The L4 family-normalization primitive behaves identically under the live
     * WordPress bootstrap: English-Protestant aliases (incl. UK editions) map to
     * the one modeled family; everything else falls open with the empty string.
     */
    public function test_family_code_contract_under_live_bootstrap(): void {
        foreach ( array( 'ESV', 'esv', 'NIVUK', 'ESVANGL', 'KJV', 'WEB', 'NET' ) as $code ) {
            $this->assertSame(
                BibleTranslations::FAMILY_ENGLISH_PROTESTANT,
                BibleTranslations::familyCode( $code ),
                "{$code} must normalize to the English-Protestant family"
            );
        }

        foreach ( array( '', '   ', 'RVR1960', 'LXX', 'AMP', 'ZZZ' ) as $code ) {
            $this->assertSame(
                '',
                BibleTranslations::familyCode( $code ),
                "{$code} must fall open (empty family) under the live bootstrap"
            );
        }
    }
}
