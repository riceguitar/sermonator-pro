# Sermonator — Capability-Parity Roadmap (Design)

- **Date:** 2026-06-25
- **Status:** DESIGN — parity roadmap. Produced via the `forum` gated process (adr-on-rails + five tenth-man passes; time-travel-critic and rollback-story recorded at the end). This is a *scope/decomposition* spec: it defines the parity boundary and decomposes the work into bundles. It does **not** design the bundles — each gets its own forum→spec→plan.
- **Grounded in:** `docs/superpowers/specs/2026-06-25-sermonator-legacy-capability-inventory.md` (the full legacy capability inventory + gap matrix).
- **Goal:** capability parity with Sermon Manager (free) + Sermon Manager Pro for migrating churches, achieved the native-WordPress way (NOT by cloning their Twig/page-builder architecture). Bible integration uses the helloao API (`https://bible.helloao.org`).

---

## 1. Framing

Parity is measured at a **"typical-church functional parity"** bar: cover what a normal migrating church actually uses; treat the Pro long-tail (multiple-podcast *management*, page-builder modules, template marketplace) as explicit backlog or won't-do. *Capability* parity ≠ *implementation* parity — a legacy feature is "at parity" when a migrating church can do the same thing, even if the mechanism differs (a block instead of a shortcode, `register_block_template` instead of Twig). Data/content preservation remains the project's #1 standard and is the tie-breaker throughout.

## 2. Decision record (ADR)

```
## ADR: Organizing principle for the parity roadmap
Choice:  C — Dependency-bundled roadmap (Switch-safe → Bible ∥ Config+display polish).
Why:     "It needs real backward compatibility and protection against breaking migrators
         from Sermon Manager." → drives on the data-preservation / switch-safety-first
         criterion (the #1 standard): C front-loads switch-safety before feature work.
What would change the answer: user override (owner declined to specify a falsifiable
         condition; takes ownership of the choice).
```

## 3. Parity boundary

### ✅ In scope — "typical-church functional parity"
- *Already shipped:* single-sermon view, sermon + 5 taxonomy archives, core blocks, single podcast feed + subscribe, SEO (JSON-LD/OG), audio-size backfill.
- *Commit the in-flight work:* Sermon Details authoring layer, `bulletin`/`featured-image`/`notes` blocks, audio **duration** (`AudioHeadProbe`). **Not free — see Bundle 1.**
- *Bible verse lookup* via helloao (server-side, cached, observable, fail-open).
- *Opinionated settings page:* podcast identity, default image, preacher label, **Bible translation**, archive slug.
- *Legacy shortcode shims — attribute-faithful:* `[sermons]` honoring its real attributes (`order`/`orderby` incl. `date_preached`, `filter_by`/`filter_value`, `year`/`month`/`before`/`after`, `hide_*`, `include`/`exclude`, `per_page`), plus `[list_sermons]`, `[latest_series]`, `[sermon_images]`, `[list_podcasts]`. **Acceptance = a migrated page renders the *same content* (order/filter) it did under SM**, never the literal shortcode text and never silently-different content.
- *Secondary display* as native blocks: term lists, term-image grid, latest-series.
- *Podcast taxonomy-filtered feeds.*
- *Multiple-podcast **feed-URL continuity***: existing `?…&id=<podcast_id>` endpoints keep resolving for current Apple/Spotify subscribers (a dead subscribed feed is a continuity break, not a feature gap). **Distinct from** the multi-podcast authoring UI (backlog). **HARD REQUIREMENT (rollback story 1):** feed-item **GUID stability** + a **pre-migration feed snapshot** must be in place *before any church is told to switch* — lost podcast subscribers cannot be clawed back, so this is prevention, not just rollback.
- *Page-builder-embedded content:* migration **detects** Elementor/Divi-embedded sermon modules and surfaces a warning + documented manual path, so the break is never silent.

### 🔵 Backlog — deferred, post-parity
- Multiple-podcast *management UI* (create/edit additional podcasts).
- PodTrac (documented analytics-continuity asterisk, not silently dropped).
- Audio seek/timestamp (`?t=`), download button.
- Recent-sermons block *(flagged: sidebar-placement continuity — candidate to promote)*.
- WXR export (data portability).

### ⛔ Won't-do — deliberate divergence (with native replacement)
- Twig templating engine + template marketplace/manager → `register_block_template` + blocks.
- *Rebuilding* page-builder modules (Elementor/Divi/Beaver/VC) → native blocks (migration detect+warn IS in scope above).
- Cross-plugin importers (Sermon Browser, Series Engine) → out; Sermonator is specifically an SM replacement.
- Player-choice / layout-toggle settings sprawl → opinionated native rendering.
- EDD licensing / auto-update → N/A (no monetization).

## 4. Bundle decomposition & sequencing

### Shared artifact — the "Legacy Compatibility Contract"
One document, written **once** and referenced by Bundles 1/2/4, defining exactly what each legacy surface renders/returns: `[sermons]` + each attribute, `[list_sermons]`/`[latest_series]`/`[sermon_images]`, the feed item-set per `id`, and the block↔shim mapping. It is kept **thin up front** (behavioral guarantees + the rule below); faithful per-attribute details are filled in per bundle, post-audit.

> **Load-bearing rule — fail-visible, never fail-wrong.** Where a shim cannot yet reproduce the faithful behavior (e.g., an unsupported `[sermons]` attribute before Bundle 2 lands), it renders the **safe unfiltered list with a visible "this listing needs review" editor/admin notice** — never silently-different content. This makes "Tier B ships last" honest: the interim state is conspicuous, not deceptive.

### Precursor gate — Migrator-reality audit (with teeth)
- **Sample:** the seeded test site + N owner-named real migrator sites.
- **Explicit thresholds (examples to finalize in the audit):** if <X% run *filtered* 2nd+ podcasts → collapse the per-podcast filtering engine to a single-feed alias; if embedded `[sermons]` average ≤2 attributes → minimal shim, defer full query semantics; if zero builder-embedded modules → drop detection, ship a generic migration note.
- **Sample validity (guard against a convenience sample):** the audit only earns the right to *shrink* Bundle 2 if it includes a minimum N of **real Sermon Manager *Pro*** sites (not just our seed + friendly single-podcast churches). If that N can't be reached, **Bundle 2 is treated as full-scope by default** rather than collapsed — a convenience sample must not down-size scope.
- **Timing:** runs **before Bundle 2 is specced**, so its findings can actually move scope. Converts "Switch-safe is small" from assumption to measured fact.

### Bundle 1 — Switch-safe · Tier A (cheap true-continuity) — *do first*
- **Review → harden → commit the in-flight work** through the owner's adversarial gate. *Grounded sizing:* the unit suite on the current working tree is **166 tests / 3 errors** — the authoring `SermonDateNormalizer` calls `current_user_can()` unmocked (`src/Admin/Authoring/SermonDateNormalizer.php:51`); integration suite status TBD (needs wp-env). This is real review/hardening on a **write path**, not a freebie.
- Podcast **feed-URL routing + legacy podcast-ID mapping** (existing default *and* `?…&id=` feeds resolve).
- Shortcode **tag registration** rendering the Contract's *defined* default (per the fail-visible rule) — not a guess.

### Bundle 2 — Deep-compat · Tier B (faithful) — *sized & scheduled by the audit*
- Attribute-faithful `[sermons]` query semantics (`date_preached` ordering, date ranges, `hide_*`, include/exclude).
- Per-podcast **filtering engine** (legacy 2nd-podcast feeds serve the *same* item set, incl. audio/video mode).
- Page-builder **detection + warning** (real multi-builder detection — or explicitly downgraded if the audit says ~zero).
- *Depends on* Bundle 4's display blocks (the render primitives the faithful shims call).

### Bundle 3 — Bible — *parallel after Tier A*
helloao verse lookup. Architecture in §5.A; full design in Bundle 3's own spec.

### Bundle 4 — Config & display — *parallel after Tier A*
- Opinionated settings page (§5.C).
- Secondary display blocks (term lists, term-image grid, latest-series) — **the render primitives Bundles 1–2 consume**, per the Contract.

### Sequencing
Compatibility Contract + Migrator audit first (cheap, gating) → **Bundle 1** → **Bible (B3) ∥ Config/display (B4)** → **Bundle 2** sized/scheduled per audit, after B4's blocks exist. Each bundle is its **own** forum→spec→plan, all bound by the one Contract.

## 5. Cross-cutting decisions (roadmap-level; detailed per bundle)

### A. Bible integration architecture (helloao)
- **Source:** helloao static JSON (`…/api/{translation}/{book}/{chapter}.json`), no key/limit, CC0-ish; **default translation BSB** (public domain, partnered).
- **Mechanism:** server-side fetch + transient cache keyed by `translation|book|chapter`; we render our own markup. **No browser script** — the deliberate divergence from RefTagger's external phone-home (privacy + no visitor-IP leak).
- **Vendor the top public-domain translations, not just BSB:** because they're CC0/PD and static (~a few MB of JSON each), **import/self-host the most-requested public-domain translations locally** (BSB + e.g. KJV, WEB) and live-fetch only the long tail. This turns the headline feature's common path from a render-time network call into a static asset — removing latency, longevity, and privacy risk — and respects how attached congregations are to *their* translation (don't assume BSB-only is acceptable). Default = BSB.
- **Capture structured references at authoring time (the parser's load-bearing de-risk).** The Sermon Details panel (Bundle 1/4) should capture book/chapter/verse **structured** going forward, so *new* sermons need **no** parsing. The free-text parser is then a **best-effort legacy backfill only**, not the whole feature.
- **Reference parser is a first-class component, not a bullet.** Free-text `bible_passage` is messy ("Jn 3:16", "1 Cor 13:4–7", "Psalm 23 & 24", cross-chapter ranges, typos, non-English). `Schema\BibleCanon` today is only 66 English names — Bundle 3 must add a name/abbrev→USFM table + a tolerant parser, **sized against a real `bible_passage` corpus** drawn from the seed/migrator data. Because new sermons are structured (above), the parser only has to be *good enough* on the legacy backlog, not perfect.
- **API constraint:** no verse-range endpoint → fetch the whole chapter, slice verses, cache the chapter.
- **Fail-open AND observable:** on unreachable/timeout/unparseable, fall back to today's plain "Scripture" text — never block render, never error the page — **but log the fallback rate and parse-failure rate** so the feature can't be silently absent in production. Measure parse success on the real corpus *before* shipping.
- **Translation setting** is curated to a sane list (not a raw 1000-slug dropdown); changing it invalidates the relevant caches.
- **Licensing:** BSB/helloao attribution as required (only obligation is rename-if-modified).

### B. The Compatibility Contract
Behavioral guarantees + the **fail-visible / never-fail-wrong** rule (§4). Thin up front, faithful details per bundle. Binds Bundles 1/2/4 so the `[sermons]`/block behavior is defined once and can't drift across separately-specced bundles. **Anti-drift:** updating the central Contract is a **required exit criterion of each bundle's PR**, not an aspiration — otherwise it decays into a stub while the real behavior scatters across three specs (the exact drift it exists to prevent).

### C. Settings philosophy
One opinionated settings page; only genuinely-per-church config (podcast identity, default image, preacher label, Bible translation, archive slug). Reuse `Schema\Identifiers` option keys; migration already populates podcast settings. No player-choice/layout-toggle sprawl.

### D. Quality bar (existing project standards, every bundle)
Own forum→spec→plan → TDD (unit Brain Monkey + integration wp-env `WP_UnitTestCase`) → adversarial review to convergence → PR (one per coherent unit). Escape all output at the `Renderer` boundary; reuse `Schema\Identifiers` constants and `Schema\VideoEmbedPolicy`; any new write path clears the audio-backfill bar (reversible, fill-missing, migration-gated). The Bible fetch is the front end's first outbound network dependency — cached, time-limited, fail-open, observable.

### E. Guardrails (won't-introduce)
No external runtime script dependencies (no RefTagger), no paid dependencies (ACF Pro), don't lower the WP 7.0 floor.

## 6. Gate record (forum)

The roadmap was hardened through five tenth-man passes; each dissent was **accepted** and folded in:

1. **Boundary smuggled continuity into backlog/won't-do.** → Promoted multiple-podcast *feed-URL continuity* into Switch-safe; right-sized shims to attribute-faithful; added page-builder detect+warn.
2. **The "feed continuity vs UI" split is illusory; faithful shims balloon Switch-safe.** → Split Switch-safe into Tier A (cheap) vs Tier B (faithful), triaged by an audit, not assumed small.
3. **In-flight commit mislabeled cheap (sunk-cost); per-bundle specs hide a shared rendering contract.** → Reframed Bundle 1 as review→harden→commit (grounded: 166 tests/3 errors); extracted the single Compatibility Contract; gave the audit teeth.
4. **Tier B (faithful continuity) ships last, inverting "switch-safety first"; Contract is chicken-and-egg.** → Added the **fail-visible/never-fail-wrong** rule so the interim is honest; kept the Contract thin up front.
5. **Bible fail-open hides the headline feature's absence; parser underestimated; single free dependency is fragile.** → Made fail-open **observable**; treated the parser as first-class sized against a real corpus; preferred **vendoring the CC0 BSB default** over live-fetch.

### Long-horizon (time-travel-critic) outcome — accepted
The strongest signal (the Bible reference parser becoming the whole feature) was **accepted and folded into the design**: structured reference capture at authoring time (§5.A) demotes the parser to a legacy-only backfill. Cheap related fixes folded in: audit sample-validity guard (§4, regret #1), vendor multiple PD translations (§5.A, regret #4), Contract-update-as-PR-exit-criterion (§5.B, regret #5).

**Known long-horizon risks (recorded, not yet fully designed out):**
- **#1 Convenience-sample audit** — mitigated by the §4 min-N-of-real-Pro-sites guard, but still depends on actually reaching those sites.
- **#2 "Won't-do page builders" → top support ticket.** The Pro-paying segment skews toward professionally-built Divi/Elementor sites; "detect + warn" tells them the site is broken without fixing it. *Recorded risk:* if the audit finds non-trivial builder-embedded sermon usage, reconsider shipping a real block-equivalent / auto-converter for the single most common module rather than a notice-only path. Not committed here.
- **#3 Parser** — addressed via structured authoring-time capture; residual risk is only the legacy backfill quality.
- **#4 Translation attachment** — mitigated by vendoring multiple PD translations; residual risk is congregations wanting a copyrighted translation we can't host.
- **#5 Contract drift** — mitigated by the PR exit criterion; residual risk is enforcement discipline.

### Rollback posture (rollback-story) — accepted
Three hard-to-reverse commitments each got a rollback plan (§8). Outcome: plans for the Bible
dependency and the Bundle-1 write-path commit are solid and each surfaced a **mandatory
precondition** (observable fail-open + vendoring; review→harden→adversarial-gate before any
production exposure). The podcast feed-continuity plan has irreducible residue (lost subscribers
can't be reclaimed), so its protection is **prevention** — feed-GUID stability + a pre-migration
feed snapshot before any switch — now elevated to a HARD REQUIREMENT in §3.

## 7. Next steps
1. Run the **Migrator-reality audit** and draft the **Compatibility Contract** (cheap, gating).
2. **Bundle 1** (review→harden→commit in-flight work + feed-URL continuity + tag registration) — its own forum→spec→plan.
3. **Bundle 3 (Bible)** and **Bundle 4 (Config & display)** in parallel — each its own forum→spec→plan.
4. **Bundle 2 (Deep-compat)** sized/scheduled per the audit, after Bundle 4's blocks.

## 8. Rollback stories (rollback-story gate output)

### 8.1 Legacy podcast feed-URL continuity (subscriber-facing)
- **Irreversible:** once a migrated church's `?…&id=<podcast_id>` feed 404s or changes its item set / GUIDs, Apple/Spotify and listeners' apps silently break (auto-unsubscribe on sustained 404; changed GUIDs cause re-download/drop). We can't reach third-party directories or listeners' apps.
- **Trigger:** a feed-diff (post vs pre-migration snapshot) shows any delta — non-200, item-count drop, or GUID mismatch — or a church reports the podcast vanished from Apple.
- **Steps:** (1) re-point the legacy route to serve the pre-migration feed from byte-immutable legacy data (pre-Finalize) or `sermonator_pre_migration_backup`/manifest (post-Finalize); (2) force item GUIDs to equal the legacy GUIDs; (3) verify 200 + identical item set via the feed-diff.
- **RTO:** < 6h (one app poll cycle); 24–48h sustained 404 risks directory auto-unsubscribe.
- **Can't undo:** subscribers already dropped, duplicate episodes from changed GUIDs, ranking dings. → **Prevention is the real control:** GUID stability + pre-migration snapshot before any switch (HARD REQUIREMENT, §3).

### 8.2 helloao Bible dependency
- **Irreversible:** almost nothing — fails *open* to plain "Scripture" text and vendors common translations locally; only residue is stale verses in page caches until TTL.
- **Trigger:** fail-open metric >10% of renders over 1h, or helloao p99 latency/error-rate over threshold, or wrong/missing verse reports.
- **Steps:** (1) flip to vendored-only mode; (2) if vendored also fails, flip the feature off → plain-text behavior; (3) purge Bible transients.
- **RTO:** hours (low urgency — page never breaks).
- **Can't undo:** none material; no third-party writes, no visitor-IP leak.

### 8.3 Bundle 1 commits unreviewed write-path code into main
- **Irreversible:** code reverts trivially; DATA it writes does not. The in-flight authoring layer is a write path with a date normalizer currently erroring in tests; on production it can overwrite/mis-normalize sermon meta, and a code revert doesn't un-write rows.
- **Trigger:** write-path integration tests red; production meta changing unexpectedly after edit; `sermonator_date` values that don't round-trip; migration-state interference.
- **Steps:** (1) `git revert` Bundle 1 authoring commits; (2) identify touched rows via WP revisions + `sermonator_date` companion meta; (3) restore from revisions / pre-migration backup / manifest; (4) green write-path tests + adversarial-gate pass before re-attempting.
- **RTO:** code revert minutes; data restoration bounded by detection speed.
- **Can't undo:** meta overwrites on sites without revisions for the affected keys, or writes after Finalize. → **Precondition:** do not run Bundle 1 on production until write-path tests are green AND it has passed the adversarial gate.
