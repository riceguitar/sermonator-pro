# Sermonator — Legacy Compatibility Contract

- **Date:** 2026-06-26
- **Status:** BINDING — referenced by every parity bundle. Thin by design; faithful per-attribute details are filled in per bundle.
- **Parent:** `docs/superpowers/specs/2026-06-25-sermonator-parity-roadmap-design.md` (§4 Compatibility Contract, §5.B).

## The load-bearing rule

> **Fail-visible, never fail-wrong.** Where a shim cannot reproduce the faithful legacy behavior, it renders the safe unfiltered list WITH an editor/admin-visible "this listing needs review" notice — never silently-different content.

A migrated page that shows a *different* sermon set than it did under Sermon Manager is a worse failure than one that visibly says "review me," because the former is undetectable. Every Tier A default below honors this rule.

## Surface guarantees

| Legacy surface | Tier A guarantee (now) | Tier B faithful behavior (deferred) | Fail-visible fallback |
|---|---|---|---|
| `[sermons]`, `[sermons_sm]` | **Bundle 2 — attribute-faithful.** Tag resolves and the per-attribute ledger (below) is applied through `SermonQuery`/`GridArgs`/`Renderer`; the review notice is DROPPED per faithfully-reproduced attribute and a **precise per-attribute** notice names ONLY the unvalidatable/dropped attributes present in the call (empty → no notice) | Realized — see the **[`[sermons]`/`[sermons_sm]` per-attribute ledger](#sermonssermons_sm-per-attribute-ledger-bundle-2)** below; remaining deferrals are signed §63 exceptions | Per-attribute: a dropped/unvalidatable axis falls back to the safe-unfiltered behavior for that axis and is named in the editor notice; `per_page` over-render additionally fires `sermonator_list_truncated` until real pagination lands |
| `[list_sermons]` | Resolves; renders the **faithful taxonomy term-list block** (`TaxonomyFilterBlock`) + reworded per-tag editor notice (legacy→new taxonomy mapping unvalidated; defaults to the Series taxonomy) | Honor the legacy `tax`/`order`/`orderby`/`display` attributes against a validated taxonomy map | Empty term list (block returns nothing) + editor notice |
| `[latest_series]` | Resolves; renders the **faithful latest-series card** (`LatestSeriesBlock`: most-recently-preached series image + title + description, optional `serviceType`) + reworded per-tag editor notice (provisional "latest" semantics unvalidated) | Latest-series resolution validated against the SM source; per-attribute mapping | Empty card (block returns nothing) + editor notice |
| `[sermon_images]` | Resolves; renders the **faithful term-image grid** (`SermonImagesBlock`, `OPTION_TERM_IMAGES` keyed by `term_taxonomy_id`) + reworded per-tag editor notice | Per-attribute (`taxonomy`/`columns`/`size`/`order`) mapping validated against the SM source | Safe sermon list + editor notice when no artwork resolves (never a blank grid) |
| `[list_podcasts]` | Resolves; renders subscribe links (existing `podcast-subscribe` capability) | Same, with per-service attributes | Subscribe links for the default podcast |
| Legacy feed URL `?feed=rss2&post_type=wpfc_sermon[&id=<legacy_id>]` | Resolves 200 to the Sermonator feed; legacy podcast id mapped to the migrated `sermonator_podcast` | Per-podcast taxonomy/audio-video filtering identical to legacy | Default podcast feed if the legacy id can't be mapped |

## GUID & feed-continuity guarantee (HARD REQUIREMENT — rollback story 1)

- Every legacy feed URL a migrated church published resolves **200**, not 404.
- Each item's `<guid>` equals the GUID the **legacy** feed emitted for that episode (captured in the pre-migration snapshot, `Sermonator\Migration\LegacyFeedSnapshot`). Subscribers' apps must not re-download or drop episodes.
- The item set and order are preserved, or the discrepancy is surfaced (fail-visible) — never silently changed.
- **Known Tier-A limitation (multi-podcast over-inclusion):** per-podcast item *filtering* is a Tier-B deferral, so on a site with 2+ podcasts a legacy `?id=<podcast>` feed carries that podcast's channel identity but the full site-wide sermon set. This is **fail-visible**, not silent — `PodcastFeed::render()` fires `do_action( 'sermonator_feed_unscoped_multipodcast', $podcastId, $itemCount )` whenever more than one published podcast exists, so the discrepancy is observable (and surfaced in the migration/admin report) until Bundle 2 adds faithful per-podcast filtering.
- **GUID capture is wired (not just the read side):** `Orchestrator::detect()` captures each legacy sermon's `the_guid()` via `LegacyFeedGuidCapturer` into `LegacyFeedSnapshot` at the detect baseline, and the Finalizer stamps it durably (`META_LEGACY_GUID`) before stripping the back-ref — proven end-to-end by `tests/Integration/Migration/LegacyFeedSnapshotTest.php` (drives the real `detect()`, never hand-seeds).
- **Lost podcast subscribers cannot be reclaimed**, so the snapshot + GUID stability must exist *before any church is told to switch*.

## Bundle 2 — Deep-Compat (Tier B realized)

Source design: `docs/superpowers/specs/2026-06-27-sermonator-bundle2-deep-compat-design.md`. The
binding rule below is unchanged — **fail-visible, never fail-wrong** — applied at per-attribute and
per-surface granularity. A surface/attribute that is *faithfully reproducible* DROPS its review
notice; one that cannot be validated against the now-absent Sermon Manager source KEEPS a precise
notice. Nothing renders silently-different content.

### `[sermons]`/`[sermons_sm]` per-attribute ledger (Bundle 2)

`Frontend\Compat\LegacyAttributeMapper` classifies every legacy attribute into one of four cells and
emits an `unfaithfulAttrs` set; `LegacyShortcodes::render()` names ONLY the present members of that
set in the editor notice (empty set → **no notice**, the notice is earned-off). Legacy aliases are
normalized first (`posts_per_page→per_page`; `id`/`sermon`/`sermons→include`; `taxonomy→filter_by`;
`tax_term→filter_value`; `hide_nav→hide_pagination→disable_pagination`), exactly as SM's
`display_sermons()` did, before classification.

Cell semantics:
- **FAITHFUL** — reproducible against the engine; applied; notice dropped for this attribute.
- **NO-OP-SAFE** — honoring vs. ignoring yields byte-identical *content* (presentation-only, or a
  bug-compatible silent-ignore that matches what SM actually rendered); applied/ignored, **no notice**.
- **UNVALIDATABLE** — best-effort applied OR dropped, but cannot be validated against the absent SM
  source; **keeps a precise per-attribute notice** naming it.
- **UNSUPPORTED** — a whole surface is deferred; recorded as a signed §63 guard exception (below).
  No content-changing render is faked.

| Legacy attribute (aliases) | Cell | Mapped onto | Notice rule |
|---|---|---|---|
| `order` (`ASC`/`DESC`) | FAITHFUL | `SermonQuery` order | none |
| `orderby` = `date`/`published`/`date_published`/`id`/`none`/`title`/`name`/`rand`/`comment_count` | FAITHFUL | `SermonQuery` `orderby` (`date`→published date, `id`→`ID`) | none |
| `orderby` = `preached`/`date_preached` (and the SM default for any invalid value) | FAITHFUL | `SermonQuery` `dateScope=preached` (EXISTS + `META_DATE <= now`, NUMERIC/signed) — reproduces legacy `display_sermons()` dropping FUTURE + DATELESS; **never forks the native LEFT-JOIN branch** | none |
| `filter_by` + `filter_value` (slug values) | FAITHFUL | `LegacyTermResolver` slug→new term (durable across Finalize) → `tax_query` | none |
| `filter_by` + `filter_value` (numeric term ids) | UNVALIDATABLE | `LegacyTermResolver` numeric→new via `TermCrosswalk` (`LEGACY_TERM_ID`); **resolve-or-DROP, never pass-through** | resolves → no notice; any id that does not resolve → that axis is DROPPED and named (passing legacy id N through would select a *different* new term N — fail-wrong) |
| `include` / `exclude` (aliases `id`/`sermon`/`sermons`) | UNVALIDATABLE | `LegacyPostResolver` numeric→new via `Crosswalk` (`LEGACY_POST_ID`) → `post__in`/`post__not_in`; **resolve-or-DROP** | resolves → no notice; any id that does not resolve → dropped from the set and named |
| `year` / `month` | FAITHFUL | `SermonQuery` NUMERIC `META_DATE` range bounds (`BETWEEN`, signed-ts safe) | none |
| `before` | FAITHFUL | `SermonQuery` NUMERIC `META_DATE` upper bound (`<=`, `strtotime`-parsed) | none |
| `after` | **NO-OP-SAFE (bug-compatible)** | intentionally ignored | **no notice** — SM's broken `=>` comparator silently ignored `after`, so honoring it would render content SM *never* produced (itself fail-wrong). Default-ignore; documented opt-in filter only |
| `per_page` (alias `posts_per_page`) | FAITHFUL **pending real pagination**; until then UNVALIDATABLE | `SermonQuery` count + paginated `Renderer` list variant + escaped pager on the `sermon_page` query var | until the pager lands: **named in the notice AND fires `sermonator_list_truncated`** — a naive count map silently drops the long tail of a large archive (the native grid has no pager); certified faithful (notice dropped) only once pagination ships |
| `disable_pagination` (aliases `hide_nav`, `hide_pagination`) | NO-OP-SAFE | pager visibility | no notice — the native grid renders no pager today, so there is nothing to disable (no content difference); honored once the `per_page` pager lands |
| `image_size` | **UNSUPPORTED (§63 no-op)** | ignored; `Renderer` uses its own image handling | no notice — presentation-only, the sermon *set* is unchanged; recorded as a signed §63 exception |
| `hide_filters`, `hide_topics`, `hide_series`, `hide_preachers`, `hide_books`, `hide_dates`, `hide_service_types` | **UNSUPPORTED (§63 filter-form deferred)** | none — the legacy filter FORM is not rendered | no per-call notice (these only gate a control surface that is itself absent); the filter-form deferral is the signed §63 exception |
| `show_initial` | UNVALIDATABLE | not honored (would inject a single-sermon view into the archive) | named in the notice when present |
| any other / unknown attribute | UNVALIDATABLE | captured into `unfaithfulAttrs` | named in the notice when present |

### Per-podcast feed scope rules (Bundle 2)

`Frontend\Feed\PodcastScopeResolver::forPodcast(id)` reads the already-migrated
`META_PODCAST_SETTINGS` term-scope keys intersected with `Identifiers::sermonTaxonomies()` (NOT a
hardcoded slug list — mirrors `PodcastMetaSchema::keys()` anti-drift). Values are already NEW term ids
(remapped at migration by `PodcastWriter::remapSettingsTerms()`) and feed `SermonQuery::buildTaxQuery`
(relation `AND` across taxonomies, `IN` within one) — byte-identical to Pro's `filter_the_query`. No
new tax-query builder.

| Situation | Behavior | Signal / notice |
|---|---|---|
| Scope keys resolve cleanly | Apply the `tax_query`; serve the scoped feed | **No notice — earned.** (audio-only mode) |
| Empty scope, single published podcast | Today's exact UNSCOPED query (provably unchanged) | Silent — byte-identical to the current single-podcast feed |
| Open `missing_podcast_term_crosswalk:*` flag (Pro had scope but a term did not resolve) | Fall back to **UNSCOPED**; **never serve a feed scoped to a dead term id** (never-serve-empty — a dead-term scope would silently empty a live subscriber feed) | fires `sermonator_feed_scope_incomplete` |
| `>1` published podcast, no scope keys | UNSCOPED (carries the podcast's channel identity but the full site-wide set) — over-inclusion, not silent | fires the existing `sermonator_feed_unscoped_multipodcast` over-inclusion signal |
| `sermons_to_show` = `video` / `*_priority` modes | DEFERRED — **audio-only ships**; video/priority faithfulness is unbuilt | fires `sermonator_feed_mode_unsupported`; the per-podcast review notice is **NOT retired** while mode faithfulness is unbuilt (signed §63 exception) |

**Irreversibility gate (rollback story).** Per-podcast filtering NARROWS the subscriber-visible item
set, and a live feed item-set change is irreversible (lost podcast subscribers cannot be reclaimed).
Before any church switches, a per-podcast pre/post feed-diff via `Migration\LegacyFeedSnapshot` MUST
pass; the GUID-continuity guarantee protects only *surviving* items. The HARD invariant is
**no-regression on a single-podcast feed** (the diff must be empty).

### Page-builder findings (Bundle 2)

`Migration\PageBuilderScanner` is a **read-only, fail-visible detector** (asserts zero writes). The
module **rebuild is explicitly backlogged** (signed §63 exception); detection-only is the full-scope
deliverable per the parity roadmap.

| Finding | Fingerprint (catch-all FLOOR, required) | Severity | Surfaced in |
|---|---|---|---|
| Builder-rendered sermon page | (a known builder meta key present: `_elementor_data`, Divi `_et_pb*`, Beaver `_fl_builder_data`, WPBakery `vc_*`) **AND** (any legacy sermon reference: a `wpfc_sermon` id, a legacy taxonomy slug, or a legacy `[sermons]`/`[list_sermons]`/`[latest_series]`/`[sermon_images]` string) | finding | Migration report (pre-switch) **and** Site Health |
| Legacy shortcode embedded in builder postmeta | a legacy `[sermons]`/… string inside builder postmeta (where the `do_shortcode` shim does **not** fire) | lower-severity distinct finding | Migration report and Site Health |

Explicitly **not built:** a `do_shortcode`-on-postmeta bridge (would couple the shim to every
builder's storage format and risk firing shortcodes in unexpected contexts). The embedded-shortcode
finding warns instead of silently rendering.

### Section-63 deferral-exception record (Bundle 2)

Per the design's §63 guard: every deferral is an **EXPLICIT signed Contract-guard exception, never a
silent down-size**. The precursor migrator-reality audit is UNMET (no real Pro-site sample), so the
detect/verify prevalence counter (podcasts-with-scope, `>1`-podcast sites, single-scoped-podcast
count, embedded-`[sermons]` attribute density, builder findings) ships to produce the data the audit
lacked; future video-mode and object-term-mirroring work is gated on it.

| Deferral | Why it is not a fail-wrong | Status |
|---|---|---|
| `[sermons] image_size` — no-op | presentation-only; the sermon *set* is unchanged, so no silently-different content | DEFERRED (signed exception) |
| `[sermons] hide_*` — legacy filter FORM not rendered | the attributes only gate a control surface that is itself absent; the content list is correct | DEFERRED (signed exception) |
| Feed `sermons_to_show` video / `*_priority` modes | audio-only ships faithfully; video/priority modes fire `sermonator_feed_mode_unsupported` and keep the per-podcast notice — never served as if faithful | DEFERRED (signed exception) |
| Page-builder module REBUILD | detection ships (report + Site Health, fail-visible); the rebuild is backlogged, not silently skipped | BACKLOGGED (signed exception) |

## Anti-drift rule

> **Updating this Contract is a required exit criterion of every parity bundle's PR.**

Without this, faithful details land in each bundle's own spec and this document decays into a stub while the real behavior scatters — the exact drift it exists to prevent.

### Changelog

| Date | Bundle | Change |
|---|---|---|
| 2026-06-26 | Bundle 1 (Switch-safe Tier A) | Contract created. Tier A defaults + fail-visible rule established; feed-URL/GUID continuity guarantee recorded; `LegacyFeedSnapshot` is the GUID source of truth. |
| 2026-06-26 | Bundle 4 (Config & display) | `[list_sermons]`/`[latest_series]`/`[sermon_images]` shims upgraded from the wrong-type safe sermon list to their **faithful Bundle 4 display blocks** (`TaxonomyFilterBlock` term list / `LatestSeriesBlock` card / `SermonImagesBlock` tt_id-keyed image grid). A **reworded per-tag editor "needs review" notice is KEPT** (legacy→new taxonomy mapping + provisional "latest" semantics are unvalidated against the absent SM source) — the surface stays fail-visible. `[sermon_images]` keeps the safe-list-on-empty fallback; `[sermons]`/`[sermons_sm]` unchanged (Bundle 2). |
| 2026-06-27 | Bundle 2 (Deep-compat, Tier B) | `[sermons]`/`[sermons_sm]` upgraded from the generic safe-list+notice shim to the **attribute-faithful** per-attribute ledger (FAITHFUL / NO-OP-SAFE / UNVALIDATABLE / UNSUPPORTED per cell; notice names ONLY the present unfaithful attrs, empty → no notice). Recorded: `dateScope=preached` reproduces legacy drop-future+dateless without forking the native branch; `after` is bug-compatible no-op; numeric `filter_value`/`include`/`exclude` resolve-or-DROP via the crosswalks (never pass-through); `per_page` keeps a notice + `sermonator_list_truncated` until real pagination ships. Added **per-podcast feed scope rules** (apply-when-clean = no notice; `missing_podcast_term_crosswalk` → unscoped + `sermonator_feed_scope_incomplete`; never-serve-empty; `>1`-podcast over-inclusion signal; audio-only ships / video modes → `sermonator_feed_mode_unsupported`, notice kept), the **page-builder finding rows** (read-only `PageBuilderScanner` catch-all floor → report + Site Health; rebuild backlogged), and the **§63 deferral-exception record** (image_size no-op, hide_* filter-form deferred, video modes deferred, builder rebuild backlogged — each a signed guard exception, never a silent down-size). |
