# Sermonator — Bundle 3 Phase 3b: Inline Bible Verse Text (Design)

- **Date:** 2026-06-26
- **Status:** DESIGN. Gated design workflow (parallel proposals → adversarial critique → synthesis). Progressive enhancement over the shipped Phase 3a link mode.
- **Spine:** **never-fail-WRONG.** A ref renders inline verse text ONLY when it clears a layered AND-gate; any failure falls open to the 3a link for that one ref (observably). Falling open is always free; a false-positive inline (real-but-wrong WEB words shown to a congregation) is the only unacceptable outcome.

## 1. The headline correction
3a's `RefValidator::isVersificationDivergent(book,chapter)` is a **unary constant blind to both axes** — it reads neither `srcVersification` nor the target translation, so it cannot answer the real question and would render wrong WEB words for a KJV/NASB/Reina-Valera-sourced church. Phase 3b makes versification a **relation over (source-family → target)**, owned by a new pure `Bible\VersificationGate`. `RefValidator` stays pure-structural and keeps its existing `inlineEligible()` as a NECESSARY pre-filter only.

## 2. The inline-eligibility predicate (every layer must pass; else → 3a link + `do_action('sermonator_bible_fallback', $passage, $reason)`)

**PURE STATIC PRE-FILTER** (`RefValidator`, no chapter data — also pre-tags authoring chips):
- **L1** in-canon && structurally-valid && `verseStart !== null` (not chapter-only) && `chapterEnd === null` (not cross-chapter).
- **L2** confidence floor: `confidence ∈ allowedFloor` (default `{exact}`; admin opt-in adds `{derived-exact}`; `{probable}` only under a documented misparse-risk acceptance). reason `low-confidence`.
- **L3** target translation `inlineEligible` (ENGWEBP yes; **ENGKJV flipped to false** until separately audited; BSB no). reason `translation-ineligible`.

**VERSIFICATION RELATION** (pure `Bible\VersificationGate`, called by the impure resolver — the never-fail-wrong core):
- **L4** normalize `srcVersification` (case, UK suffixes, aliases) → family code; unknown/empty → reason `src-versification-unsupported`.
- **L5** `inlineEligibleForPair`: pass ONLY when EITHER (a) source-family == target-family by construction, OR (b) the ordered pair appears in `MODELED_PAIRS` (which owns its OWN re-derived English↔English ESV↔WEB divergent-zone set — re-authored off the Hebrew↔English mis-calibration that darkens the whole Psalter in 3a). reason `unmodeled-versification-pair` (counted distinctly so green can never hide a mis-versification).
- **L6** provenance attestation: if `srcVersificationConfidence == 'site-default'` (backfill/absent), require `OPTION_BIBLE_INLINE_ATTESTATION` (admin affirms all references use one English-tradition version). `authored` refs (stamped contemporaneously at save with the live link version) skip attestation. reason `src-versification-unattested`.
- **L7** (book,chapter) not in the modeled pair's divergent zones. reason `versification-divergent`.

**RENDER-TIME CONFIRMATION** (impure `BibleResolver`, chapter in hand — Renderer stays pure):
- **L8** `ChapterProvider::get(...) !== null` (chapter available OFFLINE; render context never fetches). reason `chapter-unavailable` / `cold-unwarmed`.
- **L9** `RefValidator::rangeWithinChapter($ref, $chapter)` — every verse `verseStart..verseEnd` is present in the fetched chapter; a critical-text gap fails the WHOLE ref open to the link (never render a partial range implying a skipped verse). reason `verse-out-of-range`. (Presence ≠ correspondence: this catches GAP omissions, not RENUMBER shifts — those are L7's job.)

## 3. Key decisions
1. **`Bible\VersificationGate`** owns the (source-family → target) relation; `RefValidator` stays structural.
2. **Tiered source provenance.** New optional Ref field `srcVersificationConfidence` (`authored` = stamped at authoring save; `site-default` = backfill/absent default). `authored` refs gate directly; `site-default` refs additionally need the admin attestation. Back-compat: absent → `site-default` (conservative).
3. **One audited inline target.** Flip `ENGKJV` `inlineEligible:false`; ship **ENGWEBP only** (its divergent table is the only one enumerated). BSB stays ineligible (license-ambiguous).
4. **Vendor, don't bundle.** Vendor ENGWEBP normalized per-chapter JSON ON-DEMAND to `wp-content/uploads/sermonator-bible/ENGWEBP/<BOOK>/<chapter>.json` (~25–50MB, 1189 files) via `wp sermonator bible vendor` — NOT the committed repo/SVN. Each file stamped with `BIBLE_CACHE_SCHEMA_VERSION`. Inline rendering is **physically un-enableable** (`OPTION_BIBLE_INLINE_ENABLED`) until a full vendor+warm pass completes — no half-on/ships-dark.
5. **Confidence floor `exact`** (author-confirmed chips). The CUT item: NO precision-gated "trust clean auto-refs" auto-switch (it scaled axis-1 exposure off an axis-2 metric).
6. **Off-render-path.** `ChapterProvider` read order: uploads-disk → transient → (warm-context-only) fetch → null. At RENDER context: disk/cache ONLY, **zero network** (helloao has no verse-range endpoint; a render fetch would fan out to N serial 5s calls and hang). Warm-on-save (synchronous, after `RefsCapture`) + reversible `bible warm` CLI; reverse == `bible flush` (gen bump) / TTL expiry / snapshot delete. No touched-id log needed (cache is disposable — a strictly nicer reversibility than the meta backfill).
7. **Pure Renderer consumes typed nodes.** `ResolvedScripture` carries optional per-ref inline payload `{translation, attribution, verses:[{number, nodes:[{type:'text'|'wordsOfJesus'|'note', text}]}]}|null` (NOT raw HTML). `Renderer::scripture` stays pure: null → byte-identical 3a link; present → inline section + badge, `esc_html` every leaf, own `<sup>`/`<span>`, native `<details>`, **never `wp_kses`**.
8. **Confirm-chip UI into the ENQUEUED bundle** (`build/meta-box`, `window.sermonatorMetaBox`, enqueued by `SermonMetaBox::enqueueAssets`) — NOT the dormant `build/sermon-details` bundle (no PHP enqueue → would ship invisible). WRITE goes through meta-on-save: register `META_BIBLE_REFS` `show_in_rest` + an envelope sanitizer on `rest_pre_insert` (`SermonRefsRestSanitizer`) that re-decodes, drops invalid refs, caps count, and stamps `source='authoring'`/`confidence='exact'`/`srcVersificationConfidence='authored'` **server-side** (client values never trusted). Harden `clearStaleAutoParseEnvelope` to per-ref (preserve `exact` refs whose (book,chapter,verse) still appear in the parse; drop orphaned ones).
9. **3b release gate (corpus gate).** Extend `CoverageAudit` to tag WITHHELD-by-reason + emit inline-eligible%; gate the 3b enable on BOTH a **zero-wrong precision floor** AND a measured **recall floor** via read-only `wp sermonator bible audit --inline` over the owner's real corpus. A nonzero `unmodeled-versification-pair` counter is direct proof the blocklist was incomplete.

## 4. Components
`Bible\VersificationGate` (pure, MODELED_PAIRS + per-pair divergent zones + family normalization + reason codes) · `Bible\RefValidator` (+pure `rangeWithinChapter`) · `Schema\BibleTranslations` (ENGKJV→ineligible; family-code helper) · `Frontend\Bible\ChapterFetcher` (AudioHeadProbe-hardened fail-open) · `Frontend\Bible\ChapterNormalizer` (pure helloao→flat nodes) · `Frontend\Bible\ChapterCache` (gen|schema-keyed transient, MONTH TTL, never DELETE…LIKE) · `Frontend\Bible\ChapterProvider` (disk→transient→warm-only-fetch→null; render disk-only) · `Frontend\Bible\BibleWarmer` (warm-on-save + CLI backfill; migration-gated, fill-missing, structurally reversible) · `Migration\BibleChapterVendor` (snapshot to uploads + offline count-diff audit proposing blocklist additions) · `Frontend\BibleResolver` (+3b inline path L1–L9, per-ref fail-open) · `Frontend\ResolvedScripture` (+inline payload) · `Frontend\Renderer::scripture` (+inline render) · `Admin\Authoring\BibleParseController` (read-only GET preview) · `Admin\Authoring\SermonRefsRestSanitizer` (rest_pre_insert server-side stamps) · `build/meta-box` JSX confirm-chip sub-component · `Bible\CoverageAudit` (+withheld-by-reason, inline-eligible%, wrong-text counter) · `Admin\SettingsRegistrar` (+3 options) · `Cli\BibleCommand` (+vendor/warm/audit --inline).

## 5. Top risks
1. **Trusted-stamp wrong-verse** — `srcVersification` is a site-wide stamp, not per-ref; an imported foreign-versification sub-corpus could be mis-stamped → tiered provenance + attestation.
2. **Recall vs precision** — `rangeWithinChapter` proves presence not correspondence; the count-diff audit can't detect zero-net-delta renumber → blocklist completeness is load-bearing; conservative defaults.
3. **Ships dark** — `exact` floor + cold cache + conservative blocklist → low legacy recall; mitigated by the recall floor in the gate + the confirm-chip panel making new sermons `exact`.
4. **Vendor footprint/operational** (~25–50MB to uploads; lights up only after CLI) → hard-gate + clear operator docs.
5. **helloao fragility at warm/vendor time** → pinned-snapshot vendoring + SCHEMA_VERSION + fail-open-to-link.
6. **Render-path leak** — a warm-only fetch leaking to render context hangs scripture-dense pages → the disk-only-at-render invariant is tested.
7. **Authoring surface mis-wire** — chips in the dormant bundle ship invisible → build into the enqueued meta-box bundle.
8. **Envelope schema addition** — `srcVersificationConfidence` must be back-compat (absent → `site-default`).

## 6. Test strategy
Unit (Brain Monkey): `VersificationGate` never-render-wrong-text + divergence-gating + unattested + unknown-source; `rangeWithinChapter` present-passes/gap-fails-whole-ref; `ChapterNormalizer` verse/wordsOfJesus/noteId mapping; `ChapterFetcher` fail-open null/never-throws; `ChapterProvider` render-context-makes-no-network-call; resolver fail-open-to-link for EVERY reason + off-render-path proof; `Renderer::scripture` escaping + null-branch byte-identical; the rest sanitizer overwrites client confidence + drops orphan-exact. Integration (wp-env): vendor parse + rollback; warm→cache→flush reversibility (passage/envelope unmutated); confirm-chip save → exact ref → inline renders; enable-blocked-until-warmed; `audit --inline` report shape + no-write-on-GET.

## 7. Task breakdown (ordered; T16 is the operational go/no-go)
1. **T1** Identifiers/schema constants (`OPTION_BIBLE_INLINE_ENABLED`, `_ATTESTATION`, `_CONFIDENCE_FLOOR`, `BIBLE_CACHE_SCHEMA_VERSION`, vendor dir) + optional Ref `srcVersificationConfidence` (absent→`site-default`). Unit: back-compat.
2. **T2** `BibleTranslations`: ENGKJV→`inlineEligible:false`; link-version→family-code helper.
3. **T3** `Bible\VersificationGate` (pure): family normalization, MODELED_PAIRS (+re-derived English↔English divergent zones), attestation requirement, reason codes. Unit: never-render-wrong + gating + unattested + unknown-source.
4. **T4** `RefValidator::rangeWithinChapter` (pure) + keep pre-filter. Unit: present passes / gap fails whole ref.
5. **T5** `ChapterNormalizer` (pure) helloao→flat nodes + SCHEMA_VERSION stamp.
6. **T6** `ChapterFetcher` (hardened fail-open) + `ChapterCache` (gen|schema key, MONTH TTL, disk bypass).
7. **T7** `ChapterProvider` (disk→transient→warm-only-fetch→null; render disk-only). Unit: render makes no network call.
8. **T8** `Migration\BibleChapterVendor` + `bible vendor` CLI (snapshot to uploads + count-diff audit; reverse=delete).
9. **T9** `BibleWarmer` + `bible warm` CLI (warm-on-save + chunked backfill; migration-gated, fill-missing, reversible).
10. **T10** `BibleResolver` 3b inline path (L1–L9, per-ref fail-open, reason hooks, confidence floor) + `ResolvedScripture` inline payload.
11. **T11** `Renderer::scripture` inline rendering (badge, esc_html leaves, `<details>`, never wp_kses; null byte-identical).
12. **T12** `BibleParseController` (read-only GET preview) + `SermonRefsRestSanitizer` (server-side stamps, cap, drop-invalid) + per-ref `clearStaleAutoParseEnvelope` hardening.
13. **T13** `build/meta-box` JSX confirm-chip Scripture sub-component (live parse, per-chip eligibility labels, feeds `META_BIBLE_REFS`); committed build.
14. **T14** `CoverageAudit` extension + `bible audit --inline` (withheld-by-reason, inline-eligible%, unmodeled-pair counter) + Site Health.
15. **T15** `SettingsRegistrar`: 3 new options; `OPTION_BIBLE_INLINE_ENABLED` un-enableable until vendored+warmed; attestation + floor controls; cache-gen bump.
16. **T16** CORPUS GATE (operational): run `bible audit --inline` over the real migrated corpus; set precision/recall floors + MODELED_PAIRS/attestation defaults from the numbers. Go/no-go for enabling 3b at scale.
