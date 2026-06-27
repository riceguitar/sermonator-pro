# Sermonator — Bundle 2: Deep-Compat (Design)

- **Date:** 2026-06-27
- **Status:** DESIGN. Gated design workflow (proposals → adversarial critique → synthesis). The FINAL parity-roadmap bundle (Tier B deep-compat).
- **Sizing note (§63 guard):** the precursor migrator-reality audit is UNMET (no real Pro-site sample), so Bundle 2 stays **full-scope** by default. Only page-builder is genuinely blind; the roadmap defines its full-scope deliverable as **detection + warning** (rebuild already won't-do), so detection-only is full-scope here, not a collapse. Every deferral is recorded as an **explicit signed Contract-guard exception** in the PR — never a silent down-size.

## 1. Scope
1. **Attribute-faithful `[sermons]`/`[sermons_sm]`** — replace the Bundle 1 safe-default+notice shim with a per-attribute ledger mapping legacy attributes onto the existing `SermonQuery`/`GridArgs`/`Renderer` engine; drop the review notice per faithfully-reproducible attribute, keep a **precise per-attribute** notice for anything unvalidatable against the absent SM source.
2. **Per-podcast feed filtering** — a read-only scope resolver reading the already-migrated `META_PODCAST_SETTINGS` term-scope keys → `SermonQuery` `tax_query`; empty scope = today's exact unscoped query (single-podcast provably unchanged); a **never-serve-empty** invariant on unresolved scope.
3. **Page-builder detection** — a read-only, fail-visible scanner in the migration report + Site Health; the module rebuild is explicitly backlogged.

## 2. Key decisions (critique-hardened)
1. **Per-attribute ledger** (not blanket-notice, not all-faithful) — the Contract's binding rule is literally per-attribute. A `LegacyAttributeMapper` classifies each attr FAITHFUL / NO-OP-SAFE / UNVALIDATABLE / UNSUPPORTED, applies the faithful ones, and names ONLY the present unfaithful ones in the notice (empty → no notice).
2. **`per_page` needs REAL pagination.** The native grid renders a fixed count with no pager, so a naive `per_page` map silently drops the long tail of a large archive (the canonical fail-wrong). Build a paginated list variant + escaped pager keyed on a dedicated `sermon_page` query var; until it lands, `per_page` is NOT certified faithful (named in the notice + fires `sermonator_list_truncated`).
3. **Preached-date semantics diverge from native — add a `dateScope` mode to `SermonQuery`** (`inclusive`=native default LEFT-JOIN dateless-last+future; `preached`=EXISTS + `META_DATE <= now` dropping future AND dateless, matching legacy `display_sermons()`; `none`=no date meta_query). NEVER fork/mutate the native grid's OR/NOT-EXISTS branch. `META_DATE` compared as NUMERIC (signed, so pre-1970 negatives sort correctly).
4. **`after` is bug-compatible by default** — SM's broken `=>` compare silently ignored `after`, so honoring it would render DIFFERENT content than SM ever did (itself a fail-wrong by the "same content SM rendered" promise). Default-ignore behind a documented opt-in filter; no notice.
5. **Numeric legacy ids: resolve-or-drop, never pass-through.** `Crosswalk::LEGACY_TERM_ID`/`LEGACY_POST_ID` are strippable back-refs (resolvable pre-Finalize, gone post-Finalize). Resolve `include`/`exclude`/numeric `filter_value` via the crosswalk; on non-resolution DROP that axis + name it. Passing legacy id 42 through would select a DIFFERENT new term/post 42 (fail-wrong). Prefer slug resolution (durable across Finalize).
6. **One shared `LegacyTermResolver`** consumed by BOTH the `[sermons]` mapper AND the feed scope path (slug→new via durable taxonomy slug; numeric→new via `TermCrosswalk`; reports unresolved) — two divergent resolvers would give two different answers to "what survives Finalize."
7. **Per-podcast scope source = `META_PODCAST_SETTINGS` keys intersected with `ID::sermonTaxonomies()`** (NOT a hardcoded five-slug list — mirrors `PodcastMetaSchema::keys()` anti-drift). Values are already NEW term ids (remapped by `PodcastWriter::remapSettingsTerms()`), fed to `SermonQuery::buildTaxQuery` (relation=AND across taxonomies, IN within — byte-identical to Pro's `filter_the_query`). No new tax-query builder.
8. **Feed fail-visible + never-serve-empty:** clean resolve → apply, no notice (earned). An open `missing_podcast_term_crosswalk:*` flag (Pro had scope but a term didn't resolve) → fall back to UNSCOPED + fire `sermonator_feed_scope_incomplete`; **never serve a feed scoped to a dead term id** (would silently empty a live feed). `>1` published podcast with no scope keys → unscoped + the existing over-inclusion signal. Empty scope on a single podcast → today's exact query, silent.
9. **Live feed item-set change is irreversible** (rollback story 8.1) — per-podcast filtering narrows the subscriber-visible set; before any church switches, a per-podcast pre/post feed-diff via the existing `LegacyFeedSnapshot` must pass. GUID continuity protects *surviving* items only.
10. **Audio/video `sermons_to_show` modes DEFER** — audio-only ships; `video`/`*_priority` fire `sermonator_feed_mode_unsupported` and the per-podcast notice is NOT retired while mode faithfulness is unbuilt.
11. **Page builders = read-only fail-visible scanner (Option B), rebuild backlogged.** `PageBuilderScanner` fingerprint FLOOR (required catch-all): (a known builder meta key present — `_elementor_data`, Divi `_et_pb*`, Beaver `_fl_builder_data`, WPBakery `vc_*`) AND (any legacy sermon reference — a `wpfc_sermon` id, a legacy taxonomy slug, or a legacy `[sermons]`/`[list_sermons]`/`[latest_series]`/`[sermon_images]` string). A distinct lower-severity finding for legacy shortcodes embedded in builder postmeta (which the `do_shortcode` shim does NOT fire); do NOT build a do_shortcode-on-meta bridge. Surface in BOTH the migration report (pre-switch) AND Site Health.
12. **§63 prevalence counter** — emit detect/verify counts (podcasts-with-scope, `>1`-podcast sites, single-scoped-podcast count, embedded-`[sermons]` attribute density, builder findings) so the first real migration produces the data the audit lacked; future video-mode + object-term-mirroring work is gated on it.

## 3. Components
`Frontend\Compat\LegacyAttributeMapper` (raw-atts → `{gridArgs,dateScope,orderby,postIn,postNotIn,unfaithfulAttrs}`) · `Frontend\Compat\LegacyTermResolver` (shared slug/numeric term resolver) · `Frontend\Compat\LegacyPostResolver` (legacy post id → new) · `Frontend\SermonQuery` (+`orderby`, `dateScope` mode, NUMERIC date-range bounds, `post__in`/`post__not_in`) · `Frontend\Renderer` (+paginated list variant + escaped pager on `sermon_page`) · `Frontend\Compat\LegacyShortcodes` (rewritten `[sermons]`/`[sermons_sm]` → mapper→SermonQuery→Renderer + precise notice) · `Frontend\Feed\PodcastScopeResolver` (`forPodcast(id)` → `array<taxonomy,list<int>>`) · `Frontend\Feed\PodcastFeed` (+`items(scope)` + never-serve-empty fallback + fail-visible signals) · `Migration\PageBuilderScanner` (read-only detector → report + Site Health) · migration detect/verify prevalence counter · the Compatibility Contract (per-attribute ledger + scope rules + builder rows + §63 exceptions — PR exit criterion).

## 4. Top risks
1. **Silent `per_page` under-render** → real pagination + `sermonator_list_truncated` until certified.
2. **Reconstructed preached/date semantics have no running-SM oracle** → certify faithful only behind pinned integration tests; everything else keeps a notice.
3. **Post-Finalize numeric-id fail-wrong** → resolve-or-drop, never pass-through.
4. **Editor-only notices invisible to visitors/podcast apps** → every visitor-facing fail-open ALSO fires an observable `do_action`.
5. **Live per-podcast feed narrowing is irreversible** → pre/post feed-diff gate before switch.
6. **Migration-fidelity gap** — Pro variants storing scope only in podcast object-terms migrate as empty scope (recorded; the prevalence counter measures it).
7. **Undetected page builder defeats fail-visible** → the coarse catch-all floor is required, not optional.
8. **§63 scope-collapse regret** → every deferral a signed Contract exception, not silent.

## 5. Test strategy
Unit (Brain Monkey): the mapper ledger (one test per cell — orderby resolution, `after` bug-compat default, `filter_by` crosswalk, unknown-attr capture, numeric resolve/drop); `LegacyTermResolver`/`LegacyPostResolver` pre/post-Finalize transition; `PodcastScopeResolver` (scope read, missing-crosswalk → unscoped, empty → unscoped); `SermonQuery` dateScope modes; the scanner fingerprint + zero-writes. Integration (wp-env): native grid (dateScope=inclusive) byte-unchanged; dated/dateless/future/pre-1970 ordering; `[sermons per_page=N]` reaches page 2 with no content lost; per-podcast scoping serves only scoped sermons + the **HARD no-regression-on-single-podcast feed-diff** against `LegacyFeedSnapshot`; the scanner surfaces in report + Site Health. Update the Contract changelog (PR exit criterion).

## 6. Task breakdown (ordered)
1. **T1** Contract + ledger scaffold (the per-attribute ledger, scope/over-inclusion/never-serve-empty rules, builder finding rows, §63 deferral-exception record) — pins the spec; PR exit criterion.
2. **T2** Extend `SermonQuery`: `orderby` + `dateScope` modes + NUMERIC date-range bounds (signed-ts safe) + `post__in`/`post__not_in`; prove native grid (inclusive) unchanged.
3. **T3** Shared `LegacyTermResolver` + `LegacyPostResolver` (slug durable / numeric crosswalk, unresolved-reporting; pre/post-Finalize).
4. **T4** `LegacyAttributeMapper` (full per-attribute ledger → mapped args + `unfaithfulAttrs`).
5. **T5** Renderer pagination (paginated list variant + escaped pager + `sermon_page` query var) — the gate that certifies `per_page` faithful.
6. **T6** Rewrite `LegacyShortcodes::render()` for `[sermons]`/`[sermons_sm]` (mapper→SermonQuery→Renderer + precise per-attribute notice + `sermonator_list_truncated`; keep the coexistence guard).
7. **T7** `PodcastScopeResolver` (read `META_PODCAST_SETTINGS` scope keys via `sermonTaxonomies()`; report missing-crosswalk; empty → unscoped).
8. **T8** Wire scope into `PodcastFeed` (`items(scope)` → taxonomies; never-serve-empty fallback + fail-visible signals; HARD single-podcast no-regression feed-diff).
9. **T9** Audio/video mode fail-visible signal (`sermonator_feed_mode_unsupported`; keep the notice while deferred).
10. **T10** `PageBuilderScanner` (read-only catch-all floor + distinct meta-embedded finding; report + Site Health; assert zero writes).
11. **T11** detect/verify prevalence counter (scope/podcast/attribute-density/builder counts).
12. **T12** Finalize: Contract changelog + §63 exceptions, full suites green, adversarial review to convergence, PR.
