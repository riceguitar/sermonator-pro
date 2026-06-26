# Sermonator — Bundle 4: Config & Display (Design)

- **Date:** 2026-06-26
- **Status:** DESIGN. Produced by a gated design workflow (parallel proposals → adversarial critique → synthesis). Implements the roadmap's Bundle 4 (Config & display).
- **Scope:** (1) ONE opinionated settings page; (2) the secondary display blocks (`list_sermons` / `sermon_images` / `latest_series`) the Bundle 1 shims and Bundle 2 reuse.

## 1. Key decisions (critique-hardened)

1. **Hybrid settings binding.** Form 1 = WP Settings API on the shared `OPTION_GROUP_SETTINGS` (`sermonator_settings`) — surfaces Bundle 3's two Bible options with `add_settings_field` UI **only** (never re-registered) and adds three NEW registered Display options. Form 2 = a dedicated `admin-post.php` form that writes **through** to the canonical `META_PODCAST_SETTINGS` post meta (the single source of truth `PodcastConfigFactory` reads), routed back through `add_settings_error` for one notice surface. *(A parallel option for podcast identity would drift — the #1-standard failure; a sanitize-callback side-effect hack is rejected.)*
2. **Provenance boundary.** `archive_slug` and `default_image` use **DISTINCT live keys** (`sermonator_sermon_archive_slug`, `sermonator_sermon_default_image_id`) seeded from the migration artifacts — because `OptionMapper` prefix-swaps every `sermonmanager_*` row and `OptionWriter` overwrites it verbatim on a (supported, pre-Finalize) re-run. Distinct keys mean migration touches only the artifact; an admin edit is never clobbered (and never triggers a spurious rewrite flush). `preacher_label` stays 1:1 (single cosmetic string; residual re-run-reset recorded).
3. **Explicit fallbacks, not registered defaults.** `register_setting`'s `default` filter only attaches on `admin_init`/`rest_api_init`, so it is **absent at `init@5` and on the front end**. Every reader (`Registrar`, `TemplateData`) passes its own fallback to `get_option()`, sourced from a single `Schema\DisplayDefaults` seed (migrated → legacy → hard constant), which `register_setting`'s default also references.
4. **Safe archive-slug rewrite flush.** `SlugRewriteFlusher` hooks `add_option_`/`update_option_` on the live slug key, sets a persistent `OPTION_REWRITE_FLUSH_PENDING` flag **only on a real value change**, and an `init@99` handler scoped to `is_admin()||wp_doing_cron()` flushes exactly once then clears the flag — a front-end visitor never pays the flush. `archive_slug` drives single-sermon permalinks too, so the field carries change-confirm + an inbound-links-break warning, and sanitize rejects reserved/colliding slugs (falling back to the stored value, never a guess).
5. **Podcast-identity phase-gating.** Read `MigrationState` + `Detector`: auto-create the default podcast on first save **only** on a genuinely fresh site (no legacy data); when legacy data exists and phase ≠ `finalized`, render the podcast section **read-only** with a "configure after migration completes" notice. *(Else a church configures podcast #A, migration imports #B, `OptionWriter` repoints `OPTION_DEFAULT_PODCAST` → split-brain; and a re-run's `delete_post_meta`+re-add wipes identity edits.)*
6. **`META_PODCAST_SETTINGS` write discipline.** It's currently registered nowhere and `PodcastFeed` emits it straight to Apple/Spotify. Add `Schema\PodcastMetaSchema::register_post_meta()` with an `auth_callback` (`manage_options`), a typed ~13-key allowlist, and sanitize-at-write (`owner_email`→`sanitize_email`, `explicit`→bool, etc.); bust the feed cache on save. Clears the §5.D audio-backfill bar.
7. **No stored-but-dead config.** Wire consumption IN this bundle: `preacher_label` → `Registrar` `TAX_PREACHER` labels AND threaded `TemplateData`→`SermonView::$preacherLabel`→`Renderer::meta()`; `default_image` → a shared `Frontend\EffectiveImage` resolver used by BOTH the single-sermon thumbnail fallback and the new images grid. *(Shipping them unconsumed is parity theater + a regression — legacy `default_image` actually rendered a fallback.)*
8. **Display blocks.** Build TWO new blocks — `SermonImagesBlock` (term image grid) + `LatestSeriesBlock` — delegating to two NEW pure `Renderer` methods (`termImageGrid`, `latestSeries`); **reuse `TaxonomyFilterBlock` for `list_sermons`** (it already IS that primitive — add a "Sermon List" editor variation, no redundant block). `sermon_images` keys `OPTION_TERM_IMAGES` strictly by `term_taxonomy_id` (matching `ArtworkWriter`) and falls back to **safe-list + notice** (never a blank grid) when the option is absent.
9. **Shim upgrade + keep the notice.** Upgrade the Bundle 1 `[list_sermons]`/`[sermon_images]`/`[latest_series]` shims to the faithful block render (better than the wrong-type safe list) but **keep a reworded per-tag/per-attribute "needs review" notice** — the Compatibility Contract files these under binding Tier B, the `latest` semantics + att mappings are unvalidated against the (absent) SM source, and dropping the notice would be a fail-wrong. `[sermons]`/`[sermons_sm]` are Bundle 2, unchanged.

## 2. Components

| Component | Responsibility |
|---|---|
| `Admin\SettingsPage` | `add_submenu_page` under Sermons (`edit.php?post_type=sermonator_sermon`, cap `manage_options`), screen-scoped assets (mirrors `MigrationWizard`); three `add_settings_section` (Bible / Display / Podcast identity); Form 1 = options.php Settings API, Form 2 = admin-post.php; phase-aware read-only podcast section. |
| `Admin\DisplaySettingsRegistrar` | `register_setting()`s the three live Display options on the shared group (sanitize + seed defaults); `admin_init`+`rest_api_init`; never touches the Bible options. |
| `Admin\PodcastIdentityController` | admin-post Form 2 handler: nonce/cap, phase-gate (fresh auto-create vs read-only), sanitize via `PodcastMetaSchema`, write through to `META_PODCAST_SETTINGS`, bust feed cache, `add_settings_error` funnel. |
| `Schema\PodcastMetaSchema` | `register_post_meta` with `auth_callback` + typed ~13-key allowlist + per-key sanitize; the shared key catalog reused by `PodcastConfigFactory` (reader) + the writer. |
| `Schema\DisplayDefaults` | `defaultArchiveSlug()`/`defaultImageId()`/`preacherLabel()` seed resolvers (migrated artifact → legacy → hard constant); single source for both `register_setting` defaults and every explicit `get_option` fallback; one-time `default_image` URL→id resolution. |
| `Frontend\SlugRewriteFlusher` | pending-flag on real slug change; `init@99` single flush scoped admin/cron; wired unconditionally in `Plugin::boot`. |
| `Frontend\EffectiveImage` | effective image id (real thumbnail else default image id) for both single view and the images grid. |
| `Frontend\Blocks\SermonImagesBlock` + `blocks/sermon-images/block.json` | term-image grid: `get_terms` + `OPTION_TERM_IMAGES`[tt_id] + `wp_get_attachment_image` → `Renderer::termImageGrid`; safe-list+notice fallback when empty. |
| `Frontend\Blocks\LatestSeriesBlock` + `blocks/latest-series/block.json` | latest (most-recently-preached, provisional) series term + image/title/description → `Renderer::latestSeries`. |
| `Frontend\Renderer` (extended) | NEW pure `termImageGrid()` + `latestSeries()` (escape every leaf; core attachment HTML passed through); `meta()` reads the threaded `$v->preacherLabel`. |
| `Frontend\TemplateData` + `SermonView` (extended) | resolve preacher label + effective fallback image; new `SermonView::$preacherLabel` — keeping option/meta reads out of the pure Renderer. |
| `Model\Registrar` (modified) | reads `OPTION_ARCHIVE_SLUG` (CPT rewrite) + `OPTION_PREACHER_LABEL` (TAX_PREACHER labels), both at `init@5` with explicit fallbacks. |
| `Frontend\Compat\LegacyShortcodes` (modified) | upgrade the 3 shims to faithful block render, keep reworded per-tag review notice; **update the Compatibility Contract as the PR exit criterion**. |

## 3. New Identifiers options
`OPTION_ARCHIVE_SLUG='sermonator_sermon_archive_slug'`, `OPTION_DEFAULT_IMAGE_ID='sermonator_sermon_default_image_id'`, `OPTION_PREACHER_LABEL='sermonator_preacher_label'`, `OPTION_REWRITE_FLUSH_PENDING='sermonator_rewrite_flush_pending'`. All on `OPTION_GROUP_SETTINGS`.

## 4. Top risks
1. **Migration re-run clobbers live config** — mitigated by distinct-key provenance; residual: `preacher_label` 1:1 (low-stakes, recorded).
2. **`archive_slug` blast radius** (drives permalinks) — preservation-seeded default + change-confirm + collision guard + single correct-timed flush + warning; cutting it entirely remains defensible.
3. **Podcast split-brain / re-run wipe** — phase-gate read-only until finalized.
4. **`META_PODCAST_SETTINGS` feed injection** — allowlist + sanitize-at-write + cache bust.
5. **`sermon_images` blank grid** on non-migrated sites — safe-list+notice fallback.
6. **Stored-but-dead config** — wire consumption in this bundle.
7. **`register_setting` default absent at `init@5`/front end** — explicit `DisplayDefaults` fallbacks everywhere.
8. **`latest_series` semantics unvalidated** — keep the review notice (fail-visible).

## 5. Test strategy
Unit (Brain Monkey): `DisplayDefaults` seed order; sanitize callbacks (slug reserved/colliding → stored; image_id non-attachment → 0; label cap); `DisplaySettingsRegistrar` never re-registers Bible options; `PodcastMetaSchema` allowlist+sanitize; `Renderer::termImageGrid`/`latestSeries` escaping + empty-state; `EffectiveImage` fallback. Integration (wp-env): settings-save round-trip (options.php); the **rewrite-flush** test incl. the migration-re-run no-clobber/no-flush proof; `PodcastIdentityController` write-through + phase-gate; preacher-label (taxonomy label + meta row agree); block render (images grid tt_id keying + empty fallback, latest-series). Update the Compatibility Contract changelog.

## 6. Task breakdown (ordered, each independently testable)
1. Identifiers + `Schema\DisplayDefaults` (constants + seed resolvers).
2. `Admin\DisplaySettingsRegistrar` (register 3 live options; never the Bible ones; wire in boot).
3. `Frontend\SlugRewriteFlusher` (pending-flag + admin/cron single flush; re-run no-clobber/no-flush proof).
4. `preacher_label` consumption (Registrar labels + `SermonView::$preacherLabel` + `Renderer::meta`).
5. `default_image` consumption (`Frontend\EffectiveImage` + `featuredImage` fallback; one-time URL→id).
6. `Schema\PodcastMetaSchema` (register_post_meta + allowlist + sanitize; share catalog with `PodcastConfigFactory`).
7. `Admin\PodcastIdentityController` (admin-post Form 2; phase-gate; write-through; cache bust; funnel).
8. `Admin\SettingsPage` (submenu; 3 sections; Form 1 + Form 2; screen-scoped assets; phase-aware; wire in registerAdmin).
9. `Renderer::termImageGrid()` + `latestSeries()` pure methods.
10. `SermonImagesBlock` + `blocks/sermon-images/block.json` (tt_id keying; empty-option safe-list fallback; register).
11. `LatestSeriesBlock` + `blocks/latest-series/block.json` (provisional latest series + serviceType; register).
12. `list_sermons` reuse: `TaxonomyFilterBlock` "Sermon List" editor variation.
13. `LegacyShortcodes` shim upgrade (delegate to faithful blocks, keep per-tag review notice) + Contract changelog (PR exit criterion).
