# Sermonator Front-End — Phase 2 (Archives, Taxonomy, Grid, Shortcode) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** The sermon archive (`/sermons/`), all 5 taxonomy archives, an embeddable
`sermonator/sermon-grid` block, and a `[sermonator_sermons]` shortcode all render rich
sermon **cards** ordered by preached date, on both block (FSE) and classic themes.

**Architecture:** Reuses Phase-1 `TemplateData`/`SermonView`/`Renderer`. Adds a `SermonQuery`
read service (WP_Query by preached date + taxonomy filters + pagination), `Renderer::card()`
+ `Renderer::grid()`, a `pre_get_posts` ordering hook, the grid block + shortcode (both thin
skins over `Renderer::grid`), and archive/taxonomy templates (FSE block templates + classic
PHP fallbacks). Read-only — no data mutation.

**Tech Stack:** PHP 8.1+, WP 7.0+, PHPUnit (Brain Monkey units + wp-env integration), vanilla.

## Global Constraints
- Read-only; canonical ids from `Schema\Identifiers`; escape at the `Renderer` boundary.
- Sermons order by **preached date** (`sermonator_date` meta, `meta_value_num` DESC). The
  migration guarantees every sermon has `sermonator_date` (legacy `sermon_date` is always
  set), so the meta join does not drop sermons; note the assumption in code.
- Naming `Sermonator\Frontend\*`, blocks `sermonator/*`, CSS `sermonator-*`, filters
  `sermonator_frontend_*`. Minimal theme-inheriting CSS (extend `assets/frontend.css`).

## File Structure
| File | Responsibility |
|---|---|
| `src/Frontend/SermonQuery.php` | Build/run WP_Query for sermons (preached-date order, taxonomy filters, paging) → `QueryResult` |
| `src/Frontend/QueryResult.php` | Immutable: `list<SermonView> $sermons`, `int $total`, `int $totalPages`, `int $page` |
| `src/Frontend/Renderer.php` (modify) | add `card(SermonView): string`, `grid(QueryResult, array $opts): string`, `pagination(QueryResult): string` |
| `src/Frontend/ArchiveOrdering.php` | `pre_get_posts` → order sermon archive + taxonomy archives by preached date |
| `src/Frontend/Blocks/SermonGridBlock.php` + `blocks/sermon-grid/block.json` | `sermonator/sermon-grid` dynamic block |
| `src/Frontend/Blocks/TaxonomyFilterBlock.php` + `blocks/taxonomy-filter/block.json` | `sermonator/taxonomy-filter` block |
| `src/Frontend/Shortcode.php` | `[sermonator_sermons]` → `Renderer::grid` |
| `src/Frontend/BlockTemplates.php` (modify) | register archive + 5 taxonomy block templates |
| `src/Frontend/ClassicTemplates.php` (modify) | `archive_template` + `taxonomy_template` filters |
| `templates/classic/archive-sermonator-sermon.php` | classic archive PHP |
| `templates/classic/taxonomy-sermonator.php` | classic taxonomy PHP (shared by the 5) |
| `src/Frontend/FrontendServiceProvider.php` (modify) | register new blocks/templates/shortcode/ordering |
| `assets/frontend.css` (modify) | card grid + pagination styles |

## Task A — `SermonQuery` + `QueryResult` + `Renderer::card`/`grid`/`pagination`
- [ ] `QueryResult` value object (sermons, total, totalPages, page).
- [ ] `SermonQuery::run(array $args): QueryResult` — args: `perPage`, `page`, `taxonomies`
  (map tax→list of term slugs/ids), `order`. Builds WP_Query: `post_type=sermon`,
  `post_status=publish`, `meta_key=sermonator_date`, `orderby=meta_value_num`, `order`,
  `paged`, `tax_query` from filters. Maps posts → `SermonView` via `TemplateData`.
- [ ] `Renderer::card(SermonView)` — `<article class="sermonator-card">`: thumbnail
  (`get_the_post_thumbnail($v->id,'medium')`), linked title, date, preacher(s), passage,
  audio badge. All escaped; omit absent parts.
- [ ] `Renderer::grid(QueryResult, array $opts)` — `<div class="sermonator-grid" data-cols>`
  of cards; empty-state message when none; appends `pagination()`.
- [ ] `Renderer::pagination(QueryResult)` — prev/next + page links via `paginate_links`
  (guard when `totalPages<=1`).
- Unit-test `Renderer::card/grid/pagination` (Brain Monkey stubs); integration-test
  `SermonQuery` ordering + taxonomy filter + paging.

## Task B — `ArchiveOrdering` (pre_get_posts)
- [ ] On `pre_get_posts`: if `$q->is_main_query()` and (`is_post_type_archive(sermon)` or
  `is_tax(<sermon taxonomies>)`), set `meta_key=sermonator_date`, `orderby=meta_value_num`,
  `order=DESC`. So even a theme's own archive loop is preached-date ordered.
- Integration-test the main archive query ordering.

## Task C — `sermon-grid` block + `[sermonator_sermons]` shortcode
- [ ] `blocks/sermon-grid/block.json` (attrs: `perPage`,`columns`,`preacher`,`series`,
  `topic`,`book`,`serviceType`,`order`; `style: sermonator-frontend`).
- [ ] `SermonGridBlock::render` → maps attrs → `SermonQuery::run` → `Renderer::grid`.
- [ ] `Shortcode::render($atts)` — `shortcode_atts` defaults mirror the block; maps to
  `SermonQuery::run` → `Renderer::grid`. Register `[sermonator_sermons]`.
- Integration-test block + shortcode output (cards, filter, count).

## Task D — Archive + taxonomy templates (FSE + classic)
- [ ] `BlockTemplates`: register `archive-sermonator_sermon` and
  `taxonomy-sermonator_{preacher,series,topic,book,service_type}` (header + title +
  query-loop/grid + footer). Keep idempotent guard.
- [ ] `ClassicTemplates`: add `archive_template` + `taxonomy_template` filters (block-theme
  short-circuit), theme-overridable, → plugin PHP templates.
- [ ] `templates/classic/archive-sermonator-sermon.php` + `taxonomy-sermonator.php` — loop
  rendering `Renderer::card` + `Renderer::pagination`.
- Integration-test template registration + classic resolution.

## Task E — `taxonomy-filter` block + wiring
- [ ] `blocks/taxonomy-filter/block.json` (attr `taxonomy`).
- [ ] `TaxonomyFilterBlock::render` — list of term links for the chosen taxonomy
  (`get_terms` → `Renderer` term links). Hide-empty by default.
- [ ] `FrontendServiceProvider::onInit` registers the 2 new blocks + new templates;
  construct `Shortcode`+`ArchiveOrdering` and hook them in `hook()`.
- [ ] `assets/frontend.css`: `.sermonator-grid` (responsive columns), `.sermonator-card`,
  `.sermonator-pagination`.

## Task F — Verify + review + PR
- [ ] Unit + full integration green (wp-env, WP 7.0).
- [ ] Live-verify on Local: `/sermons/` archive, a taxonomy archive (e.g.
  `/preacher/pastor-john-smith/`), a page with the grid block, and the shortcode — on TT5
  (block) and twentytwentyone (classic). Confirm preached-date order + cards + pagination.
- [ ] Adversarial review (security/correctness/WP) → fix to convergence → PR.

## Out of scope (later phases)
Podcast RSS + enclosure backfill + subscribe (Phase 3); schema.org/OG + cross-theme pass
(Phase 4); search UI; AJAX filtering (filter block links to filtered archives, no JS).
