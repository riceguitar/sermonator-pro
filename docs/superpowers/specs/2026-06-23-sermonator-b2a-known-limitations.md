# Sermonator B2a (Migration Writers) — Known Limitations & Follow-ups

- **Date:** 2026-06-23
- **Status:** B2a merged. This records the items the 10-iteration adversarial review deliberately DEFERRED as minor/non-blocking, plus forward-looking constraints the later sub-projects (B2b lifecycle, C wizard) must honor. None is a data-loss risk on clean Sermon Manager data; the migration is non-destructive until Finalize.

## Review provenance

B2a writers passed 10 whole-branch adversarial review iterations (4 lenses each: data-loss / legacy-immutability / idempotency-resume / gating+tests) + a focused re-review, with 10 fix rounds (~48 confirmed data-integrity bugs fixed, each with a regression test). The catastrophic classes — dropped spam/trash comments, double-unslash corruption of paths/names/meta, KSES-stripped embeds, duplicate posts on crash-resume, and **silent loss of all sermon categorization when the legacy plugin is deactivated** — were all found and fixed. Final state: 0 critical across the last two iterations; legacy-immutability bar confirmed intact (no writer mutates a legacy or shared row before Finalize). Suites: unit 79, integration 213 (986 assertions).

## Deferred minor items (safe to defer; address opportunistically)

- **`to_ping` / `pinged` columns not copied** — transient pingback queues; `guid` is correctly regenerated. Negligible for a sermon archive.
- **`term_order` drift on native-taxonomy re-run** — advisory ordering only; no original-data loss.
- **`isUnixTimestamp` classifies packed all-digit calendar dates (`20210501`) as numeric** — advisory; the raw `sermonator_date` is preserved verbatim regardless.
- **`META_DATE_NORMALIZED` aligns to the non-numeric SUBSEQUENCE of `sermon_date`, not positionally** — documented on `Identifiers`; a normalized companion is advisory (raw is source of truth). Any B2b/C consumer must key companions by raw value/row, not by position.
- **Comment crash-orphan signature** — now a 7-field signature with an ambiguity guard + positional adoption for byte-identical copies; residual risk is only a recoverable duplicate (never a mis-thread) and only under crash-resume.
- **TermWriter marker-less same-name+same-slug adoption** — a crash-orphan that lost its `LEGACY_SLUG` marker and a native term sharing BOTH name and slug are byte-indistinguishable; adoption is gated to that exact case and only ever touches `sermonator_*` terms (never legacy), and a wrong adoption is recoverable pre-Finalize. The safe failure mode (deterministic `-legacy-{id}` suffix duplicate) is used for all other collisions.
- **Attachment immutability** is proven by code inspection (no writer fetches/mutates an attachment post) but lacks a dedicated before/after snapshot test — a coverage gap, not a live bug.
- **KSES filter-priority fidelity** nuance on restore — no legacy/shared mutation; restoration is now symmetric (captures prior state) across all 3 wrap sites.

## Forward-looking constraints the later sub-projects MUST honor

- **B2b Rollback — native shared-taxonomy counts (HARD CONSTRAINT):** `SermonWriter::mirrorNativeTaxonomies` inserts native (category/post_tag/custom) `term_relationships` rows directly via `$wpdb` WITHOUT bumping the shared `wp_term_taxonomy.count` (deferred via `OPTION_MIGRATION_PROGRESS['sermons']['native_term_recount_tt_ids']`). **Rollback must delete those rows directly via `$wpdb` and recount the affected tt_ids once — NEVER via `wp_delete_post`/`wp_set_object_terms`**, which would decrement the church's own shared counts below their true value. (Pinned in code at `mirrorNativeTaxonomies` + encoded in the Plan B2b Rollback task.)
- **B2b Finalize — native count recount:** at Finalize, recount the tt_ids recorded in `native_term_recount_tt_ids` so shared counts move exactly once, at the point of no return.
- **B2b Verifier — surfaced flags to gate on:** `legacy_taxonomy_unreadable:<tax>`, `missing_term_crosswalk:<id>`, `missing_podcast_term_crosswalk:<id>`, `missing_option_id_crosswalk:<opt>`, `meta_key_collision:<key>`, `slug_collision:<...>`, `post_parent_unresolved:<id>`, `legacy_nonnumeric_date`, `post_content_divergence`. Verification is not GREEN while any failure flag is open; some self-heal on a later write once their dependency migrates.
- **Legacy schema registration:** `LegacySchemaRegistrar::ensureRegistered()` idempotently registers the `wpfc_*` post types/taxonomies at the top of every legacy-read entry point so the migration works with the legacy plugin DEACTIVATED (the normal drop-in config). Any new legacy-read path must call it first.
