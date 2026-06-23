# b2a-fix6: Data-Integrity Fixes Report

## Summary

Four data-integrity TDD fixes implemented on branch `worktree-agent-a0304ae1892268728` (rebased on `migration-b2`).

---

## Tests Added

### PodcastOptionWriterTest.php (Fix 1)

| Test method | Purpose |
|---|---|
| `test_option_embedded_term_id_remapped_to_new_term_id` | Verifies that `sermonmanager_default_series` (a legacy term id) is translated to the new term id via TermCrosswalk after TermWriter has run. |
| `test_option_embedded_term_id_unresolvable_left_verbatim_and_flagged` | Verifies that an unresolvable term id is left verbatim AND `missing_option_id_crosswalk:sermonator_default_series` flag is recorded in `OPTION_MIGRATION_PROGRESS['options']['option_id_flags']`. |

### SermonWriterTermsCommentsTest.php (Fixes 2, 3, 4)

| Test method | Purpose |
|---|---|
| `test_complete_with_open_comment_failure_self_heals_on_rewrite` | Verifies that a COMPLETE record carrying a `comment_copy_failed:*` flag self-heals on the next write() call — the COMPLETE branch now runs `copyComments()` and clears the flag. |
| `test_comment_orphan_distinct_parents_not_cross_adopted` | Verifies that two legacy comments sharing date/email/content but differing in parent are NOT cross-adopted when crash orphans exist. OrphanForB (inserted first, lower ID, parent=99) must NOT be adopted for legacy comment A (parent=0). |
| `test_mirror_native_taxonomies_legacy_relationships_unchanged` | Documents the count-mutation contract: legacy `term_relationships` rows are read-only (byte-equal before/after), the new sermon has the native taxonomy mirrored, and `wp_term_taxonomy.count` increments (intentional, reversible derived-value mutation). |

---

## Production Code Changes

### `src/Migration/OptionWriter.php` (Fix 1)

- Added class-level constant `TERM_ID_OPTIONS = ['sermonator_default_series', 'sermonator_default_preacher']` listing known id-bearing scalar options.
- Added method `remapEmbeddedIds(string $optionName, mixed $value, TermCrosswalk $crosswalk): array{value, flags}` that translates legacy term ids via `TermCrosswalk::newTermId()`. Unresolvable ids are left verbatim; a `missing_option_id_crosswalk:<optionName>` flag is returned.
- Added method `recordOptionFlags(list<string> $flags)` that writes to `OPTION_MIGRATION_PROGRESS['options']['option_id_flags']` via `updateProgress()`.
- Updated `migrate()` to instantiate `TermCrosswalk`, call `remapEmbeddedIds()` for each mapped option, and persist accumulated flags via `recordOptionFlags()`.
- Updated class docblock to document the id-bearing options discipline and flag pattern.

### `src/Migration/SermonWriter.php` (Fixes 2, 3, 4)

**Fix 2 — COMPLETE branch comment self-heal** (lines ~111-132 in COMPLETE branch):
- Added `if ($this->hasOpenCommentFailureFlag($flags))` block in the COMPLETE branch, after the existing term self-heal block.
- Strips stale `comment_copy_failed:*` flags, re-runs `copyComments()`, sets `$touched = true`.
- Changed the `if ($touched)` block to also call `markCompleteUnlessCommentFailureOpen()` after `writeFlags()`, so COMPLETE is correctly withheld if the comment copy still fails, or re-stamped if it succeeds.

**Fix 3 — Tightened `commentIdentitySignature()`** (method around line ~1070):
- Extended signature from 3 fields (date_gmt + email + content hash) to 7 fields: adds `comment_parent`, `comment_type`, `comment_approved`, `user_id`.
- Added comprehensive docblock explaining the original 3-field weakness and the fix.
- In `reconcileOrphanComments()`: before the adoption loop, added `$legacySigCount` map counting how many unmapped legacy comments share each signature.
- Added ambiguity guard in the adoption loop: when `count($orphansBySig[$sig]) > 1 || $legacySigCount[$sig] > 1`, skips adoption (falls through to fresh insert) rather than silently cross-adopting.

**Fix 4 — `mirrorNativeTaxonomies()` docblock** (method around line ~708):
- Added detailed "COUNT MUTATION CONTRACT" section to the docblock explaining that `wp_set_object_terms()` triggers `wp_update_term_count()` (incrementing `wp_term_taxonomy.count` before Finalize), that this is intentional and reversible, and references the `TODO(B2b)` to defer native-taxonomy assignment to the Finalize step for strict count-immutability.

---

## Testing Infrastructure

Created `tests/bootstrap-integration.php` in the worktree (overrides the main-repo bootstrap) with a prepended `spl_autoload_register()` that loads `Sermonator\` classes from the worktree's `src/` directory, ensuring the worktree's modified source files are used during test runs while still using the main repo's `vendor/` and WP test framework.

---

## Final Test Counts

| Suite | Tests | Assertions |
|---|---|---|
| Integration | 181 | 802 |
| Unit | 79 | 190 |
| **Total** | **260** | **992** |

All tests GREEN.

---

## Judgment Calls

1. **Fix 1 scope**: Only `sermonator_default_series` and `sermonator_default_preacher` are in `TERM_ID_OPTIONS`. The task description says these are the "main id-bearing scalar options" — attachment ids are shared globals and excluded. The `sermonator_default_podcast` option is handled separately via `migrateDefaultPodcast()` (post crosswalk), not the term crosswalk.

2. **Fix 1 flag semantics**: The `option_id_flags` list in `OPTION_MIGRATION_PROGRESS['options']` accumulates flags from the current run (not de-duplicated across runs since `recordOptionFlags` does `array_unique` per call, but a second run would replace the prior list via `updateProgress`). This is intentional: a second `migrate()` run after TermWriter produces a fresh, accurate flag list.

3. **Fix 2 COMPLETE re-stamp**: After the comment self-heal in the COMPLETE branch, `markCompleteUnlessCommentFailureOpen()` is called. If `copyComments()` still fails (e.g. db error), COMPLETE is withheld — the next write() will fall to the resume path (not COMPLETE), which will retry. This is correct per the "never skip-forever" contract.

4. **Fix 3 ambiguity guard**: The guard refuses adoption for ANY signature that has >1 orphans OR >1 legacy comments, even if the tightened signature alone would disambiguate. This is conservative but safe — a false-negative (refusing a valid adoption) results in a fresh insert, not data loss. Fresh inserts are idempotent on re-runs via the crash-orphan reconciler.

5. **Fix 4 as documentation-only**: No behavioral change — `mirrorNativeTaxonomies()` already works correctly. The fix is purely a docblock + TODO. The test (`test_mirror_native_taxonomies_legacy_relationships_unchanged`) passes with existing code and documents the expected behavior (including the intentional count increment).

6. **Test bootstrap override**: Using a worktree-specific bootstrap with a prepended autoloader was the cleanest way to run tests against the worktree's modified src/ files while using the main repo's vendor/. This bootstrap is specific to this worktree and will not affect the main repo's test runs.
