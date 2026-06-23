# B2a Fix 10 Report

**Date:** 2026-06-23  
**Branch:** `migration-b2`  
**Tests:** 14 new integration tests in `B2aFix10Test.php`  
**All tests:** 292 total (213 integration + 79 unit) — all GREEN

---

## Overview

Applied 4 targeted fixes to `PodcastWriter.php` and `SermonWriter.php` using strict TDD (failing test first → fix → green). All fixes are idempotent and crash-safe.

---

## Fix 1: PodcastWriter `applyScopedSettingsRemap()` — empty legacy read must NOT wipe migrated settings

### Bug
`applyScopedSettingsRemap()` called `delete_post_meta($newId, META_PODCAST_SETTINGS)` unconditionally **before** checking whether `$values` was empty. When a legacy podcast post had no `sm_podcast_settings` meta (e.g., settings stored elsewhere or absent), the COMPLETE-branch self-heal silently wiped the already-migrated `sermonator_podcast_settings` row. The re-add loop never fired because `$values === []`.

### Impact
Any subsequent COMPLETE-branch pass (triggered by a resume or a re-run on an already-complete record) on a legacy podcast with empty/absent `sm_podcast_settings` would delete the migrated iTunes metadata (category, author, term filters). Silent data loss with no flag emitted.

### Fix
Added a guard before `delete_post_meta`:
```php
if ( $values === array() ) {
    return array_values( array_unique( $flags ) );
}
```
When `$values` is empty, the method is a complete NO-OP — skips both the delete and the re-add. This is asymmetric-by-design with `applyMeta()`, which only deletes per target key when the legacy source key is actually present.

### Tests (2 cases, both confirmed RED before fix)
- `test_fix1_complete_branch_selfheal_with_empty_legacy_settings_leaves_migrated_row_intact` — legacy has no `sm_podcast_settings`; migrated `sermonator_podcast_settings` must survive
- `test_fix1_complete_branch_selfheal_with_absent_legacy_settings_is_noop` — same scenario with key entirely absent

---

## Fix 2: Force-preserve `post_date`, `post_date_gmt`, AND `post_status` after insert in BOTH writers

### Bug
`preserveModifiedTimestamps()` in both `SermonWriter` and `PodcastWriter` only force-preserved `post_modified[_gmt]` via direct `$wpdb->update`. Two other categories of silent rewrite were not handled:

1. **TZ mismatch:** `wp_insert_post` recomputes `post_date_gmt` from `post_date + site_timezone` using `get_gmt_from_date()`. When the legacy church's timezone differed from the current site timezone, the migrated `post_date_gmt` would be off by the TZ delta.

2. **Past-future status flip:** A legacy post with `post_status='future'` and `post_date` in the past is silently flipped to `post_status='publish'` by `wp_insert_post` (via `wp_check_post_lock` logic in `wp_transition_post_status`). This corrupts the migrated status.

### Fix
Extended `preserveModifiedTimestamps()` in both writers to also restore `post_date`, `post_date_gmt`, and `post_status` via the same direct `$wpdb->update` path:

```php
$wpdb->update(
    $wpdb->posts,
    array(
        'post_modified'     => $legacy->post_modified,
        'post_modified_gmt' => $legacy->post_modified_gmt,
        'post_date'         => $legacy->post_date,
        'post_date_gmt'     => $legacy->post_date_gmt,
        'post_status'       => $legacy->post_status,
    ),
    array( 'ID' => $newId )
);
```

The idempotency guard was extended to check all five columns. The method was replaced with a comprehensive docblock naming all three problem categories (modified-stamp, TZ-recompute, past-future flip).

### Tests (6 cases, 2 confirmed RED before fix)
- `test_fix2_sermon_post_date_gmt_preserved_despite_tz_mismatch` — sets site TZ to UTC, legacy has 5h offset baked into post_date_gmt; force-preserve wins
- `test_fix2_sermon_draft_post_date_and_gmt_both_preserved` — draft with zero GMT sentinel
- `test_fix2_sermon_past_future_post_date_and_status_preserved` — **confirmed RED before fix** — WP flipped 'future' to 'publish'; force-preserve restores 'future'
- `test_fix2_sermon_private_post_date_gmt_preserved` — private sermon round-trip
- `test_fix2_podcast_post_date_gmt_preserved_despite_tz_mismatch` — same TZ scenario for PodcastWriter
- `test_fix2_podcast_past_future_post_date_and_status_preserved` — **confirmed RED before fix** — past-future podcast status preserved

---

## Fix 3: Add docblock + contract test for `mirrorNativeTaxonomies()` B2b Rollback constraint

### Status
`mirrorNativeTaxonomies()` already correctly implements the deferred-recount pattern. No code bug existed. This fix is documentation only.

### Change
Added a comprehensive docblock above `mirrorNativeTaxonomies()` in `SermonWriter.php` that explicitly states:

```
HARD CONSTRAINT for B2b Rollback:
  This method MUST NOT call wp_set_object_terms() or wp_update_term_count().
  Both of those functions increment wp_term_taxonomy.count immediately, which
  is a SHARED counter (not per-site, not per-migration). Any increment before
  B2b Finalize fires would corrupt the live site's term count — even if the
  migration is later rolled back, the increment cannot be undone retroactively.
  ...
  B2b Rollback MUST call wp_update_term_count() on the same tt_id list in reverse
  (to decrement counts back to their pre-migration value) before removing the
  term_relationship rows.
```

### Tests (3 contract tests — all GREEN before and after)
- `test_fix3_mirror_native_taxonomies_does_not_call_wp_set_object_terms` — verifies count isolation
- `test_fix3_native_term_recount_tt_ids_recorded_in_migration_progress` — verifies deferred-recount list persists
- `test_fix3_native_term_recount_tt_ids_deduplicated_across_runs` — verifies union semantics on re-run

---

## Fix 4: PodcastWriter orphan-adoption must call `purgeOrphanMeta()` like SermonWriter

### Bug
In the orphan-adoption branch of `PodcastWriter::write()`, after `Crosswalk::markLegacy()`, the call to `applyPostInsertSpine()` proceeded without first purging stale meta from the orphan. If the crash-orphan had meta keys from a previous (partial or differently-keyed) migration attempt, those keys would persist after adoption — even if absent from the current legacy podcast source. SermonWriter had a `purgeOrphanMeta()` call in its equivalent branch; PodcastWriter lacked both the method and the call.

### Fix

**Added `purgeOrphanMeta()` method to PodcastWriter:**
Mirrors `SermonWriter::purgeOrphanMeta()`. Iterates the orphan's current meta keys and deletes any key that is:
- NOT a Crosswalk own-key (`LEGACY_POST_ID`, `LEGACY_SLUG`, `MIGRATION_COMPLETE`, `MIGRATION_FLAGS`, `LEGACY_POST_CONTENT`), AND  
- NOT a target key that `applyMeta()` will re-write from the legacy source

The rename logic (`sm_podcast_settings` → `sermonator_podcast_settings`) is correctly handled: when computing target keys from the legacy source, the renamed key (`sermonator_podcast_settings`) is included, not the legacy key.

**Wired up in the orphan-adoption branch:**
```php
$orphanId = $this->findBackRefLessPostByLegacyIdentity( $legacy );
if ( null !== $orphanId ) {
    Crosswalk::markLegacy( $orphanId, $legacyId );
    $this->purgeOrphanMeta( $orphanId, $legacyId );  // FIX 4
    $flags = $this->applyPostInsertSpine( $orphanId, $legacyId, $this->readFlags( $orphanId ) );
    $this->markCompleteUnlessTermCrosswalkOpen( $orphanId, $flags );
    return new WriteResult( $orphanId, false, $flags, true );
}
```

### Tests (3 cases, 2 confirmed RED before fix)
- `test_fix4_podcast_orphan_adoption_purges_stale_meta_key` — **RED before fix** — stale `old_meta_key` not purged
- `test_fix4_podcast_orphan_adoption_retains_crosswalk_own_keys` — **RED before fix** — stale key not purged; verifies own-keys are retained
- `test_fix4_podcast_orphan_no_stale_meta_is_noop` — GREEN before and after — clean orphan is no-op

---

## Files Changed

| File | Change |
|------|--------|
| `src/Migration/PodcastWriter.php` | Fix 1: `applyScopedSettingsRemap()` early return guard; Fix 2: `preserveModifiedTimestamps()` extended to 5 columns; Fix 4: added `purgeOrphanMeta()` method + wired call in orphan-adoption branch |
| `src/Migration/SermonWriter.php` | Fix 2: `preserveModifiedTimestamps()` extended to 5 columns + comprehensive docblock; Fix 3: `mirrorNativeTaxonomies()` docblock with HARD CONSTRAINT |
| `tests/Integration/Migration/B2aFix10Test.php` | 14 new integration tests |

---

## Test Results

| Phase | Count |
|-------|-------|
| RED (pre-fix failures confirmed) | 6 (Fix 1: 2, Fix 2 past-future: 2, Fix 4: 2) |
| GREEN after fix | 14/14 B2aFix10 tests |
| Full suite: integration | 213/213 |
| Full suite: unit | 79/79 |
| **Total** | **292/292** |
