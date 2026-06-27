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
| `[sermons]`, `[sermons_sm]` | Tag resolves; renders the standard Sermonator sermon list (no raw `[sermons]` text) | Honor `order`/`orderby` (incl. `date_preached`), `filter_by`/`filter_value`, `year`/`month`/`before`/`after`, `hide_*`, `include`/`exclude`, `per_page` | Safe unfiltered list + editor notice when any unsupported attribute is present |
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

## Anti-drift rule

> **Updating this Contract is a required exit criterion of every parity bundle's PR.**

Without this, faithful details land in each bundle's own spec and this document decays into a stub while the real behavior scatters — the exact drift it exists to prevent.

### Changelog

| Date | Bundle | Change |
|---|---|---|
| 2026-06-26 | Bundle 1 (Switch-safe Tier A) | Contract created. Tier A defaults + fail-visible rule established; feed-URL/GUID continuity guarantee recorded; `LegacyFeedSnapshot` is the GUID source of truth. |
| 2026-06-26 | Bundle 4 (Config & display) | `[list_sermons]`/`[latest_series]`/`[sermon_images]` shims upgraded from the wrong-type safe sermon list to their **faithful Bundle 4 display blocks** (`TaxonomyFilterBlock` term list / `LatestSeriesBlock` card / `SermonImagesBlock` tt_id-keyed image grid). A **reworded per-tag editor "needs review" notice is KEPT** (legacy→new taxonomy mapping + provisional "latest" semantics are unvalidated against the absent SM source) — the surface stays fail-visible. `[sermon_images]` keeps the safe-list-on-empty fallback; `[sermons]`/`[sermons_sm]` unchanged (Bundle 2). |
