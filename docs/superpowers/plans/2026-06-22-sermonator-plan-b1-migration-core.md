# Sermonator — Plan B1: Migration Core (Contract, Mappers, Detector) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the read-and-transform half of the migration engine — the legacy schema constants, the declarative old→new Mapping Contract, the pure record mappers (where fidelity lives), and a read-only Detector that scans an existing Sermon Manager install and produces a source Manifest (counts + checksums). No writes to the new namespace yet.

**Architecture:** A `Sermonator\Migration` namespace. Pure classes (`LegacyIdentifiers`, `Crosswalk`, `MappingContract`, `SermonMetaMapper`, `PostContentReconciler`, `OptionMapper`, `Manifest`) carry the transform logic and are unit-tested with no WordPress. The `Detector` is the one WordPress-coupled class — it reads `wpfc_*`/`sermonmanager_*` data and emits a `Manifest`; it is integration-tested against a `LegacyFixture` test helper that recreates the legacy schema in the test database. Nothing in this plan writes to the `sermonator_*` namespace or mutates legacy data.

**Tech Stack:** PHP 8.1+, WordPress 6.0+, PHPUnit 9.6 (unit: pure; integration: wp-env), Brain Monkey (unit). Builds on Plan A (`Schema\Identifiers`).

## Global Constraints

(Copied verbatim from the spec — every task's requirements implicitly include these.)

- **Platform:** PHP 8.1+ / WordPress 6.0+. No custom DB tables.
- **Namespace:** new identifiers use `sermonator_`; migration code lives in `Sermonator\Migration\`.
- **Data preservation is the highest bar.** This plan is **read-only with respect to all data** — the Detector reads legacy data and never modifies it; no task writes the `sermonator_*` namespace yet. The legacy `wpfc_*`/`sermonmanager_*` data is never altered.
- **Faithful 1:1 mirror.** The target schema mirrors the source, re-prefixed. Deliberately NOT carried: the denormalized `wpfc_service_type` *post-meta* copy (taxonomy term is authoritative) and the derived term-sort-date meta (recomputed later). **All other (unknown) post meta is copied verbatim under its original key** — the migration never drops an unrecognized meta row.
- **Sermon body → `post_content`.** The old `sermon_description` meta becomes the new `post_content`; the old auto-generated `post_content` blob is discarded unless it holds substantive text absent from `sermon_description` (then preserved + flagged). There is no `sermonator_description` meta key.
- **No monetization; open source GPL-2.0-or-later.**
- **Single-site only** (multisite deferred).

## Legacy schema reference (verified against Sermon Manager v2.15.16 source)

- Post types: `wpfc_sermon`, `wpfc_sm_podcast` (Pro).
- Taxonomies: `wpfc_preacher`, `wpfc_sermon_series`, `wpfc_sermon_topics`, `wpfc_bible_book`, `wpfc_service_type`.
- Sermon meta: `sermon_date` (Unix), `sermon_date_auto`, `bible_passage`, `sermon_description` (body), `sermon_audio`, `sermon_audio_id`, `_wpfc_sermon_duration`, `_wpfc_sermon_size`, `sermon_video`, `sermon_video_link`, `sermon_notes`, `sermon_bulletin`, `Views`, `wpfc_service_type` (denormalized — DROP), `post_content_temp` (Pro backup).
- Settings options: `sermonmanager_*` prefix.
- Podcast: post meta `sm_podcast_settings`; default-feed option `wpfc_sm_default_podcast`.
- Term/series artwork: option `sermon_image_plugin` = map of **term_taxonomy_id → attachment_id**; option `sermon_image_plugin_settings` = which taxonomies are image-enabled (keyed by taxonomy name).

## File Structure

```
src/Schema/Identifiers.php                       # MODIFY: add target podcast/option/artwork constants + OPTION_PREFIX
src/Migration/LegacyIdentifiers.php              # NEW: wpfc_*/sermonmanager_*/sm_podcast_settings/sermon_image_plugin source constants
src/Migration/Crosswalk.php                      # NEW: back-ref meta key constants
src/Migration/MappingContract.php                # NEW: declarative old→new maps + drop-list
src/Migration/SermonMetaMapper.php               # NEW (pure): legacy sermon meta -> {meta, description, flags}
src/Migration/PostContentReconciler.php          # NEW (pure): old post_content + description -> {content, backup, flag}
src/Migration/OptionMapper.php                   # NEW (pure): sermonmanager_X -> sermonator_X
src/Migration/Manifest.php                       # NEW (pure value object): counts + checksums
src/Migration/Detector.php                       # NEW (integration): scan legacy data -> Manifest
tests/Unit/Migration/*Test.php                   # unit tests for the pure classes
tests/Integration/Migration/DetectorTest.php     # integration test for the Detector
tests/Integration/Support/LegacyFixture.php      # test helper: builds a wpfc_* dataset in the test DB
```

---

### Task 1: Schema constants — legacy source, target additions, crosswalk

**Files:**
- Modify: `src/Schema/Identifiers.php` (add target constants)
- Create: `src/Migration/LegacyIdentifiers.php`
- Create: `src/Migration/Crosswalk.php`
- Test: `tests/Unit/Migration/LegacyIdentifiersTest.php`

**Interfaces:**
- Produces:
  - `Schema\Identifiers` adds: `OPTION_PREFIX='sermonator_'`, `META_PODCAST_SETTINGS='sermonator_podcast_settings'`, `OPTION_DEFAULT_PODCAST='sermonator_default_podcast'`, `OPTION_TERM_IMAGES='sermonator_term_images'`.
  - `Migration\LegacyIdentifiers`: post-type/taxonomy/meta/option constants for the legacy schema, `sermonTaxonomies(): list<string>`, `sermonMetaKeys(): list<string>` (the known sermon meta keys), `OPTION_PREFIX='sermonmanager_'`.
  - `Migration\Crosswalk`: `LEGACY_POST_ID='_sermonator_legacy_id'`, `LEGACY_TERM_ID='_sermonator_legacy_term_id'`.

- [ ] **Step 1: Write the failing test — `tests/Unit/Migration/LegacyIdentifiersTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers;

final class LegacyIdentifiersTest extends TestCase {
    public function test_legacy_post_types(): void {
        $this->assertSame( 'wpfc_sermon', LegacyIdentifiers::POST_TYPE_SERMON );
        $this->assertSame( 'wpfc_sm_podcast', LegacyIdentifiers::POST_TYPE_PODCAST );
    }

    public function test_legacy_taxonomies_in_canonical_order(): void {
        $this->assertSame(
            array( 'wpfc_preacher', 'wpfc_sermon_series', 'wpfc_sermon_topics', 'wpfc_bible_book', 'wpfc_service_type' ),
            LegacyIdentifiers::sermonTaxonomies()
        );
    }

    public function test_legacy_taxonomies_align_one_to_one_with_target(): void {
        // Position i in the legacy list must map to position i in the target list.
        $this->assertSame(
            count( LegacyIdentifiers::sermonTaxonomies() ),
            count( Identifiers::sermonTaxonomies() )
        );
    }

    public function test_legacy_meta_keys_include_body_and_denormalized_service_type(): void {
        $keys = LegacyIdentifiers::sermonMetaKeys();
        $this->assertContains( 'sermon_description', $keys );
        $this->assertContains( 'wpfc_service_type', $keys );
        $this->assertContains( 'Views', $keys );
        $this->assertContains( '_wpfc_sermon_duration', $keys );
    }

    public function test_legacy_option_prefix(): void {
        $this->assertSame( 'sermonmanager_', LegacyIdentifiers::OPTION_PREFIX );
    }

    public function test_artwork_option_names(): void {
        $this->assertSame( 'sermon_image_plugin', LegacyIdentifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 'wpfc_sm_default_podcast', LegacyIdentifiers::OPTION_DEFAULT_PODCAST );
        $this->assertSame( 'sm_podcast_settings', LegacyIdentifiers::META_PODCAST_SETTINGS );
    }

    public function test_crosswalk_keys_are_prefixed_hidden_meta(): void {
        $this->assertSame( '_sermonator_legacy_id', Crosswalk::LEGACY_POST_ID );
        $this->assertSame( '_sermonator_legacy_term_id', Crosswalk::LEGACY_TERM_ID );
    }

    public function test_target_additions_on_identifiers(): void {
        $this->assertSame( 'sermonator_', Identifiers::OPTION_PREFIX );
        $this->assertSame( 'sermonator_podcast_settings', Identifiers::META_PODCAST_SETTINGS );
        $this->assertSame( 'sermonator_default_podcast', Identifiers::OPTION_DEFAULT_PODCAST );
        $this->assertSame( 'sermonator_term_images', Identifiers::OPTION_TERM_IMAGES );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter LegacyIdentifiersTest`
Expected: FAIL — `Sermonator\Migration\LegacyIdentifiers` not found.

- [ ] **Step 3: Add target constants to `src/Schema/Identifiers.php`**

Inside the `Identifiers` class, after the existing `META_VIEWS` constant, add:

```php
    public const OPTION_PREFIX          = 'sermonator_';
    public const META_PODCAST_SETTINGS  = 'sermonator_podcast_settings';
    public const OPTION_DEFAULT_PODCAST = 'sermonator_default_podcast';
    public const OPTION_TERM_IMAGES     = 'sermonator_term_images';
```

- [ ] **Step 4: Create `src/Migration/LegacyIdentifiers.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Identifiers of the LEGACY Sermon Manager (+ Pro) schema — the migration source.
 * Read-only reference; the migration never writes these.
 */
final class LegacyIdentifiers {
    public const POST_TYPE_SERMON  = 'wpfc_sermon';
    public const POST_TYPE_PODCAST = 'wpfc_sm_podcast';

    public const TAX_PREACHER     = 'wpfc_preacher';
    public const TAX_SERIES       = 'wpfc_sermon_series';
    public const TAX_TOPIC        = 'wpfc_sermon_topics';
    public const TAX_BOOK         = 'wpfc_bible_book';
    public const TAX_SERVICE_TYPE = 'wpfc_service_type';

    public const META_DATE              = 'sermon_date';
    public const META_DATE_AUTO         = 'sermon_date_auto';
    public const META_BIBLE_PASSAGE     = 'bible_passage';
    public const META_DESCRIPTION       = 'sermon_description';
    public const META_AUDIO             = 'sermon_audio';
    public const META_AUDIO_ID          = 'sermon_audio_id';
    public const META_AUDIO_DURATION    = '_wpfc_sermon_duration';
    public const META_AUDIO_SIZE        = '_wpfc_sermon_size';
    public const META_VIDEO             = 'sermon_video';
    public const META_VIDEO_LINK        = 'sermon_video_link';
    public const META_NOTES             = 'sermon_notes';
    public const META_BULLETIN          = 'sermon_bulletin';
    public const META_VIEWS             = 'Views';
    public const META_SERVICE_TYPE_DENORM = 'wpfc_service_type';
    public const META_POST_CONTENT_TEMP   = 'post_content_temp';

    public const OPTION_PREFIX         = 'sermonmanager_';
    public const OPTION_DEFAULT_PODCAST = 'wpfc_sm_default_podcast';
    public const META_PODCAST_SETTINGS  = 'sm_podcast_settings';
    public const OPTION_TERM_IMAGES      = 'sermon_image_plugin';
    public const OPTION_TERM_IMAGES_SETTINGS = 'sermon_image_plugin_settings';

    /** @return list<string> Legacy sermon taxonomy slugs, in the same order as Identifiers::sermonTaxonomies(). */
    public static function sermonTaxonomies(): array {
        return array(
            self::TAX_PREACHER,
            self::TAX_SERIES,
            self::TAX_TOPIC,
            self::TAX_BOOK,
            self::TAX_SERVICE_TYPE,
        );
    }

    /** @return list<string> Known legacy sermon meta keys (used to scope detection; unknown keys still migrate verbatim). */
    public static function sermonMetaKeys(): array {
        return array(
            self::META_DATE,
            self::META_DATE_AUTO,
            self::META_BIBLE_PASSAGE,
            self::META_DESCRIPTION,
            self::META_AUDIO,
            self::META_AUDIO_ID,
            self::META_AUDIO_DURATION,
            self::META_AUDIO_SIZE,
            self::META_VIDEO,
            self::META_VIDEO_LINK,
            self::META_NOTES,
            self::META_BULLETIN,
            self::META_VIEWS,
            self::META_SERVICE_TYPE_DENORM,
        );
    }

    private function __construct() {}
}
```

- [ ] **Step 5: Create `src/Migration/Crosswalk.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Back-reference meta keys recorded on migrated records, linking each new
 * record to its legacy source. The migration's spine: makes rollback exact,
 * re-runs idempotent, and verification pairing possible. (Query helpers are
 * added in Plan B2 where records are written.)
 */
final class Crosswalk {
    /** On migrated sermon AND podcast posts (legacy post IDs are unique across post types). */
    public const LEGACY_POST_ID = '_sermonator_legacy_id';
    /** On migrated taxonomy terms. */
    public const LEGACY_TERM_ID = '_sermonator_legacy_term_id';

    private function __construct() {}
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `composer test:unit -- --filter LegacyIdentifiersTest`
Expected: PASS (9 tests).

- [ ] **Step 7: Run the full unit suite (no regression)**

Run: `composer test:unit`
Expected: PASS (all tests).

- [ ] **Step 8: Commit**

```bash
git add src/Schema/Identifiers.php src/Migration/LegacyIdentifiers.php src/Migration/Crosswalk.php tests/Unit/Migration/LegacyIdentifiersTest.php
git commit -m "feat: legacy schema constants, target additions, crosswalk keys"
```

---

### Task 2: Mapping Contract

**Files:**
- Create: `src/Migration/MappingContract.php`
- Test: `tests/Unit/Migration/MappingContractTest.php`

**Interfaces:**
- Consumes: `LegacyIdentifiers`, `Schema\Identifiers` (Task 1).
- Produces: `Migration\MappingContract` (all static):
  - `postTypeMap(): array<string,string>` — legacy post type → new post type.
  - `taxonomyMap(): array<string,string>` — legacy taxonomy → new taxonomy.
  - `metaKeyMap(): array<string,string>` — legacy sermon meta key → new meta key (excludes the dropped/special keys).
  - `droppedMetaKeys(): list<string>` — legacy keys NOT carried as meta (`wpfc_service_type`, `sermon_description`).
  - `mapOptionName(string $legacyName): ?string` — `sermonmanager_X` → `sermonator_X`; returns null if not a `sermonmanager_` option.

- [ ] **Step 1: Write the failing test — `tests/Unit/Migration/MappingContractTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\MappingContract;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;

final class MappingContractTest extends TestCase {
    public function test_post_type_map(): void {
        $this->assertSame(
            array(
                LegacyIdentifiers::POST_TYPE_SERMON  => Identifiers::POST_TYPE_SERMON,
                LegacyIdentifiers::POST_TYPE_PODCAST => Identifiers::POST_TYPE_PODCAST,
            ),
            MappingContract::postTypeMap()
        );
    }

    public function test_taxonomy_map_covers_all_five_in_order(): void {
        $map = MappingContract::taxonomyMap();
        $this->assertCount( 5, $map );
        $this->assertSame( Identifiers::TAX_PREACHER, $map[ LegacyIdentifiers::TAX_PREACHER ] );
        $this->assertSame( Identifiers::TAX_BOOK, $map[ LegacyIdentifiers::TAX_BOOK ] );
        $this->assertSame( Identifiers::TAX_SERVICE_TYPE, $map[ LegacyIdentifiers::TAX_SERVICE_TYPE ] );
    }

    public function test_meta_key_map_renames_known_keys(): void {
        $map = MappingContract::metaKeyMap();
        $this->assertSame( Identifiers::META_DATE, $map['sermon_date'] );
        $this->assertSame( Identifiers::META_VIDEO_EMBED, $map['sermon_video'] );
        $this->assertSame( Identifiers::META_VIDEO_URL, $map['sermon_video_link'] );
        $this->assertSame( Identifiers::META_AUDIO_DURATION, $map['_wpfc_sermon_duration'] );
        $this->assertSame( Identifiers::META_VIEWS, $map['Views'] );
    }

    public function test_meta_key_map_excludes_dropped_and_special_keys(): void {
        $map = MappingContract::metaKeyMap();
        $this->assertArrayNotHasKey( 'wpfc_service_type', $map );
        $this->assertArrayNotHasKey( 'sermon_description', $map );
    }

    public function test_dropped_meta_keys(): void {
        $dropped = MappingContract::droppedMetaKeys();
        $this->assertContains( 'wpfc_service_type', $dropped );
        $this->assertContains( 'sermon_description', $dropped );
    }

    public function test_map_option_name_swaps_prefix(): void {
        $this->assertSame( 'sermonator_player', MappingContract::mapOptionName( 'sermonmanager_player' ) );
        $this->assertSame( 'sermonator_archive_slug', MappingContract::mapOptionName( 'sermonmanager_archive_slug' ) );
    }

    public function test_map_option_name_returns_null_for_non_sermonmanager(): void {
        $this->assertNull( MappingContract::mapOptionName( 'some_other_option' ) );
        $this->assertNull( MappingContract::mapOptionName( 'sermonmanagerX' ) ); // no underscore boundary
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter MappingContractTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Migration/MappingContract.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * The declarative legacy→new mapping. Single source of truth consumed by both
 * the mappers (to transform) and the verifier (to check). `sermon_description`
 * is intentionally absent from metaKeyMap — it becomes post_content (see
 * SermonMetaMapper / PostContentReconciler).
 */
final class MappingContract {
    /** @return array<string,string> */
    public static function postTypeMap(): array {
        return array(
            LegacyIdentifiers::POST_TYPE_SERMON  => Identifiers::POST_TYPE_SERMON,
            LegacyIdentifiers::POST_TYPE_PODCAST => Identifiers::POST_TYPE_PODCAST,
        );
    }

    /** @return array<string,string> Legacy taxonomy → new taxonomy, paired by canonical order. */
    public static function taxonomyMap(): array {
        return array_combine(
            LegacyIdentifiers::sermonTaxonomies(),
            Identifiers::sermonTaxonomies()
        );
    }

    /** @return array<string,string> Legacy sermon meta key → new meta key. Excludes dropped/special keys. */
    public static function metaKeyMap(): array {
        return array(
            LegacyIdentifiers::META_DATE           => Identifiers::META_DATE,
            LegacyIdentifiers::META_DATE_AUTO      => Identifiers::META_DATE_AUTO,
            LegacyIdentifiers::META_BIBLE_PASSAGE  => Identifiers::META_BIBLE_PASSAGE,
            LegacyIdentifiers::META_AUDIO          => Identifiers::META_AUDIO,
            LegacyIdentifiers::META_AUDIO_ID       => Identifiers::META_AUDIO_ID,
            LegacyIdentifiers::META_AUDIO_DURATION => Identifiers::META_AUDIO_DURATION,
            LegacyIdentifiers::META_AUDIO_SIZE     => Identifiers::META_AUDIO_SIZE,
            LegacyIdentifiers::META_VIDEO          => Identifiers::META_VIDEO_EMBED,
            LegacyIdentifiers::META_VIDEO_LINK     => Identifiers::META_VIDEO_URL,
            LegacyIdentifiers::META_NOTES          => Identifiers::META_NOTES,
            LegacyIdentifiers::META_BULLETIN       => Identifiers::META_BULLETIN,
            LegacyIdentifiers::META_VIEWS          => Identifiers::META_VIEWS,
        );
    }

    /** @return list<string> Legacy keys NOT carried as meta. */
    public static function droppedMetaKeys(): array {
        return array(
            LegacyIdentifiers::META_SERVICE_TYPE_DENORM, // taxonomy term is authoritative
            LegacyIdentifiers::META_DESCRIPTION,         // becomes post_content
        );
    }

    /** @return string|null `sermonmanager_X` → `sermonator_X`; null if not a sermonmanager_ option. */
    public static function mapOptionName( string $legacyName ): ?string {
        $prefix = LegacyIdentifiers::OPTION_PREFIX;
        if ( ! str_starts_with( $legacyName, $prefix ) ) {
            return null;
        }
        return Identifiers::OPTION_PREFIX . substr( $legacyName, strlen( $prefix ) );
    }

    private function __construct() {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter MappingContractTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Migration/MappingContract.php tests/Unit/Migration/MappingContractTest.php
git commit -m "feat: declarative legacy->new mapping contract"
```

---

### Task 3: SermonMetaMapper (pure — the fidelity core)

**Files:**
- Create: `src/Migration/SermonMetaMapper.php`
- Test: `tests/Unit/Migration/SermonMetaMapperTest.php`

**Interfaces:**
- Consumes: `MappingContract`, `LegacyIdentifiers` (Tasks 1–2).
- Produces: `Migration\SermonMetaMapper::map(array $legacyMeta): array` where `$legacyMeta` is `array<string, list<string>>` (a legacy post's meta, exactly as `get_post_meta($id)` returns: key → list of raw values). Returns:
  ```php
  [
    'meta'        => array<string, list<string>>, // new-namespace meta (renamed/passthrough; dropped keys removed)
    'description' => ?string,                      // value of sermon_description (becomes post_content), or null
    'flags'       => list<string>,                 // e.g. 'legacy_nonnumeric_date'
  ]
  ```

- [ ] **Step 1: Write the failing test — `tests/Unit/Migration/SermonMetaMapperTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\SermonMetaMapper;
use Sermonator\Schema\Identifiers;

final class SermonMetaMapperTest extends TestCase {
    public function test_renames_known_keys(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_date'       => array( '1612137600' ),
            'sermon_video_link' => array( 'https://youtu.be/x' ),
            '_wpfc_sermon_duration' => array( '00:32:10' ),
        ) );
        $this->assertSame( array( '1612137600' ), $out['meta'][ Identifiers::META_DATE ] );
        $this->assertSame( array( 'https://youtu.be/x' ), $out['meta'][ Identifiers::META_VIDEO_URL ] );
        $this->assertSame( array( '00:32:10' ), $out['meta'][ Identifiers::META_AUDIO_DURATION ] );
    }

    public function test_extracts_description_and_does_not_keep_it_as_meta(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_description' => array( '<p>The body</p>' ),
        ) );
        $this->assertSame( '<p>The body</p>', $out['description'] );
        $this->assertArrayNotHasKey( 'sermonator_description', $out['meta'] );
        $this->assertArrayNotHasKey( 'sermon_description', $out['meta'] );
    }

    public function test_description_null_when_absent(): void {
        $out = SermonMetaMapper::map( array() );
        $this->assertNull( $out['description'] );
    }

    public function test_drops_denormalized_service_type_meta(): void {
        $out = SermonMetaMapper::map( array(
            'wpfc_service_type' => array( '42' ),
        ) );
        $this->assertArrayNotHasKey( 'wpfc_service_type', $out['meta'] );
        $this->assertArrayNotHasKey( Identifiers::TAX_SERVICE_TYPE, $out['meta'] );
    }

    public function test_passes_through_unknown_meta_verbatim(): void {
        $out = SermonMetaMapper::map( array(
            '_yoast_wpseo_title' => array( 'SEO title' ),
            'custom_field'       => array( 'a', 'b' ),
        ) );
        $this->assertSame( array( 'SEO title' ), $out['meta']['_yoast_wpseo_title'] );
        $this->assertSame( array( 'a', 'b' ), $out['meta']['custom_field'] );
    }

    public function test_preserves_multiple_values_for_a_key(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_notes' => array( 'a.pdf', 'b.pdf' ),
        ) );
        $this->assertSame( array( 'a.pdf', 'b.pdf' ), $out['meta'][ Identifiers::META_NOTES ] );
    }

    public function test_flags_nonnumeric_legacy_date(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_date' => array( '01/05/2021' ),
        ) );
        $this->assertContains( 'legacy_nonnumeric_date', $out['flags'] );
        // Raw value is still carried verbatim — never guessed-away.
        $this->assertSame( array( '01/05/2021' ), $out['meta'][ Identifiers::META_DATE ] );
    }

    public function test_no_flag_for_numeric_date(): void {
        $out = SermonMetaMapper::map( array( 'sermon_date' => array( '1612137600' ) ) );
        $this->assertNotContains( 'legacy_nonnumeric_date', $out['flags'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter SermonMetaMapperTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Migration/SermonMetaMapper.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure transform of a legacy sermon's post meta into the new namespace.
 * Renames known keys, extracts sermon_description (→ post_content, returned
 * separately), drops the denormalized service-type meta, passes through every
 * unknown key verbatim, and flags non-numeric legacy dates without altering
 * the raw value. No WordPress, no side effects.
 */
final class SermonMetaMapper {
    /**
     * @param array<string, list<string>> $legacyMeta Key → list of raw values (as get_post_meta($id) returns).
     * @return array{meta: array<string, list<string>>, description: ?string, flags: list<string>}
     */
    public static function map( array $legacyMeta ): array {
        $keyMap  = MappingContract::metaKeyMap();
        $dropped = MappingContract::droppedMetaKeys();

        $meta        = array();
        $description = null;
        $flags       = array();

        foreach ( $legacyMeta as $key => $values ) {
            if ( LegacyIdentifiers::META_DESCRIPTION === $key ) {
                $description = $values[0] ?? '';
                continue;
            }

            if ( in_array( $key, $dropped, true ) ) {
                continue; // e.g. wpfc_service_type denormalized copy
            }

            if ( LegacyIdentifiers::META_DATE === $key && isset( $values[0] ) && ! self::isUnixTimestamp( $values[0] ) ) {
                $flags[] = 'legacy_nonnumeric_date';
            }

            $newKey          = $keyMap[ $key ] ?? $key; // known → renamed; unknown → verbatim
            $meta[ $newKey ] = $values;
        }

        return array(
            'meta'        => $meta,
            'description' => $description,
            'flags'       => $flags,
        );
    }

    private static function isUnixTimestamp( string $value ): bool {
        return '' !== $value && ctype_digit( ltrim( $value, '-' ) ) && '' !== ltrim( $value, '-' );
    }

    private function __construct() {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter SermonMetaMapperTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Migration/SermonMetaMapper.php tests/Unit/Migration/SermonMetaMapperTest.php
git commit -m "feat: pure sermon meta mapper (rename, extract body, drop denorm, passthrough)"
```

---

### Task 4: PostContentReconciler (pure)

**Files:**
- Create: `src/Migration/PostContentReconciler.php`
- Test: `tests/Unit/Migration/PostContentReconcilerTest.php`

**Interfaces:**
- Produces: `Migration\PostContentReconciler::reconcile(string $oldPostContent, ?string $description): array` returning:
  ```php
  [
    'content' => string,   // the new post_content (= description when present)
    'backup'  => ?string,  // old post_content preserved as _sermonator_legacy_post_content when it holds unique substantive text, else null
    'flag'    => bool,      // true when a backup was produced (needs human review)
  ]
  ```
  Rule: new content = `$description` (or `''` if null). The old auto-generated blob is discarded UNLESS it contains substantive text (non-whitespace, length > 0 after trim) that is NOT already contained in the description — then it's preserved in `backup` and `flag` is true.

- [ ] **Step 1: Write the failing test — `tests/Unit/Migration/PostContentReconcilerTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\PostContentReconciler;

final class PostContentReconcilerTest extends TestCase {
    public function test_uses_description_as_content(): void {
        $out = PostContentReconciler::reconcile( 'auto-generated blob', '<p>Real body</p>' );
        $this->assertSame( '<p>Real body</p>', $out['content'] );
    }

    public function test_discards_blob_when_its_text_is_within_description(): void {
        // Old post_content is SM's degraded text version of the same body.
        $out = PostContentReconciler::reconcile( 'Real body', '<p>Real body</p>' );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_empty_description_with_empty_blob(): void {
        $out = PostContentReconciler::reconcile( '   ', null );
        $this->assertSame( '', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_preserves_and_flags_unique_blob_content(): void {
        // Rare case: real content lived only in the editor (post_content), not in the description.
        $out = PostContentReconciler::reconcile( 'Unique content only here', null );
        $this->assertSame( '', $out['content'] ); // description is the canonical body going forward...
        $this->assertSame( 'Unique content only here', $out['backup'] ); // ...but unique text is never dropped
        $this->assertTrue( $out['flag'] );
    }

    public function test_whitespace_only_blob_is_not_backed_up(): void {
        $out = PostContentReconciler::reconcile( "\n\t  ", 'desc' );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter PostContentReconcilerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Migration/PostContentReconciler.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure reconciliation of the body. The canonical body going forward is the old
 * sermon_description (→ post_content). The old auto-generated post_content blob
 * is discarded, EXCEPT when it holds substantive text not represented in the
 * description — that text is preserved as a backup and flagged, never dropped.
 */
final class PostContentReconciler {
    /**
     * @return array{content: string, backup: ?string, flag: bool}
     */
    public static function reconcile( string $oldPostContent, ?string $description ): array {
        $content = $description ?? '';

        $blob = trim( $oldPostContent );
        if ( '' === $blob ) {
            return array( 'content' => $content, 'backup' => null, 'flag' => false );
        }

        // If every non-trivial word of the blob already appears in the description's
        // text, the blob is the degraded derivative — discard it.
        if ( self::isContainedIn( $blob, (string) $description ) ) {
            return array( 'content' => $content, 'backup' => null, 'flag' => false );
        }

        // Unique substantive text — preserve verbatim and flag for human review.
        return array( 'content' => $content, 'backup' => $oldPostContent, 'flag' => true );
    }

    /** True if the blob's visible text is a substring of the description's visible text. */
    private static function isContainedIn( string $blob, string $description ): bool {
        $needle   = self::visibleText( $blob );
        $haystack = self::visibleText( $description );
        if ( '' === $needle ) {
            return true;
        }
        return str_contains( $haystack, $needle );
    }

    /** Strip tags and collapse whitespace for a text-only comparison. */
    private static function visibleText( string $html ): string {
        $text = self::stripTags( $html );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( (string) $text );
    }

    /** WordPress-free tag stripping, so this class stays pure and unit-testable. */
    private static function stripTags( string $html ): string {
        $html = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
        return trim( strip_tags( (string) $html ) );
    }

    private function __construct() {}
}
```

> Note: `stripTags()` is a private, WordPress-free helper so the class has no WordPress dependency and the unit test runs without WP. Do not call WordPress's `wp_strip_all_tags()` here.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter PostContentReconcilerTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Migration/PostContentReconciler.php tests/Unit/Migration/PostContentReconcilerTest.php
git commit -m "feat: pure post_content/description reconciler with unique-text preservation"
```

---

### Task 5: OptionMapper (pure)

**Files:**
- Create: `src/Migration/OptionMapper.php`
- Test: `tests/Unit/Migration/OptionMapperTest.php`

**Interfaces:**
- Consumes: `MappingContract` (Task 2).
- Produces: `Migration\OptionMapper::map(array $legacyOptions): array<string,mixed>` — input is `array<string,mixed>` of legacy option name → value (only `sermonmanager_*` entries are mapped; others are ignored). Returns new-name → value verbatim.

- [ ] **Step 1: Write the failing test — `tests/Unit/Migration/OptionMapperTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\OptionMapper;

final class OptionMapperTest extends TestCase {
    public function test_maps_prefixed_options_and_preserves_values(): void {
        $out = OptionMapper::map( array(
            'sermonmanager_player'       => 'plyr',
            'sermonmanager_archive_slug' => 'sermons',
            'sermonmanager_date_format'  => '0',
        ) );
        $this->assertSame( 'plyr', $out['sermonator_player'] );
        $this->assertSame( 'sermons', $out['sermonator_archive_slug'] );
        $this->assertSame( '0', $out['sermonator_date_format'] );
    }

    public function test_ignores_non_sermonmanager_options(): void {
        $out = OptionMapper::map( array(
            'sermonmanager_player' => 'plyr',
            'siteurl'              => 'https://example.org',
            'some_plugin_setting'  => 'x',
        ) );
        $this->assertArrayHasKey( 'sermonator_player', $out );
        $this->assertArrayNotHasKey( 'siteurl', $out );
        $this->assertArrayNotHasKey( 'some_plugin_setting', $out );
        $this->assertCount( 1, $out );
    }

    public function test_preserves_array_and_bool_values(): void {
        $out = OptionMapper::map( array(
            'sermonmanager_itunes_categories' => array( 'Religion', 'Christianity' ),
            'sermonmanager_podtrac'           => false,
        ) );
        $this->assertSame( array( 'Religion', 'Christianity' ), $out['sermonator_itunes_categories'] );
        $this->assertFalse( $out['sermonator_podtrac'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter OptionMapperTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Migration/OptionMapper.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure transform of legacy settings options into the new namespace. Only
 * `sermonmanager_*` options are mapped (prefix swap); values are copied
 * verbatim, preserving type. No WordPress, no side effects.
 */
final class OptionMapper {
    /**
     * @param array<string,mixed> $legacyOptions Legacy option name → value.
     * @return array<string,mixed> New option name → value.
     */
    public static function map( array $legacyOptions ): array {
        $out = array();
        foreach ( $legacyOptions as $name => $value ) {
            $newName = MappingContract::mapOptionName( $name );
            if ( null === $newName ) {
                continue;
            }
            $out[ $newName ] = $value;
        }
        return $out;
    }

    private function __construct() {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter OptionMapperTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Migration/OptionMapper.php tests/Unit/Migration/OptionMapperTest.php
git commit -m "feat: pure option mapper (sermonmanager_ -> sermonator_)"
```

---

### Task 6: Manifest value object (pure)

**Files:**
- Create: `src/Migration/Manifest.php`
- Test: `tests/Unit/Migration/ManifestTest.php`

**Interfaces:**
- Produces: `Migration\Manifest`:
  - `__construct(array $counts, array $checksums = array())` — `$counts` is `array<string,int>` (e.g. `['sermons' => 1240, 'terms_wpfc_preacher' => 38, 'podcasts' => 6, 'options' => 47, 'artwork' => 12]`); `$checksums` is `array<int,string>` keyed by legacy post ID.
  - `count(string $key): int` — 0 if absent.
  - `counts(): array<string,int>`.
  - `checksum(int $legacyId): ?string`.
  - `toArray(): array` / static `fromArray(array $data): self` — for persistence (used by the Verifier in B2).

- [ ] **Step 1: Write the failing test — `tests/Unit/Migration/ManifestTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\Manifest;

final class ManifestTest extends TestCase {
    public function test_counts_and_default_zero(): void {
        $m = new Manifest( array( 'sermons' => 1240, 'podcasts' => 6 ) );
        $this->assertSame( 1240, $m->count( 'sermons' ) );
        $this->assertSame( 6, $m->count( 'podcasts' ) );
        $this->assertSame( 0, $m->count( 'missing' ) );
    }

    public function test_checksums(): void {
        $m = new Manifest( array( 'sermons' => 2 ), array( 10 => 'abc', 11 => 'def' ) );
        $this->assertSame( 'abc', $m->checksum( 10 ) );
        $this->assertNull( $m->checksum( 99 ) );
    }

    public function test_round_trips_through_array(): void {
        $m = new Manifest( array( 'sermons' => 3 ), array( 1 => 'h1' ) );
        $restored = Manifest::fromArray( $m->toArray() );
        $this->assertSame( 3, $restored->count( 'sermons' ) );
        $this->assertSame( 'h1', $restored->checksum( 1 ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter ManifestTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Migration/Manifest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Immutable snapshot of the legacy data's shape at detection time: per-entity
 * counts plus per-sermon content checksums. Because the migration never alters
 * legacy data, this manifest IS the backup oracle — the Verifier (B2) compares
 * the migrated result against it.
 */
final class Manifest {
    /**
     * @param array<string,int>    $counts    Entity key → count.
     * @param array<int,string>    $checksums Legacy post ID → content checksum.
     */
    public function __construct(
        private readonly array $counts,
        private readonly array $checksums = array()
    ) {}

    public function count( string $key ): int {
        return $this->counts[ $key ] ?? 0;
    }

    /** @return array<string,int> */
    public function counts(): array {
        return $this->counts;
    }

    public function checksum( int $legacyId ): ?string {
        return $this->checksums[ $legacyId ] ?? null;
    }

    /** @return array{counts: array<string,int>, checksums: array<int,string>} */
    public function toArray(): array {
        return array( 'counts' => $this->counts, 'checksums' => $this->checksums );
    }

    /** @param array{counts?: array<string,int>, checksums?: array<int,string>} $data */
    public static function fromArray( array $data ): self {
        return new self( $data['counts'] ?? array(), $data['checksums'] ?? array() );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter ManifestTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Migration/Manifest.php tests/Unit/Migration/ManifestTest.php
git commit -m "feat: Manifest value object (counts + checksums, the backup oracle)"
```

---

### Task 7: LegacyFixture test helper (integration utility)

**Files:**
- Create: `tests/Integration/Support/LegacyFixture.php`
- Test: `tests/Integration/Support/LegacyFixtureTest.php`

**Interfaces:**
- Produces: `Sermonator\Tests\Integration\Support\LegacyFixture` — builds a legacy `wpfc_*` dataset in the WordPress test DB (used by the Detector test here and the end-to-end test in B2). Methods:
  - `registerLegacySchema(): void` — registers `wpfc_sermon`, `wpfc_sm_podcast`, and the 5 `wpfc_*` taxonomies (minimal args) so terms and posts can be created.
  - `createSermon(array $overrides = array()): int` — inserts a `wpfc_sermon` post with default legacy meta (`sermon_date` etc.); returns the post ID. `$overrides` merges into the meta.
  - `createTerm(string $taxonomy, string $name): int` — inserts a term; returns term_id.
  - `createPodcast(string $title = 'Default'): int` — inserts a `wpfc_sm_podcast` with `sm_podcast_settings` meta; returns post ID.
  - `setOption(string $name, mixed $value): void` — wrapper over `update_option`.

- [ ] **Step 1: Write the failing test — `tests/Integration/Support/LegacyFixtureTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Support;

use WP_UnitTestCase;
use Sermonator\Migration\LegacyIdentifiers;

final class LegacyFixtureTest extends WP_UnitTestCase {
    public function test_creates_a_legacy_sermon_with_meta(): void {
        $fx = new LegacyFixture();
        $fx->registerLegacySchema();
        $id = $fx->createSermon( array( 'bible_passage' => array( 'John 3:16' ) ) );

        $this->assertSame( LegacyIdentifiers::POST_TYPE_SERMON, get_post_type( $id ) );
        $this->assertSame( 'John 3:16', get_post_meta( $id, 'bible_passage', true ) );
        $this->assertNotEmpty( get_post_meta( $id, 'sermon_date', true ) );
    }

    public function test_creates_terms_and_podcasts(): void {
        $fx = new LegacyFixture();
        $fx->registerLegacySchema();
        $term = $fx->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $this->assertIsInt( $term );
        $podcast = $fx->createPodcast( 'Sunday Service' );
        $this->assertSame( LegacyIdentifiers::POST_TYPE_PODCAST, get_post_type( $podcast ) );
        $this->assertNotEmpty( get_post_meta( $podcast, 'sm_podcast_settings', true ) );
    }
}
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration --filter LegacyFixtureTest`
Expected: FAIL — `LegacyFixture` not found.

- [ ] **Step 3: Create `tests/Integration/Support/LegacyFixture.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Support;

use Sermonator\Migration\LegacyIdentifiers;

/**
 * Builds a legacy Sermon Manager (wpfc_*) dataset in the WordPress test DB so
 * the Detector (and B2's end-to-end test) can run against realistic source data.
 */
final class LegacyFixture {
    public function registerLegacySchema(): void {
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_SERMON, array( 'public' => true, 'label' => 'Legacy Sermon' ) );
        }
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_PODCAST ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_PODCAST, array( 'public' => false, 'label' => 'Legacy Podcast' ) );
        }
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                register_taxonomy( $taxonomy, LegacyIdentifiers::POST_TYPE_SERMON, array( 'public' => true ) );
            }
        }
    }

    /**
     * @param array<string, list<string>> $overrides Meta overrides (key → list of values).
     */
    public function createSermon( array $overrides = array() ): int {
        $id = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Legacy Sermon ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => 'Auto-generated blob',
        ) );

        $defaults = array(
            'sermon_date'        => array( '1612137600' ),
            'sermon_date_auto'   => array( '0' ),
            'bible_passage'      => array( 'John 3:16' ),
            'sermon_description' => array( '<p>The real body of the sermon.</p>' ),
        );

        foreach ( array_merge( $defaults, $overrides ) as $key => $values ) {
            foreach ( (array) $values as $value ) {
                add_post_meta( $id, $key, $value );
            }
        }

        return $id;
    }

    public function createTerm( string $taxonomy, string $name ): int {
        $result = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $result ) ) {
            $existing = get_term_by( 'name', $name, $taxonomy );
            return $existing ? (int) $existing->term_id : 0;
        }
        return (int) $result['term_id'];
    }

    public function createPodcast( string $title = 'Default' ): int {
        $id = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        add_post_meta( $id, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );
        return $id;
    }

    public function setOption( string $name, mixed $value ): void {
        update_option( $name, $value );
    }
}
```

- [ ] **Step 4: Run integration test to verify it passes**

Run: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration --filter LegacyFixtureTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/Support/LegacyFixture.php tests/Integration/Support/LegacyFixtureTest.php
git commit -m "test: LegacyFixture helper builds a wpfc_* dataset in the test DB"
```

---

### Task 8: Detector (integration — read-only scan → Manifest)

**Files:**
- Create: `src/Migration/Detector.php`
- Test: `tests/Integration/Migration/DetectorTest.php`

**Interfaces:**
- Consumes: `LegacyIdentifiers`, `Manifest`, `LegacyFixture` (Tasks 1, 6, 7).
- Produces: `Migration\Detector`:
  - `hasLegacyData(): bool` — true if any `wpfc_sermon` exists.
  - `detect(): Manifest` — scans counts (`sermons`, `terms_<taxonomy>` per legacy taxonomy, `podcasts`, `options` [count of `sermonmanager_*` options present], `artwork` [number of entries in the `sermon_image_plugin` option]) and a content checksum per sermon (`md5` of post_content + serialized meta). **Read-only** — performs no writes.

- [ ] **Step 1: Write the failing test — `tests/Integration/Migration/DetectorTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Detector;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

final class DetectorTest extends WP_UnitTestCase {
    private LegacyFixture $fx;

    public function set_up(): void {
        parent::set_up();
        $this->fx = new LegacyFixture();
        $this->fx->registerLegacySchema();
    }

    public function test_has_legacy_data_false_on_empty(): void {
        $this->assertFalse( ( new Detector() )->hasLegacyData() );
    }

    public function test_counts_sermons_terms_podcasts(): void {
        $this->fx->createSermon();
        $this->fx->createSermon();
        $this->fx->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor A' );
        $this->fx->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor B' );
        $this->fx->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $this->fx->createPodcast();

        $manifest = ( new Detector() )->detect();

        $this->assertTrue( ( new Detector() )->hasLegacyData() );
        $this->assertSame( 2, $manifest->count( 'sermons' ) );
        $this->assertSame( 2, $manifest->count( 'terms_wpfc_preacher' ) );
        $this->assertSame( 1, $manifest->count( 'terms_wpfc_sermon_series' ) );
        $this->assertSame( 1, $manifest->count( 'podcasts' ) );
    }

    public function test_counts_options_and_artwork(): void {
        $this->fx->createSermon();
        $this->fx->setOption( 'sermonmanager_player', 'plyr' );
        $this->fx->setOption( 'sermonmanager_archive_slug', 'sermons' );
        $this->fx->setOption( LegacyIdentifiers::OPTION_TERM_IMAGES, array( 101 => 555, 102 => 556 ) );

        $manifest = ( new Detector() )->detect();
        $this->assertSame( 2, $manifest->count( 'options' ) );
        $this->assertSame( 2, $manifest->count( 'artwork' ) );
    }

    public function test_records_a_checksum_per_sermon(): void {
        $id = $this->fx->createSermon();
        $manifest = ( new Detector() )->detect();
        $this->assertNotNull( $manifest->checksum( $id ) );
    }

    public function test_detect_does_not_write(): void {
        $id = $this->fx->createSermon();
        $before = get_post_meta( $id );
        ( new Detector() )->detect();
        $this->assertEquals( $before, get_post_meta( $id ), 'detect() must not modify legacy data' );
    }
}
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration --filter DetectorTest`
Expected: FAIL — `Detector` not found.

- [ ] **Step 3: Create `src/Migration/Detector.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Read-only scan of an existing Sermon Manager install. Produces a Manifest
 * (counts + per-sermon checksums) used to size the migration and to verify it
 * later. Performs NO writes — legacy data is never touched.
 */
final class Detector {
    public function hasLegacyData(): bool {
        $q = new \WP_Query( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );
        return $q->found_posts > 0;
    }

    public function detect(): Manifest {
        $counts    = array();
        $checksums = array();

        // Sermons + per-sermon checksum.
        $ids = get_posts( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        $counts['sermons'] = count( $ids );
        foreach ( $ids as $id ) {
            $checksums[ (int) $id ] = $this->sermonChecksum( (int) $id );
        }

        // Terms per legacy taxonomy.
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
            $counts[ 'terms_' . $taxonomy ] = is_array( $terms ) ? count( $terms ) : 0;
        }

        // Podcasts.
        $counts['podcasts'] = count( get_posts( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) ) );

        // Settings options present (sermonmanager_*).
        $counts['options'] = $this->countLegacyOptions();

        // Artwork associations (term_taxonomy_id → attachment_id).
        $artwork = get_option( LegacyIdentifiers::OPTION_TERM_IMAGES, array() );
        $counts['artwork'] = is_array( $artwork ) ? count( $artwork ) : 0;

        return new Manifest( $counts, $checksums );
    }

    private function sermonChecksum( int $id ): string {
        $post = get_post( $id );
        $meta = get_post_meta( $id );
        ksort( $meta );
        return md5( ( $post ? $post->post_content : '' ) . wp_json_encode( $meta ) );
    }

    private function countLegacyOptions(): int {
        global $wpdb;
        $like = $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%';
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
        );
    }
}
```

- [ ] **Step 4: Run integration test to verify it passes**

Run: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration --filter DetectorTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the whole suite (no regression)**

Run: `composer test:unit`
Then: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Migration/Detector.php tests/Integration/Migration/DetectorTest.php
git commit -m "feat: read-only Detector scans legacy data into a Manifest"
```

---

## Self-Review

**Spec coverage (B1's slice — spec §4 components: Schema+Contract, Detector, Mappers, Manifest):**
- Mapping Contract (declarative old→new, single source of truth) → Task 2. ✓
- Legacy schema constants + crosswalk keys → Task 1. ✓
- Record mappers (pure transforms — fidelity): sermon meta → Task 3; post_content/description rule → Task 4; options → Task 5. ✓
- "Copy all unknown meta verbatim" rule → Task 3 (`test_passes_through_unknown_meta_verbatim`). ✓
- Drop denormalized service_type + sermon_description-as-meta → Tasks 2/3. ✓
- Legacy-date flag without altering raw value → Task 3. ✓
- Manifest (counts + checksums, the backup oracle) → Task 6. ✓
- Detector (read-only scan, source manifest) → Task 8; proven not to write (`test_detect_does_not_write`). ✓
- Artwork option (`sermon_image_plugin`, tt_id-keyed) counted → Task 8. ✓
- "Read-only w.r.t. all data" constraint → no task writes the new namespace or mutates legacy data. ✓

**Deferred to Plan B2 (correctly out of B1):** Writer, Crosswalk query helpers, term/artwork/podcast/option *migration* (writing), Orchestrator + state machine, Action Scheduler batch processor, Verifier/Reconciler + Report, Rollback, Finalizer, WP-CLI, full-cycle end-to-end test. (Artwork tt_id→new-tt_id remapping happens in B2's term/artwork migration.)

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** `LegacyIdentifiers::sermonTaxonomies()/sermonMetaKeys()`, `Crosswalk::LEGACY_POST_ID/LEGACY_TERM_ID`, `MappingContract::postTypeMap()/taxonomyMap()/metaKeyMap()/droppedMetaKeys()/mapOptionName()`, `SermonMetaMapper::map()` (returns `meta`/`description`/`flags`), `PostContentReconciler::reconcile()` (returns `content`/`backup`/`flag`), `OptionMapper::map()`, `Manifest` (`count`/`counts`/`checksum`/`toArray`/`fromArray`), `Detector` (`hasLegacyData`/`detect`), `LegacyFixture` (`registerLegacySchema`/`createSermon`/`createTerm`/`createPodcast`/`setOption`) are referenced consistently across tasks and tests.

**Note for B2:** the Writer consumes `SermonMetaMapper::map()` + `PostContentReconciler::reconcile()` to build each new sermon; term/artwork migration must remap the `sermon_image_plugin` map from old `term_taxonomy_id` → new `term_taxonomy_id` via the term crosswalk; the Verifier consumes the `Manifest` from `Detector::detect()`.
