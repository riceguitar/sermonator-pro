# Sermonator — Bible Inline Enablement: Operator Runbook

- **Date:** 2026-06-27
- **Audience:** the site operator turning ON inline scripture text (the Bundle-3b engine, off by default).
- **Companion:** the design at `2026-06-27-sermonator-bible-inline-enablement-design.md`.

> **Spine:** never-fail-WRONG. Inline rendering shows the actual public-domain WEB (ENGWEBP) verse text for a reference ONLY when it clears the full L1–L9 gate; anything uncertain falls open to the Phase-3a **link** for that one reference (observably, via `do_action('sermonator_bible_fallback', $passage, $reason)`). A wrong verse shown to a congregation is the only unacceptable outcome — so every step below is reversible and conservative.

## The two axes (why there are two attestations)
Inline correctness is two independent questions:
- **Axis 1 — versification (is the WEB text the *right verses*?).** Owned by `VersificationGate` (L4–L7) + `rangeWithinChapter` (L9). The **attestation** checkbox is the operator's affirmation that all references use one English-Bible tradition (so a `site-default`-provenance ref may pass L6).
- **Axis 2 — parse confidence (is the reference *correctly parsed*?).** Owned by the **confidence floor** + `DerivedExactClassifier`. `exact` = author-confirmed chips only. `derived-exact` (STRICT, single-segment) and `derived-exact-perseg` (per-ref) promote auto-parsed `probable` refs that re-parse identically in isolation.

A floor change can only ever let a ref *reach* the unchanged axis-1 gate — it can never surface a wrong verse.

## The enablement journey (each step gated + observable; instant rollback at every point)

1. **Vendor the text** — `wp sermonator bible vendor --write` (migration-gated, long-running, reversible with `--rollback`). Snapshots the public-domain ENGWEBP per-chapter JSON to `wp-content/uploads/sermonator-bible/`. *Verify:* the present/total chapter count reaches 100%; the offline count-diff audit proposes (never auto-commits) any divergent-zone additions. Inline is **physically un-enableable** until this is complete.
2. **Warm the cache** — `wp sermonator bible warm` (migration-gated, fill-missing, reversible via `bible warm --rollback` / `bible flush`). Primes the disposable transient cache for the chapters your sermons actually cite. *Verify:* sermons/cited/warmed/failed counts; a cold chapter simply falls open to a link, never an error.
3. **Preview on YOUR corpus** — open **Settings → Sermonator → Bible inline**. The read-only panel shows, over your own sermons, the would-promote count + inline-eligible % under **each** floor (exact / derived-exact / derived-exact-perseg), the withheld-by-reason breakdown, and two canaries: `unmodeled-pair` (proof the divergent-zone table is incomplete) and `heterogeneous` (proof your corpus spans >1 versification tradition). Also available as `wp sermonator bible audit --inline`.
4. **(Per-ref floor only) Axis-2 spot-check** — `wp sermonator bible audit --inline --sample=N` prints N promoted references beside their raw passage text. Read them; confirm the parses are right. Then `wp sermonator bible ack-perseg` (sets `OPTION_BIBLE_INLINE_PERSEG_ACK`). The `derived-exact-perseg` floor is **un-selectable in settings until this ack exists** — per-ref can never be reached blind. *Strict `derived-exact` needs no ack.*
5. **Attest** — the settings checkbox. The copy states the theological claim verbatim ("…ESV/NIV/NASB/NKJV/KJV/WEB number identically… if you have Septuagint/Vulgate/Catholic-canon-Psalm references, do NOT attest"). The checkbox is **hard-disabled** when the live audit reports `heterogeneous == true`; the only override is the logged `wp sermonator bible attest --force`. Without attestation, `site-default` refs stay withheld → the panel shows "0% until you attest."
6. **Set the floor** — the dropdown (exact | derived-exact | derived-exact-perseg), each with its live recall number beside it; perseg is gated by step 4.
7. **Enable** — the master toggle. The save **soft-gate** refuses unless a fresh audit shows `inline_eligible > 0 && unmodeled_pair_wrong_text == 0 && heterogeneous == false` (logged override only), then stamps the enable's reconciliation generation = the audit generation it reconciled against, and bumps the cache generation. This makes "successfully enabled but dark" impossible to mistake for a bug. *(The soft-gate runs only on the real false→true transition — an unrelated settings save never re-audits, re-stamps, or silently disables.)*

## Rollback (any point, instant)
Flip the floor back to `exact`, or toggle the master switch off → every sermon returns to **byte-identical** Phase-3a links. Nothing was written to `META_BIBLE_REFS`/`bible_passage` (promotion is render-time only), so there is nothing to undo. `bible flush` / TTL expiry / deleting the uploads snapshot reverse the vendored text + warmed cache.

## Drift safety
If the corpus changes after you enable (an import, new guest-preacher sermons), the live audit generation advances past the stamped reconciliation generation, and a **Site Health warning** fires: "inline was reconciled against an older corpus — re-audit." This catches a later sub-corpus quietly introducing heterogeneity or an unmodeled-pair after the enable decision.

## Worked example — the epiclesis church (a per-SITE decision, never a default)
Real corpus: 803 sermon passages, Sermon Manager Free, a single English-Protestant tradition (homogeneous), zero divergent-zone single refs.
- `exact` floor: **0%** inline (auto-parsed legacy refs are `probable`).
- `derived-exact` (strict): **49%** of sermons inline.
- `derived-exact-perseg`: **76%** of sermons inline (the church is liturgical — OT+Psalm+Epistle+Gospel bundled per passage; per-ref lights the individually-clean ranges in those bundles).
- **Wrong-text exposure: 0%** in both (measured) — the precision floor holds.
- **Decision:** vendor + warm + attest (safe — homogeneous, heterogeneity canary clear) + run the `--sample` spot-check + `ack-perseg` + set `derived-exact-perseg` → **~3 in 4 sermons show inline scripture, zero wrong text.** This is recorded as epiclesis's own go/no-go; the plugin default stays `exact`/disabled for every other site, which sees its OWN numbers in the preview.

## Performance note
Promotion re-parses each `probable` ref's own text at render — pure, deterministic, and memoized per request on the raw string, so a scripture-dense liturgical archive re-parses each unique reference once. It runs only when inline is enabled AND the floor is a derived-exact* floor.
