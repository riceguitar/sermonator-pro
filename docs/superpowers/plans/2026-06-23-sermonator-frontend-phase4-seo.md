# Sermonator Front-End — Phase 4 (schema.org + Open Graph + Cross-Theme) Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development / executing-plans.

**Goal:** Emit schema.org JSON-LD + Open Graph/Twitter meta on single sermons for SEO/social
sharing, and verify the whole layer renders across multiple block and classic themes. The
final phase of the front-end display layer. Read-only.

## Tasks
- **A — Pure builders.** `Seo\JsonLd::forSermon(SermonView, context): array` — a sermon as a
  schema.org `CreativeWork` with `author` (Person[]), `isPartOf` (CreativeWorkSeries),
  `publisher`, nested `AudioObject`/`VideoObject` (ISO-8601 durations), `datePublished`
  (gmdate 'c'). `Seo\OpenGraph::forSermon(...)` — `og:*` + `twitter:*` triples (image-aware
  card type, og:audio/og:video). Unit-tested.
- **B — `Seo\SeoHead`** hooks `wp_head`; on a single sermon (is_singular + is_main_query)
  emits the JSON-LD `<script>` (wp_json_encode with `JSON_HEX_TAG` → `</script>`-safe) + OG
  meta (esc_attr). Opt-out filters `sermonator_frontend_emit_json_ld` /
  `sermonator_frontend_emit_open_graph` (default on) for SEO-plugin sites. Registered in
  `FrontendServiceProvider`. Integration-tested.
- **C — Cross-theme pass + review + PR.**

## Phase 4 outcomes (2026-06-23)

**Cross-theme pass — clean sweep.** Single (sermon meta + header/footer parts + JSON-LD) and
archive (5 cards) render correctly on **twentytwentyfour, twentytwentythree, twentytwentyfive
(block)** and **twentytwentyone (classic)** — HTTP 200 everywhere. This empirically clears the
tenth-man's "header/footer template-part slug fragility" risk across the standard themes; no
template or CSS fixes were needed.

**Review — 0 defects.** Escaping (JSON_HEX_TAG neutralises `</script>`; OG via esc_attr),
JSON-LD shape + `isoDuration` (warning-free across malformed input), OG/Twitter logic, and the
`wp_head` guards were all VERIFIED-CORRECT. Applied the one cheap enhancement: a
`sermonator_frontend_emit_json_ld` opt-out filter (symmetric with the OG one) so a site whose
SEO plugin already emits sermon structured data won't get duplicate JSON-LD.

**Deferred enhancement:** richer `VideoObject` (contentUrl/embedUrl/thumbnailUrl) for video
rich-result eligibility — valid schema today, just sparse for embed-only videos.

Result: unit 124, integration 386 — green on WP 7.0; live-verified valid JSON-LD + OG on the
single sermon across all four themes.

## Out of scope (post-v1)
Sermon-date editor meta box; AJAX taxonomy filtering; per-episode podcast chapters/transcripts;
richer VideoObject; Block Bindings meta→core-block binding; Elementor widgets.
