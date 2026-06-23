# Sermonator — Plan B2b: Migration Lifecycle (Orchestrator, Verifier, Rollback, Finalizer, CLI, E2E) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Authoritative design + closed adversarial holes: `docs/superpowers/specs/2026-06-22-sermonator-b2-design-notes.md` (B2b = design-notes items 16–21). Builds on the merged B2a writers.

**Goal:** Assemble the per-record writers (B2a) into a resumable, lock-guarded, state-machined migration lifecycle: an Orchestrator that sequences and gates the phases, a Verifier that proves legacy→target completeness + source-fixity, a Rollback that exactly and non-destructively reverses, a Finalizer that is the sole (gated) destructive step, a WP-CLI surface, and a full-cycle end-to-end test.

**Architecture:** `Sermonator\Migration\` + `Sermonator\Cli\`. Durable per-record state in the `sermonator_migration_state` option (no custom tables). The destructive operations (phase-commit, Rollback, Finalizer) carry the rollback-story discipline: Finalize is the only point of no return.

**Tech Stack:** PHP 8.1+, WP 6.0+, PHPUnit 9.6 (wp-env integration), WP-CLI (in the tests-cli runtime; guard with `defined('WP_CLI')`). No new composer deps.

## Global Constraints

(Identical to B2a — repeated because each task implicitly includes them.)
- **Data preservation is the highest bar.** Read legacy READ-ONLY until Finalize; tests snapshot legacy rows before/after and assert byte-equality. Finalize is the ONLY step that deletes legacy data, and only after `verified` + a fresh drift rescan, per verified counterpart.
- **Non-destructive reversibility:** Rollback deletes only migration-made records (back-ref tagged + swept partial-orphans + comments via `LEGACY_COMMENT_ID`), restores backed-up options, and leaves legacy byte-for-byte unchanged. Post-rollback, zero records carry any back-ref.
- **Ordering enforced:** terms → (sermons, artwork, podcasts) → options(default-podcast); hard preconditions (no sermons/artwork/podcasts until terms complete with zero missing crosswalks; no default-podcast option until podcasts complete). Single advisory lock serializes/refuses concurrent runs. Monotonic state; `verified` only after the Verifier passes.
- **`sermonator_` prefix; no custom DB tables; single-site; PHP 8.1+; GPL-2.0; no monetization.**
- Integration command: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration` (sequential only).

## File Structure

```
src/Migration/MigrationState.php   # NEW: durable state machine + per-record progress
src/Migration/Orchestrator.php     # NEW: sequences/gates phases, lock, chunked/resumable
src/Migration/Verifier.php         # NEW + src/Migration/VerifyReport.php
src/Migration/Rollback.php         # NEW
src/Migration/Finalizer.php        # NEW
src/Cli/MigrationCommand.php       # NEW (guard defined('WP_CLI'))
src/Plugin.php                     # MODIFY: register CLI command on boot when WP_CLI
tests/Integration/Migration/*Test.php, tests/Integration/Migration/EndToEndTest.php
tests/Integration/Support/LegacyFixture.php  # MODIFY: richest fixtures for e2e
```

---

### Task 1: MigrationState — durable state machine + per-record progress

**Files:** Create `src/Migration/MigrationState.php`; Test `tests/Integration/Migration/MigrationStateTest.php`.
**Interfaces:** `MigrationState::phase(): string` (one of `none|detected|migrating|migrated|verified|finalized`); `set(string $phase): void` (monotonic — rejects illegal transitions, allows `migrated→detected` only via Rollback path flagged by a `rollback` arg); `recordRecord(int $legacyId, string $state, ?int $newId, array $flags): void` (`state` ∈ `pending|in_progress|complete|failed`); `record(int $legacyId): ?array`; `setManifest(Manifest $m): void` / `manifest(): ?Manifest`; `phaseComplete(string $phaseKey): bool` / `markPhaseComplete(string $phaseKey): void`. All persisted in `Identifiers::OPTION_MIGRATION_STATE` (autoload=no).
**must_handle:** durable per-record state survives a process restart (option-backed); monotonic transitions (never advance on partial; reject e.g. `none→verified`); a separate `in_progress` marker makes partials detectable distinct from `complete`; manifest stored at detect time for the Verifier/Finalizer.

- [ ] **Step 1 (failing test):** state defaults to `none`; `set('detected')` then `phase()==='detected'`; illegal jump `set('finalized')` from `detected` throws/returns false; `recordRecord(123,'in_progress',null,[])` then `record(123)` shows in_progress; `markPhaseComplete('terms')` → `phaseComplete('terms')` true; manifest round-trips via the option; a fresh `MigrationState` instance (new object) reads the same persisted phase (durability).
- [ ] Steps 2–5: implement (option-backed, monotonic guard via an allowed-transitions map), run → PASS, commit `feat: MigrationState durable state machine`.

---

### Task 2: Orchestrator — sequence, gate, lock, chunked/resumable

**Files:** Create `src/Migration/Orchestrator.php`; Test `tests/Integration/Migration/OrchestratorTest.php`.
**Interfaces:** `Orchestrator::__construct(?Detector, ?TermWriter, ?ArtworkWriter, ?SermonWriter, ?PodcastWriter, ?OptionWriter, ?MigrationState)` (defaults construct real ones); `detect(): Manifest` (runs Detector, stores manifest, state→`detected`); `run(int $batchSize = 50): array{phase:string, done:int, remaining:int, flags:list<string>}` (advances one bounded chunk of work and returns progress; re-callable until complete); `acquireLock(): bool` / `releaseLock(): void`; `status(): array`.
**must_handle:** **HARD precondition gates** — refuse to write sermons/artwork/podcasts unless `TermWriter::migrateAll` completed with zero missing crosswalks for sermon-referenced taxonomies; refuse the default-podcast option write until podcasts complete. **Single advisory lock** (a TTL sentinel in an option, or `GET_LOCK`) so a cron run and an admin run cannot race on inserts — a second concurrent `run()` refuses. **Resumable/chunked:** `run(batchSize)` migrates up to `batchSize` sermons per call, recording per-record state; a crash mid-batch then a fresh `run()` resumes (no duplicates, partials redone). State advances terms→(sermons/artwork/podcasts)→options, never skipping; `migrated` set only when every phase reports complete; never sets `verified` itself (the Verifier does).

- [ ] **Step 1 (failing test):** with a `LegacyFixture` seeded (terms, sermons, podcast, options, artwork), `detect()` → manifest counts; calling `run()` repeatedly with `batchSize=1` eventually migrates everything (terms first, then the rest) and reaches `phase()==='migrated'`; **sermons refuse to run while terms incomplete** (inject an incomplete-terms state → sermon phase is gated); a second `run()` while a lock is held refuses/serializes; **crash simulation** (abort after the first sermon of a batch, then `run()` again) → no duplicate sermons; legacy snapshot byte-equal throughout.
- [ ] Steps 2–5: implement, run → PASS, commit `feat: Orchestrator (gated, locked, chunked, resumable)`.

---

### Task 3: Verifier — legacy→target completeness + drift oracle

**Files:** Create `src/Migration/VerifyReport.php`, `src/Migration/Verifier.php`; Test `tests/Integration/Migration/VerifierTest.php`.
**Interfaces:** `VerifyReport{bool $complete, list<int> $drift, list<int> $missing, list<string> $openFlags, array $counts}`; `Verifier::verify(Manifest $m): VerifyReport`.
**must_handle:** **GATING(3)** — recompute `LegacyChecksum::forPost` per legacy id and compare to `$m->checksum($legacyId)`; mismatch → `drift[]` (source edited between detect and migrate). **CRITICAL legacy→target direction:** enumerate EVERY legacy id in the manifest and assert exactly one migrated counterpart via `Crosswalk::findNewByLegacyId` **AND** that counterpart's `MIGRATION_FLAGS` failure set is empty — so an offsetting skip+duplicate cannot satisfy a bare count match (`missing[]` for any legacy id without a clean counterpart). Terms/options completeness via counts + field-by-field through the crosswalk, paired by `MappingContract::taxonomyMap()` (not same-key). **Slug-collision:** when a term carries a `slug_collision` flag, compare the new slug against its `LEGACY_SLUG` (exact), not `legacy==new`. Extended drift: also fixity-check terms (name+slug+description) and options. `complete = drift==[] && missing==[] && openFlags==[]`; on `complete`, set state `verified`. Read-only.

- [ ] **Step 1 (failing test):** after a full migrate, `verify($manifest)->complete === true` and state→`verified`; **edit a legacy meta value after detect** → `drift` non-empty, not complete; **delete one migrated sermon (counterpart missing)** → `missing` non-empty even if counts coincidentally balance (seed a duplicate elsewhere to balance counts); a disambiguated-slug term verifies via `LEGACY_SLUG` (no false mismatch); an open per-record failure flag → `complete=false`.
- [ ] Steps 2–5: implement, run → PASS, commit `feat: Verifier (legacy->target completeness + fixity drift oracle)`.

---

### Task 4: Rollback — exact, ordered, idempotent, non-destructive

**Files:** Create `src/Migration/Rollback.php`; Test `tests/Integration/Migration/RollbackTest.php`.
**Interfaces:** `Rollback::pendingDeletions(): array{posts:list<int>, terms:list<int>, comments:list<int>, options:list<string>}`; `Rollback::run(): array{deleted:array, restored:list<string>, warnings:list<string>}`.
**must_handle:** **HARD CONSTRAINT (from B2a fix-10) — native shared-taxonomy counts:** B2a's `SermonWriter::mirrorNativeTaxonomies` inserts native (category/post_tag/custom) `term_relationships` rows directly via `$wpdb` WITHOUT incrementing the shared `wp_term_taxonomy.count` (deferred via `OPTION_MIGRATION_PROGRESS['sermons']['native_term_recount_tt_ids']`). Rollback MUST therefore delete those native relationship rows **directly via `$wpdb`** (`object_id=$newId, term_taxonomy_id=$ttId`), **NOT** via `wp_delete_post`/`wp_set_object_terms` — which would fire `wp_update_term_count` and DECREMENT the church's own shared term counts below their true value. After the direct deletes, recount the affected tt_ids once (`wp_update_term_count_now`). Then proceed with the rest:
delete in strict order — (a) migration-made **posts** `wp_delete_post($id, true)` (force, cascades relationships/comments) enumerated by `Crosswalk::allMigratedPostIds()` PLUS a sweep of un-stamped `sermonator_sermon`/`sermonator_podcast` posts as suspected partial-migration residue (NOTE: before the force-delete, strip the directly-inserted native term_relationships per the constraint above so the cascade doesn't move shared counts); (b) orphan **comments** carrying `LEGACY_COMMENT_ID` (in case a cascade missed any); (c) migration-made **terms** `wp_delete_term($id, $tax, ...)` with explicit args (avoid default-term reassignment side effects); (d) **options** — delete the `sermonator_*` options the migration created, restore any overwritten ones from `OPTION_PRE_MIGRATION_BACKUP`. **Refuse** when `phase()==='finalized'`. **Edit-guard:** detect migrated posts modified after creation (compare `post_modified` vs created) and surface in `warnings` before deleting; require a matching LIVE legacy source for each (`Crosswalk` back-ref → legacy id still `get_post`-able) so a cloned/native post carrying a stray back-ref is NOT deleted. Idempotent/resumable (a re-run completes cleanly). After run, state→`detected`, and **zero records carry any back-ref**. Legacy data byte-for-byte unchanged throughout.

- [ ] **Step 1 (failing test):** after a full migrate, `run()` removes stamped sermons+podcasts+terms+comments, restores the backed-up option, deletes the migration-created option; an un-stamped partial-orphan post is swept; an orphaned comment row removed via back-ref; an interrupted rollback re-run completes idempotently; post-rollback a query finds **zero** `LEGACY_POST_ID`/`LEGACY_TERM_ID`/`LEGACY_COMMENT_ID` rows; an admin-edited migrated post appears in `warnings`; a native post carrying a stray back-ref but no live legacy source is NOT deleted; **legacy posts/terms/comments/options snapshot byte-equal before/after**.
- [ ] Steps 2–5: implement, run → PASS, commit `feat: Rollback (exact, ordered, idempotent, non-destructive)`.

---

### Task 5: Finalizer — the sole gated destructive step

**Files:** Create `src/Migration/Finalizer.php`; Test `tests/Integration/Migration/FinalizerTest.php`.
**Interfaces:** `Finalizer::run(bool $confirmed = false): array{deleted:array, stripped:int, refused:?string}`; `Finalizer::stripAllowlist(): list<string>` (= `Crosswalk::strippableBackRefs()`).
**must_handle:** **Hard-refuse** unless `MigrationState::phase()==='verified'` AND a **fresh** `Verifier`-style drift rescan still matches the manifest (no drift since verification) AND `$confirmed===true`; otherwise return `refused` with the reason, deleting nothing. Delete legacy data **per verified counterpart only** — for each legacy id whose counterpart was field-by-field verified, delete the legacy post (`wp_delete_post(true)`), its terms (only legacy terms that were migrated and are now unreferenced — be conservative; deleting legacy terms may be deferred/optional, document the choice), legacy options/artwork option that were migrated; **never** gate a destructive delete on cardinality equality. Strip ONLY `stripAllowlist()` back-refs from the migrated records — **never** `Crosswalk::LEGACY_POST_CONTENT` (preserved divergent body) and never a `MIGRATION_FLAGS` row that still carries an unresolved divergence flag (only strip after divergence flags are human-cleared). After success, state→`finalized`. Irreversible — this is the point of no return.

- [ ] **Step 1 (failing test):** Finalize **refuses** when state≠`verified`; refuses when a fresh rescan shows drift; refuses without `confirmed=true`; a never-migrated legacy id (counterpart missing) is **NOT** deleted; on success the verified legacy sermons are gone, `MIGRATION_COMPLETE`/`LEGACY_POST_ID` stripped, but `_sermonator_legacy_post_content` **survives**; a post carrying an unresolved `post_content_divergence` flag **blocks** stripping that flag row; state→`finalized`; after finalize, Rollback refuses.
- [ ] Steps 2–5: implement, run → PASS, commit `feat: Finalizer (verified+drift-gated, allowlist strip preserving content)`.

---

### Task 6: WP-CLI commands + Plugin wiring

**Files:** Create `src/Cli/MigrationCommand.php`; Modify `src/Plugin.php`; Test `tests/Integration/Migration/CliTest.php`.
**Interfaces:** `Sermonator\Cli\MigrationCommand` with subcommands `detect`, `migrate`, `verify`, `rollback`, `finalize`, `status` — thin wrappers over `Orchestrator`/`Verifier`/`Rollback`/`Finalizer`. `Plugin::boot()` registers it via `WP_CLI::add_command('sermonator migration', ...)` only when `defined('WP_CLI') && WP_CLI`.
**must_handle:** thin — NO migration logic in the CLI (delegate to the gated services so all adversarial protections apply identically); `migrate` honors `--batch-size` and loops `Orchestrator::run` to completion with progress output; **destructive** commands `rollback`/`finalize` require `--yes` (or interactive confirm) and **print the exact id set first**; acquire the same advisory lock; `status` prints `MigrationState::phase()` + open flags; register only under WP_CLI.
**Test approach (no real `WP_CLI` runner needed):** test the command class methods directly (instantiate `MigrationCommand`, call `migrate([], ['batch-size'=>50])` etc.) asserting they invoke the services and that `finalize` without `--yes` aborts (returns/raises without finalizing) while `finalize([], ['yes'=>1])` runs only when state is `verified`. Stub `WP_CLI::` static calls via a tiny shim or guard so the methods run under PHPUnit.

- [ ] **Step 1 (failing test):** `migrate` invokes Orchestrator with the batch size and reports counts; `finalize` without `--yes` aborts (nothing finalized); `finalize` with confirmation runs only when state==`verified`; `rollback` prints pending deletions before acting; `status` reports the phase + open flags.
- [ ] Steps 2–5: implement, run → PASS, commit `feat: WP-CLI migration commands (thin, gated, confirm-guarded)`.

---

### Task 7: End-to-end full-cycle integration test

**Files:** Modify `tests/Integration/Support/LegacyFixture.php` (richest dataset); Create `tests/Integration/Migration/EndToEndTest.php`.
**Fixture additions:** `createTerm` with description + explicit slug; `seedArtwork(array $ttIdToAttachment)`; `seedComments(int $postId, threaded)`; a sermon with serialized array meta + a custom/unknown meta key; a sermon whose body lives ONLY in `post_content_temp`; a sermon with a non-numeric `sermon_date` string; orphan + shared terms; a podcast with taxonomy-filter settings; `sermonmanager_*` options incl. one with a pre-existing `sermonator_*` native value to back up.
**must_handle (the data-preservation bar, end-to-end):**
- snapshot ALL legacy rows (posts, meta, terms, term relationships, options, attachments, comments) before; run `detect → run* (to migrated) → verify (assert complete, state verified)`; assert **ZERO legacy mutation** (snapshot byte-equal) up to Finalize;
- field-by-field continuity through the crosswalk: counts per taxonomy = manifest; term name/slug/description match (or `LEGACY_SLUG` for collisions); artwork old tt_id→new tt_id→same attachment; serialized meta arrays intact on target; comments threaded with new parents; **temp-only body preserved + flagged**; **non-numeric date normalized alongside untouched raw**;
- **idempotent full re-run is a clean no-op** (no duplicate posts/terms/comments/meta/options);
- **rollback path** → pristine start (zero back-refs, restored options, legacy untouched);
- **finalize path** → refuses until `verified`, then deletes only verified legacy records, preserves `_sermonator_legacy_post_content`, state `finalized`;
- **drift oracle** catches a post-detect legacy edit;
- **crash-injection** mid-sermon-batch then resume → no duplicate sermons/comments.

- [ ] **Step 1 (failing test):** write `EndToEndTest` exercising the above as distinct test methods (happy-path continuity; idempotent re-run; rollback-to-pristine; finalize-gated; drift-detected; crash-resume), each with before/after legacy snapshots.
- [ ] Steps 2–5: extend `LegacyFixture`, implement the test, run the focused e2e + the WHOLE integration suite + `composer test:unit` → all PASS pristine, commit `test: full-cycle migration end-to-end (continuity, idempotent, rollback, finalize, drift, resume)`.

---

## Self-Review

**Coverage vs design notes B2b (items 16–21):** MigrationState+Orchestrator(16)→T1+T2; Verifier(17)→T3; Rollback(18)→T4; Finalizer(19)→T5; CLI(20)→T6; e2e(21)→T7. ✓
**Closed holes mapped:** term-before-sermon precondition + concurrency lock + chunked-resume→T2; verifier legacy→target direction + drift oracle + slug-via-LEGACY_SLUG→T3; rollback residue (comments+partial-orphans) + ordering + option-restore + edit-guard→T4; premature-finalize + allowlist-preserving-content→T5. ✓
**Irreversible operations** (Orchestrator phase-commit, Rollback, Finalizer) each tested for their gates; Finalize is the documented point of no return.
**Placeholder note:** integration tasks give complete TEST contracts + exact interfaces + the closed-hole must_handle; implementers TDD the WordPress plumbing against the tests.
