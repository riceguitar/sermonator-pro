<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\ResolvedScripture;
use Sermonator\Frontend\SermonView;

final class RendererTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'wp_kses_allowed_html' )->justReturn( array() );
        Functions\when( 'wp_oembed_get' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( '__' )->returnArg();
        Functions\when( 'get_the_post_thumbnail' )->justReturn( '' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string,mixed> $over */
    private function view( array $over = array() ): SermonView {
        return new SermonView(
            id: 5,
            title: 'T',
            permalink: 'http://x/s/',
            preachedTimestamp: $over['ts'] ?? null,
            biblePassage: $over['passage'] ?? 'John 1:1-14',
            audioUrl: $over['audio'] ?? '',
            audioDuration: $over['dur'] ?? '',
            audioSize: 0,
            videoEmbed: $over['embed'] ?? '',
            videoUrl: $over['vurl'] ?? '',
            views: 0,
            imageId: $over['imageId'] ?? 0,
            bulletinUrl: $over['bulletinUrl'] ?? '',
            notes: $over['notes'] ?? '',
            preachers: $over['preachers'] ?? array( array( 'name' => 'Pastor John', 'url' => 'http://x/p' ) ),
            preacherLabel: $over['preacherLabel'] ?? '',
            effectiveImageId: $over['effectiveImageId'] ?? 0,
        );
    }

    public function test_meta_uses_threaded_preacher_label(): void {
        $html = ( new Renderer() )->meta( $this->view( array( 'preacherLabel' => 'Speaker' ) ) );
        $this->assertStringContainsString( '<dt>Speaker</dt>', $html );
        $this->assertStringNotContainsString( '<dt>Preacher</dt>', $html );
    }

    public function test_meta_falls_back_to_preacher_when_label_empty(): void {
        // Empty threaded label (the SermonView default) → the hardcoded 'Preacher' fallback.
        $html = ( new Renderer() )->meta( $this->view( array( 'preacherLabel' => '' ) ) );
        $this->assertStringContainsString( '<dt>Preacher</dt>', $html );
    }

    public function test_meta_includes_passage_and_preacher(): void {
        $html = ( new Renderer() )->meta( $this->view() );
        $this->assertStringContainsString( 'John 1:1-14', $html );
        $this->assertStringContainsString( 'Pastor John', $html );
        $this->assertStringContainsString( 'sermonator-meta', $html );
        $this->assertStringContainsString( '<a href="http://x/p">Pastor John</a>', $html );
    }

    public function test_meta_omits_absent_fields(): void {
        $html = ( new Renderer() )->meta( $this->view( array( 'passage' => '', 'preachers' => array() ) ) );
        $this->assertStringNotContainsString( 'sermonator-meta__passage', $html );
        $this->assertStringNotContainsString( 'sermonator-meta__preacher', $html );
        $this->assertStringNotContainsString( '>0<', $html ); // never the literal "0"
    }

    public function test_meta_empty_when_nothing_to_show(): void {
        $empty = new SermonView(
            id: 1, title: 'T', permalink: 'http://x/', preachedTimestamp: null,
            biblePassage: '', audioUrl: '', audioDuration: '', audioSize: 0,
            videoEmbed: '', videoUrl: '', views: 0
        );
        $this->assertSame( '', ( new Renderer() )->meta( $empty ) );
    }

    public function test_audio_player_empty_when_no_audio(): void {
        $this->assertSame( '', ( new Renderer() )->audioPlayer( $this->view() ) );
    }

    public function test_audio_player_renders_audio_tag_and_download(): void {
        $html = ( new Renderer() )->audioPlayer( $this->view( array( 'audio' => 'http://x/a.mp3', 'dur' => '00:10:00' ) ) );
        $this->assertStringContainsString( '<audio', $html );
        $this->assertStringContainsString( 'http://x/a.mp3', $html );
        $this->assertStringContainsString( 'download', $html );
        $this->assertStringContainsString( 'data-duration="00:10:00"', $html );
    }

    public function test_video_prefers_embed_then_url_then_empty(): void {
        $r = new Renderer();
        $this->assertStringContainsString( '<iframe', $r->video( $this->view( array( 'embed' => '<iframe src="x"></iframe>' ) ) ) );
        // wp_oembed_get stubbed to false → falls back to a plain link.
        $this->assertStringContainsString( 'http://x/v', $r->video( $this->view( array( 'vurl' => 'http://x/v' ) ) ) );
        $this->assertSame( '', $r->video( $this->view() ) );
    }

    public function test_video_url_uses_oembed_when_available(): void {
        Functions\when( 'wp_oembed_get' )->justReturn( '<iframe class="oembed" src="http://x/player"></iframe>' );
        $html = ( new Renderer() )->video( $this->view( array( 'vurl' => 'http://youtube.com/watch?v=x' ) ) );
        $this->assertStringContainsString( 'oembed', $html );
        $this->assertStringContainsString( 'sermonator-video', $html );
    }

    public function test_date_label_uses_wp_date(): void {
        Functions\when( 'get_option' )->justReturn( 'F j, Y' );
        Functions\when( 'wp_date' )->justReturn( 'December 21, 2025' );
        $html = ( new Renderer() )->meta( $this->view( array( 'ts' => 1734775200 ) ) );
        $this->assertStringContainsString( 'December 21, 2025', $html );
        $this->assertStringContainsString( 'sermonator-meta__date', $html );
    }

    public function test_card_links_title_and_shows_preacher_and_audio_badge(): void {
        $html = ( new Renderer() )->card( $this->view( array( 'audio' => 'http://x/a.mp3' ) ) );
        $this->assertStringContainsString( 'sermonator-card', $html );
        $this->assertStringContainsString( '<a href="http://x/s/">T</a>', $html );
        $this->assertStringContainsString( 'Pastor John', $html );
        $this->assertStringContainsString( 'sermonator-card__badge', $html );
    }

    public function test_card_no_audio_badge_when_no_audio(): void {
        $html = ( new Renderer() )->card( $this->view() );
        $this->assertStringNotContainsString( 'sermonator-card__badge', $html );
    }

    public function test_grid_empty_state(): void {
        $result = new \Sermonator\Frontend\QueryResult( array(), 0, 0, 1 );
        $html   = ( new Renderer() )->grid( $result );
        $this->assertStringContainsString( 'sermonator-grid__empty', $html );
        $this->assertStringNotContainsString( 'sermonator-card', $html );
    }

    public function test_grid_renders_cards_with_columns(): void {
        $result = new \Sermonator\Frontend\QueryResult( array( $this->view(), $this->view() ), 2, 1, 1 );
        $html   = ( new Renderer() )->grid( $result, array( 'columns' => 4 ) );
        $this->assertStringContainsString( 'data-columns="4"', $html );
        $this->assertSame( 2, substr_count( $html, 'sermonator-card"' ) );
    }

    public function test_taxonomy_links_respects_show_count_false(): void {
        $terms = array( array( 'name' => 'Grace', 'url' => 'http://x/g', 'count' => 4 ) );
        $with  = ( new Renderer() )->taxonomyLinks( $terms, 'Topics', true );
        $without = ( new Renderer() )->taxonomyLinks( $terms, 'Topics', false );
        $this->assertStringContainsString( 'sermonator-termlist__count', $with );
        $this->assertStringNotContainsString( 'sermonator-termlist__count', $without );
    }

    public function test_featured_image_empty_when_no_image(): void {
        $this->assertSame( '', ( new Renderer() )->featuredImage( $this->view() ) );
    }

    public function test_featured_image_renders_thumbnail(): void {
        Functions\when( 'get_the_post_thumbnail' )->justReturn( '<img src="http://x/img.jpg" alt="">' );
        $html = ( new Renderer() )->featuredImage( $this->view( array( 'imageId' => 42 ) ) );
        $this->assertStringContainsString( 'sermonator-single__media', $html );
        $this->assertStringContainsString( '<img src="http://x/img.jpg" alt="">', $html );
    }

    public function test_featured_image_renders_default_when_no_thumbnail(): void {
        // No real thumbnail (imageId 0) but a resolved default image id: the
        // Renderer renders the configured default via the attachment API (legacy
        // default_image parity), never the post-thumbnail API.
        Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="http://x/default.jpg" alt="">' );
        $html = ( new Renderer() )->featuredImage( $this->view( array( 'effectiveImageId' => 77 ) ) );
        $this->assertStringContainsString( 'sermonator-single__media', $html );
        $this->assertStringContainsString( '<img src="http://x/default.jpg" alt="">', $html );
    }

    public function test_bulletin_empty_when_no_url(): void {
        $this->assertSame( '', ( new Renderer() )->bulletin( $this->view() ) );
    }

    public function test_bulletin_renders_download_link(): void {
        $html = ( new Renderer() )->bulletin( $this->view( array( 'bulletinUrl' => 'http://x/bulletin.pdf' ) ) );
        $this->assertStringContainsString( 'sermonator-bulletin', $html );
        $this->assertStringContainsString( 'http://x/bulletin.pdf', $html );
        $this->assertStringContainsString( 'Download bulletin', $html );
        $this->assertStringContainsString( 'download', $html );
    }

    public function test_notes_empty_when_no_notes(): void {
        $this->assertSame( '', ( new Renderer() )->notes( $this->view() ) );
    }

    public function test_notes_renders_download_link(): void {
        $html = ( new Renderer() )->notes( $this->view( array( 'notes' => 'http://x/notes.pdf' ) ) );
        $this->assertStringContainsString( 'sermonator-notes', $html );
        $this->assertStringContainsString( 'http://x/notes.pdf', $html );
        $this->assertStringContainsString( 'Download sermon notes', $html );
        $this->assertStringContainsString( 'download', $html );
    }

    public function test_scripture_null_renders_nothing(): void {
        // Fail-open: null leaves today's escaped meta row byte-identical.
        $this->assertSame( '', ( new Renderer() )->scripture( $this->view(), null ) );
    }

    public function test_scripture_empty_resolution_renders_nothing(): void {
        $this->assertSame( '', ( new Renderer() )->scripture( $this->view(), new ResolvedScripture( array() ) ) );
    }

    public function test_scripture_renders_link_label_and_version_badge(): void {
        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'John 3:16',
                'linkUrl'        => 'https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV',
                'version'        => 'ESV',
                'inlineEligible' => false,
            ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        $this->assertStringContainsString( 'sermonator-scripture', $html );
        $this->assertStringContainsString(
            '<a class="sermonator-scripture__link" href="https://www.biblegateway.com/passage/?search=John%203%3A16&version=ESV">John 3:16</a>',
            $html
        );
        // The version badge exists ONLY on the resolved path.
        $this->assertStringContainsString( '<span class="sermonator-scripture__version">(ESV)</span>', $html );
    }

    public function test_scripture_renders_one_item_per_ref(): void {
        $resolved = new ResolvedScripture( array(
            array( 'label' => 'John 3:16', 'linkUrl' => 'http://x/a', 'version' => 'ESV', 'inlineEligible' => false ),
            array( 'label' => 'Matthew 5:1-7:29', 'linkUrl' => 'http://x/b', 'version' => 'ESV', 'inlineEligible' => false ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        $this->assertSame( 2, substr_count( $html, 'sermonator-scripture__ref' ) );
        $this->assertStringContainsString( 'Matthew 5:1-7:29', $html );
    }

    public function test_term_image_grid_empty_input_renders_nothing(): void {
        $this->assertSame( '', ( new Renderer() )->termImageGrid( array(), 'Series', 3 ) );
    }

    public function test_term_image_grid_renders_linked_images_with_label_and_columns(): void {
        $items = array(
            array(
                'name'        => 'Grace',
                'url'         => 'http://x/series/grace',
                'imageHtml'   => '<img src="http://x/grace.jpg" alt="Grace">',
                'description' => '',
            ),
            array(
                'name'        => 'Hope',
                'url'         => '',
                'imageHtml'   => '<img src="http://x/hope.jpg" alt="Hope">',
                'description' => '',
            ),
        );
        $html = ( new Renderer() )->termImageGrid( $items, 'Series', 4 );

        $this->assertStringContainsString( '<ul class="sermonator-image-grid" data-columns="4">', $html );
        $this->assertStringContainsString( 'sermonator-image-grid__label', $html );
        $this->assertStringContainsString( 'Series', $html );
        // Core attachment HTML is passed through verbatim (already-safe).
        $this->assertStringContainsString( '<img src="http://x/grace.jpg" alt="Grace">', $html );
        // Linked item links to its term url; the unlinked item is plain.
        $this->assertStringContainsString( '<a href="http://x/series/grace">', $html );
        $this->assertSame( 2, substr_count( $html, 'sermonator-image-grid__item' ) );
    }

    public function test_term_image_grid_clamps_columns(): void {
        $items = array( array( 'name' => 'A', 'url' => '', 'imageHtml' => '', 'description' => '' ) );
        $this->assertStringContainsString(
            'data-columns="6"',
            ( new Renderer() )->termImageGrid( $items, '', 99 )
        );
        $this->assertStringContainsString(
            'data-columns="1"',
            ( new Renderer() )->termImageGrid( $items, '', 0 )
        );
    }

    public function test_term_image_grid_escapes_name_and_url(): void {
        Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
        Functions\when( 'esc_url' )->alias(
            static fn( $s ) => str_replace( array( '"', '<', '>' ), array( '%22', '%3C', '%3E' ), (string) $s )
        );

        $items = array(
            array(
                'name'        => 'Evil <script>alert(1)</script>',
                'url'         => 'http://x/"><script>',
                // Already-safe core HTML — passed through untouched.
                'imageHtml'   => '<img src="http://x/i.jpg" alt="">',
                'description' => '',
            ),
        );
        $html = ( new Renderer() )->termImageGrid( $items, 'Series', 3 );

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '"><script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
        // The attachment HTML still passed through verbatim.
        $this->assertStringContainsString( '<img src="http://x/i.jpg" alt="">', $html );
    }

    public function test_latest_series_empty_input_renders_nothing(): void {
        $r = new Renderer();
        $this->assertSame( '', $r->latestSeries( array(), true, true ) );
        $this->assertSame(
            '',
            $r->latestSeries(
                array( 'name' => '', 'url' => 'http://x/s', 'imageHtml' => '<img>', 'description' => 'd' ),
                true,
                true
            )
        );
    }

    public function test_latest_series_renders_image_title_and_description(): void {
        $item = array(
            'name'        => 'Advent',
            'url'         => 'http://x/series/advent',
            'imageHtml'   => '<img src="http://x/advent.jpg" alt="Advent">',
            'description' => 'A season of waiting.',
        );
        $html = ( new Renderer() )->latestSeries( $item, true, true );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringContainsString( '<img src="http://x/advent.jpg" alt="Advent">', $html );
        $this->assertStringContainsString( '<a href="http://x/series/advent">Advent</a>', $html );
        $this->assertStringContainsString( 'A season of waiting.', $html );
    }

    public function test_latest_series_respects_show_title_and_description_toggles(): void {
        $item = array(
            'name'        => 'Advent',
            'url'         => 'http://x/series/advent',
            'imageHtml'   => '<img src="http://x/advent.jpg" alt="Advent">',
            'description' => 'A season of waiting.',
        );
        $html = ( new Renderer() )->latestSeries( $item, false, false );

        $this->assertStringNotContainsString( 'sermonator-latest-series__title', $html );
        $this->assertStringNotContainsString( 'sermonator-latest-series__description', $html );
        // The image still renders even with both labels suppressed.
        $this->assertStringContainsString( '<img src="http://x/advent.jpg" alt="Advent">', $html );
    }

    public function test_latest_series_escapes_title_url_and_kses_description(): void {
        Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
        Functions\when( 'esc_url' )->alias(
            static fn( $s ) => str_replace( array( '"', '<', '>' ), array( '%22', '%3C', '%3E' ), (string) $s )
        );
        // wp_kses_post strips disallowed tags (e.g. <script>) but keeps safe inline HTML.
        Functions\when( 'wp_kses_post' )->alias(
            static fn( $s ) => preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $s )
        );

        $item = array(
            'name'        => 'Evil <script>alert(1)</script>',
            'url'         => 'http://x/"><script>',
            'imageHtml'   => '<img src="http://x/i.jpg" alt="">',
            'description' => 'Safe <em>desc</em><script>alert(2)</script>',
        );
        $html = ( new Renderer() )->latestSeries( $item, true, true );

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '"><script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
        // Curated inline HTML in the description survives wp_kses_post.
        $this->assertStringContainsString( 'Safe <em>desc</em>', $html );
    }

    public function test_scripture_escapes_label_url_and_badge(): void {
        // Real escaping (not returnArg) so we can prove nothing reaches output raw.
        Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
        Functions\when( 'esc_url' )->alias(
            static fn( $s ) => str_replace( array( '"', '<', '>' ), array( '%22', '%3C', '%3E' ), (string) $s )
        );
        Functions\when( 'esc_html__' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );

        $resolved = new ResolvedScripture( array(
            array(
                'label'          => 'Evil <script>alert(1)</script>',
                'linkUrl'        => 'http://x/"><script>',
                'version'        => '<b>ESV</b>',
                'inlineEligible' => false,
            ),
        ) );

        $html = ( new Renderer() )->scripture( $this->view(), $resolved );

        // No raw unescaped markup from any dynamic value reached the output.
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '"><script>', $html );
        $this->assertStringNotContainsString( '<b>ESV</b>', $html );
        // The escaped forms are present.
        $this->assertStringContainsString( '&lt;script&gt;', $html );
        $this->assertStringContainsString( '&lt;b&gt;ESV&lt;/b&gt;', $html );
    }
}
