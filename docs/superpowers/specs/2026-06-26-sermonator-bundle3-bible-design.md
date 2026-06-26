# Sermonator — Bundle 3: Bible Verse Lookup (Design)

- **Date:** 2026-06-26
- **Status:** DESIGN. Produced by a gated design workflow (parallel approach-proposals per sub-decision → adversarial tenth-man + 12-month-regret critique → synthesis). Implements the roadmap's locked Bible architecture (`2026-06-25-sermonator-parity-roadmap-design.md` §5.A) at detail.
- **Goal:** replace the legacy RefTagger verse feature with a native, privacy-respecting Bible lookup on the single-sermon page, using the **helloao** static-JSON API — without ever displaying a verse we aren't confident the author preached.

## 1. Three load-bearing corrections (the critique's payoff)

The roadmap architecture was right but incomplete. The design corrects it on three points; these are the spec's spine:

1. **Never fail-*wrong*, not merely fail-*open*.** A *successful* fetch is not sufficient confidence. Legacy `bible_passage` references are versified to the church's translation (default ESV via legacy `verse_bible_version`); rendering ENGWEBP/WEB text for an ESV-versified reference yields **real-but-wrong verses** at an enumerable divergent set (Psalter superscription offsets, Ps 9/10, Joel 2:28–32↔3:1–5, Malachi 3↔4, Acts/Rom 16 splits, 3 John, Rev 12–13). Inline verse **text** is shown ONLY when the reference is fully-specified, in-canon, its verse range is verified to exist in the fetched chapter, AND its source versification maps cleanly to the target. Otherwise → a reference **link**, or today's plain text.
2. **Two-axis translation.** Legacy RefTagger chose a *link target* — it never hosted text, so there was no licensing burden. Don't conflate that into "WEB for everyone" (which silently substitutes wording the pastor never preached). **Axis A — link version:** unconstrained (incl. copyrighted ESV/NIV/NASB), mirrors legacy `verse_bible_version`, the low-stakes **parity default that ships first**. **Axis B — inline text translation:** public-domain only, default **ENGWEBP (WEB)**, an opt-in, always-labeled progressive enhancement.
3. **All resolution off the render path.** helloao has no verse-range endpoint, so a multi-reference passage would fan out to N serial 5s fetches on a cold render — the scripture-densest pages hang. References are parsed/validated/fetched/cached at **authoring-save** and via a **migration-gated backfill**; render is a deterministic cache/disk read with **zero synchronous network**. First-fetch latency/failures are the author's concern, never the visitor's.

**Default inline translation is ENGWEBP, NOT BSB.** Verified: ENGWEBP is unambiguous complete-66-book public domain; helloao marks BSB proprietary/ambiguous (berean.bible). We redistribute vendored text inside a GPL-2.0+ plugin to thousands of sites, so the inline default must be license-clean. The roadmap's stale "default BSB" line is overridden.

## 2. ⚠️ Prerequisite (Risk #2): structured capture must actually ship

The roadmap assumed authoring-time *structured* capture already exists; it does not — `SermonMetaRegistrar` registers `META_BIBLE_PASSAGE` as plain text. **If Bundle 3 does not add structured `META_BIBLE_REFS` capture, the bounded backfill parser becomes the PERMANENT primary path for 100% of sermons, not a shrinking tail.** Therefore Task 10 (authoring-panel structured capture) is a **prerequisite within Bundle 3**, and the parser's coverage bound is re-scoped as permanent until structured capture is the norm.

## 3. Data model (all keys via `Schema\Identifiers`, never hardcoded)

- `META_BIBLE_REFS = 'sermonator_bible_refs'` — one versioned JSON envelope `{"v":1,"refs":[Ref,…]}`, where `Ref = {bookUSFM, chapterStart, verseStart|null, verseEnd|null, chapterEnd|null, raw, source:'authoring'|'backfill'|'import', confidence:'exact'|'probable'|'ambiguous', srcVersification}`. Null verses = whole chapter; `chapterEnd != null` = cross-chapter range (lossless for "Matt 5:1–7:29"). Written by BOTH authoring-capture and backfill/import (**one schema, multiple producers**).
- `META_BIBLE_REFS_UNPARSEABLE` — sentinel companion (mirrors `META_DATE_UNPARSEABLE`), stamped when backfill parses a non-empty passage to zero refs (per-post fail-open is measurable).
- `OPTION_BIBLE_LINK_VERSION = 'sermonator_bible_link_version'` (axis A; default mirrors legacy `verse_bible_version`, e.g. `ESV`).
- `OPTION_BIBLE_INLINE_TRANSLATION = 'sermonator_bible_translation'` (axis B; default `ENGWEBP`).
- `OPTION_GROUP_SETTINGS = 'sermonator_settings'` (shared settings group; neither Bundle 3 nor 4 hardcodes it).
- `OPTION_BIBLE_CACHE_GEN` (int cache-buster), `OPTION_BIBLE_STATS` (precomputed corpus-audit rollup), `LOG_OPTION 'sermonator_bible_refs_backfill_log'` (exact-reverse id log).
- **`META_BIBLE_PASSAGE` is NEVER mutated** — the preserved human display label, the fail-open output, and the parser input. (#1 data preservation; migration Verifier fixity holds automatically.) Note: `metaKeys()` membership is cosmetic/test-only — reversibility is hand-wired via `LOG_OPTION` + a reverse method, like `AudioSizeBackfill`.

## 4. Components

| Component | Responsibility |
|---|---|
| `Schema\BibleBookMap` | Pure data: `usfm()` (display-name→USFM, keyed off `BibleCanon::defaultBooks()` so they can't drift; ids verified vs helloao `books.json` — PSA/SNG/PHP/PHM/1JN/JHN/JDG/JUD/JAS) + `aliases()` (normalized-alias→USFM; digit-prefixed forms only — the normalizer collapses I/First/1st→1). |
| `Schema\BibleTranslations` | Curated allowlist (so settings is a sane list, not 1000 slugs); tags license-status (PD/ambiguous/unconfirmed) + inline-eligibility. ENGWEBP = vendored/default/inline-eligible; BSB = ambiguous, inline-ineligible. |
| `Bible\TranslationRegistry` | `current()` resolves BOTH axes: read option → validate vs curated → fallback ENGWEBP, with a `sermonator_bible_translation` filter. Called from TemplateData/save/backfill, **never** the pure Renderer. |
| `Bible\ReferenceParser` | Pure: raw passage → `{segments:[{raw,status,refs:[Ref]}]}`. Curated longest-match book lookup (kills J-cluster mis-id) + tiny stateful scanner carrying current-book/chapter across separators (cross-chapter ranges). **Segment granularity = the never-fail-wrong unit.** Out of scope: non-English, prose, fuzzy/typo. |
| `Bible\RefValidator` | Gates a Ref to render-ready: single in-canon book, verse range verified within the fetched chapter, versification maps cleanly. Divergent/chapter-only/collision → NOT inline-eligible (link-only/sentinel). |
| `Frontend\Bible\ChapterFetcher` | Static fail-open fetch (AudioHeadProbe idiom): `wp_remote_get` timeout 5, redirection 2, reject_unsafe_urls, scheme+shape validation. On error → null + `error_log` + fallback hook. Returns a normalized flat chapter `[{number,nodes}]`. Never throws. |
| `Frontend\Bible\ChapterCache` | Transient layer. Key `sermonator_bible_ . md5(cacheGen|translation|USFM|chapter|vSCHEMA)`; value = normalized chapter; TTL `MONTH_IN_SECONDS`. Vendored chapters bypass it (disk). Invalidate by bumping `cacheGen` (never `DELETE…LIKE`). |
| `Frontend\Bible\ChapterProvider` | One read resolver, one shape: repo-vendored disk → uploads-vendored disk → transient → (warm context only) live fetch → fail-open null. At RENDER time strictly cache/disk read — never network. |
| `Frontend\Bible\BibleWarmer` | Warm-on-save: resolve+fetch+normalize+cache cited chapters (AudioSizeBackfill discipline), so render is offline. Off the hot path; failures visible to author. |
| `Migration\BibleRefsBackfill` | Carbon copy of `AudioSizeBackfill` guardrails: native-only, fill-missing-only, exactly-reversible via `LOG_OPTION`, idempotent/chunkable, MigrationGuard-gated. **Dry-run REPORT mode first.** Never overwrites `source:'authoring'`. |
| `Frontend\BibleResolver` | Impure orchestrator at template time: reads `META_BIBLE_REFS`, validates, pulls chapters via `ChapterProvider`, builds `ResolvedScripture` or null. Fires observability hooks. |
| `Frontend\ResolvedScripture` | Value object (sibling of `SermonView`): list of `{label, translation, linkUrl, inlineEligible, source, verses:[{number,nodes}]}` with pre-typed nodes (text|format|note), NOT raw HTML. |
| `Renderer::scripture(SermonView, ?ResolvedScripture)` | New **pure** method. Null → `''` (today's escaped meta row stays byte-identical). Per ref: a section + visible translation **badge** (only on the resolved path); `esc_html` every leaf node, own `<span>/<sup>`, **never** `wp_kses` verse text. `meta()` passage row unchanged. |
| `Bible\CoverageAudit` | WP-Cron + on-save ground-truth: counts corpus refs vs refs that resolve (independent of render). Writes `OPTION_BIBLE_STATS`. Powers a native Site Health test. **No write-on-GET.** |
| Authoring panel addition | Free-text + live server parse-preview (read-only REST `sermonator/v1/bible-parse`, AudioMetaController pattern) → editable confirm-chips → writes `META_BIBLE_REFS` on save via an extended `SermonMetaSanitizer`. Separate JSX sub-components (Bundle 1 vs 3 edit different files). |
| `Cli\BibleCommand` | `wp sermonator bible {vendor|warm|backfill|flush}` (AudioCommand discipline: dry-run/rollback/limit). Registered behind `defined('WP_CLI')`. |

**Queryability:** dual-write `TAX_BOOK` terms (already seeded by `BibleCanon`) at capture/backfill so "sermons on John 3" / book archives are queryable (the JSON envelope is opaque to `WP_Query`).

## 5. Rendering, fail-open, observability (summary)
- **Rendering:** a dedicated section (not a `meta()` mutation), wired at **one** `the_content`-append/shared hook inherited by classic, block single-sermon, AND theme-override surfaces (per-surface echo would ship dark on block themes and zero the observability denominator). Native `<details>/<summary>` for collapse (no JS — honors the no-browser-script lock). Inline text only for inline-eligible refs; otherwise the link (axis A) or the plain row.
- **Fail-open (HARD):** the resolver never throws; on any failure (unreachable/timeout/parse-fail/unknown-translation/low-confidence/versification-divergence) → null or omit that one ref (free per-reference partial fail-open). `Renderer::scripture()` returning `''` leaves today's output byte-identical (provable zero-regression).
- **Observability (ground truth, not render-attempts):** `CoverageAudit` precomputes two split metrics — **parse-coverage** (structural baseline, never a rollback trigger) vs **live-fetch-failure-rate** (the only input to the roadmap §8.2 helloao alarm, so messy legacy data can't cry wolf) — plus an enriched-vs-withheld-vs-failed breakdown so green can't hide a suppressed maybe-wrong verse. Hooks: `sermonator_bible_resolved`, `sermonator_bible_fallback($passage,$reason)`.

## 6. Phasing

**Phase 3a — Link-first parity (ships first; license-clean; the safe default).** Delivers clickable scripture references to the church's chosen version + the structured-capture foundation. Tasks 1–4 (Identifiers, BibleBookMap, BibleTranslations/Registry, Parser+Validator), 8–10 (Resolver/ResolvedScripture link-mode, `Renderer::scripture` link-mode + single wiring point, authoring-panel structured capture), 12 (backfill dry-run + write), 13 (CoverageAudit parse-coverage), 14 (settings: both axes registered, link axis active), 15 (`bible flush` + CLI registration), + `TAX_BOOK` dual-write. **No vendoring, no live fetch, no inline text** → no licensing/versification/network risk. This is the parity-complete "Bible verse lookup."

**Phase 3b — Inline PD text (opt-in enhancement).** Adds `ChapterFetcher`/`ChapterCache`/`ChapterProvider`, ENGWEBP per-chapter vendoring (Task 7) + `bible vendor`/`warm` CLI (Task 11), `RefValidator` versification gating to inline-eligibility, the inline rendering + badge, and the fetch-failure metric. Higher-stakes; gated behind the corpus audit.

**Corpus gate (Task 16):** no numeric "good-enough" threshold is written until the backfill **dry-run report** + versification-divergence audit runs against the owner's real migrated `bible_passage` corpus (the repo has only "John 3:16"). Observable-before-write, like the audio backfill.

## 7. Test strategy
Unit (Brain Monkey): BibleBookMap drift+id alignment; ReferenceParser table-driven (cross-chapter, J-cluster "Jn"→JHN, ordinals, never-fail-wrong per segment); RefValidator (in-canon, range-exists, divergent-set→ineligible); ChapterFetcher fail-open; ChapterCache key/gen/schema; `Renderer::scripture` (null→'', esc_html leaves, badge only on resolved, no `wp_kses`); BibleRefsBackfill (fill-missing, never-overwrite-authoring, exact rollback, idempotency, MigrationGuard, dry-run writes nothing); envelope round-trip; IdentifiersTest. Integration (wp-env): full save→warm→cache→provider→resolver→renderer round-trip with a vendored ENGWEBP fixture; `the_content` wiring on classic + block + theme-override surfaces; REST parse endpoint auth+sanitize; CLI dry-run+rollback; Site Health reads `OPTION_BIBLE_STATS`.

## 8. Top risks (carried forward)
1. **Fail-wrong** (200-with-wrong-content) — mitigated by `srcVersification` tagging + RefValidator gating the divergent set; only as good as the divergent-set enumeration + correct `verse_bible_version` capture.
2. **Structured capture never really ships** → parser permanent-primary (Risk reborn) — mitigated by making Task 10 a prerequisite.
3. **Bulk/CSV/podcast import is the dominant creation path** for many churches — `source:'import'` is a first-class capture surface under the same gate, or the feature lights only hand-edited sermons.
4. **Silent absence** on block/override surfaces + observability green over a dark feature — mitigated by single wiring point + corpus ground-truth audit.
5. **Render-path network fan-out** for un-warmed multi-ref legacy pages — mitigated by warm-on-save + a hard 1-chapter/~2s residual budget if any live render fetch is ever kept.
6. **Licensing blast radius** (vendoring ambiguous/proprietary text into a GPL plugin) — ENGWEBP-only vendoring + license-tagged allowlist.
7. **helloao single-free-dependency fragility** — pinned-snapshot vendoring + `BIBLE_CACHE_SCHEMA_VERSION`.
8. **No real corpus in-repo** — the measure-before-shipping gate needs the owner's migrated DB (Task 16).
