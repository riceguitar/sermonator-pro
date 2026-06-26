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
| `[list_sermons]` | Resolves; renders the safe sermon list | Term list for the requested taxonomy (Bundle 4 block) | Safe list + editor notice |
| `[latest_series]` | Resolves; renders the safe sermon list | Latest-series image + title + description (Bundle 4 block) | Safe list + editor notice |
| `[sermon_images]` | Resolves; renders the safe sermon list | Term image grid (Bundle 4 block) | Safe list + editor notice |
| `[list_podcasts]` | Resolves; renders subscribe links (existing `podcast-subscribe` capability) | Same, with per-service attributes | Subscribe links for the default podcast |
| Legacy feed URL `?feed=rss2&post_type=wpfc_sermon[&id=<legacy_id>]` | Resolves 200 to the Sermonator feed; legacy podcast id mapped to the migrated `sermonator_podcast` | Per-podcast taxonomy/audio-video filtering identical to legacy | Default podcast feed if the legacy id can't be mapped |

## GUID & feed-continuity guarantee (HARD REQUIREMENT — rollback story 1)

- Every legacy feed URL a migrated church published resolves **200**, not 404.
- Each item's `<guid>` equals the GUID the **legacy** feed emitted for that episode (captured in the pre-migration snapshot, `Sermonator\Migration\LegacyFeedSnapshot`). Subscribers' apps must not re-download or drop episodes.
- The item set and order are preserved, or the discrepancy is surfaced (fail-visible) — never silently changed.
- **Lost podcast subscribers cannot be reclaimed**, so the snapshot + GUID stability must exist *before any church is told to switch*.

## Anti-drift rule

> **Updating this Contract is a required exit criterion of every parity bundle's PR.**

Without this, faithful details land in each bundle's own spec and this document decays into a stub while the real behavior scatters — the exact drift it exists to prevent.

### Changelog

| Date | Bundle | Change |
|---|---|---|
| 2026-06-26 | Bundle 1 (Switch-safe Tier A) | Contract created. Tier A defaults + fail-visible rule established; feed-URL/GUID continuity guarantee recorded; `LegacyFeedSnapshot` is the GUID source of truth. |
