# Sermonator Parity — Precursor + Bundle 1 (Switch-safe Tier A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** De-risk the switch from Sermon Manager by (a) measuring the real migrator population, (b) writing the Legacy Compatibility Contract, and (c) shipping Switch-safe Tier A — hardening+committing the in-flight work, keeping legacy podcast feed URLs alive with stable GUIDs, and registering legacy shortcode tags so migrated pages never show raw `[sermons]` text.

**Architecture:** Follows the parity roadmap (`docs/superpowers/specs/2026-06-25-sermonator-parity-roadmap-design.md`). Tier A is the cheap-but-load-bearing continuity layer: it does NOT implement attribute-faithful query semantics (that is Bundle 2) — instead, legacy shortcodes render a **fail-visible default** (safe list + an editor-only "needs review" notice), per the Contract. Feed continuity intercepts the legacy feed request, maps the legacy podcast ID to a `sermonator_podcast`, and replays the GUIDs captured in a pre-migration snapshot so subscribers' apps don't re-download or drop episodes.

**Tech Stack:** PHP 8.1, WordPress 7.0+, PSR-4 (`Sermonator\` → `src/`), PHPUnit 9.6 + Brain Monkey (unit) + `@wordpress/env` `WP_UnitTestCase` (integration).

## Global Constraints

- **PSR-4:** `Sermonator\` → `src/`. NEVER hardcode post-type/taxonomy/meta/option strings — use `Sermonator\Schema\Identifiers` (and `Sermonator\Migration\LegacyIdentifiers` for legacy `wpfc_*` strings).
- **TDD:** failing test first. Unit = Brain Monkey (pure logic, no WP). Integration = wp-env `WP_UnitTestCase`.
- **Escape at the `Renderer` boundary** (`esc_html`/`esc_url`/`esc_attr`; video via `Schema\VideoEmbedPolicy`). Feed text is XML-escaped in `FeedBuilder` (already).
- **Any write path clears the audio-backfill bar:** reversible, fill-missing-only, refuses to run mid-migration (`MigrationGuard::editingAllowed()`).
- **Platform floor:** WordPress 7.0 / PHP 8.1 — do not lower.
- **The roadmap + inventory docs are currently uncommitted on `main`.** Commit only when the user asks; if committing, branch first.
- **HARD REQUIREMENT (rollback story 1):** feed-GUID stability + a pre-migration feed snapshot must exist before any church is told to switch. Lost podcast subscribers cannot be reclaimed.

## File Structure

- `docs/superpowers/research/2026-06-26-migrator-reality-audit.md` — audit findings + Bundle 2 sizing verdict (Task 1).
- `docs/superpowers/specs/2026-06-26-sermonator-legacy-compatibility-contract.md` — the binding Contract (Task 2).
- `tests/Unit/Admin/Authoring/SermonDateNormalizerTest.php` — fix mocks + add capability-gate test (Task 3).
- `src/Migration/LegacyFeedSnapshot.php` — capture/store/read the pre-migration legacy feed (URL + per-episode GUID) (Task 5).
- `src/Frontend/Feed/LegacyPodcastId.php` — map a legacy `wpfc_sm_podcast` id → `sermonator_podcast` id (Task 6).
- `src/Frontend/Feed/LegacyFeedRouter.php` — intercept legacy feed requests and route to `PodcastFeed` (Task 6).
- `src/Frontend/Feed/PodcastFeed.php` — accept an injected GUID-override map for replayed GUIDs (Task 7).
- `src/Frontend/Compat/LegacyShortcodes.php` — register `[sermons]` & friends with the fail-visible default (Task 8).

---

### Task 1: Migrator-reality audit (precursor gate)

Investigation task — deliverable is a findings doc that sizes Bundle 2. No production code.

**Files:**
- Create: `docs/superpowers/research/2026-06-26-migrator-reality-audit.md`

- [ ] **Step 1: Define the sample and the validity guard.** In the doc, record the list of sites to inspect: the seeded `sermonator-test` site plus every real **Sermon Manager Pro** site the owner can supply DB/site access to. State the guard verbatim: *"The audit may down-size Bundle 2 only if it includes ≥ N real Pro sites (N to be set with the owner, suggest ≥ 5). If unreached, Bundle 2 stays full-scope."*

- [ ] **Step 2: Measure filtered podcasts.** For each site, query `wpfc_sm_podcast` posts and their `sm_podcast_settings` meta; record how many run a *2nd+* podcast and whether each filters by taxonomy or audio/video mode. Tabulate: `% of sites with ≥2 podcasts`, `% of those that filter`.

- [ ] **Step 3: Measure `[sermons]` attribute density.** Grep each site's `wp_posts.post_content` for `[sermons` / `[sermons_sm` / `[list_sermons` / `[latest_series` / `[sermon_images`. For each occurrence, count attributes used. Tabulate: `mean attributes per [sermons]`, `% using order/orderby/filter_by/date attributes`.

- [ ] **Step 4: Measure page-builder-embedded sermon modules.** Search `post_content` and `postmeta` (`_elementor_data`, Divi `et_pb_*` shortcodes, Beaver `_fl_builder_data`) for the Pro module signatures (`smpro_archive`, `smpro_tax`, the Elementor/Divi widget slugs from the inventory). Tabulate count per builder.

- [ ] **Step 5: Write the sizing verdict.** Against the roadmap thresholds (§4 of the spec), state for each Bundle 2 component whether the evidence says KEEP-FULL, REDUCE, or DROP, with the numbers behind each call. Mark the verdict "provisional" if the validity guard (Step 1) was not met.

---

### Task 2: Legacy Compatibility Contract (binding artifact)

Deliverable is the thin Contract every later bundle references. No production code.

**Files:**
- Create: `docs/superpowers/specs/2026-06-26-sermonator-legacy-compatibility-contract.md`

- [ ] **Step 1: State the load-bearing rule.** Write, verbatim and at the top: *"Fail-visible, never fail-wrong. Where a shim cannot reproduce the faithful legacy behavior, it renders the safe unfiltered list WITH an editor/admin-visible 'this listing needs review' notice — never silently-different content."*

- [ ] **Step 2: Enumerate each legacy surface and its guaranteed behavior.** One row per surface — `[sermons]`, `[sermons_sm]`, `[list_sermons]`, `[latest_series]`, `[sermon_images]`, `[list_podcasts]`, the legacy feed URL(s) — each stating: the Tier A guaranteed behavior, the Tier B faithful behavior (deferred), and what the fail-visible fallback looks like.

- [ ] **Step 3: State the GUID + feed continuity guarantee.** Record: legacy feed URLs resolve 200; item GUIDs equal the pre-migration snapshot's GUIDs; item set order is preserved (or fail-visible if it can't be).

- [ ] **Step 4: Record the anti-drift rule.** Verbatim: *"Updating this Contract is a required exit criterion of every parity bundle's PR."* Add a changelog table (date | bundle | what changed) seeded with this Bundle 1 row.

---

### Task 3: Fix the 3 failing `SermonDateNormalizer` unit tests + add a capability-gate test

The normalizer gained a `current_user_can('edit_post', …)` guard (`src/Admin/Authoring/SermonDateNormalizer.php:51`) that the unit test never mocked, so Brain Monkey throws `MissingFunctionExpectations`. Fix the mock; add the missing test that proves the guard blocks writes.

**Files:**
- Test: `tests/Unit/Admin/Authoring/SermonDateNormalizerTest.php`

**Interfaces:**
- Consumes: `SermonDateNormalizer::normalize(int $post_id, \WP_Post $post): void`; `Schema\Identifiers::META_DATE`, `META_DATE_AUTO`.
- Produces: nothing new (test-only change).

- [ ] **Step 1: Run the suite to see the 3 errors.**

Run: `composer test:unit -- --filter SermonDateNormalizerTest`
Expected: 3 errors — `"current_user_can" is not defined nor mocked in this test.`

- [ ] **Step 2: Mock `current_user_can` in `setUp()`.** Add this line to `setUp()` immediately after the existing `get_post_time` mock (around line 43):

```php
Functions\when( 'current_user_can' )->justReturn( true );
```

- [ ] **Step 3: Run the suite to verify the 3 errors clear.**

Run: `composer test:unit -- --filter SermonDateNormalizerTest`
Expected: PASS (4 tests).

- [ ] **Step 4: Add the failing test for the capability gate (TDD — the guard is currently untested).** Add this method to the test class:

```php
public function test_insufficient_caps_blocks_writes(): void {
    Functions\when( 'current_user_can' )->justReturn( false );
    $post = $this->post( '2025-12-21 09:00:00', '2025-12-21 09:00:00' );

    ( new SermonDateNormalizer() )->normalize( $this->postId, $post );

    $this->assertArrayNotHasKey( Identifiers::META_DATE, $this->meta );
    $this->assertArrayNotHasKey( Identifiers::META_DATE_AUTO, $this->meta );
}
```

- [ ] **Step 5: Run it to verify it passes** (the guard already exists, so this is a characterization test).

Run: `composer test:unit -- --filter SermonDateNormalizerTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit.**

```bash
git add tests/Unit/Admin/Authoring/SermonDateNormalizerTest.php
git commit -m "test(authoring): mock current_user_can + cover capability gate in SermonDateNormalizer"
```

---

### Task 4: Green the full suites + adversarial-review gate for the in-flight work, then commit it

The in-flight working tree (authoring layer, `bulletin`/`featured-image`/`notes` blocks, `AudioHeadProbe`, `VideoEmbedPolicy`, core edits) is unreviewed and must clear the gate before any production exposure. This task is the gate, not new features.

**Files:**
- Modify (commit): all currently-untracked/modified in-flight files (`git status` lists them).

- [ ] **Step 1: Run the full unit suite.**

Run: `composer test:unit`
Expected: PASS (was 166 tests / 3 errors → now green after Task 3). If any non-Task-3 failures appear, STOP and fix them as their own TDD cycle before continuing.

- [ ] **Step 2: Run the full integration suite under wp-env.**

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro \
  vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration
```
Expected: all green. Any failure → fix as its own TDD cycle (add a sub-task) before committing. Do not commit red.

- [ ] **Step 3: Build the editor JS bundles so committed `build/` matches source.**

Run: `npm install && npm run build`
Expected: `build/sermon-details/` and `build/meta-box/` regenerate without error.

- [ ] **Step 4: Adversarial review gate (REQUIRED — write path).** Dispatch the `pr-review-toolkit:code-reviewer` and `pr-review-toolkit:silent-failure-hunter` agents over the in-flight diff (`git diff` + untracked files), focused on the authoring **write paths** (`SermonMetaSanitizer`, `SermonMetaRestSanitizer`, `SermonDateNormalizer`, `SermonMetaRegistrar`) and migration interaction. Resolve every finding to convergence. Record outcomes in a short `docs/superpowers/sdd/`-style note.

- [ ] **Step 5: Branch (we're on `main`) and commit the reviewed in-flight work as a coherent unit.**

```bash
git checkout -b bundle1-switch-safe
git add -A
git commit -m "feat(authoring): commit reviewed Sermon Details authoring layer + bulletin/featured-image/notes blocks + audio duration"
```

---

### Task 5: Pre-migration legacy feed snapshot (capture GUIDs before switch)

Before migration finalizes, capture the legacy feed's per-episode GUID (and URL) so Sermonator can replay them. This is the HARD REQUIREMENT backing rollback story 1. Reversible + read-only against legacy data.

**Files:**
- Create: `src/Migration/LegacyFeedSnapshot.php`
- Test: `tests/Unit/Migration/LegacyFeedSnapshotTest.php`

**Interfaces:**
- Consumes: `Schema\Identifiers::OPTION_PREFIX`.
- Produces:
  - `LegacyFeedSnapshot::store(array $guidByLegacyPostId): void` — persists the map to option `sermonator_legacy_feed_snapshot`.
  - `LegacyFeedSnapshot::guidFor(int $legacyPostId): ?string` — returns the captured GUID or null.
  - `LegacyFeedSnapshot::OPTION = 'sermonator_legacy_feed_snapshot'`.

- [ ] **Step 1: Write the failing test.**

```php
<?php
namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Migration\LegacyFeedSnapshot;

final class LegacyFeedSnapshotTest extends TestCase {
    /** @var array<string,mixed> */
    private array $options = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        Functions\when( 'update_option' )->alias( function ( string $k, $v ) {
            $this->options[ $k ] = $v; return true;
        } );
        Functions\when( 'get_option' )->alias( function ( string $k, $d = false ) {
            return $this->options[ $k ] ?? $d;
        } );
    }

    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_stores_and_reads_guid_by_legacy_post_id(): void {
        ( new LegacyFeedSnapshot() )->store( array( 42 => 'http://old/feed/?p=42', 7 => 'wpfc-7' ) );

        $this->assertSame( 'http://old/feed/?p=42', ( new LegacyFeedSnapshot() )->guidFor( 42 ) );
        $this->assertSame( 'wpfc-7', ( new LegacyFeedSnapshot() )->guidFor( 7 ) );
        $this->assertNull( ( new LegacyFeedSnapshot() )->guidFor( 999 ) );
    }
}
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `composer test:unit -- --filter LegacyFeedSnapshotTest`
Expected: FAIL — class `LegacyFeedSnapshot` not found.

- [ ] **Step 3: Implement `LegacyFeedSnapshot`.**

```php
<?php
declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Persists the legacy podcast feed's per-episode GUID, captured at detect/migrate time before
 * Finalize, keyed by legacy post ID. Replayed by the feed layer so already-subscribed apps do
 * not re-download or drop episodes after the switch (rollback story 1). Read-only against
 * legacy data; the stored map is the only writable artifact and is reversible (delete option).
 */
final class LegacyFeedSnapshot {
    public const OPTION = 'sermonator_legacy_feed_snapshot';

    /** @param array<int,string> $guidByLegacyPostId */
    public function store( array $guidByLegacyPostId ): void {
        $clean = array();
        foreach ( $guidByLegacyPostId as $id => $guid ) {
            if ( (int) $id > 0 && is_string( $guid ) && $guid !== '' ) {
                $clean[ (int) $id ] = $guid;
            }
        }
        update_option( self::OPTION, $clean, false );
    }

    public function guidFor( int $legacyPostId ): ?string {
        $map = get_option( self::OPTION, array() );
        if ( ! is_array( $map ) ) {
            return null;
        }
        $guid = $map[ $legacyPostId ] ?? null;
        return is_string( $guid ) && $guid !== '' ? $guid : null;
    }
}
```

- [ ] **Step 4: Run it to verify it passes.**

Run: `composer test:unit -- --filter LegacyFeedSnapshotTest`
Expected: PASS.

- [ ] **Step 5: Add an integration task to POPULATE the snapshot during detect.** In the migration Detector (where the write-once manifest is built), capture each legacy `wpfc_sermon`'s emitted feed GUID. The exact legacy GUID string MUST be read from the legacy feed output, not guessed — verify against `Sermon-Manager-2.15.15/views/wpfc-podcast-feed.php:352`. Add an integration test asserting that after detect on the `LegacyFixture`, `LegacyFeedSnapshot::guidFor($legacyId)` returns the same GUID the legacy feed emitted. (Write this as a `tests/Integration/Migration/LegacyFeedSnapshotTest.php` `WP_UnitTestCase` once the legacy GUID format is confirmed.)

- [ ] **Step 6: Commit.**

```bash
git add src/Migration/LegacyFeedSnapshot.php tests/Unit/Migration/LegacyFeedSnapshotTest.php
git commit -m "feat(migration): capture legacy feed GUIDs in a pre-migration snapshot (rollback story 1)"
```

---

### Task 6: Legacy feed-URL routing + legacy podcast-ID mapping

Make `?feed=rss2&post_type=wpfc_sermon[&id=<legacy_id>]` resolve to Sermonator's feed instead of 404ing post-migration, mapping the legacy podcast id to the migrated `sermonator_podcast`.

**Files:**
- Create: `src/Frontend/Feed/LegacyPodcastId.php`
- Create: `src/Frontend/Feed/LegacyFeedRouter.php`
- Test: `tests/Unit/Frontend/Feed/LegacyPodcastIdTest.php`
- Test: `tests/Integration/Frontend/Feed/LegacyFeedRouterTest.php`

**Interfaces:**
- Consumes: `Schema\Identifiers::POST_TYPE_PODCAST`, `OPTION_DEFAULT_PODCAST`; `Migration\LegacyIdentifiers` (post-ID crosswalk option written by migration).
- Produces:
  - `LegacyPodcastId::resolve(int $legacyId): int` — returns the migrated `sermonator_podcast` id, or the default podcast id when `legacyId` is 0/unmapped.
  - `LegacyFeedRouter::hook(): void` — registers a `request`/`template_redirect` interception.

- [ ] **Step 1: Write the failing unit test for `LegacyPodcastId`.**

```php
<?php
namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Feed\LegacyPodcastId;
use Sermonator\Schema\Identifiers as ID;

final class LegacyPodcastIdTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_zero_legacy_id_returns_default_podcast(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $k === ID::OPTION_DEFAULT_PODCAST ? 11 : $d );
        $this->assertSame( 11, ( new LegacyPodcastId() )->resolve( 0 ) );
    }

    public function test_mapped_legacy_id_returns_migrated_podcast(): void {
        Functions\when( 'get_option' )->alias( function ( $k, $d = false ) {
            if ( $k === 'sermonator_podcast_id_crosswalk' ) { return array( 5 => 22 ); }
            return $d;
        } );
        $this->assertSame( 22, ( new LegacyPodcastId() )->resolve( 5 ) );
    }
}
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `composer test:unit -- --filter LegacyPodcastIdTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `LegacyPodcastId`.** (The crosswalk option name must match what the migration's `OptionIdRemapper`/`PodcastWriter` actually writes — verify in `src/Migration/`; the test above assumes `sermonator_podcast_id_crosswalk`. If the real name differs, update both.)

```php
<?php
declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Schema\Identifiers as ID;

/** Maps a legacy wpfc_sm_podcast id to the migrated sermonator_podcast id (default when unmapped). */
final class LegacyPodcastId {
    private const CROSSWALK = 'sermonator_podcast_id_crosswalk';

    public function resolve( int $legacyId ): int {
        if ( $legacyId > 0 ) {
            $map = get_option( self::CROSSWALK, array() );
            if ( is_array( $map ) && isset( $map[ $legacyId ] ) && (int) $map[ $legacyId ] > 0 ) {
                return (int) $map[ $legacyId ];
            }
        }
        return (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );
    }
}
```

- [ ] **Step 4: Run it to verify it passes.**

Run: `composer test:unit -- --filter LegacyPodcastIdTest`
Expected: PASS.

- [ ] **Step 5: Implement `LegacyFeedRouter` and write an integration test.** Hook `request` to detect a legacy feed query (`feed=rss2|feed` AND `post_type=wpfc_sermon`), translate `$_GET['id']` via `LegacyPodcastId`, set `$_GET['podcast']`, and dispatch `PodcastFeed::render()`. Integration test (`WP_UnitTestCase`): simulate `GET /?feed=rss2&post_type=wpfc_sermon&id=5`, assert response is `application/rss+xml` and contains the migrated podcast's `<title>`. (Build the router against the real `PodcastFeed::render()` already at `src/Frontend/Feed/PodcastFeed.php:38`.)

- [ ] **Step 6: Wire it in `FrontendServiceProvider::hook()`** (next to `( new PodcastFeed() )->hook();` at `src/Frontend/FrontendServiceProvider.php`).

```php
( new \Sermonator\Frontend\Feed\LegacyFeedRouter() )->hook();
```

- [ ] **Step 7: Run unit + integration, then commit.**

```bash
git add src/Frontend/Feed/LegacyPodcastId.php src/Frontend/Feed/LegacyFeedRouter.php \
  src/Frontend/FrontendServiceProvider.php tests/Unit/Frontend/Feed/LegacyPodcastIdTest.php \
  tests/Integration/Frontend/Feed/LegacyFeedRouterTest.php
git commit -m "feat(feed): route legacy ?feed=rss2&post_type=wpfc_sermon URLs to the Sermonator feed with legacy id mapping"
```

---

### Task 7: GUID stability — replay snapshot GUIDs for migrated episodes

`FeedBuilder` currently emits `'sermonator-'.$id` (`FeedBuilder.php:68`). For migrated episodes that must equal the legacy GUID, or every subscriber re-downloads. Inject a GUID-override map into `PodcastFeed::items()` sourced from `LegacyFeedSnapshot`.

**Files:**
- Modify: `src/Frontend/Feed/PodcastFeed.php:76-87` (FeedItem construction)
- Test: `tests/Integration/Frontend/Feed/PodcastFeedGuidTest.php`

**Interfaces:**
- Consumes: `Migration\LegacyFeedSnapshot::guidFor(int): ?string`.
- Produces: feed items whose `guid` equals the snapshot GUID when present, else `'sermonator-'.$id` (unchanged for new episodes).

- [ ] **Step 1: Write the failing integration test.** With a `LegacyFeedSnapshot` storing `guidFor(<migratedId>) = 'wpfc-legacy-guid-xyz'`, render the feed and assert the item `<guid …>wpfc-legacy-guid-xyz</guid>` appears (not `sermonator-<id>`); and that an episode with NO snapshot entry still emits `sermonator-<id>`.

Run: `… phpunit --filter PodcastFeedGuidTest` → Expected: FAIL.

- [ ] **Step 2: Use the snapshot when building each item.** In `PodcastFeed::items()`, before the `new FeedItem(`, resolve the GUID:

```php
$snapshot = new \Sermonator\Migration\LegacyFeedSnapshot();
// inside the foreach, replacing the guid argument:
$guid = $snapshot->guidFor( $view->id ) ?? ( 'sermonator-' . $view->id );
```
and pass `guid: $guid,` to the `FeedItem` constructor (currently `guid: 'sermonator-' . $view->id,` at line 79).

- [ ] **Step 3: Run the integration test to verify it passes.**

Run: `… phpunit --filter PodcastFeedGuidTest`
Expected: PASS.

- [ ] **Step 4: Commit.**

```bash
git add src/Frontend/Feed/PodcastFeed.php tests/Integration/Frontend/Feed/PodcastFeedGuidTest.php
git commit -m "feat(feed): replay pre-migration GUIDs so subscribed apps don't re-download episodes (rollback story 1)"
```

---

### Task 8: Legacy shortcode tag registration (fail-visible default)

Register `[sermons]`, `[sermons_sm]`, `[list_sermons]`, `[latest_series]`, `[sermon_images]`, `[list_podcasts]` so migrated pages never render raw shortcode text. Tier A renders a **safe default** with an editor-visible "needs review" notice per the Contract — NOT attribute-faithful output (that's Bundle 2).

**Files:**
- Create: `src/Frontend/Compat/LegacyShortcodes.php`
- Test: `tests/Unit/Frontend/Compat/LegacyShortcodesTest.php`
- Test: `tests/Integration/Frontend/Compat/LegacyShortcodesTest.php`
- Modify: `src/Frontend/FrontendServiceProvider.php` (register on hook)

**Interfaces:**
- Consumes: `Sermonator\Frontend\Shortcode` (the existing `[sermonator_sermons]` renderer), `Sermonator\Frontend\Renderer`.
- Produces:
  - `LegacyShortcodes::hook(): void` — registers the legacy tags on `init`.
  - `LegacyShortcodes::TAGS` (list of registered tags).
  - `LegacyShortcodes::needsReviewNotice(): string` — editor-only notice markup (pure; unit-tested).

- [ ] **Step 1: Write the failing unit test for the fail-visible notice.**

```php
<?php
namespace Sermonator\Tests\Unit\Frontend\Compat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Compat\LegacyShortcodes;

final class LegacyShortcodesTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( '__' )->returnArg();
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_notice_renders_only_for_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertStringContainsString( 'sermonator-compat-notice', LegacyShortcodes::needsReviewNotice() );
    }

    public function test_notice_is_empty_for_visitors(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertSame( '', LegacyShortcodes::needsReviewNotice() );
    }
}
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `composer test:unit -- --filter "Unit\\Frontend\\Compat\\LegacyShortcodesTest"`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `LegacyShortcodes`.** Each legacy tag delegates to the existing `[sermonator_sermons]` renderer for a safe sermon list, prepended with the editor-only notice. (List shims like `[list_sermons]`/`[latest_series]`/`[sermon_images]` render the same safe sermon list in Tier A; Bundle 4's blocks + Bundle 2 give them faithful output later.)

```php
<?php
declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Frontend\Shortcode;

/**
 * Tier A legacy-shortcode shims. Migrated pages contain [sermons] et al.; without these tags
 * WordPress prints the raw "[sermons]" text. Per the Legacy Compatibility Contract these render
 * a SAFE default (the standard Sermonator sermon list) plus an editor-only "needs review"
 * notice — fail-visible, never fail-wrong. Attribute-faithful output is Bundle 2.
 */
final class LegacyShortcodes {
    public const TAGS = array(
        'sermons', 'sermons_sm', 'list_sermons', 'latest_series', 'sermon_images', 'list_podcasts',
    );

    public function hook(): void {
        add_action( 'init', array( $this, 'register' ) );
    }

    public function register(): void {
        foreach ( self::TAGS as $tag ) {
            add_shortcode( $tag, array( $this, 'render' ) );
        }
    }

    /** @param array<string,string>|string $atts */
    public function render( $atts = array(), ?string $content = null, string $tag = '' ): string {
        $list = ( new Shortcode() )->render( is_array( $atts ) ? $atts : array() );
        return self::needsReviewNotice() . $list;
    }

    public static function needsReviewNotice(): string {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return '';
        }
        return '<p class="sermonator-compat-notice" role="note">'
            . esc_html__( 'This sermon listing was migrated from Sermon Manager and shows a default layout. Review it before relying on the original filters/order.', 'sermonator' )
            . '</p>';
    }
}
```

- [ ] **Step 4: Run the unit test to verify it passes.**

Run: `composer test:unit -- --filter "Unit\\Frontend\\Compat\\LegacyShortcodesTest"`
Expected: PASS.

- [ ] **Step 5: Write an integration test** (`WP_UnitTestCase`) asserting `do_shortcode('[sermons]')` returns markup containing the sermon list and NOT the literal string `[sermons]`, and that as a visitor (no `edit_posts`) the `sermonator-compat-notice` is absent.

Run: `… phpunit --filter "Integration\\Frontend\\Compat\\LegacyShortcodesTest"` → Expected: PASS.

- [ ] **Step 6: Register it in `FrontendServiceProvider::hook()`.**

```php
( new \Sermonator\Frontend\Compat\LegacyShortcodes() )->hook();
```

- [ ] **Step 7: Run full unit + integration suites, then commit.**

```bash
git add src/Frontend/Compat/LegacyShortcodes.php src/Frontend/FrontendServiceProvider.php \
  tests/Unit/Frontend/Compat/LegacyShortcodesTest.php tests/Integration/Frontend/Compat/LegacyShortcodesTest.php
git commit -m "feat(compat): register legacy [sermons] et al. shims with fail-visible default (Compatibility Contract)"
```

---

## Self-Review

**Spec coverage (against the roadmap's precursor + Bundle 1):**
- Migrator audit (with sample-validity guard) → Task 1. ✓
- Legacy Compatibility Contract (fail-visible rule + PR-exit anti-drift) → Task 2. ✓
- Review→harden→commit in-flight work (fix 3 failing tests first) → Tasks 3–4. ✓
- Feed-URL routing + legacy id mapping → Task 6. ✓
- Feed-GUID stability + pre-migration snapshot (HARD REQUIREMENT) → Tasks 5, 7. ✓
- Shortcode tag registration, fail-visible → Task 8. ✓
- Bundle 2 / Bible / Config-display → intentionally NOT planned here (own forums later). ✓

**Known investigate-points (honest, not placeholders):** the exact legacy feed GUID string (Task 5 Step 5 — verify against `wpfc-podcast-feed.php:352`) and the migration's actual podcast-ID crosswalk option name (Task 6 Step 3 — verify in `src/Migration/`). Both are explicit verification steps, not invented values, because the snapshot/crosswalk are the sources of truth.

**Type consistency:** `LegacyFeedSnapshot::guidFor(int): ?string` is produced in Task 5 and consumed in Task 7; `LegacyPodcastId::resolve(int): int` produced in Task 6; `Shortcode::render(array): string` consumed in Task 8 matches the real signature at `src/Frontend/Shortcode.php`. ✓

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-06-26-sermonator-parity-bundle1-switch-safe.md`.
