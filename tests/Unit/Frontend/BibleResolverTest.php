<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Schema\Identifiers;

/**
 * Unit coverage for the Phase 3a (link-mode) {@see BibleResolver}.
 *
 * The two impure dependencies are stubbed: `get_post_meta` returns a stored
 * envelope, and `get_option` resolves the axis-A link version to a fixed
 * curated code (ESV) so the Bible Gateway URLs are deterministic.
 */
final class BibleResolverTest extends TestCase {
    /** @var list<array{ref:array<string,mixed>,version:string}> */
    private array $resolvedHook = array();

    /** @var list<array{passage:string,reason:string}> */
    private array $fallbackHook = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->resolvedHook = array();
        $this->fallbackHook = array();

        // Deterministic axis-A link version: ESV (a curated link version, so
        // TranslationRegistry returns it without touching the legacy seed).
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( Identifiers::OPTION_BIBLE_LINK_VERSION === $name ) {
                return 'ESV';
            }
            return $default;
        } );

        // apply_filters passes the value through untouched.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        // Capture observability hooks for assertions.
        Functions\when( 'do_action' )->alias( function ( $tag, ...$args ) {
            if ( 'sermonator_bible_resolved' === $tag ) {
                $this->resolvedHook[] = array( 'ref' => $args[0], 'version' => $args[1] );
            } elseif ( 'sermonator_bible_fallback' === $tag ) {
                $this->fallbackHook[] = array( 'passage' => $args[0], 'reason' => $args[1] );
            }
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubMeta( string $stored ): void {
        Functions\when( 'get_post_meta' )->alias( function ( $postId, $key, $single = false ) use ( $stored ) {
            return Identifiers::META_BIBLE_REFS === $key ? $stored : '';
        } );
    }

    /**
     * @param list<array<string,mixed>> $refs
     */
    private function envelope( array $refs ): string {
        return (string) json_encode( array( 'v' => 1, 'refs' => $refs ) );
    }

    public function test_returns_null_when_meta_absent(): void {
        $this->stubMeta( '' );
        $this->assertNull( BibleResolver::resolve( 123 ) );
    }

    public function test_returns_null_when_envelope_has_no_refs(): void {
        $this->stubMeta( $this->envelope( array() ) );
        $this->assertNull( BibleResolver::resolve( 123 ) );
    }

    public function test_returns_null_on_corrupt_json(): void {
        $this->stubMeta( '{not valid json' );
        $this->assertNull( BibleResolver::resolve( 123 ) );
    }

    public function test_resolves_single_verse_label_and_url(): void {
        $this->stubMeta( $this->envelope( array(
            array(
                'bookUSFM'     => 'JHN',
                'chapterStart' => 3,
                'verseStart'   => 16,
                'verseEnd'     => null,
                'chapterEnd'   => null,
                'raw'          => 'John 3:16',
            ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $refs = $resolved->refs();
        $this->assertCount( 1, $refs );
        $this->assertSame( 'John 3:16', $refs[0]['label'] );
        $this->assertSame( 'ESV', $refs[0]['version'] );
        $this->assertTrue( $refs[0]['inlineEligible'] );
        $this->assertSame(
            'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV',
            $refs[0]['linkUrl']
        );
    }

    public function test_label_variants_cover_chapter_range_and_cross_chapter(): void {
        $this->stubMeta( $this->envelope( array(
            // whole chapter
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => null, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3' ),
            // verse range
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 18, 'chapterEnd' => null, 'raw' => 'John 3:16-18' ),
            // chapter range
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 5, 'verseStart' => null, 'verseEnd' => null, 'chapterEnd' => 7, 'raw' => 'Matthew 5-7' ),
            // cross-chapter verse range
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 5, 'verseStart' => 1, 'verseEnd' => 29, 'chapterEnd' => 7, 'raw' => 'Matthew 5:1-7:29' ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );
        $this->assertInstanceOf( ResolvedScripture::class, $resolved );

        $labels = array_column( $resolved->refs(), 'label' );
        $this->assertSame(
            array( 'John 3', 'John 3:16-18', 'Matthew 5-7', 'Matthew 5:1-7:29' ),
            $labels
        );
    }

    public function test_skips_out_of_canon_ref_and_fires_fallback(): void {
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Hesitations 1:1' ),
            array( 'bookUSFM' => 'GEN', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Genesis 1:1' ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );
        $this->assertInstanceOf( ResolvedScripture::class, $resolved );

        // Only the in-canon ref survives.
        $refs = $resolved->refs();
        $this->assertCount( 1, $refs );
        $this->assertSame( 'Genesis 1:1', $refs[0]['label'] );

        // The out-of-canon ref fired a fallback with its raw passage.
        $this->assertCount( 1, $this->fallbackHook );
        $this->assertSame( 'Hesitations 1:1', $this->fallbackHook[0]['passage'] );
        $this->assertSame( 'not-in-canon', $this->fallbackHook[0]['reason'] );
    }

    public function test_returns_null_when_every_ref_is_skipped(): void {
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Nope 1:1' ),
        ) ) );

        $this->assertNull( BibleResolver::resolve( 123 ) );
        $this->assertCount( 1, $this->fallbackHook );
    }

    public function test_divergent_zone_ref_is_link_but_not_inline_eligible(): void {
        // Psalms is wholly versification-divergent -> link-only (inlineEligible false).
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'PSA', 'chapterStart' => 23, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Psalm 23:1' ),
        ) ) );

        $resolved = BibleResolver::resolve( 123 );
        $this->assertInstanceOf( ResolvedScripture::class, $resolved );

        $refs = $resolved->refs();
        $this->assertSame( 'Psalms 23:1', $refs[0]['label'] );
        $this->assertFalse( $refs[0]['inlineEligible'] );
        // Still resolved (rendered as a link in 3a).
        $this->assertCount( 1, $this->resolvedHook );
    }

    public function test_fires_resolved_hook_per_ref_with_version(): void {
        $this->stubMeta( $this->envelope( array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
            array( 'bookUSFM' => 'ROM', 'chapterStart' => 8, 'verseStart' => 28, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Romans 8:28' ),
        ) ) );

        BibleResolver::resolve( 123 );

        $this->assertCount( 2, $this->resolvedHook );
        $this->assertSame( 'ESV', $this->resolvedHook[0]['version'] );
        $this->assertSame( 'JHN', $this->resolvedHook[0]['ref']['bookUSFM'] );
    }
}
