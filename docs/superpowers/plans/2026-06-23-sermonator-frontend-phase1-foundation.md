# Sermonator Front-End — Phase 0 + Phase 1 (Foundation & Single View) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A single `sermonator_sermon` renders richly (meta, audio player, video) with zero
configuration on both block themes (TT5/FSE) and classic themes, built on a shared read
model + renderer that later phases reuse.

**Architecture:** Block-first FSE-native with a classic PHP fallback. One read-only
namespace `Sermonator\Frontend\`. `TemplateData` is the only class that reads post
meta/terms; `Renderer` is the only class that builds piece-HTML. Dynamic blocks +
`register_block_template()` serve block themes; `single_template` filter + a PHP template
serve classic themes. Single-meta renders by *template presence* (no request-scoped guard).

**Tech Stack:** PHP 8.1+, WordPress 7.0+, Composer PSR-4, PHPUnit 9.6 (Brain Monkey units +
wp-env integration), vanilla JS for the player. No runtime dependencies.

## Global Constraints

- **WP floor 7.0+, PHP 8.1+.** (verbatim from spec §0; raised from 6.0 per ADR)
- **GPL-2.0-or-later, ZERO monetization, no warranties.**
- **Read-only.** Phase 1 writes NO data. (The only DB write in the whole feature is the
  Phase 3 enclosure backfill — not in this plan.)
- **All output escaped at the `Renderer` boundary** — `esc_html`/`esc_url`/`esc_attr`;
  stored video embed HTML via `wp_kses_post`. No raw meta echoed anywhere.
- **Canonical identifiers come from `Sermonator\Schema\Identifiers`** — never hardcode post
  type / taxonomy / meta-key strings. Post type `sermonator_sermon`; meta keys
  `sermonator_date`, `sermonator_bible_passage`, `sermonator_audio`,
  `_sermonator_audio_duration`, `_sermonator_audio_size`, `sermonator_video_embed`,
  `sermonator_video_url`, `sermonator_views`; taxonomies via `Identifiers::sermonTaxonomies()`.
- **Naming:** classes `Sermonator\Frontend\*`; blocks `sermonator/*`; CSS classes
  `sermonator-*`; filters/actions prefixed `sermonator_frontend_*`.
- **Single-meta by template presence** — NO request-scoped render guard. `the_content`
  auto-append is an explicit opt-in, default **OFF** (`sermonator_frontend_auto_append_meta`).
- **Minimal theme-inheriting CSS** with hardcoded fallbacks as the baseline; preset vars
  (`var(--wp--preset--*, fallback)`) are progressive enhancement; no `!important`.

## Test execution

- Unit: `composer test:unit` (Brain Monkey; stub WP funcs with `Brain\Monkey\Functions\when`).
- Integration: run via wp-env Docker. Command used elsewhere in this repo:
  `wp-env run tests-cli --env-cwd=wp-content/plugins/sermonator phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration`
  (If `wp-env` is not running: `wp-env start` first. The repo's `.wp-env.json` defines it.)
- Live verification: the Local "sermonator-test" site via the wrapper
  `…/scratchpad/wp-local.sh` (plugin symlinked + seeded; see `reference-local-test-env`).

## File Structure

| File | Responsibility |
|---|---|
| `src/Frontend/SermonView.php` | Immutable readonly value object: one sermon's display data |
| `src/Frontend/TemplateData.php` | The ONLY reader of post meta/terms → `SermonView` |
| `src/Frontend/Renderer.php` | The ONLY builder of piece-HTML (meta, audio, video, scripture, taxo links) |
| `src/Frontend/Blocks/AbstractBlock.php` | Shared block registration helper |
| `src/Frontend/Blocks/SermonMetaBlock.php` | `sermonator/sermon-meta` register + render_callback |
| `src/Frontend/Blocks/AudioPlayerBlock.php` | `sermonator/audio-player` register + render_callback |
| `src/Frontend/Blocks/VideoBlock.php` | `sermonator/video` register + render_callback |
| `blocks/sermon-meta/block.json` | block metadata (dynamic) |
| `blocks/audio-player/block.json` | block metadata (dynamic) |
| `blocks/video/block.json` | block metadata (dynamic) |
| `src/Frontend/BlockTemplates.php` | `register_block_template('sermonator//single-sermonator_sermon')` |
| `src/Frontend/ClassicTemplates.php` | `single_template` filter + opt-in `the_content` append |
| `templates/classic/single-sermonator-sermon.php` | classic-theme PHP single template |
| `src/Frontend/Assets.php` | conditional enqueue of CSS + player JS |
| `assets/frontend.css` | minimal structural CSS |
| `assets/audio-player.js` | tiny vanilla player enhancement |
| `src/Frontend/FrontendServiceProvider.php` | wires all hooks (init/wp_enqueue_scripts) |
| `src/Plugin.php` (modify) | boot Frontend on front-end + feed requests |
| `sermonator.php` (modify) | header `Requires at least: 7.0` |
| `src/Support/VersionGate.php` (modify) | min WP 7.0 |
| `.wp-env.json` (modify) | core → 7.0 (register_block_template needs ≥6.7) |

---

### Task 0: Phase 0 — de-risk the linchpin (spike + decision)

**Goal:** Before building the fleet, prove on the real Local site that (a) a plugin
`register_block_template('single-sermonator_sermon')` beats TT5's generic `single` with
zero config and renders header/footer, (b) the same data renders on one classic theme via
`single_template`, (c) single-meta renders exactly once by template presence. Record the
outcome; if precedence/parts are fragile, switch single rendering to `template_include`
(same Renderer) before Phase 1.

**Files:** throwaway spike code (do NOT keep); record decision in this plan's "Phase 0
outcome" note + the spec's §11.

- [ ] **Step 1: Minimal block + block template spike.** In a scratch branch, register a
  trivial dynamic block `sermonator/spike` whose render_callback returns
  `'<p data-spike>SPIKE: ' . esc_html( get_the_title() ) . '</p>'`, and register a block
  template:

```php
add_action( 'init', function () {
    register_block_type( 'sermonator/spike', array(
        'render_callback' => fn() => '<p data-spike>SPIKE ' . esc_html( (string) get_the_ID() ) . '</p>',
    ) );
    if ( function_exists( 'register_block_template' ) ) {
        register_block_template( 'sermonator//single-sermonator_sermon', array(
            'title'   => 'Spike Single Sermon',
            'content' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
                       . '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group">'
                       . '<!-- wp:post-title {"level":1} /-->'
                       . '<!-- wp:sermonator/spike /-->'
                       . '<!-- wp:post-content /-->'
                       . '</main><!-- /wp:group -->'
                       . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
        ) );
    }
} );
```

- [ ] **Step 2: Deploy + verify precedence on TT5 (block theme).**

Run:
```bash
WP=/private/tmp/claude-501/-Users-david-Repo-sermonator-pro/a71493fd-f36c-46c1-809f-bd906fcfc302/scratchpad/wp-local.sh
"$WP" eval 'echo wp_is_block_theme() ? "block\n" : "classic\n";'
curl -s http://sermonator-test.local/sermons/the-light-has-come/ | grep -c 'data-spike'
```
Expected: `block` and the grep count ≥ 1 (our block template won, the spike block rendered).
Also confirm header/footer rendered: `curl -s … | grep -ci '<header'` ≥ 1.

- [ ] **Step 3: Verify classic-theme path.**

Run:
```bash
"$WP" theme activate twentytwentyone
curl -s http://sermonator-test.local/sermons/the-light-has-come/ | grep -c 'data-spike'
"$WP" theme activate twentytwentyfive   # restore
```
On the classic theme the block template does NOT apply; this step verifies the *baseline*
that we will need `single_template` for classic (expect grep count `0` here — that is the
gap Task 7 fills). Record the observation.

- [ ] **Step 4: Verify meta-by-presence (no double, no request state).** With the spike
  block present in the template, confirm exactly one `data-spike` on the single. Remove the
  `wp:sermonator/spike` line from the registered template content, reload, confirm `0`.
  This proves presence controls rendering — no guard needed.

- [ ] **Step 5: Record decision + tear down.** Append a "## Phase 0 outcome" note to THIS
  plan file stating: block-template precedence on TT5 = pass/fail, header/footer parts =
  pass/fail, decision = "proceed block-first" OR "fall back to template_include for singles".
  Delete all spike code (it must not ship). Commit only the plan-file note:

```bash
git add docs/superpowers/plans/2026-06-23-sermonator-frontend-phase1-foundation.md
git commit -m "spike(frontend): Phase 0 linchpin de-risk outcome"
```

---

### Task 1: Raise the WP floor to 7.0 (plugin header, VersionGate, wp-env)

**Files:**
- Modify: `sermonator.php` (header `Requires at least`)
- Modify: `src/Support/VersionGate.php`
- Modify: `.wp-env.json`
- Test: `tests/Unit/Support/VersionGateTest.php` (existing — update expectations)

**Interfaces:**
- Produces: `VersionGate` enforcing WP ≥ 7.0; unchanged constructor signature
  `__construct(string $phpVersion, string $wpVersion)` and methods `isSatisfied(): bool`,
  `failureMessage(): string`.

- [ ] **Step 1: Read current VersionGate + its test** to learn the exact min-version
  constant name and message format. Run: `cat src/Support/VersionGate.php tests/Unit/Support/VersionGateTest.php`.

- [ ] **Step 2: Update the failing test first.** Change the WP-version assertions so that
  `'6.9'` (or current min-1) is NOT satisfied and `'7.0'` IS. Example edit — adjust to the
  file's actual structure:

```php
public function test_wordpress_below_7_0_is_rejected(): void {
    $gate = new VersionGate( '8.1.0', '6.9' );
    $this->assertFalse( $gate->isSatisfied() );
}

public function test_wordpress_7_0_is_accepted(): void {
    $gate = new VersionGate( '8.1.0', '7.0' );
    $this->assertTrue( $gate->isSatisfied() );
}
```

- [ ] **Step 3: Run unit tests, expect the new WP assertions to FAIL.**
  Run: `composer test:unit -- --filter VersionGate`
  Expected: FAIL (min version still 6.0).

- [ ] **Step 4: Bump the minimum.** In `VersionGate.php` change the WP minimum constant
  from `6.0` to `7.0` (leave PHP at 8.1). In `sermonator.php` change the header line to
  `* Requires at least: 7.0`. In `.wp-env.json` change `"core": "WordPress/WordPress#6.5"`
  to `"core": "WordPress/WordPress#7.0"`.

- [ ] **Step 5: Run unit tests, expect PASS.**
  Run: `composer test:unit -- --filter VersionGate`
  Expected: PASS.

- [ ] **Step 6: Commit.**

```bash
git add sermonator.php src/Support/VersionGate.php .wp-env.json tests/Unit/Support/VersionGateTest.php
git commit -m "feat(frontend): raise WP floor to 7.0 (register_block_template)"
```

---

### Task 2: `SermonView` value object + `TemplateData` read model

**Files:**
- Create: `src/Frontend/SermonView.php`
- Create: `src/Frontend/TemplateData.php`
- Test: `tests/Unit/Frontend/TemplateDataTest.php`

**Interfaces:**
- Produces:
  - `final class SermonView` — readonly props: `int $id`, `string $title`,
    `string $permalink`, `?int $preachedTimestamp`, `string $biblePassage`,
    `string $audioUrl`, `string $audioDuration`, `int $audioSize`, `string $videoEmbed`,
    `string $videoUrl`, `int $views`, and term arrays `array $preachers, $series, $topics,
    $books, $serviceTypes` (each a list of `array{name:string,url:string}`).
  - `final class TemplateData { public function sermon(int $postId): SermonView }` — reads
    `get_post`, `get_post_meta`, `get_the_terms`/`get_term_link`, `get_permalink`. Returns
    a `SermonView`. Missing meta → empty string / null / `[]` (never the literal "0").

- [ ] **Step 1: Write the failing unit test.** Stub WP functions with Brain Monkey:

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

final class TemplateDataTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_maps_meta_and_terms_into_view(): void {
        Functions\when('get_post')->justReturn((object) array('ID' => 5));
        Functions\when('get_the_title')->justReturn('The Light Has Come');
        Functions\when('get_permalink')->justReturn('http://x/sermons/the-light-has-come/');
        $meta = array(
            ID::META_DATE          => array('1734775200'),
            ID::META_BIBLE_PASSAGE => array('John 1:1-14'),
            ID::META_AUDIO         => array('http://x/a.mp3'),
            ID::META_AUDIO_DURATION=> array('00:34:12'),
            ID::META_AUDIO_SIZE    => array('32871234'),
            ID::META_VIDEO_URL     => array('http://x/v'),
            ID::META_VIEWS         => array('142'),
        );
        Functions\when('get_post_meta')->alias(
            fn($id, $key, $single) => $single ? ($meta[$key][0] ?? '') : ($meta[$key] ?? array())
        );
        Functions\when('get_the_terms')->justReturn(array());
        Functions\when('get_term_link')->justReturn('http://x/term');

        $view = (new TemplateData())->sermon(5);

        $this->assertSame('The Light Has Come', $view->title);
        $this->assertSame('John 1:1-14', $view->biblePassage);
        $this->assertSame('http://x/a.mp3', $view->audioUrl);
        $this->assertSame(32871234, $view->audioSize);
        $this->assertSame(142, $view->views);
        $this->assertSame(1734775200, $view->preachedTimestamp);
        $this->assertSame('', $view->videoEmbed);   // missing → empty, never "0"
    }
}
```

- [ ] **Step 2: Run it, expect FAIL** (`Class "Sermonator\Frontend\TemplateData" not found`).
  Run: `composer test:unit -- --filter TemplateDataTest`

- [ ] **Step 3: Implement `SermonView`** (readonly value object):

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

final class SermonView {
    /**
     * @param list<array{name:string,url:string}> $preachers
     * @param list<array{name:string,url:string}> $series
     * @param list<array{name:string,url:string}> $topics
     * @param list<array{name:string,url:string}> $books
     * @param list<array{name:string,url:string}> $serviceTypes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $permalink,
        public readonly ?int $preachedTimestamp,
        public readonly string $biblePassage,
        public readonly string $audioUrl,
        public readonly string $audioDuration,
        public readonly int $audioSize,
        public readonly string $videoEmbed,
        public readonly string $videoUrl,
        public readonly int $views,
        public readonly array $preachers = array(),
        public readonly array $series = array(),
        public readonly array $topics = array(),
        public readonly array $books = array(),
        public readonly array $serviceTypes = array()
    ) {}
}
```

- [ ] **Step 4: Implement `TemplateData`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

final class TemplateData {
    public function sermon( int $postId ): SermonView {
        $str = static fn( string $key ): string => (string) get_post_meta( $postId, $key, true );
        $rawDate = $str( ID::META_DATE );
        return new SermonView(
            id:                $postId,
            title:             (string) get_the_title( $postId ),
            permalink:         (string) get_permalink( $postId ),
            preachedTimestamp: ( $rawDate !== '' && ctype_digit( $rawDate ) ) ? (int) $rawDate : null,
            biblePassage:      $str( ID::META_BIBLE_PASSAGE ),
            audioUrl:          $str( ID::META_AUDIO ),
            audioDuration:     $str( ID::META_AUDIO_DURATION ),
            audioSize:         (int) $str( ID::META_AUDIO_SIZE ),
            videoEmbed:        $str( ID::META_VIDEO_EMBED ),
            videoUrl:          $str( ID::META_VIDEO_URL ),
            views:             (int) $str( ID::META_VIEWS ),
            preachers:         $this->terms( $postId, ID::TAX_PREACHER ),
            series:            $this->terms( $postId, ID::TAX_SERIES ),
            topics:            $this->terms( $postId, ID::TAX_TOPIC ),
            books:             $this->terms( $postId, ID::TAX_BOOK ),
            serviceTypes:      $this->terms( $postId, ID::TAX_SERVICE_TYPE ),
        );
    }

    /** @return list<array{name:string,url:string}> */
    private function terms( int $postId, string $taxonomy ): array {
        $terms = get_the_terms( $postId, $taxonomy );
        if ( ! is_array( $terms ) ) {
            return array();
        }
        $out = array();
        foreach ( $terms as $term ) {
            $link = get_term_link( $term );
            $out[] = array(
                'name' => (string) $term->name,
                'url'  => is_wp_error( $link ) ? '' : (string) $link,
            );
        }
        return $out;
    }
}
```

- [ ] **Step 5: Run unit test, expect PASS.**
  Run: `composer test:unit -- --filter TemplateDataTest`. (Add `Functions\when('is_wp_error')->justReturn(false);` to the test if needed.)

- [ ] **Step 6: Commit.**

```bash
git add src/Frontend/SermonView.php src/Frontend/TemplateData.php tests/Unit/Frontend/TemplateDataTest.php
git commit -m "feat(frontend): SermonView value object + TemplateData read model"
```

---

### Task 3: `Renderer` — pure escaped HTML builders

**Files:**
- Create: `src/Frontend/Renderer.php`
- Test: `tests/Unit/Frontend/RendererTest.php`

**Interfaces:**
- Consumes: `SermonView` (Task 2).
- Produces: `final class Renderer` with methods returning escaped HTML strings:
  - `meta(SermonView $v): string` — `<dl class="sermonator-meta">` of passage, preacher(s),
    series, date, service type(s), book(s), topic(s); omits absent fields entirely.
  - `audioPlayer(SermonView $v): string` — `<div class="sermonator-audio">…<audio controls
    preload="metadata" src=…>` + download link; empty string if `audioUrl === ''`.
  - `video(SermonView $v): string` — `videoEmbed` via `wp_kses_post`, else oEmbed-able
    `videoUrl` wrapped in `.sermonator-video`; empty string if both empty.
  - `dateLabel(SermonView $v): string` — formatted via `wp_date( get_option('date_format'),
    $ts )`; empty if no timestamp.

- [ ] **Step 1: Write failing unit test** (Brain Monkey stubs for `esc_*`/`wp_kses_post`):

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\SermonView;

final class RendererTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('__')->returnArg();
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function view(array $over = array()): SermonView {
        return new SermonView(
            id: 5, title: 'T', permalink: 'http://x/s/', preachedTimestamp: null,
            biblePassage: $over['passage'] ?? 'John 1:1-14',
            audioUrl: $over['audio'] ?? '', audioDuration: '', audioSize: 0,
            videoEmbed: $over['embed'] ?? '', videoUrl: $over['vurl'] ?? '', views: 0,
            preachers: $over['preachers'] ?? array(array('name' => 'Pastor John', 'url' => 'http://x/p')),
        );
    }

    public function test_meta_includes_passage_and_preacher(): void {
        $html = (new Renderer())->meta($this->view());
        $this->assertStringContainsString('John 1:1-14', $html);
        $this->assertStringContainsString('Pastor John', $html);
        $this->assertStringContainsString('sermonator-meta', $html);
    }

    public function test_meta_omits_absent_fields(): void {
        $html = (new Renderer())->meta($this->view(array('passage' => '', 'preachers' => array())));
        $this->assertStringNotContainsString('sermonator-meta__passage', $html);
        $this->assertStringNotContainsString('>0<', $html); // never the literal "0"
    }

    public function test_audio_player_empty_when_no_audio(): void {
        $this->assertSame('', (new Renderer())->audioPlayer($this->view()));
    }

    public function test_audio_player_renders_audio_tag(): void {
        $html = (new Renderer())->audioPlayer($this->view(array('audio' => 'http://x/a.mp3')));
        $this->assertStringContainsString('<audio', $html);
        $this->assertStringContainsString('http://x/a.mp3', $html);
    }

    public function test_video_prefers_embed_then_url_then_empty(): void {
        $r = new Renderer();
        $this->assertStringContainsString('<iframe', $r->video($this->view(array('embed' => '<iframe src="x"></iframe>'))));
        $this->assertStringContainsString('http://x/v', $r->video($this->view(array('vurl' => 'http://x/v'))));
        $this->assertSame('', $r->video($this->view()));
    }
}
```

- [ ] **Step 2: Run it, expect FAIL.**
  Run: `composer test:unit -- --filter RendererTest`

- [ ] **Step 3: Implement `Renderer`** (escape everything; omit absent fields; never echo "0"):

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

final class Renderer {
    public function meta( SermonView $v ): string {
        $rows = '';
        if ( $v->biblePassage !== '' ) {
            $rows .= $this->row( 'passage', __( 'Scripture', 'sermonator' ), esc_html( $v->biblePassage ) );
        }
        $rows .= $this->termRow( 'preacher', __( 'Preacher', 'sermonator' ), $v->preachers );
        $rows .= $this->termRow( 'series', __( 'Series', 'sermonator' ), $v->series );
        $date = $this->dateLabel( $v );
        if ( $date !== '' ) {
            $rows .= $this->row( 'date', __( 'Date', 'sermonator' ), esc_html( $date ) );
        }
        $rows .= $this->termRow( 'service-type', __( 'Service', 'sermonator' ), $v->serviceTypes );
        $rows .= $this->termRow( 'book', __( 'Book', 'sermonator' ), $v->books );
        $rows .= $this->termRow( 'topic', __( 'Topics', 'sermonator' ), $v->topics );
        if ( $rows === '' ) {
            return '';
        }
        return '<dl class="sermonator-meta">' . $rows . '</dl>';
    }

    public function audioPlayer( SermonView $v ): string {
        if ( $v->audioUrl === '' ) {
            return '';
        }
        $url = esc_url( $v->audioUrl );
        $dur = $v->audioDuration !== '' ? ' data-duration="' . esc_attr( $v->audioDuration ) . '"' : '';
        return '<div class="sermonator-audio"' . $dur . '>'
            . '<audio class="sermonator-audio__el" controls preload="metadata" src="' . $url . '"></audio>'
            . '<a class="sermonator-audio__download" href="' . $url . '" download>'
            . esc_html__( 'Download', 'sermonator' ) . '</a>'
            . '</div>';
    }

    public function video( SermonView $v ): string {
        if ( $v->videoEmbed !== '' ) {
            return '<div class="sermonator-video">' . wp_kses_post( $v->videoEmbed ) . '</div>';
        }
        if ( $v->videoUrl !== '' ) {
            return '<div class="sermonator-video">'
                . '<a href="' . esc_url( $v->videoUrl ) . '">' . esc_html( $v->videoUrl ) . '</a>'
                . '</div>';
        }
        return '';
    }

    public function dateLabel( SermonView $v ): string {
        if ( $v->preachedTimestamp === null ) {
            return '';
        }
        return (string) wp_date( (string) get_option( 'date_format' ), $v->preachedTimestamp );
    }

    private function row( string $key, string $label, string $valueHtml ): string {
        return '<div class="sermonator-meta__' . esc_attr( $key ) . '">'
            . '<dt>' . esc_html( $label ) . '</dt><dd>' . $valueHtml . '</dd></div>';
    }

    /** @param list<array{name:string,url:string}> $terms */
    private function termRow( string $key, string $label, array $terms ): string {
        if ( $terms === array() ) {
            return '';
        }
        $links = array();
        foreach ( $terms as $t ) {
            $links[] = $t['url'] !== ''
                ? '<a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['name'] ) . '</a>'
                : esc_html( $t['name'] );
        }
        return $this->row( $key, $label, implode( ', ', $links ) );
    }
}
```

- [ ] **Step 4: Run unit test, expect PASS.** Add `Functions\when('wp_date')…`/`get_option`
  stubs only if a test exercises `dateLabel` (the provided tests use `preachedTimestamp:
  null`, so no stub needed). Run: `composer test:unit -- --filter RendererTest`.

- [ ] **Step 5: Commit.**

```bash
git add src/Frontend/Renderer.php tests/Unit/Frontend/RendererTest.php
git commit -m "feat(frontend): Renderer pure escaped HTML builders"
```

---

### Task 4: `sermon-meta` dynamic block

**Files:**
- Create: `blocks/sermon-meta/block.json`
- Create: `src/Frontend/Blocks/AbstractBlock.php`
- Create: `src/Frontend/Blocks/SermonMetaBlock.php`
- Test: `tests/Integration/Frontend/BlocksTest.php`

**Interfaces:**
- Consumes: `TemplateData` (Task 2), `Renderer` (Task 3).
- Produces:
  - `abstract class AbstractBlock { abstract public function name(): string;
    abstract public function render( array $attributes, string $content, \WP_Block $block ): string;
    public function register(): void; protected function resolvePostId( array $attributes, \WP_Block $block ): int; }`
    — `register()` calls `register_block_type( dirname(SERMONATOR_FILE)."/blocks/{slug}", ['render_callback'=>[$this,'render']] )`.
  - `final class SermonMetaBlock extends AbstractBlock` — `name(): 'sermonator/sermon-meta'`,
    `render()` returns `(new Renderer())->meta( (new TemplateData())->sermon( $postId ) )`.

- [ ] **Step 1: Write the failing integration test:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

final class BlocksTest extends WP_UnitTestCase {
    private function makeSermon(): int {
        $id = self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON, 'post_title' => 'The Light Has Come' ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
        update_post_meta( $id, ID::META_AUDIO, 'http://x/a.mp3' );
        return (int) $id;
    }

    public function test_sermon_meta_block_renders_passage(): void {
        $id = $this->makeSermon();
        $html = do_blocks( '<!-- wp:sermonator/sermon-meta {"postId":' . $id . '} /-->' );
        $this->assertStringContainsString( 'John 1:1-14', $html );
        $this->assertStringContainsString( 'sermonator-meta', $html );
    }

    public function test_audio_player_block_renders_audio_tag(): void {
        $id = $this->makeSermon();
        $html = do_blocks( '<!-- wp:sermonator/audio-player {"postId":' . $id . '} /-->' );
        $this->assertStringContainsString( '<audio', $html );
    }

    public function test_video_block_empty_without_video(): void {
        $id = $this->makeSermon();
        $html = trim( do_blocks( '<!-- wp:sermonator/video {"postId":' . $id . '} /-->' ) );
        $this->assertSame( '', $html );
    }
}
```

- [ ] **Step 2: Run it, expect FAIL** (block not registered → empty output).
  Run: `wp-env run tests-cli --env-cwd=wp-content/plugins/sermonator phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration --filter BlocksTest`

- [ ] **Step 3: Create `blocks/sermon-meta/block.json`:**

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "sermonator/sermon-meta",
  "title": "Sermon Meta",
  "category": "widgets",
  "description": "Scripture, preacher, series, date and taxonomy details for a sermon.",
  "textdomain": "sermonator",
  "usesContext": [ "postId" ],
  "attributes": { "postId": { "type": "number" } },
  "supports": { "html": false },
  "style": "sermonator-frontend"
}
```

- [ ] **Step 4: Implement `AbstractBlock`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend\Blocks;

abstract class AbstractBlock {
    abstract public function name(): string;
    abstract public function render( array $attributes, string $content, \WP_Block $block ): string;

    public function register(): void {
        $slug = (string) preg_replace( '#^sermonator/#', '', $this->name() );
        register_block_type(
            dirname( SERMONATOR_FILE ) . '/blocks/' . $slug,
            array( 'render_callback' => array( $this, 'render' ) )
        );
    }

    protected function resolvePostId( array $attributes, \WP_Block $block ): int {
        if ( ! empty( $attributes['postId'] ) ) {
            return (int) $attributes['postId'];
        }
        if ( isset( $block->context['postId'] ) ) {
            return (int) $block->context['postId'];
        }
        return (int) get_the_ID();
    }
}
```

- [ ] **Step 5: Implement `SermonMetaBlock`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

final class SermonMetaBlock extends AbstractBlock {
    public function name(): string { return 'sermonator/sermon-meta'; }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->resolvePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->meta( ( new TemplateData() )->sermon( $postId ) );
    }
}
```

- [ ] **Step 6: Register the block from the service provider stub.** (Temporarily, to make
  the test pass, register in a `muplugins_loaded`/`init` hook. Final wiring is Task 9 — but
  add a minimal `FrontendServiceProvider::registerBlocks()` now and call it from
  `Plugin::boot()` guarded by `! is_admin() || wp_doing_ajax()`; see Task 9 for the full
  version.) Minimal interim wiring:

```php
// in src/Frontend/FrontendServiceProvider.php (created fully in Task 9)
add_action( 'init', static function (): void {
    ( new \Sermonator\Frontend\Blocks\SermonMetaBlock() )->register();
    ( new \Sermonator\Frontend\Blocks\AudioPlayerBlock() )->register();
    ( new \Sermonator\Frontend\Blocks\VideoBlock() )->register();
} );
```

  (AudioPlayerBlock + VideoBlock are Tasks 5/6; if implementing strictly in order, register
  only SermonMetaBlock here and add the others in their tasks.)

- [ ] **Step 7: Run the meta test, expect PASS.**
  Run: `… --filter test_sermon_meta_block_renders_passage`

- [ ] **Step 8: Commit.**

```bash
git add blocks/sermon-meta src/Frontend/Blocks/AbstractBlock.php src/Frontend/Blocks/SermonMetaBlock.php tests/Integration/Frontend/BlocksTest.php
git commit -m "feat(frontend): sermon-meta dynamic block"
```

---

### Task 5: `audio-player` block + player JS

**Files:**
- Create: `blocks/audio-player/block.json`
- Create: `src/Frontend/Blocks/AudioPlayerBlock.php`
- Create: `assets/audio-player.js`

**Interfaces:**
- Produces: `final class AudioPlayerBlock extends AbstractBlock` — `name():
  'sermonator/audio-player'`, `render()` → `(new Renderer())->audioPlayer(...)`.

- [ ] **Step 1:** Add the test (already in `BlocksTest::test_audio_player_block_renders_audio_tag`
  from Task 4). Run it, expect FAIL until the block is registered.

- [ ] **Step 2: Create `blocks/audio-player/block.json`** (same shape as sermon-meta;
  `"name": "sermonator/audio-player"`, `"title": "Sermon Audio Player"`, add
  `"viewScript": "sermonator-audio-player"`).

- [ ] **Step 3: Implement `AudioPlayerBlock`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

final class AudioPlayerBlock extends AbstractBlock {
    public function name(): string { return 'sermonator/audio-player'; }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->resolvePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->audioPlayer( ( new TemplateData() )->sermon( $postId ) );
    }
}
```

- [ ] **Step 4: Create `assets/audio-player.js`** — progressive enhancement only (the
  `<audio controls>` already works without JS): add 1×/1.5×/2× speed buttons.

```js
document.querySelectorAll('.sermonator-audio').forEach(function (wrap) {
  var audio = wrap.querySelector('.sermonator-audio__el');
  if (!audio) return;
  var speeds = [1, 1.5, 2], i = 0;
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'sermonator-audio__speed';
  btn.textContent = '1×';
  btn.addEventListener('click', function () {
    i = (i + 1) % speeds.length;
    audio.playbackRate = speeds[i];
    btn.textContent = speeds[i] + '×';
  });
  wrap.appendChild(btn);
});
```

- [ ] **Step 5: Register the block** (add `AudioPlayerBlock` to the `init` registration from
  Task 4). Run the audio test, expect PASS.

- [ ] **Step 6: Commit.**

```bash
git add blocks/audio-player src/Frontend/Blocks/AudioPlayerBlock.php assets/audio-player.js
git commit -m "feat(frontend): audio-player block + speed control JS"
```

---

### Task 6: `video` block

**Files:**
- Create: `blocks/video/block.json`
- Create: `src/Frontend/Blocks/VideoBlock.php`

**Interfaces:**
- Produces: `final class VideoBlock extends AbstractBlock` — `name(): 'sermonator/video'`,
  `render()` → `(new Renderer())->video(...)`.

- [ ] **Step 1:** Test already present (`BlocksTest::test_video_block_empty_without_video`).
  Run, expect FAIL until registered.

- [ ] **Step 2: Create `blocks/video/block.json`** (`"name": "sermonator/video"`,
  `"title": "Sermon Video"`).

- [ ] **Step 3: Implement `VideoBlock`** (mirror AudioPlayerBlock, call `->video(...)`).

- [ ] **Step 4: Register the block** (add to `init` registration). Add a positive test:

```php
public function test_video_block_renders_embed(): void {
    $id = $this->makeSermon();
    update_post_meta( $id, ID::META_VIDEO_EMBED, '<iframe src="http://x/v"></iframe>' );
    $html = do_blocks( '<!-- wp:sermonator/video {"postId":' . $id . '} /-->' );
    $this->assertStringContainsString( '<iframe', $html );
}
```

- [ ] **Step 5: Run video tests, expect PASS. Commit.**

```bash
git add blocks/video src/Frontend/Blocks/VideoBlock.php tests/Integration/Frontend/BlocksTest.php
git commit -m "feat(frontend): video block"
```

---

### Task 7: Block template (single) + classic fallback + meta-by-presence

**Files:**
- Create: `src/Frontend/BlockTemplates.php`
- Create: `src/Frontend/ClassicTemplates.php`
- Create: `templates/classic/single-sermonator-sermon.php`
- Test: `tests/Integration/Frontend/SingleTemplateTest.php`

**Interfaces:**
- Produces:
  - `final class BlockTemplates { public function register(): void }` — calls
    `register_block_template('sermonator//single-sermonator_sermon', [...])` (guarded by
    `function_exists`), content composes header part + post-title + `sermonator/sermon-meta`
    + `sermonator/audio-player` + `sermonator/video` + post-content + footer part.
  - `final class ClassicTemplates { public function hook(): void; public function
    singleTemplate( string $template ): string; public function maybeAppendMeta( string $content ): string }`
    — `single_template` filter returns the plugin PHP template for `sermonator_sermon` on
    classic themes; `maybeAppendMeta` appended to `the_content` only if
    `apply_filters('sermonator_frontend_auto_append_meta', false)` is true.

- [ ] **Step 1: Write the failing integration test:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\BlockTemplates;
use Sermonator\Schema\Identifiers as ID;

final class SingleTemplateTest extends WP_UnitTestCase {
    public function test_block_template_registered_for_single_sermon(): void {
        ( new BlockTemplates() )->register();
        $all = get_block_templates( array(), 'wp_template' );
        $slugs = array_map( static fn( $t ) => $t->slug, $all );
        $this->assertContains( 'single-sermonator_sermon', $slugs,
            'Plugin must register a single-sermonator_sermon block template.' );
    }

    public function test_block_template_content_wires_sermon_blocks(): void {
        ( new BlockTemplates() )->register();
        $tpl = get_block_template( 'sermonator//single-sermonator_sermon', 'wp_template' );
        $this->assertNotNull( $tpl );
        $this->assertStringContainsString( 'wp:sermonator/sermon-meta', $tpl->content );
        $this->assertStringContainsString( 'wp:sermonator/audio-player', $tpl->content );
    }
}
```

- [ ] **Step 2: Run, expect FAIL.**
  Run: `… --filter SingleTemplateTest`

- [ ] **Step 3: Implement `BlockTemplates`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

final class BlockTemplates {
    public function register(): void {
        if ( ! function_exists( 'register_block_template' ) ) {
            return;
        }
        register_block_template( 'sermonator//single-' . ID::POST_TYPE_SERMON, array(
            'title'       => __( 'Single Sermon', 'sermonator' ),
            'description' => __( 'Default Sermonator single-sermon layout.', 'sermonator' ),
            'content'     => $this->singleContent(),
        ) );
    }

    private function singleContent(): string {
        return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
            . '<!-- wp:post-title {"level":1} /-->'
            . '<!-- wp:sermonator/sermon-meta /-->'
            . '<!-- wp:sermonator/audio-player /-->'
            . '<!-- wp:sermonator/video /-->'
            . '<!-- wp:post-content /-->'
            . '</main><!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
    }
}
```

- [ ] **Step 4: Run block-template tests, expect PASS.**

- [ ] **Step 5: Add the classic-path test + meta-by-presence test:**

```php
public function test_classic_single_template_used_when_not_block_theme(): void {
    $ct = new \Sermonator\Frontend\ClassicTemplates();
    $resolved = $ct->singleTemplate( '/themes/x/single.php' );
    // On classic theme context the plugin template path is returned; we assert it points
    // at our file. (Block-theme detection guards this in production.)
    $this->assertStringContainsString( 'single-sermonator-sermon.php', $resolved );
}

public function test_auto_append_meta_is_off_by_default(): void {
    $id = self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
    update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );
    $GLOBALS['post'] = get_post( $id );
    setup_postdata( $GLOBALS['post'] );
    $out = apply_filters( 'the_content', 'BODY' );
    $this->assertSame( 'BODY', trim( $out ), 'Auto-append must be OFF by default (one emitter).' );
    wp_reset_postdata();
}
```

- [ ] **Step 6: Implement `ClassicTemplates`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

final class ClassicTemplates {
    public function hook(): void {
        add_filter( 'single_template', array( $this, 'singleTemplate' ) );
        add_filter( 'the_content', array( $this, 'maybeAppendMeta' ) );
    }

    public function singleTemplate( string $template ): string {
        if ( ! is_singular( ID::POST_TYPE_SERMON ) ) {
            return $template;
        }
        // Theme override wins: theme can ship sermonator/single-sermonator-sermon.php.
        $themeTemplate = locate_template( array( 'sermonator/single-sermonator-sermon.php' ) );
        if ( $themeTemplate !== '' ) {
            return $themeTemplate;
        }
        return dirname( SERMONATOR_FILE ) . '/templates/classic/single-sermonator-sermon.php';
    }

    public function maybeAppendMeta( string $content ): string {
        if ( ! is_singular( ID::POST_TYPE_SERMON ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        /** Default OFF: the template is the single emitter; opt in to also append. */
        if ( ! apply_filters( 'sermonator_frontend_auto_append_meta', false ) ) {
            return $content;
        }
        $view = ( new TemplateData() )->sermon( (int) get_the_ID() );
        $r    = new Renderer();
        return $r->meta( $view ) . $r->audioPlayer( $view ) . $r->video( $view ) . $content;
    }
}
```

- [ ] **Step 7: Create `templates/classic/single-sermonator-sermon.php`:**

```php
<?php
/** Classic-theme single sermon template. Theme-overridable at sermonator/single-sermonator-sermon.php. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

get_header();
while ( have_posts() ) {
    the_post();
    $view = ( new TemplateData() )->sermon( get_the_ID() );
    $r    = new Renderer();
    echo '<article class="sermonator-single">';
    echo '<h1 class="sermonator-single__title">' . esc_html( get_the_title() ) . '</h1>';
    echo $r->meta( $view );        // already escaped at the Renderer boundary
    echo $r->audioPlayer( $view );
    echo $r->video( $view );
    echo '<div class="sermonator-single__body">';
    the_content();
    echo '</div></article>';
}
get_footer();
```

- [ ] **Step 8: Run all SingleTemplateTest, expect PASS.** Commit.

```bash
git add src/Frontend/BlockTemplates.php src/Frontend/ClassicTemplates.php templates/classic/single-sermonator-sermon.php tests/Integration/Frontend/SingleTemplateTest.php
git commit -m "feat(frontend): single block template + classic fallback (meta-by-presence)"
```

---

### Task 8: `Assets` — conditional minimal CSS + player JS enqueue

**Files:**
- Create: `src/Frontend/Assets.php`
- Create: `assets/frontend.css`
- Test: `tests/Integration/Frontend/AssetsTest.php`

**Interfaces:**
- Produces: `final class Assets { public function hook(): void; public function register():
  void }` — registers handle `sermonator-frontend` (CSS) + `sermonator-audio-player` (JS),
  enqueued on single sermon and when a `sermonator/*` block is present.

- [ ] **Step 1: Write the failing integration test:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Assets;

final class AssetsTest extends WP_UnitTestCase {
    public function test_styles_registered(): void {
        ( new Assets() )->register();
        $this->assertTrue( wp_style_is( 'sermonator-frontend', 'registered' ) );
        $this->assertTrue( wp_script_is( 'sermonator-audio-player', 'registered' ) );
    }
}
```

- [ ] **Step 2: Run, expect FAIL.** `… --filter AssetsTest`

- [ ] **Step 3: Create `assets/frontend.css`** (structural only; preset vars w/ fallbacks):

```css
.sermonator-meta { display: grid; gap: .25rem .75rem; margin: 0 0 1.5rem; }
.sermonator-meta > div { display: flex; gap: .5rem; }
.sermonator-meta dt { font-weight: 600; margin: 0; color: var(--wp--preset--color--contrast, #1a1a1a); }
.sermonator-meta dd { margin: 0; }
.sermonator-audio { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin: 0 0 1.5rem; }
.sermonator-audio__el { width: 100%; max-width: 32rem; }
.sermonator-audio__speed { cursor: pointer; border: 1px solid currentColor; background: transparent; border-radius: .25rem; padding: .15rem .5rem; font: inherit; }
.sermonator-video { margin: 0 0 1.5rem; }
.sermonator-video iframe { max-width: 100%; }
```

- [ ] **Step 4: Implement `Assets`:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

final class Assets {
    public function hook(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueue' ) );
    }

    public function register(): void {
        wp_register_style( 'sermonator-frontend', SERMONATOR_PLUGIN_URL . 'assets/frontend.css', array(), SERMONATOR_VERSION );
        wp_register_script( 'sermonator-audio-player', SERMONATOR_PLUGIN_URL . 'assets/audio-player.js', array(), SERMONATOR_VERSION, true );
    }

    public function maybeEnqueue(): void {
        if ( is_singular( ID::POST_TYPE_SERMON ) || is_post_type_archive( ID::POST_TYPE_SERMON )
            || $this->isSermonTaxonomy() ) {
            wp_enqueue_style( 'sermonator-frontend' );
            wp_enqueue_script( 'sermonator-audio-player' );
        }
    }

    private function isSermonTaxonomy(): bool {
        foreach ( ID::sermonTaxonomies() as $tax ) {
            if ( is_tax( $tax ) ) {
                return true;
            }
        }
        return false;
    }
}
```

  (Block-context enqueue is handled automatically by block.json `style`/`viewScript` when a
  block is placed outside a sermon page.)

- [ ] **Step 5: Run AssetsTest, expect PASS. Commit.**

```bash
git add src/Frontend/Assets.php assets/frontend.css tests/Integration/Frontend/AssetsTest.php
git commit -m "feat(frontend): conditional minimal CSS + player JS enqueue"
```

---

### Task 9: `FrontendServiceProvider` + `Plugin::boot` wiring

**Files:**
- Create: `src/Frontend/FrontendServiceProvider.php`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Frontend/BootTest.php`

**Interfaces:**
- Consumes: all Phase 1 classes.
- Produces: `final class FrontendServiceProvider { public function hook(): void }` — on
  `init` registers the 3 blocks + block templates; instantiates `ClassicTemplates`/`Assets`
  and calls their `hook()`. `Plugin::boot()` calls `self::registerFrontend()` for non-admin
  (or ajax/feed) requests.

- [ ] **Step 1: Write the failing integration test:**

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;

final class BootTest extends WP_UnitTestCase {
    public function test_blocks_registered_after_init(): void {
        $reg = \WP_Block_Type_Registry::get_instance();
        $this->assertNotNull( $reg->get_registered( 'sermonator/sermon-meta' ) );
        $this->assertNotNull( $reg->get_registered( 'sermonator/audio-player' ) );
        $this->assertNotNull( $reg->get_registered( 'sermonator/video' ) );
    }
}
```

- [ ] **Step 2: Run, expect FAIL** (blocks registered only by the interim Task-4 stub, not
  the provider). `… --filter BootTest`

- [ ] **Step 3: Implement `FrontendServiceProvider`** (replaces the interim `init` closure):

```php
<?php
declare(strict_types=1);
namespace Sermonator\Frontend;

use Sermonator\Frontend\Blocks\SermonMetaBlock;
use Sermonator\Frontend\Blocks\AudioPlayerBlock;
use Sermonator\Frontend\Blocks\VideoBlock;

final class FrontendServiceProvider {
    public function hook(): void {
        add_action( 'init', array( $this, 'onInit' ) );
        ( new ClassicTemplates() )->hook();
        ( new Assets() )->hook();
    }

    public function onInit(): void {
        ( new SermonMetaBlock() )->register();
        ( new AudioPlayerBlock() )->register();
        ( new VideoBlock() )->register();
        ( new BlockTemplates() )->register();
    }
}
```

- [ ] **Step 4: Wire into `Plugin::boot()`.** After `registerAdmin()`, add a
  `registerFrontend()` that runs on non-AJAX front-end + feed requests:

```php
// in src/Plugin.php boot(), after self::registerAdmin();
self::registerFrontend();

private static function registerFrontend(): void {
    // Front-end, feed, and REST render contexts — but not wp-admin screens or CLI.
    if ( is_admin() && ! wp_doing_ajax() ) {
        return;
    }
    ( new \Sermonator\Frontend\FrontendServiceProvider() )->hook();
}
```

  Remove the interim `init` closure added in Task 4 (the provider now owns registration).

- [ ] **Step 5: Run BootTest + full frontend suite, expect PASS.**
  Run: `wp-env run tests-cli --env-cwd=wp-content/plugins/sermonator phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration --filter Frontend`

- [ ] **Step 6: Commit.**

```bash
git add src/Frontend/FrontendServiceProvider.php src/Plugin.php tests/Integration/Frontend/BootTest.php
git commit -m "feat(frontend): wire FrontendServiceProvider into Plugin boot"
```

---

### Task 10: Golden-HTML drift test + live verification on Local

**Files:**
- Test: `tests/Integration/Frontend/MetaParityTest.php`

**Interfaces:**
- Consumes: `SermonMetaBlock` render + classic template render path.

- [ ] **Step 1: Write the golden-HTML parity test** (block render vs shortcode/classic
  Renderer call must produce identical meta markup for the same sermon):

```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;
use Sermonator\Schema\Identifiers as ID;

final class MetaParityTest extends WP_UnitTestCase {
    public function test_block_and_direct_renderer_meta_match(): void {
        $id = self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON, 'post_title' => 'T' ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'John 1:1-14' );

        $viaBlock  = do_blocks( '<!-- wp:sermonator/sermon-meta {"postId":' . $id . '} /-->' );
        $viaDirect = ( new Renderer() )->meta( ( new TemplateData() )->sermon( $id ) );

        $this->assertSame( trim( $viaDirect ), trim( $viaBlock ),
            'Block render and direct Renderer output must match — no composition drift.' );
    }
}
```

- [ ] **Step 2: Run, expect PASS** (both call the same Renderer). If it fails, the block
  wrapper added markup — fix the block to return Renderer output verbatim.

- [ ] **Step 3: Deploy + live-verify on the Local TT5 site.** The plugin is symlinked and
  seeded already. Re-seed if needed, flush rewrites, and confirm rich rendering:

```bash
WP=/private/tmp/claude-501/-Users-david-Repo-sermonator-pro/a71493fd-f36c-46c1-809f-bd906fcfc302/scratchpad/wp-local.sh
"$WP" rewrite flush
echo "--- single (block theme TT5) ---"
curl -s http://sermonator-test.local/sermons/the-light-has-come/ | grep -ioE 'sermonator-meta|John 1:1-14|Pastor John Smith|<audio' | sort -u
```
Expected: `sermonator-meta`, `John 1:1-14`, `Pastor John Smith`, `<audio` all present
(the gap from session start is closed).

- [ ] **Step 4: Live-verify the classic-theme path.**

```bash
"$WP" theme activate twentytwentyone
curl -s http://sermonator-test.local/sermons/the-light-has-come/ | grep -ioE 'sermonator-single|John 1:1-14|<audio' | sort -u
"$WP" theme activate twentytwentyfive
```
Expected: `sermonator-single`, `John 1:1-14`, `<audio` present on the classic theme too.

- [ ] **Step 5: Run the FULL suites green** (unit + integration) before declaring Phase 1
  done.
  Run: `composer test:unit` then the integration suite. Expected: all green.

- [ ] **Step 6: Commit.**

```bash
git add tests/Integration/Frontend/MetaParityTest.php
git commit -m "test(frontend): golden-HTML meta parity (no composition drift)"
```

---

## Self-Review

**Spec coverage (Phase 0 + Phase 1 scope only):**
- §3 architecture (Frontend namespace, TemplateData/Renderer single sources) → Tasks 2, 3, 9 ✓
- §5 blocks sermon-meta/audio-player/video → Tasks 4, 5, 6 ✓
- §6 block template (single) + classic fallback + **meta-by-presence, auto-append default
  OFF** → Task 7 ✓
- §9 minimal theme-inheriting CSS w/ fallbacks → Task 8 ✓
- §11 Phase 0 de-risk + decision gate → Task 0 ✓
- §12 unit + integration + **golden-HTML drift test** + live verification → Tasks 2,3 / 4-9 / 10 ✓
- §0 WP 7.0 floor → Task 1 ✓
- *Deferred to later plans (correctly out of scope here):* archives + taxonomy + grid +
  shortcode (Phase 2), podcast feed + backfill + subscribe (Phase 3), SEO/JSON-LD/OG
  (Phase 4).

**Placeholder scan:** none — every code/test step has concrete content.

**Type consistency:** `SermonView` props (Task 2) are consumed by `Renderer` (Task 3) and
`TemplateData` produces it; `AbstractBlock::render(array,string,\WP_Block):string` (Task 4)
is the signature reused by Tasks 5/6; `register()` slug derivation matches block.json names;
`ClassicTemplates`/`Assets`/`BlockTemplates` method names match their Task-9 wiring.

## Phase 1 adversarial-review outcomes (2026-06-23)

Three-lens review (security / WP-correctness / correctness-design). Confirmed findings
**resolved** this round (all tested + live-verified):
- **CRITICAL** — pre-1970 (negative) `sermonator_date` was dropped to null (`ctype_digit`
  rejects the `-`), omitting the date row → data loss. Now `parseTimestamp()` mirrors the
  migration's `ctype_digit( ltrim( …, '-' ) )`. Unit-tested (negative + lone-dash).
- **IMPORTANT (security)** — blocks rendered any `postId` regardless of status/type →
  draft/private/non-sermon disclosure. Added `AbstractBlock::renderablePostId()` gate
  (`get_post_type === sermon && (is_post_publicly_viewable || current_user_can read_post)`).
  Integration-tested (draft + non-sermon → empty); editor preview preserved via read_post.
- **IMPORTANT (security)** — `style` in the video kses allowlist was a full-page-overlay
  clickjacking primitive. Removed from iframe/video allowlists.
- **IMPORTANT** — double `init` asset registration removed; `load_plugin_textdomain` +
  `Domain Path` added (self-hosted .mo loading); dead `align:wide` block support removed.
- **IMPORTANT (spec gap)** — `videoUrl` now renders an inline **oEmbed** (cached, known
  providers only) with a link fallback. Live-verified (YouTube URL → iframe).
- **MINOR** — strengthened parity test to compare block-sequence vs classic composition
  (real cross-path drift check, not block-vs-self); added `WP_Error` term-link unit test;
  `single_template` short-circuits on block themes (`wp_is_block_theme()`).

**Deferred (recorded, intentionally NOT done in Phase 1):**
- **Editor preview JS** — blocks are server-registered/insertable but have no client `edit`,
  so the Site-Editor preview is server-rendered (often blank without loop context). Needs a
  small editor script + a build step → Phase 2.
- **iframe `src` host-allowlist** — deliberately NOT added: legacy embeds come from many
  providers and a host allowlist would silently drop migrated embeds, violating the
  data-preservation bar. Trust model = "a user who can edit a sermon is trusted" (custom
  `sermonator_sermon` caps). Revisit with a per-provider allowlist + `register_post_meta`
  `auth_callback` when the admin/editing UI lands.
- **Header/footer template-part slug fragility** on non-standard block themes — Phase-4
  cross-theme pass (spec §13).
- **Off-single `viewScript` auto-enqueue** — confirm the player JS loads when the
  audio-player block is placed on a non-sermon page; low risk, verify in Phase 2.

Result: unit 93, integration 347 — all green on WP 7.0; published single renders rich on
TT5 (block) + twentytwentyone (classic); draft gated.

## Phase 0 outcome

**Run 2026-06-23 on the Local "sermonator-test" site (TT5, WP 7.0). RESULT: PASS →
PROCEED BLOCK-FIRST.** No fallback to `template_include` needed.

Evidence (throwaway mu-plugin registering `sermonator/spike` + a
`sermonator//single-sermonator_sermon` block template; since deleted):
- `wp_is_block_theme()` = BLOCK; `register_block_template()` available (WP 7.0).
- `get_block_template('sermonator//single-sermonator_sermon')` resolved with
  **`source=plugin`** — the plugin template participates in resolution.
- HTTP single sermon `/sermons/the-light-has-come/` = 200; the plugin's
  `single-sermonator_sermon` **beat TT5's generic `single` with zero config** — spike block
  rendered **exactly once**, `<header>` and `<footer>` template parts both resolved, post
  title present.
- **Meta-by-presence confirmed:** removing the `wp:sermonator/spike` line from the template
  content → spike markers dropped to **0**. No request-scoped guard required.
- Classic theme `twentytwentyone` installed on the test site for Task 7/10 classic-path
  verification (the `single_template` filter path — stable, not spiked here).

Implication for the build: Tasks 4–7 proceed as written. The `RenderGuard` is correctly
absent; single-meta is governed purely by template composition.
