# Migrator-Reality Audit — Sermonator Parity Roadmap §4 Precursor Gate

- **Date:** 2026-06-26
- **Status:** EXECUTED — methodology defined; **sample-validity guard NOT met** (no real Sermon Manager Pro migrator sites available this session).
- **Gate:** Precursor gate "Migrator-reality audit (with teeth)" in `docs/superpowers/specs/2026-06-25-sermonator-parity-roadmap-design.md` §4.
- **Binding output:** sizing input for **Bundle 2 — Deep-compat · Tier B**. This audit decides whether Bundle 2 may be *shrunk* from full scope.
- **Verdict (jump to §4):** because the validity guard is unmet, **Bundle 2 STAYS FULL-SCOPE by default.**

---

## 1. Methodology (what to measure, on which sites, against which thresholds)

The audit exists to convert "Switch-safe is small" from an assumption into a measured fact, and — critically — to guard against a *convenience sample* down-sizing scope. It earns the right to *shrink* Bundle 2 **only** if it includes a minimum number of **real Sermon Manager Pro** sites (see §1.4). Per roadmap §4, the audit must run **before Bundle 2 is specced** so its findings can actually move scope.

### 1.0 Sample definition
- **Required population:** real Sermon Manager / Sermon Manager **Pro** production sites named by the owner, captured *pre-migration* (legacy SM data still in place) so legacy surfaces (`[sermons]` shortcodes, multi-podcast feeds, builder modules) are observable as the migrating church actually uses them.
- **Allowed supplement (non-counting):** the seeded local test site — characterizes tooling/methodology only, does **not** count toward the validity guard (§1.4, §3).

### 1.1 Filtered 2nd+ podcasts (drives the per-podcast filtering engine)
- **What to query per site:**
  - Count of distinct podcasts/feeds configured under SM Pro (legacy podcast post-type / SM Pro podcast settings).
  - For each 2nd-and-subsequent podcast, whether it is **filtered** (serves a *subset* of sermons by taxonomy/criteria, incl. audio-vs-video mode) versus an unfiltered alias of the primary feed.
  - Which `?…&id=<podcast_id>` feed endpoints are live and externally subscribed (Apple/Spotify) — the continuity-critical set.
- **Metric:** % of sampled sites that run **≥1 filtered 2nd+ podcast**.
- **Threshold (binding for this audit):** **< 20%** of real-Pro sites run a filtered 2nd+ podcast → **collapse** the per-podcast filtering engine to a **single-feed alias** (all legacy `id=` routes resolve to one feed; preserve URL + GUID continuity but not subset filtering). Otherwise → **keep the full per-podcast filtering engine** in Bundle 2.
- *Note:* feed-URL **continuity** (routes resolve, GUIDs stable) is a Bundle 1 HARD REQUIREMENT regardless of this result; this threshold governs only the *subset-filtering* engine in Bundle 2.

### 1.2 `[sermons]` attribute density (drives faithful query semantics)
- **What to query per site:** scan `post_content` (posts, pages, reusable blocks, widget areas) for legacy SM shortcodes — `[sermons]`, `[list_sermons]`, `[latest_series]`, `[sermon_images]`, `[list_podcasts]`. For every `[sermons]` occurrence, record the count and identity of attributes used (`order`/`orderby` incl. `date_preached`, `filter_by`/`filter_value`, `year`/`month`/`before`/`after`, `hide_*`, `include`/`exclude`, `per_page`).
- **Metric:** mean number of attributes per `[sermons]` instance, plus a frequency histogram of *which* attributes appear.
- **Threshold (binding for this audit):** mean **≤ 2 attributes** per `[sermons]` instance → ship a **minimal shim** (tag registration + the Contract default per the fail-visible rule) and **defer full query semantics** out of Bundle 2's critical path; attributes observed at low frequency move to backlog. Mean **> 2** → implement the **attribute-faithful query engine** (full `date_preached` ordering, date ranges, `hide_*`, include/exclude) in Bundle 2.

### 1.3 Page-builder-embedded sermon modules (drives detect+warn)
- **What to query per site:** presence of builder data (`_elementor_data`, `_elementor_edit_mode`, `_et_pb_use_builder`/`_et_builder_version` for Divi, Beaver Builder `_fl_builder_*`, WPBakery `_wpb_*`) **and** whether those builder payloads embed SM sermon modules/widgets or SM shortcodes inside builder content.
- **Metric:** count of sites with **≥1 builder-embedded sermon module**.
- **Threshold (binding for this audit):** **zero** builder-embedded sermon modules across the real-Pro sample → **drop detection**, ship a generic migration note. **≥1** → keep **real multi-builder detection + warning** (the documented manual path) in Bundle 2. *(Recorded long-horizon risk #2: if usage is non-trivial, reconsider a real block-equivalent for the single most common module rather than notice-only — not committed here.)*

### 1.4 Sample-validity guard (the teeth)
- **Rule (roadmap §4):** the audit may *shrink* Bundle 2 **only if** the sample includes a minimum **N of real Sermon Manager *Pro*** sites — not the seed and not friendly single-podcast churches.
- **Binding N for this audit:** **N = 3** real SM Pro sites. (Owner did not name a higher falsifiable N; 3 is the floor below which any "shrink" decision is a convenience-sample artifact.)
- **Failure behavior:** if N is not reached, **none** of the §1.1–§1.3 thresholds may be applied to shrink scope; **Bundle 2 is full-scope by default.**

---

## 2. Execution this session — validity guard status

**The validity guard is NOT met.** No real Sermon Manager Pro migrator sites were available this session (owner-named real-Pro site access is still outstanding — see `.superpowers/sdd/progress.md`, "Task 1 (migrator audit): BLOCKED — needs real Sermon Manager Pro site access from owner").

- Real SM Pro sites sampled: **0** (required N = 3).
- Therefore §1.1–§1.3 thresholds **cannot be evaluated against a valid sample** and **cannot be used to shrink Bundle 2.**

---

## 3. Methodology demonstration — local seed-site characterization (NON-COUNTING)

Run *only* to exercise the audit instrumentation and confirm the queries work. **This is a native Sermonator seed, not a real SM/SM Pro migration**, so by §1.0/§1.4 it **cannot satisfy the validity guard** and its numbers **do not** down-size Bundle 2. Captured via `scratchpad/wp-local.sh` against the FlyEngine Local `sermonator-test` site.

| Probe | Command | Result |
|---|---|---|
| Podcasts | `post list --post_type=sermonator_podcast --format=count` | **1** ("Sunday Sermons Podcast", ID 11) |
| Sermons | `post list --post_type=sermonator_sermon --format=count` | **1009** |
| Pages | `post list --post_type=page --format=count` | **3** (1 publish test page, 1 sample, 1 draft privacy) |
| Series / Preacher / Topic / Book / Service-type terms | `term list <tax> --format=count` | **8 / 109 / 2492 / 67 / 16** |
| Builder meta | `post meta list 15` (+ probed `_elementor_data`/`_et_pb_use_builder`) | **none** |
| Active plugins | `plugin list` | `sermonator`, `wordpress-importer` only — **no Sermon Manager / SM Pro present** |

**Per-axis read on the seed (illustrative only, NON-BINDING):**
- **Filtered 2nd+ podcasts (§1.1):** 1 podcast total → **0** filtered 2nd+ podcasts. (Seed is single-podcast by construction.)
- **`[sermons]` attribute density (§1.2):** the only sermon listing in content (page 15, "Sermon Test Page") is `[sermonator_sermons count="3" columns="2"]` plus a `wp:sermonator/sermon-grid` block — i.e. **Sermonator's own** native shortcode/block, **not** the legacy SM `[sermons]` shortcode. **Zero legacy `[sermons]` instances exist on the seed**, so no legacy attribute-density signal can be drawn from it at all.
- **Builder-embedded modules (§1.3):** **0** (no builder meta, no builder plugins).

**Why the seed cannot stand in for the sample:** it has no legacy SM plugin, no legacy `[sermons]` shortcodes, and a single native podcast — precisely the surfaces the audit must measure are *absent*. A native seed is a tooling check, not migrator reality. (Note: `wp db query` is unavailable in this environment — MySQL socket down — so content scans were done via `wp post`/`wp post meta`; on a real-Pro site the §1.2/§1.3 scans should use a full `post_content` REGEXP scan once DB access is available.)

---

## 4. Verdict (binding)

Because the sample-validity guard is **unmet** (0 of the required N = 3 real Sermon Manager Pro sites; the local seed is a native, non-migrator site that cannot count), the convenience-sample protection in roadmap §4 fires:

> **Bundle 2 — Deep-compat · Tier B STAYS FULL-SCOPE by default.**

Concretely, until a valid real-Pro sample is gathered, Bundle 2 must be specced to include **all** of:
1. Attribute-faithful `[sermons]` query semantics (`date_preached` ordering, date ranges, `hide_*`, include/exclude).
2. The full per-podcast **filtering engine** (legacy 2nd-podcast feeds serve the *same* item set, incl. audio/video mode).
3. Real multi-builder **detection + warning** (Elementor/Divi/Beaver/WPBakery).

No threshold from §1.1–§1.3 may be invoked to drop or minimize any of these.

### Re-run trigger
Re-run this audit when the owner provides access to **≥ 3 real SM Pro sites**. At that point evaluate §1.1–§1.3 against the binding thresholds; a documented "shrink" decision then becomes legitimate and Bundle 2's spec can be re-sized accordingly. Until then this verdict holds.
