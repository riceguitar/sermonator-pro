# Sermonator — Data Foundation & Migration Engine (Sub-project 1)

- **Date:** 2026-06-22
- **Status:** Approved design (pre-implementation)
- **Scope:** Sub-project 1 of the Sermonator rebuild

## 1. Context

Today there are two plugins in this problem space:

- **Sermon Manager** (free, `WP-for-Church/Sermon-Manager`, v2.15.16) — registers the `wpfc_sermon` post type, five taxonomies, and all sermon meta. Built on CMB2. No custom DB tables; no destructive uninstall.
- **Sermon Manager Pro** (this repo, v2.0.13) — a paid add-on (Twig templates, page-builder integrations, podcast feeds, legacy WHMCS licensing). Largely front-end; ~20% dead code; deactivation hook dangerously wipes `wp-content/data`.

**The goal:** replace both with a single standalone plugin, **Sermonator**. All identifiers rebrand to the `sermonator_` namespace. Sermonator is a **drop-in replacement**: an existing Sermon Manager (+ Pro) install upgrades to it with **zero data loss**. The front-end (Twig/Divi/Elementor/Beaver/Gutenberg/old podcast rendering) is fully discarded and rewritten later — only **data continuity** matters.

**Governing standard:** data preservation and reuse is the highest bar — it outranks clean architecture and every other concern. Any tradeoff that touches data is decided in favor of data safety.

**Decomposition:** "Sermonator" is six sub-projects (1: data foundation + migration; 2: admin/editing; 3: podcast feed generation; 4: front-end; 5: importers; 6: distribution & extensibility — **no monetization**). This spec covers **sub-project 1 only** — the data model and the migration engine that every other piece sits on.

## 2. Constraints (decided)

- **Platform:** PHP 8.1+ / WordPress 6.0+. Installs below this floor get a graceful "update PHP/WP to migrate" admin notice; their data is never touched.
- **Migration UX:** a guided admin **wizard** (detect → migrate in the background → verify → roll back or finalize), with a **WP-CLI** mirror underneath for large sites and testing. "Backup" is structural, not a DB dump — see the source manifest in §7.
- **Reversibility:** **indefinite, non-destructive rollback.** The original Sermon Manager data is never altered or deleted until the admin explicitly clicks **Finalize**. The migration *copies forward* into the `sermonator_` namespace and leaves the legacy `wpfc_*`/`sermonmanager_*` data pristine.
- **Window behaviour:** a **verification window**, not parallel operation. Flow is migrate → verify → switch → finalize. No live dual-entry, so no delta/re-sync engine.
- **Approach:** faithful WP-API copy-forward migrator (records recreated via WordPress core functions), not raw SQL and not WXR re-import.
- **No custom DB tables** (consistent with Sermon Manager). All state lives in options + back-reference meta.
- **No monetization; open source.** Zero licensing/upsell/paid-update/telemetry code anywhere in Sermonator. Licensed **GPL-2.0-or-later**, no warranties. The legacy WHMCS/EDD licensing, self-hosted update server, and Intercom/Freshchat are removed, not ported. Any future monetization is via separate addon plugins/services — so the core ships clean extension points, never paywalls.

## 3. Success criterion (acceptance gate)

Sub-project 1 is "done" only when, on the fixture corpus:

1. The full-cycle test passes: **detect → migrate → verify → rollback → re-migrate → finalize.**
2. After **rollback**, the legacy tables are **byte-for-byte unchanged** (the test that proves non-destructiveness).
3. **Reconciliation is green**: source and target match by count and per-field checksum, and every source meta row is accounted for in the target.

This operationalizes "data preservation is the highest bar."

## 4. Component architecture

Each unit has one job, a clear interface, and is independently testable. Only the Writer, Rollback, and Finalizer touch the database — and only the Finalizer ever touches *legacy* data, only after explicit human authorization.

| Unit | One job | Writes data? | Depends on |
|---|---|---|---|
| **Model Registrar** | Register `sermonator_sermon`, `sermonator_podcast`, the 5 taxonomies, capabilities | no | WP |
| **Schema + Mapping Contract** | Single source-of-truth declaration of new keys/options *and* the old→new field map | no (data) | — |
| **Legacy Detector** | Read-only scan: count sermons/terms/podcasts/options/artwork; write source manifest | no | Schema |
| **Migration Orchestrator** | Own the lifecycle state machine; persist status; coordinate batches | state only | all below |
| **Batch Processor** | Chunked, resumable background runner (Action Scheduler): progress, retries, lock | no | Orchestrator |
| **Record Mappers** (sermon, term, podcast, option, artwork) | Pure transforms: legacy record → new record(s) per the Contract | no | Schema |
| **Writer** | Persist mapped records via WP APIs into `sermonator_` | yes (new only) | Mappers |
| **Verifier / Reconciler** | Compare source vs target field-by-field; produce the report | no | Schema |
| **Rollback** | Delete all records carrying a legacy back-ref → pristine start state | yes (new only) | Orchestrator |
| **Finalizer** | On explicit confirm, remove legacy data + guide old-plugin removal | yes (legacy) | Orchestrator |
| **Wizard UI** + **CLI** | Two thin frontends over the orchestrator/detector/verifier | no | Orchestrator |

**Key ideas:**
1. **The Mapping Contract is the center of gravity** — one declarative old→new map that serves as spec, migrator input, and verifier oracle. Migrator and checker read the same map, so they can't drift.
2. **Mappers are pure and total** — no side effects, so every transform is unit-testable against fixtures. Fidelity is won here.
3. **Structural safety** — read-only or new-namespace-only everywhere except Finalize. "Never lose data" is structurally true, not merely careful.

## 5. Target data model (`sermonator_` namespace)

All standard WP tables. No custom tables.

**Post types**
- `sermonator_sermon` — mirrors `wpfc_sermon`. Own `capability_type`, `map_meta_cap`, `show_in_rest`. Supports: title, editor, thumbnail, excerpt, comments, revisions, author.
- `sermonator_podcast` — mirrors Pro's `wpfc_sm_podcast` (one record per feed).

**Taxonomies** (non-hierarchical; final slugs adjustable)
`sermonator_preacher`, `sermonator_series`, `sermonator_topic`, `sermonator_book`, `sermonator_service_type`.

**Bible books are a fixed-but-extensible taxonomy.** `sermonator_book` ships seeded with a default canon, but it remains a normal, admin-editable taxonomy: a Catholic or Orthodox church can add deuterocanonical books (Tobit, Judith, Wisdom, Sirach, Baruch, 1–2 Maccabees, etc.). Migration consequences: **every existing book term is preserved, including any custom books a church already added**, and any later "sort books in biblical order" feature must tolerate non-canonical / user-added books gracefully (no crash, sensible fallback ordering).

**Per-sermon meta**

| New key | Holds |
|---|---|
| `sermonator_date` / `sermonator_date_auto` | preached date (Unix) + auto flag |
| `sermonator_bible_passage` | passage text |
| `sermonator_audio` / `sermonator_audio_id` | audio URL / attachment id |
| `_sermonator_audio_duration` / `_sermonator_audio_size` | hh:mm:ss / bytes |
| `sermonator_video_embed` / `sermonator_video_url` | video embed / link |
| `sermonator_notes` / `sermonator_bulletin` | file URLs |
| `sermonator_views` | view count |
| `_thumbnail_id` | unchanged — references the same attachment |

**Settings options:** `sermonmanager_*` → `sermonator_*` (~50 behaviour settings, values verbatim).

**Podcast feeds:** `sm_podcast_settings` → `sermonator_podcast_settings`; `wpfc_sm_default_podcast` → `sermonator_default_podcast`; each feed's taxonomy-term filters preserved.

**Term/series artwork:** the bundled taxonomy-images association option (term_id → attachment_id) → a `sermonator_` term-image option.

**Sermon body lives in native `post_content`** (decided). `sermonator_sermon` supports the editor; the body is the WordPress content, not a custom field. There is no `sermonator_description` meta key. See §6 for how the old `sermon_description` is moved into `post_content` during migration.

**Capabilities:** `sermonator_sermon` caps + `manage_sermonator_categories` + `manage_sermonator_settings`, granted to admin/editor/author (mirrors current roles).

**Engine-owned options:** `sermonator_version`, `sermonator_migration_state` (state machine + progress + manifest reference).

**Deliberately not carried forward (still lossless):**
- The denormalized `wpfc_service_type` *post-meta* copy — the taxonomy term relationship is authoritative; the meta is redundant.
- Term-level sort-date meta — purely derived; **recomputed** from migrated sermons, not lost.

## 6. The Mapping Contract

**① Sermons** (`wpfc_sermon` → `sermonator_sermon`): every post column copied verbatim (title, excerpt, author, `post_date`/`_gmt`, status, slug, comment/ping status, menu_order, password, parent) **except `post_content`**, which is sourced from the old `sermon_description` (see the tricky cases below). `post_type` and `post_content` change; everything else is identical. New post ID recorded via back-ref crosswalk.

**② Terms** (5 taxonomies): create each `sermonator_` term mirroring name/slug/description; record per-taxonomy crosswalk. **Orphan terms (zero sermons) are still migrated.** New sermons assigned new terms via crosswalk.

**③ Sermon meta:** known keys re-prefixed per §5 (note: `sermon_description` is *not* re-prefixed — it becomes `post_content`, see the tricky cases). **Critical fidelity rule: all other post meta is copied verbatim under its original key** (Yoast/SEO, custom fields, other-plugin meta). Known keys remapped; everything else moves unchanged. The migration never drops an unrecognized meta row.

**④ Comments:** copied to the new post (new comment IDs), preserving author, date, content, threading, approval status.

**⑤ Term artwork:** taxonomy-images option keyed by *old* term IDs → remapped through term crosswalk → new `sermonator_` term-image option. Attachment IDs unchanged (shared media).

**⑥ Podcast feeds:** `wpfc_sm_podcast` → `sermonator_podcast`; settings copied (taxonomy/category references remapped); per-feed term-filter assignments remapped; default-feed pointer repointed via post crosswalk.

**⑦ Settings:** `sermonmanager_*` → `sermonator_*`, values verbatim. Pro's front-end-only options (page assignment, template, licensing) are out of scope.

**Tricky cases (explicit):**
- **Sermon body → `post_content`:** the authoritative rich body lives in the old `sermon_description` meta, while the old `post_content` is a degraded, auto-generated text blob (SM's "Bible Text | Preacher | Series | …" render). Rule: **new `post_content` = old `sermon_description`**; the old derived `post_content` blob is **discarded** (it is regenerable and carries no unique data). Safety net for weird installs: if the old `post_content` (or Pro's `post_content_temp` backup) contains substantive text that is **not** present in `sermon_description`, that text is preserved in `_sermonator_legacy_post_content` and **flagged** for human review — never silently dropped. (During the reversible window the originals are untouched regardless, so nothing is at risk before Finalize.)
- **Legacy date formats:** copy the raw `sermon_date` value verbatim to `sermonator_date`; if non-numeric, additionally store a normalized timestamp, keep the raw original, and flag it. Never overwrite the original interpretation with a guess.
- **Absent companions** (`sermon_audio_id`, duration, size for external/remote files): simply absent — not an error.
- **Idempotency:** the crosswalk means a re-run skips already-migrated records.

## 7. Migration engine

**Lifecycle state machine** (in `sermonator_migration_state`):

```
none → detected → migrating → migrated → verified → finalized
                      ↑__________↓  (rollback returns to "detected")
```

1. **Detect** (read-only): scan, count, write a **source manifest** (counts + per-entity checksums). Because originals are never touched, this manifest *is* the backup — it proves the source is unchanged and the copy complete.
2. **Migrate** (background, chunked, resumable): records → mappers → Writer into `sermonator_`, building the crosswalk.
3. **Verify:** reconcile target against manifest; recompute derived term-dates; produce the report.
4. **Roll back** (any time pre-finalize): delete everything the migration created; state returns to `detected`; originals never moved.
5. **Finalize** (explicit, deliberate, the only destructive step): remove legacy `wpfc_*`/`sermonmanager_*` data; guide old-plugin removal.

**Crosswalk = back-reference meta (no custom table):** `_sermonator_legacy_id` (sermons), `_sermonator_legacy_term_id` (terms), `_sermonator_legacy_podcast_id` (feeds). Queryable; makes rollback exact (delete everything carrying a back-ref); makes re-runs idempotent (skip mapped legacy ids); gives the verifier its source↔target pairing. The Finalizer strips these back-refs once committed.

**Batch processor: Action Scheduler** — battle-tested queue for large, resumable, observable jobs (retries, admin log), avoiding the loopback fragility of the legacy `wp-background-process` lib. Chunked (~50 sermons/batch); a 10k-sermon archive is just more batches, never a timeout.

**Verification/reconciliation report** (migration not "verified" until clean):
- **Counts** match source vs target (sermons, terms per taxonomy, relationships, comments, podcasts, options, artwork).
- **Per-sermon field checksums** match the contract's expected transform.
- **Meta coverage:** every source meta row accounted for in target.
- **Flags** surfaced for human review: `post_content` divergences, legacy-date conversions, per-record errors.

**Wizard (4 screens)**, driven by the state machine:
1. **Detected** — "Found N sermons, M series, K feeds…" → [Start migration]
2. **Progress** — live bar, current batch, ETA, pause/resume
3. **Report** — reconciliation table + flags → [Roll back] / [Looks good — switch to Sermonator]
4. **Finalize** (shown later, separate, with a persistent "legacy data retained — rollback available" notice) → [Finalize & remove old data] behind a confirm

**CLI mirror:** `wp sermonator detect|migrate|verify|rollback|finalize`.

## 8. Error handling & edge cases

- **Per-record isolation, no silent failures:** a malformed record never aborts the run — logged with legacy id + reason, surfaced in the report as "needs attention." Verification can't go green until flagged records are resolved or explicitly acknowledged.
- **Resumable & locked:** crash/timeout → resume via back-refs (skip completed); Action Scheduler retries failed batches; a run-lock prevents concurrent migrations.
- **Rollback only deletes what the migration made** (records carrying `_sermonator_legacy_id`) — never legacy data, never natively-authored content.
- **Finalize is the only irreversible action** — gated behind `verified` state + explicit confirmation + its own pre-finalize manifest.
- **Version gate & coexistence:** below PHP 8.1/WP 6.0 → no migration, data untouched. During the window, old plugins and Sermonator coexist without collision (fully disjoint namespaces).

**Edge-case catalog (each gets a test):** 10k–50k-sermon archives; legacy/empty dates; body-only-in-`post_content`; orphan / shared / duplicate-named terms; custom/added Bible-book terms (Catholic/Orthodox deuterocanon); missing/external media (referenced, not re-fetched); other-plugin/custom meta; idempotent re-runs; fresh installs with nothing to migrate; free-only installs (no feeds); source edited between detect and migrate (manifest-checksum warning → re-detect).

**Multisite is out of scope for sub-project 1** (single-site only). Network/multisite support comes in a later iteration; the migration may assume a single site for now.

## 9. Testing strategy

- **Fixture corpus:** representative (sanitized) `wpfc_*` dataset exercising every field type, taxonomy, feed, artwork, comments, custom meta, legacy dates, and the awkward cases above.
- **Unit tests per mapper** (pure functions — highest value).
- **Full-cycle integration test:** detect → migrate → verify (green reconciliation) → **rollback (legacy tables byte-for-byte unchanged)** → re-migrate (identical, idempotent) → finalize (legacy gone, new intact).
- **Reconciliation/property tests:** counts, checksums, "every source meta row has a target home."
- **Scale test:** 10k generated sermons complete in bounded batches, no timeout/memory blowup.
- **CI matrix:** PHP 8.1/8.2/8.3 × WP 6.x (single-site; multisite deferred).

## 10. Deferred decisions & out of scope

**Decided since first draft:** sermon body lives in native `post_content` (no `sermonator_description` meta); `sermonator_book` is a seeded-but-extensible taxonomy; multisite is out of scope for now.

**Still deferred to later sub-projects (intentionally not decided here):**
- Final taxonomy slug names (proposed in §5; adjustable before implementation).
- Exact enumerated list of the ~50 `sermonmanager_*` → `sermonator_*` settings (mechanical; produced during implementation from the settings classes).
- Multisite / network-install support (a later iteration).

**Out of scope for sub-project 1:** sermon-editing UI beyond the wizard; front-end rendering; podcast feed *output*; SermonBrowser/Series Engine/WXR importers; licensing & distribution. Each is a later sub-project that depends on this foundation.
