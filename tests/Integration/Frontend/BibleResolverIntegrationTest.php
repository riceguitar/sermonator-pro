<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\BibleResolver;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the Phase 3a (link-mode) {@see BibleResolver}, driving
 * real `get_post_meta` + `get_option` (no Brain Monkey). NOT run in CI here (no
 * Docker / wp-env in this environment) — authored to run under wp-env later.
 */
final class BibleResolverIntegrationTest extends WP_UnitTestCase {
    private function sermon(): int {
        return (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => 'Scripture Sermon',
        ) );
    }

    /**
     * @param list<array<string,mixed>> $refs
     */
    private function storeRefs( int $id, array $refs ): void {
        update_post_meta(
            $id,
            ID::META_BIBLE_REFS,
            wp_json_encode( array( 'v' => 1, 'refs' => $refs ) )
        );
    }

    public function test_returns_null_when_no_refs_meta(): void {
        $id = $this->sermon();
        $this->assertNull( BibleResolver::resolve( $id ) );
    }

    public function test_resolves_real_envelope_to_biblegateway_links(): void {
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'NIV' );

        $id = $this->sermon();
        $this->storeRefs( $id, array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 5, 'verseStart' => 1, 'verseEnd' => 29, 'chapterEnd' => 7, 'raw' => 'Matthew 5:1-7:29' ),
        ) );

        $resolved = BibleResolver::resolve( $id );

        $this->assertInstanceOf( ResolvedScripture::class, $resolved );
        $refs = $resolved->refs();
        $this->assertCount( 2, $refs );

        $this->assertSame( 'John 3:16', $refs[0]['label'] );
        $this->assertSame( 'NIV', $refs[0]['version'] );
        $this->assertSame(
            'https://www.biblegateway.com/passage/?search=John%203%3A16&version=NIV',
            $refs[0]['linkUrl']
        );

        $this->assertSame( 'Matthew 5:1-7:29', $refs[1]['label'] );
    }

    public function test_fires_resolved_and_fallback_hooks(): void {
        $resolved = array();
        $fallback = array();
        add_action( 'sermonator_bible_resolved', static function ( $ref, $version ) use ( &$resolved ) {
            $resolved[] = array( $ref['bookUSFM'], $version );
        }, 10, 2 );
        add_action( 'sermonator_bible_fallback', static function ( $passage, $reason ) use ( &$fallback ) {
            $fallback[] = array( $passage, $reason );
        }, 10, 2 );

        $id = $this->sermon();
        $this->storeRefs( $id, array(
            array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Bogus 1:1' ),
            array( 'bookUSFM' => 'GEN', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'Genesis 1:1' ),
        ) );

        BibleResolver::resolve( $id );

        $this->assertSame( array( array( 'GEN', $resolved[0][1] ) ), $resolved );
        $this->assertSame( array( array( 'Bogus 1:1', 'not-in-canon' ) ), $fallback );
    }

    public function test_never_mutates_preserved_passage_meta(): void {
        $id = $this->sermon();
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 3:16' );
        $this->storeRefs( $id, array(
            array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
        ) );

        BibleResolver::resolve( $id );

        $this->assertSame( 'John 3:16', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }
}
