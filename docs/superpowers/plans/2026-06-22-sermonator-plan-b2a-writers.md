# Sermonator — Plan B2a: Migration Writers & Crosswalk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. The authoritative design (with the closed adversarial holes) is `docs/superpowers/specs/2026-06-22-sermonator-b2-design-notes.md` — read it for the "why" behind each `must_handle`.

**Goal:** Build the per-record *writers* of the migration engine — pure helpers (date normalizer, 3-arg reconciler, artwork mapper, shared checksum) plus the crosswalk query/write spine and the Term/Artwork/Sermon/Podcast/Option writers — each able to migrate one entity non-destructively, idempotently, and crash-safely into the `sermonator_*` namespace.

**Architecture:** Extends `Sermonator\Migration\`. Pure classes stay WordPress-free (unit-tested). Writers are integration-tested against wp-env using `LegacyFixture`. The crash-safety spine (back-ref FIRST, `MIGRATION_COMPLETE` LAST, idempotency gate distinguishing complete-vs-partial) is shared across all writers. Nothing alters legacy data.

**Tech Stack:** PHP 8.1+, WP 6.0+, PHPUnit 9.6 (unit pure / integration wp-env), Brain Monkey. No new composer deps. Builds on merged Plan A + B1.

## Global Constraints

- **Data preservation is the highest bar.** Every writer reads legacy READ-ONLY; every integration test snapshots the touched legacy rows before/after and asserts byte-equality. Shared attachment posts are NEVER mutated (referenced by id only). Nothing deletes/alters legacy until Finalize (B2b).
- **Crash-safety spine:** back-ref (`Crosswalk::markLegacy`/`markLegacyTerm`) written FIRST (immediately after insert), `MIGRATION_COMPLETE` flag written LAST. Idempotency gate distinguishes *complete* from *stamped-but-partial* (resume/redo a partial; never skip-forever).
- **Serialized/encoding correctness:** write meta from the per-key UNSERIALIZED `get_post_meta($id,$key,false)` form (core re-serializes). `wp_slash($postarr)`. Disable KSES for inserts (`kses_remove_filters()` / restore). Encoding-harden checksums (`wp_json_encode(..., JSON_INVALID_UTF8_SUBSTITUTE)` and assert non-empty, else `serialize()`).
- **Idempotency:** every writer is re-runnable with zero duplicates (posts, terms, comments, meta rows, options). Multi-value meta applied via delete-then-re-add the full multiset; single-value via replace/unique.
- **Never adopt a native record:** on a `term_exists`/slug collision with a church's own term, create a NEW distinct term (deterministic suffix) and flag — never stamp a back-ref onto a native term.
- **`sermonator_` prefix; no custom DB tables; PHP 8.1+; single-site; GPL-2.0; no monetization.**
- Integration test command: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration` (sequential only). Unit: `composer test:unit`.

## File Structure

```
src/Schema/Identifiers.php                 # MODIFY: add OPTION_* + META_DATE_NORMALIZED constants
src/Migration/Crosswalk.php                # MODIFY: add LEGACY_TERM_TT_ID/LEGACY_COMMENT_ID/MIGRATION_COMPLETE/LEGACY_SLUG/MIGRATION_FLAGS/LEGACY_POST_CONTENT + query/write helpers
src/Migration/DateNormalizer.php           # NEW pure
src/Migration/PostContentReconciler.php    # MODIFY: 3-arg, shortcode/HTML-aware
src/Migration/TermArtworkMapper.php        # NEW pure
src/Migration/LegacyChecksum.php           # NEW; Detector delegates
src/Migration/Detector.php                 # MODIFY: delegate sermonChecksum
src/Migration/TermWriter.php               # NEW integration
src/Migration/TermCrosswalk.php            # NEW integration (reader)
src/Migration/ArtworkWriter.php            # NEW integration
src/Migration/WriteResult.php              # NEW value object
src/Migration/SermonWriter.php             # NEW integration
src/Migration/PodcastWriter.php            # NEW integration
src/Migration/OptionWriter.php             # NEW integration
tests/Integration/Support/LegacyFixture.php # MODIFY: richer fixtures (terms w/ desc+slug, comments, serialized meta, temp-only body, artwork seed)
tests/Unit/Migration/*Test.php  tests/Integration/Migration/*Test.php
```

---

### Task 1: Schema/crosswalk/state constants (pure)

**Files:** Modify `src/Schema/Identifiers.php`, `src/Migration/Crosswalk.php`; Test `tests/Unit/Migration/B2ConstantsTest.php`.

**Interfaces produced:** `Identifiers::OPTION_TERM_IMAGES_SETTINGS='sermonator_term_images_settings'`, `OPTION_MIGRATION_STATE='sermonator_migration_state'`, `OPTION_PRE_MIGRATION_BACKUP='sermonator_pre_migration_backup'`, `OPTION_MIGRATION_PROGRESS='sermonator_migration_progress'`, `META_DATE_NORMALIZED='sermonator_date_normalized'`. `Crosswalk::LEGACY_TERM_TT_ID='_sermonator_legacy_term_tt_id'`, `LEGACY_COMMENT_ID='_sermonator_legacy_comment_id'`, `MIGRATION_COMPLETE='_sermonator_migration_complete'`, `LEGACY_SLUG='_sermonator_legacy_slug'`, `MIGRATION_FLAGS='_sermonator_migration_flags'`, `LEGACY_POST_CONTENT='_sermonator_legacy_post_content'`. Add `Crosswalk::strippableBackRefs(): list<string>` returning ONLY the pure back-refs safe for the Finalizer to strip: `LEGACY_POST_ID, LEGACY_TERM_ID, LEGACY_TERM_TT_ID, LEGACY_COMMENT_ID, MIGRATION_COMPLETE` (NOT `LEGACY_POST_CONTENT`/`MIGRATION_FLAGS`).

- [ ] **Step 1: failing test** — `tests/Unit/Migration/B2ConstantsTest.php`:
```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Migration;
use PHPUnit\Framework\TestCase;
use Sermonator\Schema\Identifiers;
use Sermonator\Migration\Crosswalk;
final class B2ConstantsTest extends TestCase {
    public function test_new_identifier_options(): void {
        $this->assertSame('sermonator_term_images_settings', Identifiers::OPTION_TERM_IMAGES_SETTINGS);
        $this->assertSame('sermonator_migration_state', Identifiers::OPTION_MIGRATION_STATE);
        $this->assertSame('sermonator_pre_migration_backup', Identifiers::OPTION_PRE_MIGRATION_BACKUP);
        $this->assertSame('sermonator_migration_progress', Identifiers::OPTION_MIGRATION_PROGRESS);
        $this->assertSame('sermonator_date_normalized', Identifiers::META_DATE_NORMALIZED);
    }
    public function test_new_crosswalk_keys_hidden_and_distinct(): void {
        foreach ([Crosswalk::LEGACY_TERM_TT_ID, Crosswalk::LEGACY_COMMENT_ID, Crosswalk::MIGRATION_COMPLETE, Crosswalk::LEGACY_SLUG, Crosswalk::MIGRATION_FLAGS, Crosswalk::LEGACY_POST_CONTENT] as $k) {
            $this->assertStringStartsWith('_sermonator_', $k);
        }
        $this->assertNotSame(Crosswalk::LEGACY_TERM_ID, Crosswalk::LEGACY_TERM_TT_ID);
    }
    public function test_strippable_allowlist_excludes_preserved_content(): void {
        $strip = Crosswalk::strippableBackRefs();
        $this->assertContains(Crosswalk::LEGACY_POST_ID, $strip);
        $this->assertContains(Crosswalk::MIGRATION_COMPLETE, $strip);
        $this->assertNotContains(Crosswalk::LEGACY_POST_CONTENT, $strip);
        $this->assertNotContains(Crosswalk::MIGRATION_FLAGS, $strip);
    }
}
```
- [ ] **Step 2:** run `composer test:unit -- --filter B2ConstantsTest` → FAIL.
- [ ] **Step 3:** add the constants to `Identifiers` (after existing OPTION_* consts) and `Crosswalk` (after existing LEGACY_* consts), plus `Crosswalk::strippableBackRefs()`.
- [ ] **Step 4:** run → PASS; `composer test:unit` (no regression).
- [ ] **Step 5:** commit `feat: B2 schema/crosswalk/state constants`.

---

### Task 2: DateNormalizer (pure)

**Files:** Create `src/Migration/DateNormalizer.php`; Test `tests/Unit/Migration/DateNormalizerTest.php`.
**Interface:** `DateNormalizer::normalize(string $raw, ?\DateTimeZone $tz = null): ?int`.
**must_handle:** GATING(2); TZ-anchored parse (no server-TZ drift for date-only strings); garbage→null, never throws; numeric input out of scope (caller decides).

- [ ] **Step 1: failing test:**
```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Migration;
use PHPUnit\Framework\TestCase;
use Sermonator\Migration\DateNormalizer;
final class DateNormalizerTest extends TestCase {
    public function test_parses_iso_date_in_given_tz(): void {
        $utc = DateNormalizer::normalize('2021-05-01', new \DateTimeZone('UTC'));
        $this->assertSame(gmmktime(0,0,0,5,1,2021), $utc);
    }
    public function test_timezone_anchoring_changes_result(): void {
        $utc = DateNormalizer::normalize('2021-05-01', new \DateTimeZone('UTC'));
        $est = DateNormalizer::normalize('2021-05-01', new \DateTimeZone('America/New_York'));
        $this->assertNotSame($utc, $est);            // proves TZ is honored, no server-TZ leak
        $this->assertSame(5 * 3600, $est - $utc);    // EST is UTC-5 for a date-only midnight
    }
    public function test_parses_slash_date(): void {
        $this->assertIsInt(DateNormalizer::normalize('01/05/2021', new \DateTimeZone('UTC')));
    }
    public function test_garbage_returns_null(): void {
        $this->assertNull(DateNormalizer::normalize('not a date', new \DateTimeZone('UTC')));
        $this->assertNull(DateNormalizer::normalize('', new \DateTimeZone('UTC')));
    }
}
```
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement:
```php
<?php
declare(strict_types=1);
namespace Sermonator\Migration;
final class DateNormalizer {
    public static function normalize(string $raw, ?\DateTimeZone $tz = null): ?int {
        $raw = trim($raw);
        if ($raw === '') { return null; }
        $tz = $tz ?? new \DateTimeZone('UTC');
        try {
            $dt = new \DateTimeImmutable($raw, $tz);   // anchors date-only strings to $tz
        } catch (\Exception $e) {
            return null;
        }
        // Reject values DateTimeImmutable accepts as relative junk: require it changed from "now" anchor predictably is hard;
        // instead reject if the input had no digit (covers 'not a date').
        if (!preg_match('/\d/', $raw)) { return null; }
        return $dt->getTimestamp();
    }
    private function __construct() {}
}
```
> Note: `'not a date'` has no digit → null. `new \DateTimeImmutable('not a date')` actually throws, caught above; the digit guard is belt-and-suspenders. Verify both paths in tests.
- [ ] **Step 4:** run → PASS; full unit suite.
- [ ] **Step 5:** commit `feat: timezone-anchored DateNormalizer`.

---

### Task 3: PostContentReconciler → 3-arg, shortcode/HTML-aware (pure)

**Files:** Modify `src/Migration/PostContentReconciler.php`; Modify `tests/Unit/Migration/PostContentReconcilerTest.php` (keep 5 existing, add new).
**Interface:** `reconcile(string $oldPostContent, ?string $description, ?string $postContentTemp = null): array{content:string, backup:?string, flag:bool}`.
**must_handle:** GATING(1) — feed `postContentTemp` through the safety-net; body-only-in-temp caught. Default 3rd arg keeps the 5 existing tests green. Discard a blob only when its visible text is contained AND it has no shortcode token (`/\[[a-z]/i`) and no structural/media HTML (`<iframe|<audio|<video|<img|<script|<embed`) absent from the description — else route to backup+flag. Distinct/ordered backup join (don't double-store identical text from old+temp); single coherent flag. Stay pure.

- [ ] **Step 1: add failing tests** (append to existing test class):
```php
    public function test_body_only_in_post_content_temp_is_preserved(): void {
        $out = \Sermonator\Migration\PostContentReconciler::reconcile('', null, 'Only in temp backup');
        $this->assertSame('', $out['content']);
        $this->assertStringContainsString('Only in temp backup', (string) $out['backup']);
        $this->assertTrue($out['flag']);
    }
    public function test_temp_text_within_description_not_backed_up(): void {
        $out = \Sermonator\Migration\PostContentReconciler::reconcile('', '<p>Full body here</p>', 'Full body here');
        $this->assertNull($out['backup']);
        $this->assertFalse($out['flag']);
    }
    public function test_shortcode_blob_not_discarded_even_if_text_contained(): void {
        $out = \Sermonator\Migration\PostContentReconciler::reconcile('[audio src="x.mp3"]Intro', '<p>Intro</p>', null);
        $this->assertNotNull($out['backup']);   // shortcode carries data the plain text doesn't
        $this->assertTrue($out['flag']);
    }
    public function test_old_and_temp_both_unique_single_flag_distinct_backup(): void {
        $out = \Sermonator\Migration\PostContentReconciler::reconcile('Alpha unique', 'desc', 'Beta unique');
        $this->assertStringContainsString('Alpha unique', (string) $out['backup']);
        $this->assertStringContainsString('Beta unique', (string) $out['backup']);
        $this->assertTrue($out['flag']);
    }
```
- [ ] **Step 2:** run → the 4 new FAIL (3rd arg / shortcode logic), the 5 existing still pass with the default arg once implemented.
- [ ] **Step 3:** implement — change signature to add `?string $postContentTemp = null`; collect unique substantive pieces from BOTH `$oldPostContent` and `$postContentTemp` via the existing containment check **augmented** with `hasStructuralPayload($blob, $description)` (true if blob contains a shortcode token or media/structural HTML tag not present in the description's visible text); join unique pieces with `"\n\n"`, dedupe identical visible text; `flag = backup !== null`. Keep `stripTags()`/`visibleText()`. Add:
```php
    private static function isUniqueSubstantive(string $blob, string $description): bool {
        $blob = trim($blob);
        if ($blob === '') { return false; }
        if (self::hasStructuralPayload($blob, $description)) { return true; }
        return !str_contains(self::visibleText($description), self::visibleText($blob));
    }
    private static function hasStructuralPayload(string $blob, string $description): bool {
        $hasShortcode = (bool) preg_match('/\[[a-z][a-z0-9_\-]*[\s\]]/i', $blob);
        $hasMediaHtml = (bool) preg_match('/<(iframe|audio|video|img|script|embed|object)\b/i', $blob);
        return $hasShortcode || $hasMediaHtml;   // such payload is never "contained" in plain description text
    }
```
Rewrite `reconcile()` to: `content = $description ?? ''`; gather `$pieces = []`; for each of `[$oldPostContent, $postContentTemp]` that is a non-null string and `isUniqueSubstantive`, and whose visible text isn't already in `$pieces`, push the original blob; `backup = $pieces ? implode("\n\n", $pieces) : null`; `flag = $pieces !== []`.
- [ ] **Step 4:** run all PostContentReconciler tests → PASS (5 existing + 4 new); full unit suite.
- [ ] **Step 5:** commit `feat: 3-arg shortcode/HTML-aware PostContentReconciler (post_content_temp safety-net)`.

---

### Task 4: TermArtworkMapper (pure)

**Files:** Create `src/Migration/TermArtworkMapper.php`; Test `tests/Unit/Migration/TermArtworkMapperTest.php`.
**Interfaces:** `remapImages(array $legacyImages, array $ttIdCrosswalk): array{images:array<int,int>, dropped:list<int>, conflicts:list<int>}`; `remapSettings(array $legacySettings): array<string,mixed>`.
**must_handle:** (int)-cast BOTH legacyImages keys and crosswalk keys before lookup (numeric-string keys resolve, not drop); new-tt_id collision → `conflicts` (never overwrite); attachment_id verbatim; `remapSettings` re-keys taxonomy-name keys via `MappingContract::taxonomyMap()` and PASSES THROUGH all other (global) keys verbatim.

- [ ] **Step 1: failing test:**
```php
<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Migration;
use PHPUnit\Framework\TestCase;
use Sermonator\Migration\TermArtworkMapper;
final class TermArtworkMapperTest extends TestCase {
    public function test_remaps_tt_ids_attachment_verbatim(): void {
        $out = TermArtworkMapper::remapImages([12 => 500, 13 => 501], [12 => 900, 13 => 901]);
        $this->assertSame([900 => 500, 901 => 501], $out['images']);
        $this->assertSame([], $out['dropped']);
    }
    public function test_numeric_string_keys_resolve(): void {
        $out = TermArtworkMapper::remapImages(['12' => 500], [12 => 900]);
        $this->assertSame([900 => 500], $out['images']);
    }
    public function test_missing_crosswalk_dropped(): void {
        $out = TermArtworkMapper::remapImages([99 => 500], [12 => 900]);
        $this->assertSame([], $out['images']);
        $this->assertSame([99], $out['dropped']);
    }
    public function test_collision_recorded_not_overwritten(): void {
        $out = TermArtworkMapper::remapImages([12 => 500, 13 => 600], [12 => 900, 13 => 900]);
        $this->assertContains(900, $out['conflicts']);
    }
    public function test_settings_remap_taxonomy_keys_passthrough_globals(): void {
        $out = TermArtworkMapper::remapSettings(['wpfc_sermon_series' => 1, 'image_size' => 'medium']);
        $this->assertArrayHasKey('sermonator_series', $out);
        $this->assertSame(1, $out['sermonator_series']);
        $this->assertSame('medium', $out['image_size']);   // global passes through
    }
}
```
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement (pure; `remapImages` int-casts keys, builds new map, records dropped/conflicts; `remapSettings` uses `MappingContract::taxonomyMap()` to re-key matching top-level keys, else verbatim).
- [ ] **Step 4:** run → PASS; full unit suite.
- [ ] **Step 5:** commit `feat: pure TermArtworkMapper (tt_id remap + settings remap)`.

---

### Task 5: LegacyChecksum + Detector delegation (integration)

**Files:** Create `src/Migration/LegacyChecksum.php`; Modify `src/Migration/Detector.php`; Test `tests/Integration/Migration/LegacyChecksumTest.php`.
**Interface:** `LegacyChecksum::forPost(int $legacyId): string`.
**must_handle:** GATING(3) — exact `md5($post->post_content . $encoded)` where `$encoded` = encode of `ksort`'d raw `get_post_meta($id)`; encoding-hardened (`wp_json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE)`, assert non-empty, else `serialize($meta)`); Detector's `sermonChecksum` delegates so the two cannot drift; Detector's existing checksum tests remain the regression guard.

- [ ] **Step 1: failing test** asserting `LegacyChecksum::forPost($id)` equals Detector's checksum for the same fixture sermon (capture Detector's value via a Manifest before refactor), changing a meta value changes the hash, and invalid-UTF-8 meta still contributes (changing it changes the hash). Use `LegacyFixture` to create the sermon.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** extract Detector's inlined `sermonChecksum` body verbatim into `LegacyChecksum::forPost`, add the encoding hardening; make `Detector::sermonChecksum` call `LegacyChecksum::forPost`.
- [ ] **Step 4:** run new test + `--filter DetectorTest` (regression) → PASS; unit suite.
- [ ] **Step 5:** commit `feat: shared LegacyChecksum (encoding-hardened); Detector delegates`.

---

### Task 6: Crosswalk post query/write helpers (integration)

**Files:** Modify `src/Migration/Crosswalk.php`; Test `tests/Integration/Migration/CrosswalkPostTest.php`.
**Interfaces:** `Crosswalk::findNewByLegacyId(int $legacyPostId, string $postType = Identifiers::POST_TYPE_SERMON): ?int`; `markLegacy(int $newPostId, int $legacyPostId): void`; `migratedPostIds(string $postType = Identifiers::POST_TYPE_SERMON): list<int>`; `allMigratedPostIds(): list<int>`.
**must_handle:** resolve by **postmeta authoritatively, status-agnostic** (must find trashed/auto-draft so a re-run doesn't duplicate); `findNewByLegacyId` asserts at-most-one (loud — e.g. `error_log` + return the lowest id — on >1); `markLegacy` uses `add_post_meta(..., true)` (single row); `allMigratedPostIds()` spans sermon AND podcast post types.

- [ ] **Step 1: failing test** — `tests/Integration/Migration/CrosswalkPostTest.php` (WP_UnitTestCase): markLegacy→findNewByLegacyId round-trip; absent→null; a stamped post moved to `trash` is STILL found (no duplicate on re-run); markLegacy writes exactly one back-ref row; `migratedPostIds` excludes natively-authored posts; `allMigratedPostIds` includes a stamped podcast. (Implementer registers target schema via `Registrar` in setUp; uses real `sermonator_sermon`/`sermonator_podcast` posts + `add_post_meta(Crosswalk::LEGACY_POST_ID,...)`.)
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement using a direct `$wpdb` postmeta query (status-agnostic) for `findNewByLegacyId`/`migratedPostIds`/`allMigratedPostIds`, joined to `$wpdb->posts` on post_type; `markLegacy` via `add_post_meta($newPostId, self::LEGACY_POST_ID, $legacyPostId, true)`.
- [ ] **Step 4:** run → PASS; unit + integration suites.
- [ ] **Step 5:** commit `feat: Crosswalk post query/write helpers (status-agnostic, dup-safe)`.

---

### Task 7: Crosswalk term query/write helpers (integration)

**Files:** Modify `src/Migration/Crosswalk.php`; Test `tests/Integration/Migration/CrosswalkTermTest.php`.
**Interfaces:** `findNewTermByLegacyId(int $legacyTermId, string $targetTaxonomy): ?int` (taxonomy-aware); `markLegacyTerm(int $newTermId, int $legacyTermId, int $legacyTtId): void` (writes BOTH `LEGACY_TERM_ID` + `LEGACY_TERM_TT_ID`).
**must_handle:** taxonomy-aware lookup (resolved id guaranteed in `$targetTaxonomy` — query joins `term_taxonomy` on taxonomy); idempotency probe **bypasses stale term cache** (`$wpdb` direct or `clean_term_cache` before probe); unknown id → null.

- [ ] **Step 1: failing test:** markLegacyTerm→findNewTermByLegacyId round-trips within the correct taxonomy; duplicate-named terms in different taxonomies resolve independently; both back-ref metas present; a freshly-inserted term is found by the very next probe (no stale-cache miss).
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement via `$wpdb` querying `termmeta` (key=LEGACY_TERM_ID, value=legacyTermId) joined to `term_taxonomy` filtered by `$targetTaxonomy`; `markLegacyTerm` writes both term metas with `add_term_meta(..., true)`.
- [ ] **Step 4:** run → PASS; suites.
- [ ] **Step 5:** commit `feat: Crosswalk term helpers (taxonomy-aware, cache-safe)`.

---

### Task 8: TermWriter::migrateTerm (integration)

**Files:** Create `src/Migration/TermWriter.php`; Test `tests/Integration/Migration/TermWriterMigrateTermTest.php`.
**Interface:** `TermWriter::migrateTerm(string $legacyTaxonomy, \WP_Term $legacyTerm): int`.
**must_handle:** idempotent via cache-safe back-ref probe; `wp_insert_term` into `MappingContract::taxonomyMap()[$legacyTaxonomy]` copying name/slug/description; on `term_exists` collision with a NATIVE term → create a NEW term with a DETERMINISTIC suffix (`$slug.'-legacy-'.$legacyTerm->term_id`), record `slug_collision` flag, **never** add a back-ref to the native term; assert `!is_wp_error($res)` BEFORE `add_term_meta`; record `LEGACY_SLUG` (original slug) on the new term; write back-refs immediately after a confirmed insert.

- [ ] **Step 1: failing test:** created term name/slug/description match; both back-ref metas + `LEGACY_SLUG` present; second call idempotent (same id, term count unchanged); a pre-existing NATIVE term with same name+slug → a NEW distinct term is created (deterministic slug), the native term is untouched (no back-ref on it); re-run after collision computes the same deterministic slug (no duplicate).
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement per must_handle (use `term_exists($slug, $targetTax)` / `wp_insert_term` return; on `WP_Error` `term_exists` data, branch to deterministic-slug insert; `is_wp_error` guard before `Crosswalk::markLegacyTerm` + `add_term_meta(LEGACY_SLUG)`; store flags via a private array returned/loggable).
- [ ] **Step 4:** run → PASS; suites.
- [ ] **Step 5:** commit `feat: TermWriter::migrateTerm (collision-safe, never adopts native term)`.

---

### Task 9: TermWriter::migrateAll (integration)

**Files:** Modify `src/Migration/TermWriter.php`; Test `tests/Integration/Migration/TermWriterMigrateAllTest.php`.
**Interface:** `TermWriter::migrateAll(): array{migrated:int, skipped:int, flags:list<string>}`.
**must_handle:** iterate all 5 legacy taxonomies `get_terms(['taxonomy'=>$t,'hide_empty'=>false])` in `LegacyIdentifiers::sermonTaxonomies()` order (orphans migrate); **hard uniqueness guard** — after each insert re-query the back-ref and throw/flag a reconciliation error if any legacy term_id maps to >1 new term; resumable (re-run skips crosswalked terms, zero dupes); legacy terms byte-for-byte unchanged.

- [ ] **Step 1: failing test:** orphan term IS migrated; per-taxonomy target counts equal legacy counts; full re-run reports all skipped + zero duplicates; legacy terms snapshot byte-equal before/after; an injected duplicate back-ref makes `migrateAll` raise the uniqueness error.
- [ ] **Step 2:** run → FAIL.  **Step 3:** implement.  **Step 4:** run → PASS; suites.  **Step 5:** commit `feat: TermWriter::migrateAll (orphans, uniqueness guard, resumable)`.

---

### Task 10: TermCrosswalk reader (integration)

**Files:** Create `src/Migration/TermCrosswalk.php`; Test `tests/Integration/Migration/TermCrosswalkTest.php`.
**Interfaces:** `newTermId(int $legacyTermId): ?int` (deterministic lowest-id, flags >1); `ttIdMap(): array<int,int>` (legacy tt_id → NEW tt_id from each migrated term's CURRENT `term_taxonomy_id`).
**must_handle:** `newTermId` pins a single deterministic result (lowest new term_id), flags the >1 case rather than picking arbitrarily; `ttIdMap` reads NEW tt_id from `term_taxonomy_id` (not term_id) keyed by the stored `LEGACY_TERM_TT_ID`; null for unmigrated; keep reader separate from the pure mapper.

- [ ] Standard TDD steps (failing test: after `migrateAll`, `newTermId` resolves a known legacy term_id to the correct new term_id; unmigrated→null; `ttIdMap` has one entry per migrated term mapping legacy tt_id→the new term's current tt_id). Commit `feat: TermCrosswalk reader (newTermId + ttIdMap)`.

---

### Task 11: ArtworkWriter::migrate (integration)

**Files:** Create `src/Migration/ArtworkWriter.php`; Test `tests/Integration/Migration/ArtworkWriterTest.php`.
**Interface:** `ArtworkWriter::migrate(TermCrosswalk $crosswalk): array{written:int, dropped:list<int>, conflicts:list<int>}`.
**must_handle:** read legacy `sermon_image_plugin` + `sermon_image_plugin_settings`; run `TermArtworkMapper` against `$crosswalk->ttIdMap()`; write `sermonator_term_images` + `sermonator_term_images_settings`; **add_option-first + backup** any pre-existing target value to `OPTION_PRE_MIGRATION_BACKUP` (never blind `update_option`); attachments referenced; legacy options untouched; dropped/conflicts persisted (not just returned).

- [ ] Standard TDD steps (failing test: target keyed by NEW tt_id w/ original attachment_id; orphaned legacy tt_id → dropped flag, no crash; settings taxonomy names remapped + globals preserved; pre-existing native `sermonator_term_images` backed up not clobbered; re-run idempotent; legacy options byte-equal). Commit `feat: ArtworkWriter (tt_id remap, backup-safe)`.

---

### Task 12: WriteResult + SermonWriter — post columns, KSES-safe body, back-ref-first, idempotency (integration)

**Files:** Create `src/Migration/WriteResult.php`, `src/Migration/SermonWriter.php`; Test `tests/Integration/Migration/SermonWriterPostTest.php`.
**Interfaces:** `WriteResult{int $newId, bool $created, list<string> $flags}`; `SermonWriter::__construct(?DateNormalizer $dates = null)`; `SermonWriter::write(int $legacyId): WriteResult`.
**must_handle (this task = the post/body/idempotency facet):** idempotency gate via `Crosswalk::findNewByLegacyId` distinguishing `MIGRATION_COMPLETE` from stamped-but-partial (resume/redo a partial; never skip-forever); read legacy READ-ONLY; build `$postarr` preserving `post_title/post_author/post_date/post_date_gmt/post_status/post_name/comment_status/ping_status/menu_order/post_excerpt/post_password`, `post_type=POST_TYPE_SERMON`; `post_content` from the 3-arg reconciler (`post_content`, `sermon_description` meta, `post_content_temp` meta); `wp_slash($postarr)`; **disable KSES** around `wp_insert_post` (`kses_remove_filters()` then restore via `kses_init_filters()`); stamp back-ref IMMEDIATELY after insert; write `MIGRATION_COMPLETE` LAST (end of the full `write()` after meta/terms/comments in later tasks — for THIS task's scope, set it after the post+body, and later tasks move it to the true end); slug-drift → `slug_changed` flag + store `LEGACY_SLUG`; non-zero `post_parent` → translate via `findNewByLegacyId` else 0 + flag; never mutate attachments; legacy post+meta byte-equal before/after.

> Implementation note for the COMPLETE-LAST invariant across split tasks: in this task, do NOT write `MIGRATION_COMPLETE` yet — instead leave the post "stamped (back-ref) but not complete". Tasks 13–14 add meta/terms/comments and Task 14 writes `MIGRATION_COMPLETE` at the very end of `write()`. The idempotency gate: if back-ref exists AND `MIGRATION_COMPLETE` exists → `created=false`, return existing id, re-run only self-healing steps (terms); if back-ref exists but NOT complete → resume (re-enter the remaining steps on the existing new post, do not insert a second post).

- [ ] **Step 1: failing test:** every preserved column matches legacy; `post_type=sermonator_sermon`; `post_content` = reconciled body; an `<iframe>`/`[shortcode]` in the legacy body survives verbatim (KSES off); body with quotes/backslashes/unicode not corrupted; back-ref present right after insert; simulated abort before complete → re-run does NOT create a second post (resume); slug uniquified by WP → `slug_changed` flag + `LEGACY_SLUG`; legacy `get_post`+`get_post_meta` byte-equal before/after.
- [ ] **Step 2–5:** implement, run → PASS, commit `feat: SermonWriter post/body (KSES-safe, back-ref-first, resumable)`.

---

### Task 13: SermonWriter — meta application + date normalization (integration)

**Files:** Modify `src/Migration/SermonWriter.php`; Test `tests/Integration/Migration/SermonWriterMetaTest.php`.
**must_handle:** apply `SermonMetaMapper::map()` output, but **source write values from per-key `get_post_meta($legacyId, $key, false)` (UNSERIALIZED)** — NOT the no-key raw-serialized values; renamed known keys, dropped denorm `wpfc_service_type` + `sermon_description`-as-meta, unknown keys verbatim, multi-value preserved via **delete-then-re-add the full multiset** (idempotent on resume), single-value keys (`_thumbnail_id`, audio id, `sermonator_date_normalized`, flags row) written replace/unique; `post_content_temp` copied verbatim as ITS OWN row (single canonical home) AND fed to the reconciler (no double-flag); for EVERY non-numeric `sermon_date` row, write a `sermonator_date_normalized` companion (`DateNormalizer::normalize`) ALONGSIDE the untouched raw, replace semantics; record `legacy_nonnumeric_date` in the `MIGRATION_FLAGS` row; numeric dates get no normalized row.

- [ ] **Step 1: failing test:** serialized array meta `['a'=>1,'b'=>[2,3]]` on legacy → `get_post_meta(newId,key,true) === the array` (NOT a string); multi-value `sermon_notes` → two `sermonator_notes` rows; `_yoast_wpseo_title` verbatim; no `sermonator_description`/`wpfc_service_type` meta; `post_content_temp` row present; raw `'01/05/2021'` preserved verbatim AND `sermonator_date_normalized` is a unix int + flag recorded; numeric date → no normalized row; multi-row sermon_date with two non-numeric values → a companion per row; re-applying meta does not duplicate single-value rows or accumulate flag rows.
- [ ] **Step 2–5:** implement, run → PASS, commit `feat: SermonWriter meta application (unserialized, idempotent) + date normalization`.

---

### Task 14: SermonWriter — terms + comments + COMPLETE-last (integration)

**Files:** Modify `src/Migration/SermonWriter.php`; Test `tests/Integration/Migration/SermonWriterTermsCommentsTest.php`.
**must_handle (terms):** assign new terms by translating each legacy assignment through `Crosswalk::findNewTermByLegacyId($legacyTermId, MappingContract::taxonomyMap()[$legacyTax])` + `wp_set_object_terms` per target taxonomy; check `WP_Error`, flag `missing_term_crosswalk:<id>` (never silent drop); term repair re-appliable on an EXISTING post (when `created=false` but a `missing_term_crosswalk` flag is open, re-resolve). **(comments):** copy all comments depth-first with new ids, remap `comment_parent` via an old→new map rebuilt from `LEGACY_COMMENT_ID` back-refs, preserve author/email/url/date/date_gmt/content/approved/type/user_id + IP/agent/karma, stamp each new comment with `LEGACY_COMMENT_ID` (skip already-copied → idempotent), copy commentmeta with the unserialize discipline; legacy comments untouched. **Finally write `MIGRATION_COMPLETE` LAST.**

- [ ] **Step 1: failing test:** legacy `wpfc_preacher` term → new post in the crosswalked `sermonator_preacher` term (correct taxonomy); legacy term without crosswalk → not assigned + flag, no crash; `wp_set_object_terms` WP_Error path surfaces a flag; threaded parent+reply copied with reply `comment_parent` = NEW parent id; approval + author email preserved; comment with commentmeta → meta lands on new comment, arrays round-trip; second `write()` copies zero duplicate comments; a post that had an open missing-term flag re-applies the now-available term on a second write; `MIGRATION_COMPLETE` set after all steps; legacy comments byte-equal.
- [ ] **Step 2–5:** implement, run → PASS, commit `feat: SermonWriter terms + threaded comments + COMPLETE-last`.

---

### Task 15: PodcastWriter + OptionWriter (integration)

**Files:** Create `src/Migration/PodcastWriter.php`, `src/Migration/OptionWriter.php`; Test `tests/Integration/Migration/PodcastOptionWriterTest.php`.
**Interfaces:** `PodcastWriter::write(int $legacyId): WriteResult`; `OptionWriter::migrate(): array{written:int, backed_up:int}`.
**must_handle:** PodcastWriter reuses the SermonWriter disciplines (back-ref FIRST + `MIGRATION_COMPLETE` LAST, KSES-safe insert, unserialized per-key meta, idempotent); `sm_podcast_settings` → `sermonator_podcast_settings` with any taxonomy/term references inside remapped via `TermCrosswalk`; podcasts stamped with `LEGACY_POST_ID` (so `allMigratedPostIds`/rollback cover them). OptionWriter reads every `sermonmanager_*` option, applies `OptionMapper`, **add_option-first + backup** pre-existing `sermonator_*` to `OPTION_PRE_MIGRATION_BACKUP`, remaps `wpfc_sm_default_podcast` → `sermonator_default_podcast` via `Crosswalk::findNewByLegacyId(..., POST_TYPE_PODCAST)`; legacy options untouched.

- [ ] **Step 1: failing test:** podcast mirrored with `sm_podcast_settings` array round-tripping as an array; `sermonmanager_*` option → `sermonator_*` verbatim value/type; pre-existing target option backed up not clobbered; default-podcast option remapped to the new podcast id; podcast idempotent re-run; stamped podcast appears in `Crosswalk::allMigratedPostIds()`; legacy podcast/options byte-equal.
- [ ] **Step 2–5:** implement, run → PASS, commit `feat: PodcastWriter + OptionWriter (backup-safe, idempotent)`.

---

## Self-Review

**Coverage vs design notes B2a (tasks 1–15 here = synthesis 1–19):** constants(1)→T1; DateNormalizer(2)→T2; reconciler-3arg(3)→T3; TermArtworkMapper(4,5)→T4; LegacyChecksum(6)→T5; Crosswalk post(7)→T6; Crosswalk term(8)→T7; TermWriter::migrateTerm(9)→T8; migrateAll(10)→T9; TermCrosswalk(11)→T10; ArtworkWriter(12)→T11; SermonWriter post/body(13,18)→T12; meta+dates(14,15)→T13; terms+comments(16,17)→T14; Podcast+Option(19)→T15. ✓

**Closed critical holes mapped to tasks:** serialized-meta→T13; KSES→T12; back-ref-first/COMPLETE-last→T12/T14; wp_slash→T12; taxonomy-aware term lookup→T7/T14; term-cache staleness→T7; term uniqueness guard→T9; never-adopt-native-term→T8; comment idempotency/threading→T14; attachment non-mutation→T12; date all-rows+TZ→T2/T13; add_option-first/backup→T11/T15. ✓

**Deferred to B2b:** Orchestrator/state/batch, Verifier, Rollback, Finalizer, CLI, full-cycle e2e.

**Placeholder note:** integration tasks give complete TEST contracts + exact interfaces + the closed-hole `must_handle`; implementers TDD the WordPress plumbing against those tests (the tests are the spec). Pure tasks give complete implementation code.
