# Sermonator — Legacy Capability Inventory & Parity Gap Matrix

- **Date:** 2026-06-25
- **Status:** RESEARCH ARTIFACT — input to a forthcoming forum-gated *capability-parity scope* decision. Nothing here is committed scope yet.
- **Goal it serves:** Sermonator's north star is **capability parity** with the plugins it replaces — the same *capabilities* for migrating users, achieved the native-WordPress way (NOT by cloning their Twig/page-builder architecture).
- **Sources inventoried (full source on disk):**
  - Free: `/Users/david/Repo/SermonManager/Sermon-Manager-2.15.15` ("Sermon Manager for WordPress" v2.15.15)
  - Pro: `/Users/david/Repo/SermonManager/sermon-manager-pro` ("Sermon Manager Pro 2.0", EDD product id 53)
- **How produced:** four parallel source-reading passes (display surface / admin+Bible+data / podcast+media / Pro), then reconciled against Sermonator's current `main` + in-flight working tree (verified by grep on 2026-06-25).
- **Distinction that governs everything below:** *capability* parity ≠ *implementation* parity. A legacy feature is "at parity" when a migrating church can do the same thing, even if the mechanism differs (e.g. a block instead of a shortcode, `register_block_template` instead of Twig).

---

## 1. Sermonator baseline (what we have to compare against)

**Merged to `main`:** `sermonator_sermon`/`sermonator_podcast` CPTs; 5 taxonomies; full sermon meta; `TemplateData→SermonView→Renderer` pipeline; single-sermon view; sermon + 5 taxonomy archives; blocks `sermon-meta`, `audio-player`, `video`, `sermon-card`, `sermon-grid`, `taxonomy-filter`, `podcast-subscribe`; `[sermonator_sermons]` shortcode; `register_block_template` + classic fallback; single Apple-compatible podcast RSS feed; reversible audio-**size** backfill; schema.org JSON-LD + Open Graph.

**In-flight (uncommitted working tree):** "Sermon Details" authoring layer (`src/Admin/Authoring/`); `Schema\VideoEmbedPolicy`; `Frontend\Feed\AudioHeadProbe` (audio **duration** groundwork); blocks `bulletin`, `featured-image`, `notes`; JS build toolchain.

**Confirmed ABSENT on 2026-06-25 (grep-verified):** any Bible-verse linking / version setting / RefTagger; any settings/options admin page; `preacher_label` customization; any `WP_Widget`; the `list_sermons` / `sermon_images` / `latest_series` capabilities; taxonomy-filtered podcast feeds. `[sermonator_sermons]` accepts only `count, columns, order, preacher, series, topic, book, service_type` (a subset of legacy `[sermons]`).

---

## 2. Free plugin (Sermon Manager 2.15.15) — capability inventory

### 2.1 Shortcodes
| Tag | Purpose | Key attributes |
|---|---|---|
| `[sermons]` (+ alias `[sermons_sm]`) | Main archive/grid with sort + filter UI + pagination | `per_page, sermons, order, orderby` (incl. `date_preached`/`preached`), `filter_by, filter_value, year, month, after, before, image_size, hide_filters, hide_{topics,series,preachers,books,dates,service_types}, include, exclude, disable_pagination` |
| `[list_sermons]` (legacy `[list-sermons]`) | Term list for a taxonomy, linked to term archives | `display` (series/preachers/topics/books), `order, orderby` |
| `[sermon_images]` (legacy `[sermon-images]`) | Term **image grid** (series/preacher images) | `display, order, orderby, size, hide_title, show_description` |
| `[latest_series]` | Latest series image + title + description block | `image_class, size, show_title, title_wrapper, title_class, service_type, show_description, wrapper_class` |
| `[sermon_sort_fields]` | Standalone filter form (taxonomy dropdowns) | per-taxonomy filters + `visibility`, `hide_*`, `action` |
| `[list_podcasts]` | Subscribe links (iTunes/Android/Overcast) | `include, exclude` |

### 2.2 Widgets
- `recent-sermons` (`SM_Widget_Recent_Sermons`): recent sermons list with title/preacher/date and optional key verse (`widget_show_key_verse`). Options: `title, number, before_widget, after_widget`.
- No Gutenberg blocks in the free plugin.

### 2.3 Bible / scripture behaviour  ← headline gap
- Mechanism: **RefTagger** third-party script (`https://api.reftagger.com/v2/RefTagger.js`, `.es` variant for Spanish locales) scans rendered pages and turns Bible references into hover-popups. Loaded only when `verse_popup` is NOT checked. (`assets/vendor/js/verse.js`, `sermons.php:361`)
- Version setting `verse_bible_version` (default ESV): ESV, NIV, KJV, NKJV, NASB, NCV, LEB, MESSAGE, GW, HCSB, AMP, ASV, DAR, DOUAYRHEIMS, YLT; Spanish LBLA95, NBLH, NVI, RVR60, RVA.
- Data: `bible_passage` post meta (free-text reference, e.g. "John 3:16-18, Luke 2:1-3"). Older `bible_passages_start/_end` arrays auto-migrated to the single string.
- `widget_show_key_verse` surfaces the passage inside the recent-sermons widget.
- **Note:** RefTagger is an external phone-home dependency — a design tension for an open-source/privacy-minded rewrite (see §7 decision 1).

### 2.4 CPT / taxonomies / meta / capabilities / term images
- CPT `wpfc_sermon` with mapped custom caps; custom caps `manage_wpfc_categories`, `manage_wpfc_sm_settings`; granted to admin/editor/author (matrix in source).
- Taxonomies: `wpfc_preacher`, `wpfc_sermon_series`, `wpfc_sermon_topics`, `wpfc_bible_book`, `wpfc_service_type` (all flat, REST-enabled, customizable slugs; `preacher_label` renames Preacher → Speaker/Minister/…).
- **Term images** (the bundled "Sermon Images Plugin"): `image_id` term meta on preacher + series; option `sermon_image_plugin_associations`.
- Sermon meta: `sermon_date` (Unix ts), `sermon_description` (wysiwyg), `bible_passage`, `sermon_audio`, `sermon_audio_id`, `_wpfc_sermon_duration` (HH:MM:SS), `_wpfc_sermon_size` (bytes), `sermon_video` (embed), `sermon_video_link` (url), `sermon_notes`, `sermon_bulletin`, `wpfc_service_type` (denorm), `Views`, `sermon_date_auto`.
- **View counts** via an `entry-views` library (`Views` meta); `enable_views_count_logged_in` toggle.

### 2.5 Settings surface (CMB2, 5 tabs, all options prefixed `sermonmanager_`)
- **General:** `player` (plyr/mediaelement/WordPress/none), `date_format`, `archive_slug`, `common_base_slug`, `preacher_label`.
- **Display:** `default_image`, `css` (disable CSS), `archive_orderby`, `archive_order`, `archive_player`, `archive_meta`, `disable_image_archive`, `disable_image_single`, `hide_read_more_when_not_needed`, `hide_filters`, `service_type_filtering`, `use_prev_next_pagination`.
- **Podcast:** `title, description, website_link, language, copyright, webmaster_name, webmaster_email, itunes_author, itunes_subtitle, itunes_summary, itunes_owner_name, itunes_owner_email, itunes_cover_image, itunes_sub_category, podtrac, enable_podcast_html_description, enable_podcast_redirection, podcast_redirection_old_url, podcast_redirection_new_url, podcasts_per_page, podcast_url_{itunes,android,overcast}, podcast_sermon_image_series`.
- **Verse:** `verse_popup, verse_bible_version, widget_show_key_verse`.
- **Advanced:** debug/compat/perf flags — `debug_import, sort_bible_books, theme_compatibility, use_native_player_safari, disable_cloudflare_plyr, enable_views_count_logged_in, import_disallow_comments, post_content_enabled, player_js_footer, disable_layouts, force_layouts, clear_transients`, update-runner flags.

### 2.6 Podcast / RSS / media
- Single RSS2 feed at `?feed=rss2&post_type=wpfc_sermon`, **also taxonomy-filterable** via query params (`&wpfc_sermon_series=…` etc.). Requires `sermon_audio`. Full iTunes channel + item tags (author/subtitle/summary/owner/explicit=no/image/category, per-item author/subtitle/image/duration/keywords, enclosure, PodTrac wrapping, dc:creator, content:encoded). Template `views/wpfc-podcast-feed.php`, overridable from theme.
- Feed redirection (old→new URL, 301).
- Audio: size via `wp_get_attachment_metadata()` (local) or HTTP HEAD `content-length` (remote); duration via attachment metadata or manual entry; 4 player choices; seek/timestamp support (`?t=1h2m3s`/`25:12`).
- Video: provider detection for YouTube/Vimeo/Facebook/direct, Plyr embeds, seek; `sermon_video` (embed code) + `sermon_video_link` (url).

### 2.7 Importers / exporters
- WXR **export** (`SM_Export_SM`) and **import** (`SM_Import_SM`).
- Cross-plugin importers: **Sermon Browser** (`SM_Import_SB`, reads `*_sb_*` tables) and **Series Engine** (`SM_Import_SE`, reads `*_se_*` tables).

---

## 3. Pro plugin (Sermon Manager Pro 2.0) — what it ADDS

1. **Custom templating engine** — filesystem **Twig** templates (`wpfc_sm_template` CPT), template manager UI, duplication/versioning/auto-update, built-in skins (Epiclesis/Genesis/GSquare), 6 settings tabs (`sm_template_settings`). *(This is exactly the architecture we are replacing with native blocks.)*
2. **Multiple podcasting** — `wpfc_sm_podcast` CPT; unlimited podcasts, each filtered by taxonomy with its own iTunes metadata; feeds at `?feed=rss2&post_type=wpfc_sermon&id=<podcast_id>`; per-podcast `sm_podcast_settings`; audio-only/video-only/priority modes; video-enclosure MIME handling.
3. **Page-builder integration** — Elementor (6 widgets: Archive, Filtering, Taxonomy, Audio Player, Video Player, Info + skins/query controls), Beaver, Divi, Visual Composer modules, and a Gutenberg `sermons-grid` block.
4. **Pro shortcodes** — `[smpro_archive]`, `[smpro_tax]`, and `[powerpress]` (if Blubrry PowerPress enabled).
5. **Enhanced Bible popups** — RefTagger styling, fonts/colors, social sharing (Twitter/Facebook/Google+/Faithlife), Logos buttons, chapter-level tagging, biblia/faithlife reader choice.
6. **Players** — enhanced Plyr with **download button**; optional PowerPress player.
7. **Date filtering** — virtual `wpfc_dates` month/year filter across all builder integrations.
8. **Pro settings** — `blubrry_powerpress_player, update_branch, smp_archive_page, smp_tax_page, force_page_overrides, smpro_banner_backgroud, additional_css, sermonmanager_license_key`; page-assignment + custom-CSS editor.
9. **Licensing/updates** — EDD against `my.wpforchurch.com` (release/nightly branches). *(N/A — Sermonator has no monetization.)*

---

## 4. Parity gap matrix (vs Sermonator on 2026-06-25)

Legend: ✅ at parity · 🟡 partial / in-flight · 🔴 real gap · ⚪ deliberate divergence (replace, don't copy)

| Capability area | Legacy | Sermonator | Status |
|---|---|---|---|
| Data model (CPT/tax/meta/caps/term images/views) | free+pro | migration preserves all | ✅ |
| Single sermon view | free | Renderer | ✅ |
| Sermon + 5 taxonomy archives | free | merged | ✅ |
| Archive shortcode w/ full sort+filter+date attrs | `[sermons]` | `[sermonator_sermons]` (subset) | 🟡 |
| Standalone filter form | `[sermon_sort_fields]` | taxonomy-filter block | 🟡 |
| Term lists | `[list_sermons]` | — | 🔴 |
| Term **image grid** | `[sermon_images]` | featured-image (single) | 🔴 |
| Latest-series block | `[latest_series]` | — | 🔴 |
| Recent-sermons **widget** | widget | — | 🔴 |
| Notes/bulletin attachments | free | bulletin+notes blocks (in-flight) | 🟡 |
| **Bible verse linking + version setting** | RefTagger | plain "Scripture" text only | 🔴 |
| Single Apple-compatible feed + iTunes meta | free | merged | ✅ |
| Subscribe links | `[list_podcasts]` | podcast-subscribe block | ✅ |
| Taxonomy-filtered feeds | free | single feed, no tax filter | 🔴 |
| **Multiple podcasts** | pro | data migrated, no UI | 🟡 |
| PodTrac / feed redirection / per-feed paging | free | — | 🔴 |
| Audio playback + enclosure size | free | merged (backfill) | ✅ |
| Audio **duration** compute | free | AudioHeadProbe (in-flight) | 🟡 |
| Video providers (YT/Vimeo/FB/direct) + seek | free | video block (basic) | 🟡 |
| Seek/timestamp (`?t=`), download button | free/pro | — | 🔴 |
| Per-church config (podcast meta, default image, preacher label, archive slug) | settings | **no settings page** | 🔴 |
| Player choice / layout micro-toggles | settings | opinionated native | ⚪ |
| Twig templating + template manager | pro | `register_block_template` + blocks | ⚪ |
| Page-builder modules (Elementor/Divi/Beaver/VC) | pro | native blocks | ⚪ (revisit on demand) |
| Cross-plugin importers (SB/SE) + WXR export | free | SM→Sermonator migration only | ⚪ (open — §7.5) |
| Licensing/EDD updates | pro | — | ⚪ (no monetization) |

---

## 5. Deliberate divergences (capabilities we will NOT clone)
- **Twig engine + template marketplace/manager** → native blocks + `register_block_template`.
- **Page-builder modules** → native blocks (Elementor/Divi revisited only on real demand).
- **Settings sprawl** (player choice, theme-compat, layout flags) → opinionated native rendering; only genuinely per-church config gets a UI.
- **RefTagger-by-default** → see §7.1 (lean self-hosted/no phone-home).
- **EDD licensing/updates** → N/A.

---

## 6. ⚠️ Data-continuity flag — legacy shortcodes embedded in content
Migrating churches have legacy shortcodes (`[sermons]`, `[list_sermons]`, `[latest_series]`, `[sermon_images]`, `[list_podcasts]`) saved inside their pages/posts. If Sermonator doesn't register compatible tags, **those pages render the literal `[sermons]` text or nothing** — a visible content break. Given that content/data preservation is the project's #1 standard (and `PostContentReconciler` already shows we care about `post_content`), **legacy-shortcode shims are arguably a parity *requirement*, not a nicety**, even though blocks are the go-forward authoring surface.

---

## 7. Open decisions for the forum gate (with current recommendations)
Each needs the owner's call; recommendations are starting positions, not conclusions.

1. **Bible verse lookup** *(highest priority; owner-named)* — *Lean:* native verse linking + a Bible-version setting that links out to a reader, **without** loading RefTagger's external script by default; optional opt-in RefTagger-compatible mode. Run **adr-on-rails** (external-dep vs self-hosted vs link-only) + **rollback-story** (it injects into rendered content).
2. **Legacy shortcode shims** — *Lean:* yes, register compatible tags so migrated content survives. Pairs with the data-preservation standard.
3. **Settings page** — *Lean:* one small opinionated screen (podcast identity, default image, `preacher_label`, Bible version, archive slug); skip display-toggle sprawl.
4. **Multiple podcasts** — *Lean:* ship parity with the single default podcast (data already migrated); multi-podcast UI as fast-follow. Reconcile with `2026-06-23-sermonator-b2a-known-limitations.md`.
5. **Importers / WXR export** — *Lean:* cross-plugin importers (Sermon Browser/Series Engine) **out** of scope; consider a lightweight WXR **export** for data portability. Decide explicitly.
6. **Widgets** — *Lean:* a "recent sermons" **block** instead of a legacy `WP_Widget`.
7. **Secondary display gaps** — `[list_sermons]`/`[sermon_images]`/`[latest_series]` and taxonomy-filtered feeds, seek/timestamps, download button: triage into parity-now vs backlog during the spec.

---

## 8. Identifier appendix (verbatim — critical for parity & shims)
- **Free shortcodes:** `sermons`, `sermons_sm`, `list_sermons` (`list-sermons`), `sermon_images` (`sermon-images`), `latest_series`, `sermon_sort_fields`, `list_podcasts`.
- **Pro shortcodes:** `smpro_archive`, `smpro_tax`, `powerpress`.
- **Free widget id:** `recent-sermons`.
- **Legacy post types:** `wpfc_sermon`, `wpfc_sm_podcast` (pro), `wpfc_sm_template` (pro).
- **Legacy taxonomies:** `wpfc_preacher`, `wpfc_sermon_series`, `wpfc_sermon_topics`, `wpfc_bible_book`, `wpfc_service_type`.
- **Legacy feed:** `?feed=rss2&post_type=wpfc_sermon[&<tax>=<slug>][&id=<podcast_id> (pro)]`.
- **Option prefix:** `sermonmanager_` (free), plus pro keys in §3.8. Full free option-key list in §2.5.
- **Legacy sermon meta:** see §2.4 (already mapped in `src/Migration/LegacyIdentifiers.php`).
