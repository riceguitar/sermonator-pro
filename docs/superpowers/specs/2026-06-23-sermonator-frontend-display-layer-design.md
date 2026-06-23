# Sermonator Front-End Display Layer — Design

- **Date:** 2026-06-23
- **Status:** Approved (forum gates run; tenth-man dissent accepted and folded in)
- **Scope:** Greenfield public rendering of sermons & podcasts. Read-only — no data
  mutation; data preservation is owned entirely upstream by the migration engine.
- **Floor:** WP **7.0+**, PHP **8.1+** (raised from 6.0 per ADR below). GPL-2.0-or-later,
  zero monetization.

## 1. Decision (ADR, user-owned)

**Choice:** C — **Block-first FSE-native + classic PHP fallback**. WP floor raised 6.0 → 7.0.

**Why (user's words):** Building toward the future; old WP installs are not our concern —
they can modernize first. Re-prioritizes the criteria: drops the "stay on 6.0 floor"
criterion, elevates future-proof / Site-Editor-editable.

**What would change the answer:** Nothing — unconditional commitment to the modern path.
(User accepted past the falsifiability push-back and instructed to proceed.)

Raising the floor to 7.0 makes `register_block_template()` (added 6.7) and the Block
Bindings API (6.5+) first-class. The Local test site already runs WP 7.0.

## 2. Goals & non-goals

**Goals (v1, "Full" scope):**
- Single sermon view — full meta, audio player, video, scripture, taxonomy links.
- Sermon archive (`/sermons/`) + all **5** taxonomy archives (preacher, series, topic,
  book, service type).
- Audio player + video embed/oEmbed.
- Apple-Podcasts-compatible **podcast RSS feed**.
- **schema.org** structured data (JSON-LD) + **Open Graph**/Twitter Card tags.
- **Embeddable sermon list** — a Sermon Grid block + a `[sermonator_sermons]` shortcode.
- Renders **rich with zero configuration** on block themes (FSE/TT5) **and** classic
  themes; **minimal, theme-inheriting** styling.

**Non-goals (v1):** Block Bindings meta→core-block binding (v1.1), Elementor widgets
(future addon), legacy Twig/page-builder template compatibility (discarded), full-text
sermon search, transcripts, series/preacher landing-page CMS.

## 3. Architecture

One read-only namespace `Sermonator\Frontend\`, booted from `Plugin::boot()` on front-end
**and** REST/feed requests only (skip `is_admin()` non-ajax and WP-CLI rendering paths).

```
Frontend\FrontendServiceProvider  — wires hooks: init (blocks, templates, feed, shortcode,
                                    rewrite), wp_head (SEO), wp_enqueue_scripts (assets)
Frontend\TemplateData             — pure read model. The ONLY class that reads
                                    get_post_meta()/terms. Hydrates immutable Sermon /
                                    Podcast value objects from a post ID. No echo.
Frontend\Renderer                 — SINGLE SOURCE OF TRUTH for per-piece HTML: meta list,
                                    audio player, video, scripture, taxonomy links, sermon
                                    card. Pure: value object in → escaped HTML string out.
Frontend\Blocks\*                 — dynamic blocks; render_callback delegates to Renderer.
Frontend\BlockTemplates           — register_block_template() defaults for block themes.
Frontend\ClassicTemplates         — single/archive/taxonomy_template filters + guarded
                                    the_content fallback, for non-block themes.
Frontend\PodcastFeed              — add_feed() → Apple-compatible RSS builder.
Frontend\Feed\EnclosureResolver   — resolves/validates audio byte length + MIME.
Frontend\Seo\JsonLd + OpenGraph   — head metadata builders (pure) + wp_head emitter.
Frontend\Shortcode                — [sermonator_sermons] → Renderer (Elementor/classic/legacy).
Frontend\Assets                   — conditional minimal CSS + tiny vanilla player JS.
```

**Invariant — exactly one mechanism renders a single per request.** The block template
(block theme) **or** the PHP template (classic) owns the single layout. The `the_content`
enrichment is a **guarded** fallback that no-ops when a Sermonator template/block has
already emitted the meta for the current post (render-once guard keyed by post ID). See
§6 for the guard contract — it is load-bearing and de-risked in Phase 0.

## 4. Components & data flow

1. **Request → template resolution.**
   - *Block theme:* `register_block_template('sermonator//single-sermonator_sermon')`
     (more specific than the theme's generic `single`) is resolved with zero config. The
     template composes core blocks (`post-title`, `post-content`) + Sermonator blocks,
     inside the theme's `header`/`footer` template parts.
   - *Classic theme:* `single_template`/`archive_template`/`taxonomy_template` filters
     return plugin PHP templates from `templates/classic/`, **theme-overridable** via
     `locate_template()` (theme copy in `sermonator/` subdir always wins).
2. **Rendering.** Blocks, PHP templates, and the shortcode are all thin skins over
   `TemplateData` (reads) + `Renderer` (HTML). No component reads meta directly except
   `TemplateData`; none builds piece-HTML except `Renderer`.
3. **Feed.** `/feed/sermonator-podcast/` (and `?feed=sermonator-podcast`) → `PodcastFeed`
   queries published sermons for the default (or `?podcast=ID`) podcast, builds RSS.
4. **Head.** On single sermon/podcast, `Seo\*` emit JSON-LD + OG at `wp_head`.

## 5. Blocks (v1)

All `apiVersion: 3`, `block.json`-registered, **server-rendered** (`render.php` →
`Renderer`), with minimal `edit` (static preview). Naming `sermonator/*`:

| Block | Purpose |
|---|---|
| `sermon-meta` | passage, preacher, series, date (preached), service type, book, topics (uses block context for current post; `postId` attribute for explicit) |
| `audio-player` | native `<audio>` + tiny PE JS (play/pause, scrub, 1×/1.5×/2× speed, download) |
| `video` | renders stored embed HTML (`wp_kses_post`) or oEmbeds a URL |
| `sermon-grid` | query loop (taxonomy filter, count, orderby preached-date), card layout, pagination — the embeddable list |
| `taxonomy-filter` | links/dropdowns across the 5 taxonomies; drives grid/archive |
| `podcast-subscribe` | Apple/Spotify/RSS links for default-or-chosen podcast |

## 6. Block templates, classic fallback, and the render-once guard

**Block templates** registered for: `single-sermonator_sermon`,
`archive-sermonator_sermon`, `taxonomy-sermonator_{preacher,series,topic,book,service_type}`,
`single-sermonator_podcast`. Editable in the Site Editor. Header/footer referenced via
template parts — **Phase 0 verifies part-slug resolution on TT5 and a classic theme** (a
theme with non-standard part slugs renders headless otherwise; fallback is to omit explicit
part wrapping and rely on the theme's template canvas).

**Classic templates** in `templates/classic/` render identical output via `Renderer`,
theme-overridable, plus the guarded `the_content` enrichment.

**Render-once guard contract:**
- A per-request set of post IDs `meta_emitted` (in `Renderer` or a small `RenderGuard`).
- Any mechanism that emits the sermon meta block for post N calls `RenderGuard::mark(N)`.
- The `the_content` filter emits meta only if `! RenderGuard::has(N)` **and** the current
  query is the main single-sermon query (`is_singular(POST_TYPE_SERMON) && in_the_loop()
  && is_main_query()`).
- Result: block template / PHP template that includes the meta block ⇒ guard set ⇒
  `the_content` no-ops. Theme/Site-Editor template that has NO Sermonator meta ⇒ guard
  unset ⇒ `the_content` injects (graceful enrichment). Customizers who *want* to suppress
  the auto-append get a filter `sermonator_frontend_auto_append_meta` (default true).

## 7. Podcast RSS feed (first-class workstream, not a footnote)

Apple validation is unforgiving; this is treated as a correctness-critical component.

- **Endpoint:** `add_feed('sermonator-podcast', ...)`; rewrite flush on activation/upgrade.
- **Channel:** title/subtitle/summary/author/owner/category/explicit/language/image from
  the podcast's `sermonator_podcast_settings`. `itunes:category` mapped through a **fixed
  Apple-taxonomy allowlist** (free text rejected → nearest valid + admin notice).
- **Items:** one per **published** sermon with audio, ordered by preached date desc.
  - `enclosure` `length` resolved by `EnclosureResolver`: prefer `_sermonator_audio_size`;
    if missing/zero, attempt a cached HEAD `Content-Length`; if still unknown, **omit the
    item** per Apple's "length required" rule and **count it** for the admin surface
    (a complete-but-shorter feed beats an Apple-rejected one).
  - `enclosure` `type` from extension/HEAD MIME (default `audio/mpeg`).
  - `guid isPermaLink="false"` = stable string from post ID (never changes across
    re-titling/slug edits).
  - `pubDate` from preached date; `itunes:duration` from `_sermonator_audio_duration`.
- **No silent drops:** sermons skipped (no audio, or unresolved length) are surfaced as
  "N episodes excluded from feed" in an admin notice / WP-CLI, not dropped silently.
- **Empty/unconfigured:** no default podcast ⇒ valid empty channel + admin notice, no fatal.

## 8. SEO / structured data

- **JSON-LD** on single sermon: `CreativeWork` (a sermon) embedding `AudioObject` and/or
  `VideoObject`; `name`, `datePublished` (preached date), `author` (preacher),
  `isPartOf`/series. Podcast single: `PodcastSeries`; feed episodes → `PodcastEpisode`.
- **Open Graph / Twitter Card:** title, description (excerpt → notes fallback),
  `og:audio`/`og:video`, `og:image` (featured image). Escaped; only on our singles.
- Builders are **pure** (data → array → `wp_json_encode`), unit-testable without WP.

## 9. Styling & assets

- **Minimal, theme-inheriting, with realistic fallbacks.** Structural CSS only (flex/grid
  layout, player controls, meta rows). Colors/typography are progressive enhancement via
  `var(--wp--preset--color--*, <sane-fallback>)`; because classic themes have no presets
  and block-theme preset slugs vary, **the hardcoded fallbacks are the baseline** and are
  tested on a classic theme. No `!important`; everything theme-overridable.
- **Conditional load.** block.json `style`/`viewScript` (loaded only when a block is
  present); classic templates/shortcode enqueue only on Sermonator queries. Player JS is a
  few KB, vanilla, no dependencies, deferred.

## 10. Error handling & edge cases

Missing meta → field omitted (never "0"/empty rows). Draft/private/future → WP visibility
respected (excluded from archives **and** feed). Broken/empty audio → no player. Empty
embed → nothing. Theme template override always wins. **All output escaped at the
`Renderer` boundary** (`esc_html`/`esc_url`/`esc_attr`; stored video embed via
`wp_kses_post`). No raw meta echoed anywhere. Feed audio URLs validated (`esc_url_raw`).

## 11. Phasing (sequencing is a hard requirement — tenth-man outcome)

**Phase 0 — De-risk the linchpin (do FIRST, throwaway-friendly spike):**
1. Prove a plugin `register_block_template('single-sermonator_sermon')` beats the active
   block theme's generic `single` with **zero config** on TT5, and renders header/footer
   parts correctly.
2. Prove the same data renders on **one classic theme** (e.g. Twenty Twenty-One) via the
   `single_template` filter.
3. Prove the **render-once guard** prevents double-meta across (a) block template +
   `the_content`, and (b) a customized template with the meta block removed.
4. **Decision gate:** if block-template precedence or part resolution is fragile, fall back
   to `template_include` for singles too (Approach-A mechanism) — **same Renderer**, lower
   risk. Record the outcome in the plan before building the fleet.

**Phase 1 — Core read model + Renderer + single view** (TemplateData, Renderer, RenderGuard,
sermon-meta + audio-player + video blocks, single template both theme types).

**Phase 2 — Archives + taxonomy + grid + shortcode + taxonomy-filter** (lists, pagination).

**Phase 3 — Podcast feed + EnclosureResolver + subscribe block** (Apple validation).

**Phase 4 — SEO (JSON-LD + OG)** + assets polish + cross-theme pass.

Each phase ends green (unit + integration) and is verified live on the Local TT5 site.

## 12. Testing

- **Unit (Brain Monkey):** `Renderer` builders (escaping + omission rules), `TemplateData`
  mapping, `JsonLd`/`OpenGraph` builders, RSS item/channel builders, `EnclosureResolver`
  (size present / missing / HEAD / unresolved), `RenderGuard`.
- **Composition-drift control:** one **golden-HTML** test asserting block-render,
  classic-PHP, and shortcode produce matching meta markup for the same fixture sermon.
- **Integration (wp-env):** `register_block_template` resolution beats generic theme
  template; classic `single_template` path; feed endpoint XML + iTunes tags + enclosure
  length rules; `wp_head` JSON-LD/OG presence; shortcode query; **draft/private excluded
  from archive AND feed**; guard prevents double meta.
- **Live (Local/TT5 + one classic theme):** seeded data renders rich, feed shape validated
  against Apple's spec (enclosure length, fixed category, image, stable GUID).

## 13. Known risks (carried forward)

- **Block-template precedence / part-slug resolution varies by theme** — mitigated by
  Phase 0 spike + `template_include` fallback path. (tenth-man #1)
- **Composition lives in 3 places** (block template HTML, classic PHP, shortcode) — drift
  surface mitigated by the golden-HTML test, not eliminated. (tenth-man #2)
- **Apple enclosure `length` from legacy/external audio** may be missing/wrong — mitigated
  by EnclosureResolver + omit-and-surface, never silent. (tenth-man #3)
- **Preset-variable theming is unreliable** — hardcoded fallbacks are the baseline.
  (tenth-man #4)
