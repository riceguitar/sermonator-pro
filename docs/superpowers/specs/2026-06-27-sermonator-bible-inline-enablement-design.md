# Sermonator — Bible Inline Enablement (Design)

- **Date:** 2026-06-27
- **Status:** DESIGN. Gated design workflow (proposals → adversarial critique → synthesis). The deferred T16 go/no-go that turns ON the Bundle-3b inline engine (currently disabled — 0% at the `exact` floor, because all auto-parsed legacy refs are `probable`).
- **Sized on real data:** the epiclesis corpus (803 sermon passages, run through the plugin): strict `derived-exact` = **49%** of sermons inline; per-ref `derived-exact-perseg` = **76%**; wrong-text exposure = **0%** in both.
- **Spine (unchanged): never-fail-WRONG.** This feature changes only the axis-2 (parse-confidence) L2 floor; the axis-1 `VersificationGate` (L4–L7) + `rangeWithinChapter` (L9) still independently withhold any wrong-versification ref. Promotion cannot surface wrong verses.

## 1. Load-bearing decision: render-time promotion, never a stored re-stamp
The `probable → inline` promotion is computed at **render time** by one shared pure classifier (`Bible\DerivedExactClassifier`), consumed identically by the resolver's L2, the audit's L2, and the live preview. This (a) keeps **#1 data preservation absolute** — zero new writes, no re-backfill of the 803 sermons, instant reversibility (floor→`exact` = 0% with nothing to undo); (b) makes the preview use the **same classifier** as the resolver (no "preview lies / dead lever" fork); (c) leaves the L1–L9 spine untouched — promotion only lets a ref *reach* the unchanged `VersificationGate`.

## 2. The predicate
`Bible\DerivedExactClassifier::isDerivedExact(array $ref): bool` is true ONLY when:
1. **L1-shaped:** `verseStart !== null && chapterEnd === null` (a concrete in-chapter verse/range; never chapter-only, never cross-chapter);
2. the ref carries a non-empty own-segment `$ref['raw']` (missing → false);
3. **re-parse-identity:** re-parsing `$ref['raw']` IN ISOLATION through `ReferenceParser::parse()` yields exactly one `matched` segment with exactly one ref structurally identical (`bookUSFM/chapterStart/verseStart/verseEnd/chapterEnd`) to the stored ref. A fallback segment, ≠1 segment, ≠1 ref, or any mismatch → false.

It is **per-ref by construction** and never inspects siblings: a carry-over continuation (the bare `18` in `John 3:16, 18`, or `5:1-11` in `Isaiah 6:1-13; Luke 5:1-11`), re-parsed alone, has no book at its head → fallback → false → stays `probable` → falls open to a link. This converts the carry-over safety from emergent parser behavior into a **pinned contract** (T-F invariant test).

**Strict vs per-ref** is one thin policy method `promotes($ref, $floor, $refCountInEnvelope)` — the single shared promotion rule both lockstep L2 checks call:
- `exact` → always false;
- `derived-exact` (STRICT, the offerable default meaning) → `$refCountInEnvelope === 1 && isDerivedExact($ref)` — a compound passage's segments are NEVER promoted even when individually clean (the **corpus-independent dark guarantee**);
- `derived-exact-perseg` → `isDerivedExact($ref)` regardless of sibling count.

## 3. Key decisions (critique-hardened)
1. **Render-time, shared pure classifier** (not a stored re-stamp) — §1.
2. **`derived-exact` MEANS strict single-segment** (49%); per-ref is a **distinct** `derived-exact-perseg` floor (76%) — strict's compound-stays-dark is the one corpus-independent safety property; n=1 homogeneous evidence can't be promoted to a global semantic.
3. **Gate per-ref behind a THIRD gate:** an axis-2 human spot-check ack (`OPTION_BIBLE_INLINE_PERSEG_ACK`, un-selectable until set), because the 49→76 delta is exactly the Psalm-bearing lectionary bundles whose safety rides the single attestation boolean and which the axis-1 audit is structurally blind to.
4. **De-store the tier:** stored-confidence `{exact,probable,ambiguous}` and floor `{exact,derived-exact,derived-exact-perseg}` are **disjoint**; the classifier is the ONLY promotion path; a pre-stamped `derived-exact` ref clears nothing (closes the import/bulk-promote bypass). Enforced by assertions in `RefsCapture` + `SermonRefsRestSanitizer`.
5. **Re-parse-identity + a pinned liturgical-corpus invariant test** (no continuation ever promotes; every promotion isolated-identical to in-context) — fails loudly on parser drift.
6. **Enable soft-gate:** keep the snapshot-complete gate AND refuse enable unless a fresh audit shows `inline_eligible > 0 && unmodeled_pair_wrong_text == 0 && heterogeneous == false` (logged override only); stamp the enable's reconciliation generation = the audit generation it reconciled against (kills "enabled but dark = looks like a bug" + corpus drift).
7. **Default stays `exact`/disabled for everyone;** epiclesis's strict-49% / perseg-76% is a recorded per-SITE operational decision backed by its own audit — never an inherited default.

## 4. Attestation UX
- **Informed:** the L6 checkbox copy states the THEOLOGICAL claim verbatim — "I affirm every sermon's reference uses the same English versification tradition (ESV/NIV/NASB/NKJV/KJV/WEB number identically). If you have Septuagint/Vulgate/Catholic-canon-Psalm-numbered references, do NOT attest — inline could show real-but-wrong verses." Lowering the floor while attestation is off surfaces an explicit "0% until you attest" state (site-default refs fail L6 by construction → safely dark).
- **Safe:** the checkbox is **hard-disabled when the live audit reports `heterogeneous == true`** (>1 source-versification family bucket = the single-tradition premise is false). A rare false-positive has a logged escape hatch (`wp sermonator bible attest --force`), never a silent UI bypass. Because site-default backfill stamps every ref the same `srcVersification`, the perseg floor additionally requires the axis-2 spot-check.
- **Reversible:** unchecking instantly returns site-default refs to L6-withheld → 3a links; nothing to undo. `authored` confirm-chip refs (skip L6) are unaffected.
- **Live per-site preview:** a read-only settings panel (fed by `CoverageAudit` with a pending-floor + assume-attested ceiling param) shows the would-promote count + inline-eligible% under **each** of exact / strict / perseg in one pass, the withheld-by-reason breakdown, and the unmodeled-pair + heterogeneity canaries — over THEIR own corpus, before save.

## 5. Enablement flow (each step gated + observable; instant rollback to 3a links at every point)
1. **Vendor** — `wp sermonator bible vendor --write` (migration-gated, reversible). 2. **Warm** — `wp sermonator bible warm` (migration-gated, fill-missing, reversible). 3. **Live audit preview** (settings, read-only). 4. **Axis-2 spot-check ack** (perseg only) — `wp sermonator bible audit --inline --sample=N` prints promoted refs + raw for human verification → sets `OPTION_BIBLE_INLINE_PERSEG_ACK` (perseg un-selectable until then). 5. **Attest** (checkbox, hard-disabled on heterogeneity). 6. **Set floor** (exact | derived-exact | derived-exact-perseg, each with its live recall number; perseg gated by step 4). 7. **Enable** (`sanitizeInlineEnabled` soft-gate + reconciliation-generation stamp + cache-gen bump).

## 6. Components
`Bible\DerivedExactClassifier` (NEW pure — `isDerivedExact` + `promotes`; per-request raw memo) · `Frontend\BibleResolver` (`confidenceClears($ref,$floor,$refCount)` delegates promotion; threads per-post refCount; de-stored rank) · `Bible\CoverageAudit` (lockstep `confidenceClears` on the same classifier; per-post grouping for the singleton constraint; the would-promote preview + `--sample`; Site-Health drift warning) · `Bible\VersificationGate` / `Bible\RefValidator` (UNCHANGED — axis-1 + L1/L9 still run after promotion) · `Admin\SettingsRegistrar` (+`derived-exact-perseg` gated by the ack; hard-disable attest on heterogeneity + logged override; `sanitizeInlineEnabled` reconciliation soft-gate; new Identifiers consts) · `Admin\BibleInlinePreviewPanel` (NEW read-only live preview) · `Cli\BibleCommand` (`audit --inline` three-floor + `--sample`; `bible attest --force`) · `Admin\Authoring\SermonRefsRestSanitizer` + `Bible\RefsCapture` (de-store assertion — never emit/accept `derived-exact`) · `Frontend\Renderer::scripture` (UNCHANGED, re-verified pure + null byte-identical).

## 7. Top risks
1. **Attestation-leveraged wrong-Psalm via the per-ref delta** → the third (axis-2 spot-check) gate + heterogeneity hard-disable; strict needs none.
2. **De-store regression** (a path pre-stamps `derived-exact`) → disjoint stored/floor sets + assertions.
3. **Re-parse-identity drift** (parser change) → the pinned liturgical-corpus invariant test fails loudly.
4. **Render-time parse cost on scripture-dense pages** → per-request memo on `raw`; runs only when inline enabled AND floor=derived-exact*.
5. **Naming confusion** (`derived-exact` reads as a stored tier) → explicit de-store + disjoint sets + docs.
6. **Corpus drift between audit and enable** → the reconciliation-generation stamp + the Site-Health drift warning.
7. **Inheriting epiclesis's numbers** → default exact/disabled; the preview shows each site its OWN numbers.

## 8. Test strategy
Unit (Brain Monkey): classifier (carry-over isolation withholds continuations; structural-mismatch withholds; pre-stamped `derived-exact` inert); resolver/audit lockstep identical across a fixture matrix; de-store assertions; preview counters match hand-computed fixtures incl. the 49% vs 76% delta; the **pinned re-parse-identity invariant** over a liturgical fixture. Integration (wp-env): multi-segment some-inline/some-link; **attested-but-divergent-ref-still-links**; enable-gating + reconciliation-generation persistence; **rollback byte-identical to 3a**; attestation hard-disable on a seeded heterogeneous corpus; no write-on-GET for the preview/audit.

## 9. Task breakdown (T-A … T-M)
A. `DerivedExactClassifier` (pure: `isDerivedExact` re-parse-identity + `promotes`; raw memo).
B. De-store the tier in `BibleResolver`; `confidenceClears($ref,$floor,$refCount)` delegates; thread per-post refCount.
C. Lockstep `CoverageAudit::confidenceClears` on the same classifier; per-post grouping for the singleton constraint.
D. De-store enforcement in `SermonRefsRestSanitizer` + `RefsCapture` (reject/never-emit `derived-exact`).
E. `CoverageAudit` would-promote preview — one-pass exact/strict/perseg counters + assume-attested ceiling; `--sample=N` axis-2 output.
F. Pinned re-parse-identity invariant test over a liturgical fixture corpus (fails loudly on parser drift).
G. `SettingsRegistrar` — `derived-exact-perseg` gated by `OPTION_BIBLE_INLINE_PERSEG_ACK`; hard-disable attest on heterogeneity (+ logged CLI override); `sanitizeInlineEnabled` reconciliation soft-gate + generation stamp; new Identifiers consts.
H. Settings live audit-preview panel (read-only; "0% until you attest"; verbatim theological copy).
I. `BibleCommand` — `audit --inline` three-floor + `--sample` spot-check; `bible attest --force` logged override.
J. `Renderer::scripture` — confirm unchanged + extend the null-branch byte-identical test (inline-off rollback); meta-mutation spy proves zero writes.
K. `CoverageAudit` Site-Health drift warning when the live audit generation passes the generation stamped at enable.
L. Integration suite (wp-env) — the §8 cases.
M. Docs/runbook — the per-site go/no-go journey; record epiclesis (strict 49% baseline; perseg 76% only after the axis-2 spot-check).
