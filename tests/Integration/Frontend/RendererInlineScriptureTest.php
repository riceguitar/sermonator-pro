<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Frontend\SermonView;

/**
 * Integration coverage for the pure {@see Renderer::scripture()} inline-mode render
 * (Phase 3b, spec §3.7 / T11), driving the REAL WordPress escaping primitives
 * (`esc_html` / `esc_url` / `esc_attr` / `esc_html__`) instead of Brain Monkey stubs.
 *
 * NOT run in CI here (no Docker / wp-env in this environment) — authored to run under
 * wp-env later. Its job is to PROVE, against the genuine kses-free esc_html, two things
 * the unit suite asserts against returnArg mocks:
 *   1. the null-inline branch is byte-identical to the shipped 3a link output, and
 *   2. every inline node leaf is esc_html-escaped (no constructed value reaches output
 *      as raw markup) — the Renderer never calls wp_kses on the typed nodes.
 *
 * The Renderer is PURE: it receives an already-resolved {@see ResolvedScripture} and a
 * {@see SermonView}; there is no option/meta/network read here, so no fixture beyond the
 * value objects is needed.
 */
final class RendererInlineScriptureTest extends WP_UnitTestCase {
    private function view(): SermonView {
        return new SermonView(
            id: 7,
            title: 'Inline Render Sermon',
            permalink: 'http://example.test/s/',
            preachedTimestamp: null,
            biblePassage: 'John 3:16',
            audioUrl: '',
            audioDuration: '',
            audioSize: 0,
            videoEmbed: '',
            videoUrl: '',
            views: 0,
            imageId: 0,
            bulletinUrl: '',
            notes: '',
            preachers: array(),
            preacherLabel: '',
            effectiveImageId: 0,
        );
    }

    public function test_null_inline_branch_is_byte_identical_to_3a_link_under_real_esc(): void {
        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'John 3:16',
                'linkUrl'        => 'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV',
                'version'        => 'ESV',
                'inlineEligible' => false,
                'inline'         => null,
            ),
        ) );

        $expected = '<section class="sermonator-scripture">'
            . '<h2 class="sermonator-scripture__heading">' . esc_html__( 'Scripture', 'sermonator' ) . '</h2>'
            . '<ul class="sermonator-scripture__list">'
            . '<li class="sermonator-scripture__ref">'
            . '<a class="sermonator-scripture__link" href="'
            . esc_url( 'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV' ) . '">'
            . esc_html( 'John 3:16' )
            . '</a>'
            . ' <span class="sermonator-scripture__version">(' . esc_html( 'ESV' ) . ')</span>'
            . '</li>'
            . '</ul></section>';

        $this->assertSame( $expected, ( new Renderer() )->scripture( $this->view(), $resolved ) );
    }

    /**
     * INSTANT-ROLLBACK GUARANTEE under the REAL escaping primitives (T-J / spec §5):
     * the SAME ref rendered with `inline => null` (inline disabled / floor=exact /
     * any L1–L9 withhold) is byte-identical to the canonical 3a link output, while
     * the inline payload genuinely differs. Disabling inline returns the sermon to
     * its 3a link unchanged, to the byte — with no stored-meta change to undo.
     */
    public function test_inline_off_is_byte_identical_to_3a_link_rollback_under_real_esc(): void {
        $ref = array(
            'label'          => 'John 3:16',
            'linkUrl'        => 'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV',
            'version'        => 'ESV',
            'inlineEligible' => true,
        );

        $on = ( new Renderer() )->scripture( $this->view(), new ResolvedScripture( array(
            $ref + array(
                'inline' => array(
                    'translation' => 'ENGWEBP',
                    'attribution' => 'World English Bible',
                    'verses'      => array(
                        array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'For God so loved the world' ) ) ),
                    ),
                ),
            ),
        ) ) );

        $off = ( new Renderer() )->scripture(
            $this->view(),
            new ResolvedScripture( array( $ref + array( 'inline' => null ) ) )
        );

        $expected = '<section class="sermonator-scripture">'
            . '<h2 class="sermonator-scripture__heading">' . esc_html__( 'Scripture', 'sermonator' ) . '</h2>'
            . '<ul class="sermonator-scripture__list">'
            . '<li class="sermonator-scripture__ref">'
            . '<a class="sermonator-scripture__link" href="'
            . esc_url( 'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV' ) . '">'
            . esc_html( 'John 3:16' )
            . '</a>'
            . ' <span class="sermonator-scripture__version">(' . esc_html( 'ESV' ) . ')</span>'
            . '</li>'
            . '</ul></section>';

        $this->assertSame( $expected, $off );
        $this->assertNotSame( $off, $on );
        $this->assertStringContainsString( 'sermonator-scripture__ref--inline', $on );
    }

    public function test_inline_payload_renders_typed_nodes_with_real_escaping(): void {
        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'John 3:16',
                'linkUrl'        => 'http://example.test/a',
                'version'        => 'ESV',
                'inlineEligible' => true,
                'inline'         => array(
                    'translation' => 'ENGWEBP',
                    'attribution' => 'World English Bible',
                    'verses'      => array(
                        array(
                            'number' => 16,
                            'nodes'  => array(
                                array( 'type' => 'text', 'text' => 'For God so loved the world, ' ),
                                array( 'type' => 'wordsOfJesus', 'text' => 'that he gave his one and only Son' ),
                                array( 'type' => 'note', 'text' => 'or, only begotten Son' ),
                            ),
                        ),
                    ),
                ),
            ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        $this->assertStringContainsString( '<span class="sermonator-scripture__attribution">(World English Bible)</span>', $html );
        $this->assertStringContainsString( '<sup class="sermonator-scripture__num">16</sup>', $html );
        $this->assertStringContainsString( 'For God so loved the world, ', $html );
        $this->assertStringContainsString( '<span class="sermonator-scripture__woj">that he gave his one and only Son</span>', $html );
        $this->assertStringContainsString( '<sup class="sermonator-scripture__note">or, only begotten Son</sup>', $html );
        $this->assertStringNotContainsString( 'sermonator-scripture__link', $html );
    }

    public function test_inline_node_leaves_are_esc_html_escaped_not_wp_kses(): void {
        // Under real esc_html a script/img/style payload must come out fully entity-
        // encoded. (wp_kses_post would strip-but-allow some of this; esc_html encodes
        // ALL of it — the stricter, correct choice for values WE construct.)
        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'John 3:16',
                'linkUrl'        => 'http://example.test/a',
                'version'        => 'ESV',
                'inlineEligible' => true,
                'inline'         => array(
                    'translation' => 'ENGWEBP',
                    'attribution' => '<b>WEB</b>',
                    'verses'      => array(
                        array(
                            'number' => 16,
                            'nodes'  => array(
                                array( 'type' => 'text', 'text' => '<script>alert(1)</script>' ),
                                array( 'type' => 'wordsOfJesus', 'text' => '<img src=x onerror=evil()>' ),
                                array( 'type' => 'note', 'text' => '<em>kept?</em>' ),
                            ),
                        ),
                    ),
                ),
            ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '<img src=x', $html );
        $this->assertStringNotContainsString( '<b>WEB</b>', $html );
        // <em> would SURVIVE wp_kses_post; esc_html encodes it — proof we used esc_html.
        $this->assertStringNotContainsString( '<em>kept?</em>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
        $this->assertStringContainsString( '&lt;em&gt;kept?&lt;/em&gt;', $html );
        // Structural wrappers we build remain.
        $this->assertStringContainsString( '<span class="sermonator-scripture__woj">', $html );
    }

    public function test_unknown_node_type_degrades_to_escaped_plain_text(): void {
        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'John 3:16',
                'linkUrl'        => 'http://example.test/a',
                'version'        => 'ESV',
                'inlineEligible' => true,
                'inline'         => array(
                    'translation' => 'ENGWEBP',
                    'attribution' => 'World English Bible',
                    'verses'      => array(
                        array(
                            'number' => 16,
                            'nodes'  => array(
                                array( 'type' => 'poetryBreak', 'text' => '<hr>' ),
                            ),
                        ),
                    ),
                ),
            ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        $this->assertStringNotContainsString( '<hr>', $html );
        $this->assertStringContainsString( '&lt;hr&gt;', $html );
    }

    public function test_long_passage_wraps_in_native_details(): void {
        $verses = array();
        for ( $n = 1; $n <= 6; $n++ ) {
            $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => "Verse {$n}. " ) ) );
        }

        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'Psalm 23:1-6',
                'linkUrl'        => 'http://example.test/a',
                'version'        => 'ESV',
                'inlineEligible' => true,
                'inline'         => array(
                    'translation' => 'ENGWEBP',
                    'attribution' => 'World English Bible',
                    'verses'      => $verses,
                ),
            ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        $this->assertStringContainsString( '<details class="sermonator-scripture__details">', $html );
        $this->assertStringContainsString( '<summary class="sermonator-scripture__summary">', $html );
        $this->assertSame( 6, substr_count( $html, 'sermonator-scripture__verse' ) );
    }
}
