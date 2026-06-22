# Sermonator — Plan A: Plugin Foundation & Data Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up an installable, modern WordPress plugin ("Sermonator") that registers the `sermonator_` data model — post types, taxonomies, capabilities — behind a PHP/WP version gate, with the Bible-book taxonomy seeded from a default canon.

**Architecture:** A namespaced (`Sermonator\`), Composer-PSR-4 plugin. A single source-of-truth `Schema\Identifiers` class declares every slug/key; a `Model\Registrar` registers post types and taxonomies from those identifiers; `Model\Capabilities` and `Model\Activation` handle roles and one-time seeding. A pure `Support\VersionGate` decides whether the plugin boots. No custom DB tables.

**Tech Stack:** PHP 8.1+, WordPress 6.0+, Composer (PSR-4), PHPUnit 9.6, Brain Monkey (unit/mocking via `yoast/wp-test-utils`), `@wordpress/env` (wp-env, Docker) for integration tests.

## Global Constraints

(Copied verbatim from the spec — every task's requirements implicitly include these.)

- **Platform floor:** PHP 8.1+ / WordPress 6.0+. Below this → no boot, a graceful "update PHP/WP to migrate" admin notice, data untouched.
- **Namespace:** all identifiers use the `sermonator_` prefix. PHP namespace root `Sermonator\` → `src/`.
- **No custom DB tables.** State lives in options + back-reference meta.
- **Post types:** `sermonator_sermon` (own `capability_type`, `map_meta_cap`, `show_in_rest`, supports the editor — body is native `post_content`), `sermonator_podcast`.
- **Taxonomies (non-hierarchical):** `sermonator_preacher`, `sermonator_series`, `sermonator_topic`, `sermonator_book`, `sermonator_service_type`.
- **`sermonator_book` is seeded-but-extensible:** ships a default canon, remains admin-editable (Catholic/Orthodox deuterocanon), ordering tolerant of additions.
- **Capabilities:** `sermonator_sermon` caps + `manage_sermonator_categories` + `manage_sermonator_settings`, granted to admin/editor/author.
- **Single-site only** (multisite out of scope).
- **Data preservation is the highest bar** — nothing in this plan touches legacy `wpfc_*` data.

---

## File Structure

```
sermonator.php                       # Plugin header + boot entry (version gate → Plugin::boot)
composer.json                        # PSR-4 autoload, dev deps
phpunit.xml.dist                     # Two suites: unit, integration
.wp-env.json                         # wp-env config for integration tests
tests/bootstrap-unit.php             # Brain Monkey bootstrap (no WP)
tests/bootstrap-integration.php      # WP test bootstrap (wp-env)
src/
  Plugin.php                         # Boot orchestrator (wires Registrar + Capabilities)
  Support/VersionGate.php            # Pure: is PHP/WP new enough? + failure message
  Schema/Identifiers.php             # Single source of truth: every slug + meta key
  Schema/BibleCanon.php              # Default Bible-book list (66) for seeding
  Model/Registrar.php                # register_post_type / register_taxonomy
  Model/Capabilities.php             # Add caps to roles
  Model/Activation.php               # Activation hook: seed canon, flush rewrite
tests/
  Unit/Support/VersionGateTest.php
  Unit/Schema/IdentifiersTest.php
  Unit/Schema/BibleCanonTest.php
  Integration/Model/RegistrarTest.php
  Integration/Model/CapabilitiesTest.php
  Integration/Model/ActivationTest.php
```

---

### Task 1: Project scaffold, autoload & main plugin file

**Files:**
- Create: `composer.json`
- Create: `sermonator.php`
- Create: `src/Plugin.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Sermonator\Plugin::boot(): void` (idempotent boot entry, called by `sermonator.php`); Composer PSR-4 autoload mapping `Sermonator\` → `src/`.

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "sermonator/sermonator",
  "description": "Sermon management for WordPress.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/wp-test-utils": "^1.2"
  },
  "autoload": {
    "psr-4": { "Sermonator\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "Sermonator\\Tests\\": "tests/" }
  },
  "config": {
    "allow-plugins": { "composer/installers": true }
  }
}
```

- [ ] **Step 2: Create the main plugin file `sermonator.php`**

```php
<?php
/**
 * Plugin Name: Sermonator
 * Description: Sermon management for WordPress.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * License: GPL-2.0-or-later
 * Text Domain: sermonator
 *
 * @package Sermonator
 */

defined( 'ABSPATH' ) || exit;

define( 'SERMONATOR_VERSION', '0.1.0' );
define( 'SERMONATOR_FILE', __FILE__ );
define( 'SERMONATOR_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

\Sermonator\Plugin::boot();
```

- [ ] **Step 3: Create `src/Plugin.php` (minimal boot, expanded in later tasks)**

```php
<?php

declare(strict_types=1);

namespace Sermonator;

use Sermonator\Support\VersionGate;

final class Plugin {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        $gate = new VersionGate( PHP_VERSION, get_bloginfo( 'version' ) );
        if ( ! $gate->isSatisfied() ) {
            add_action(
                'admin_notices',
                static function () use ( $gate ): void {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html( $gate->failureMessage() )
                    );
                }
            );
            return;
        }

        // Registrar + Capabilities are wired here in Tasks 4 and 5.
    }
}
```

- [ ] **Step 4: Install dependencies and verify autoload**

Run: `composer install`
Expected: `vendor/autoload.php` exists; no errors.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock sermonator.php src/Plugin.php
git commit -m "feat: plugin scaffold, PSR-4 autoload, boot entry"
```

---

### Task 2: Test infrastructure (unit + integration)

**Files:**
- Create: `phpunit.xml.dist`
- Create: `.wp-env.json`
- Create: `tests/bootstrap-unit.php`
- Create: `tests/bootstrap-integration.php`

**Interfaces:**
- Consumes: Composer autoload from Task 1.
- Produces: two runnable suites — `composer test:unit` (pure PHP, Brain Monkey) and `composer test:integration` (real WP via wp-env). Later tasks add tests to `tests/Unit/**` and `tests/Integration/**`.

- [ ] **Step 1: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    colors="true"
    failOnWarning="true"
    failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Create `tests/bootstrap-unit.php` (no WordPress)**

```php
<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

\Brain\Monkey\setUp();
register_shutdown_function( static function (): void {
    \Brain\Monkey\tearDown();
} );
```

- [ ] **Step 3: Create `.wp-env.json` for integration**

```json
{
  "core": "WordPress/WordPress#6.5",
  "phpVersion": "8.1",
  "plugins": [ "." ],
  "config": { "WP_DEBUG": true }
}
```

- [ ] **Step 4: Create `tests/bootstrap-integration.php`**

```php
<?php

declare(strict_types=1);

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function (): void {
    require dirname( __DIR__ ) . '/sermonator.php';
} );

require_once $wp_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 5: Add test scripts to `composer.json`**

Add this `"scripts"` block to `composer.json` (alongside the existing keys):

```json
  "scripts": {
    "test:unit": "phpunit --bootstrap tests/bootstrap-unit.php --testsuite unit",
    "test:integration": "phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration"
  }
```

- [ ] **Step 6: Add a smoke test to prove the unit harness runs — `tests/Unit/SmokeTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
    public function test_harness_runs(): void {
        $this->assertTrue( true );
    }
}
```

- [ ] **Step 7: Run the unit suite**

Run: `composer test:unit`
Expected: PASS (1 test, `test_harness_runs`).

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml.dist .wp-env.json tests/ composer.json
git commit -m "test: unit + integration harness (PHPUnit, Brain Monkey, wp-env)"
```

---

### Task 3: Version gate (pure, unit-tested)

**Files:**
- Create: `src/Support/VersionGate.php`
- Test: `tests/Unit/Support/VersionGateTest.php`

**Interfaces:**
- Consumes: nothing (pure — takes versions as constructor args).
- Produces: `Sermonator\Support\VersionGate::__construct(string $phpVersion, string $wpVersion)`, `->isSatisfied(): bool`, `->failureMessage(): string`. Constants `VersionGate::MIN_PHP = '8.1'`, `VersionGate::MIN_WP = '6.0'`. `Plugin::boot()` (Task 1) already calls these.

- [ ] **Step 1: Write the failing test — `tests/Unit/Support/VersionGateTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sermonator\Support\VersionGate;

final class VersionGateTest extends TestCase {
    public function test_satisfied_when_both_versions_meet_floor(): void {
        $gate = new VersionGate( '8.1.0', '6.0' );
        $this->assertTrue( $gate->isSatisfied() );
    }

    public function test_satisfied_on_newer_versions(): void {
        $gate = new VersionGate( '8.3.2', '6.5' );
        $this->assertTrue( $gate->isSatisfied() );
    }

    public function test_not_satisfied_when_php_too_old(): void {
        $gate = new VersionGate( '8.0.30', '6.5' );
        $this->assertFalse( $gate->isSatisfied() );
        $this->assertStringContainsString( 'PHP 8.1+', $gate->failureMessage() );
        $this->assertStringContainsString( '8.0.30', $gate->failureMessage() );
    }

    public function test_not_satisfied_when_wp_too_old(): void {
        $gate = new VersionGate( '8.1.0', '5.9' );
        $this->assertFalse( $gate->isSatisfied() );
        $this->assertStringContainsString( 'WordPress 6.0+', $gate->failureMessage() );
    }

    public function test_failure_message_reassures_about_data(): void {
        $gate = new VersionGate( '8.0', '5.9' );
        $this->assertStringContainsString( 'data is untouched', $gate->failureMessage() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter VersionGateTest`
Expected: FAIL with "Class Sermonator\Support\VersionGate not found".

- [ ] **Step 3: Write `src/Support/VersionGate.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Support;

final class VersionGate {
    public const MIN_PHP = '8.1';
    public const MIN_WP  = '6.0';

    public function __construct(
        private readonly string $phpVersion,
        private readonly string $wpVersion
    ) {}

    public function isSatisfied(): bool {
        return version_compare( $this->phpVersion, self::MIN_PHP, '>=' )
            && version_compare( $this->wpVersion, self::MIN_WP, '>=' );
    }

    public function failureMessage(): string {
        $problems = array();
        if ( version_compare( $this->phpVersion, self::MIN_PHP, '<' ) ) {
            $problems[] = sprintf( 'PHP %s+ (you have %s)', self::MIN_PHP, $this->phpVersion );
        }
        if ( version_compare( $this->wpVersion, self::MIN_WP, '<' ) ) {
            $problems[] = sprintf( 'WordPress %s+ (you have %s)', self::MIN_WP, $this->wpVersion );
        }

        return 'Sermonator requires ' . implode( ' and ', $problems )
            . '. The plugin will not run until you update; your existing sermon data is untouched.';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter VersionGateTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/VersionGate.php tests/Unit/Support/VersionGateTest.php
git commit -m "feat: version gate for PHP 8.1 / WP 6.0 floor"
```

---

### Task 4: Schema identifiers (single source of truth, unit-tested)

**Files:**
- Create: `src/Schema/Identifiers.php`
- Test: `tests/Unit/Schema/IdentifiersTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Sermonator\Schema\Identifiers` with constants and static accessors:
  - Post-type constants: `POST_TYPE_SERMON`, `POST_TYPE_PODCAST`.
  - Taxonomy constants: `TAX_PREACHER`, `TAX_SERIES`, `TAX_TOPIC`, `TAX_BOOK`, `TAX_SERVICE_TYPE`.
  - `Identifiers::sermonTaxonomies(): array` → the five taxonomy slugs (ordered).
  - `Identifiers::metaKeys(): array` → list of `sermonator_*` meta key strings used on sermons.
  - The Registrar (Task 5) consumes `sermonTaxonomies()`; the migration engine (Plan B) consumes `metaKeys()`.

- [ ] **Step 1: Write the failing test — `tests/Unit/Schema/IdentifiersTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\Identifiers;

final class IdentifiersTest extends TestCase {
    public function test_post_types_are_prefixed(): void {
        $this->assertSame( 'sermonator_sermon', Identifiers::POST_TYPE_SERMON );
        $this->assertSame( 'sermonator_podcast', Identifiers::POST_TYPE_PODCAST );
    }

    public function test_five_sermon_taxonomies_in_order(): void {
        $this->assertSame(
            array(
                'sermonator_preacher',
                'sermonator_series',
                'sermonator_topic',
                'sermonator_book',
                'sermonator_service_type',
            ),
            Identifiers::sermonTaxonomies()
        );
    }

    public function test_every_identifier_uses_the_prefix(): void {
        $all = array_merge(
            array( Identifiers::POST_TYPE_SERMON, Identifiers::POST_TYPE_PODCAST ),
            Identifiers::sermonTaxonomies(),
            Identifiers::metaKeys()
        );
        foreach ( $all as $id ) {
            $this->assertMatchesRegularExpression( '/^_?sermonator_/', $id, "$id is not prefixed" );
        }
    }

    public function test_identifiers_are_unique(): void {
        $all = array_merge(
            Identifiers::sermonTaxonomies(),
            Identifiers::metaKeys()
        );
        $this->assertSame( count( $all ), count( array_unique( $all ) ), 'duplicate identifier' );
    }

    public function test_meta_keys_include_core_sermon_fields(): void {
        $keys = Identifiers::metaKeys();
        foreach ( array( 'sermonator_date', 'sermonator_bible_passage', 'sermonator_audio', 'sermonator_views' ) as $expected ) {
            $this->assertContains( $expected, $keys );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter IdentifiersTest`
Expected: FAIL with "Class Sermonator\Schema\Identifiers not found".

- [ ] **Step 3: Write `src/Schema/Identifiers.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Schema;

final class Identifiers {
    public const POST_TYPE_SERMON  = 'sermonator_sermon';
    public const POST_TYPE_PODCAST = 'sermonator_podcast';

    public const TAX_PREACHER     = 'sermonator_preacher';
    public const TAX_SERIES       = 'sermonator_series';
    public const TAX_TOPIC        = 'sermonator_topic';
    public const TAX_BOOK         = 'sermonator_book';
    public const TAX_SERVICE_TYPE = 'sermonator_service_type';

    public const META_DATE           = 'sermonator_date';
    public const META_DATE_AUTO      = 'sermonator_date_auto';
    public const META_BIBLE_PASSAGE  = 'sermonator_bible_passage';
    public const META_AUDIO          = 'sermonator_audio';
    public const META_AUDIO_ID       = 'sermonator_audio_id';
    public const META_AUDIO_DURATION = '_sermonator_audio_duration';
    public const META_AUDIO_SIZE     = '_sermonator_audio_size';
    public const META_VIDEO_EMBED    = 'sermonator_video_embed';
    public const META_VIDEO_URL      = 'sermonator_video_url';
    public const META_NOTES          = 'sermonator_notes';
    public const META_BULLETIN       = 'sermonator_bulletin';
    public const META_VIEWS          = 'sermonator_views';

    /** @return list<string> The five sermon taxonomy slugs, in display order. */
    public static function sermonTaxonomies(): array {
        return array(
            self::TAX_PREACHER,
            self::TAX_SERIES,
            self::TAX_TOPIC,
            self::TAX_BOOK,
            self::TAX_SERVICE_TYPE,
        );
    }

    /** @return list<string> Every sermonator_* meta key stored on a sermon. */
    public static function metaKeys(): array {
        return array(
            self::META_DATE,
            self::META_DATE_AUTO,
            self::META_BIBLE_PASSAGE,
            self::META_AUDIO,
            self::META_AUDIO_ID,
            self::META_AUDIO_DURATION,
            self::META_AUDIO_SIZE,
            self::META_VIDEO_EMBED,
            self::META_VIDEO_URL,
            self::META_NOTES,
            self::META_BULLETIN,
            self::META_VIEWS,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter IdentifiersTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Schema/Identifiers.php tests/Unit/Schema/IdentifiersTest.php
git commit -m "feat: Schema\\Identifiers single source of truth for slugs and meta keys"
```

---

### Task 5: Bible canon (default book list, unit-tested)

**Files:**
- Create: `src/Schema/BibleCanon.php`
- Test: `tests/Unit/Schema/BibleCanonTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Sermonator\Schema\BibleCanon::defaultBooks(): array` → ordered list of the 66 Protestant-canon book names used to seed the `sermonator_book` taxonomy on activation. The list is a *seed*, not a constraint — admins add more later.

- [ ] **Step 1: Write the failing test — `tests/Unit/Schema/BibleCanonTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\BibleCanon;

final class BibleCanonTest extends TestCase {
    public function test_default_canon_has_66_books(): void {
        $this->assertCount( 66, BibleCanon::defaultBooks() );
    }

    public function test_starts_with_genesis_ends_with_revelation(): void {
        $books = BibleCanon::defaultBooks();
        $this->assertSame( 'Genesis', $books[0] );
        $this->assertSame( 'Revelation', $books[ count( $books ) - 1 ] );
    }

    public function test_book_names_are_unique(): void {
        $books = BibleCanon::defaultBooks();
        $this->assertSame( count( $books ), count( array_unique( $books ) ) );
    }

    public function test_does_not_include_deuterocanon_by_default(): void {
        // Deuterocanon is added by admins, not seeded.
        $this->assertNotContains( 'Tobit', BibleCanon::defaultBooks() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter BibleCanonTest`
Expected: FAIL with "Class Sermonator\Schema\BibleCanon not found".

- [ ] **Step 3: Write `src/Schema/BibleCanon.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Schema;

final class BibleCanon {
    /**
     * Default 66-book Protestant canon, in traditional order, used to seed
     * the sermonator_book taxonomy. A seed only — admins may add others
     * (e.g. Catholic/Orthodox deuterocanonical books).
     *
     * @return list<string>
     */
    public static function defaultBooks(): array {
        return array(
            'Genesis', 'Exodus', 'Leviticus', 'Numbers', 'Deuteronomy',
            'Joshua', 'Judges', 'Ruth', '1 Samuel', '2 Samuel',
            '1 Kings', '2 Kings', '1 Chronicles', '2 Chronicles', 'Ezra',
            'Nehemiah', 'Esther', 'Job', 'Psalms', 'Proverbs',
            'Ecclesiastes', 'Song of Solomon', 'Isaiah', 'Jeremiah', 'Lamentations',
            'Ezekiel', 'Daniel', 'Hosea', 'Joel', 'Amos',
            'Obadiah', 'Jonah', 'Micah', 'Nahum', 'Habakkuk',
            'Zephaniah', 'Haggai', 'Zechariah', 'Malachi',
            'Matthew', 'Mark', 'Luke', 'John', 'Acts',
            'Romans', '1 Corinthians', '2 Corinthians', 'Galatians', 'Ephesians',
            'Philippians', 'Colossians', '1 Thessalonians', '2 Thessalonians', '1 Timothy',
            '2 Timothy', 'Titus', 'Philemon', 'Hebrews', 'James',
            '1 Peter', '2 Peter', '1 John', '2 John', '3 John',
            'Jude', 'Revelation',
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter BibleCanonTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Schema/BibleCanon.php tests/Unit/Schema/BibleCanonTest.php
git commit -m "feat: default Bible canon for seeding the book taxonomy"
```

---

### Task 6: Model Registrar — post types & taxonomies (integration-tested)

**Files:**
- Create: `src/Model/Registrar.php`
- Modify: `src/Plugin.php` (wire Registrar into boot)
- Test: `tests/Integration/Model/RegistrarTest.php`

**Interfaces:**
- Consumes: `Schema\Identifiers` (Task 4).
- Produces: `Sermonator\Model\Registrar::register(): void` — registers `sermonator_sermon`, `sermonator_podcast`, and the five taxonomies. Hooked on `init`. `Plugin::boot()` calls `(new Registrar())->hook()`.

- [ ] **Step 1: Write the failing integration test — `tests/Integration/Model/RegistrarTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers;

final class RegistrarTest extends WP_UnitTestCase {
    public function test_sermon_post_type_is_registered(): void {
        $this->assertTrue( post_type_exists( Identifiers::POST_TYPE_SERMON ) );
    }

    public function test_podcast_post_type_is_registered(): void {
        $this->assertTrue( post_type_exists( Identifiers::POST_TYPE_PODCAST ) );
    }

    public function test_sermon_supports_editor_for_native_post_content_body(): void {
        $this->assertTrue( post_type_supports( Identifiers::POST_TYPE_SERMON, 'editor' ) );
    }

    public function test_sermon_is_rest_enabled(): void {
        $object = get_post_type_object( Identifiers::POST_TYPE_SERMON );
        $this->assertTrue( $object->show_in_rest );
    }

    public function test_all_five_taxonomies_registered_for_sermon(): void {
        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            $this->assertTrue( taxonomy_exists( $taxonomy ), "$taxonomy missing" );
            $this->assertContains(
                Identifiers::POST_TYPE_SERMON,
                get_taxonomy( $taxonomy )->object_type,
                "$taxonomy not attached to sermon"
            );
        }
    }

    public function test_taxonomies_are_non_hierarchical(): void {
        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            $this->assertFalse( get_taxonomy( $taxonomy )->hierarchical, "$taxonomy should be flat" );
        }
    }
}
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `wp-env start && composer test:integration -- --filter RegistrarTest`
Expected: FAIL — post types/taxonomies do not exist yet.

- [ ] **Step 3: Write `src/Model/Registrar.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Model;

use Sermonator\Schema\Identifiers;

final class Registrar {
    public function hook(): void {
        add_action( 'init', array( $this, 'register' ), 5 );
    }

    public function register(): void {
        $this->registerSermonPostType();
        $this->registerPodcastPostType();
        $this->registerTaxonomies();
    }

    private function registerSermonPostType(): void {
        register_post_type(
            Identifiers::POST_TYPE_SERMON,
            array(
                'labels'          => array(
                    'name'          => __( 'Sermons', 'sermonator' ),
                    'singular_name' => __( 'Sermon', 'sermonator' ),
                    'menu_name'     => __( 'Sermonator', 'sermonator' ),
                ),
                'public'          => true,
                'show_in_rest'    => true,
                'has_archive'     => true,
                'menu_icon'       => 'dashicons-book-alt',
                'capability_type' => Identifiers::POST_TYPE_SERMON,
                'map_meta_cap'    => true,
                'hierarchical'    => false,
                'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'revisions', 'author' ),
                'rewrite'         => array( 'slug' => 'sermons', 'with_front' => false ),
            )
        );
    }

    private function registerPodcastPostType(): void {
        register_post_type(
            Identifiers::POST_TYPE_PODCAST,
            array(
                'labels'       => array(
                    'name'          => __( 'Podcasts', 'sermonator' ),
                    'singular_name' => __( 'Podcast', 'sermonator' ),
                ),
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON,
                'show_in_rest' => true,
                'supports'     => array( 'title' ),
            )
        );
    }

    private function registerTaxonomies(): void {
        $labels = array(
            Identifiers::TAX_PREACHER     => array( 'Preachers', 'Preacher' ),
            Identifiers::TAX_SERIES       => array( 'Series', 'Series' ),
            Identifiers::TAX_TOPIC        => array( 'Topics', 'Topic' ),
            Identifiers::TAX_BOOK         => array( 'Books', 'Book' ),
            Identifiers::TAX_SERVICE_TYPE => array( 'Service Types', 'Service Type' ),
        );

        foreach ( Identifiers::sermonTaxonomies() as $taxonomy ) {
            list( $plural, $singular ) = $labels[ $taxonomy ];
            register_taxonomy(
                $taxonomy,
                array( Identifiers::POST_TYPE_SERMON ),
                array(
                    'labels'       => array(
                        'name'          => $plural,
                        'singular_name' => $singular,
                    ),
                    'hierarchical' => false,
                    'public'       => true,
                    'show_ui'      => true,
                    'show_in_rest' => true,
                    'query_var'    => true,
                )
            );
        }
    }
}
```

- [ ] **Step 4: Wire Registrar into `src/Plugin.php`**

Replace the `// Registrar + Capabilities are wired here...` comment line in `boot()` with:

```php
        ( new \Sermonator\Model\Registrar() )->hook();
```

- [ ] **Step 5: Run integration test to verify it passes**

Run: `composer test:integration -- --filter RegistrarTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Model/Registrar.php src/Plugin.php tests/Integration/Model/RegistrarTest.php
git commit -m "feat: register sermonator_ post types and taxonomies"
```

---

### Task 7: Capabilities (integration-tested)

**Files:**
- Create: `src/Model/Capabilities.php`
- Modify: `src/Plugin.php` (wire Capabilities into boot)
- Test: `tests/Integration/Model/CapabilitiesTest.php`

**Interfaces:**
- Consumes: `Schema\Identifiers` (Task 4).
- Produces: `Sermonator\Model\Capabilities::grant(): void` — adds `sermonator_sermon` caps + `manage_sermonator_categories` + `manage_sermonator_settings` to roles. `Capabilities::sermonCaps(): array` returns the singular/plural cap map for `register_post_type`. Called from the activation hook (Task 8) and (idempotently) ensured on boot.

- [ ] **Step 1: Write the failing integration test — `tests/Integration/Model/CapabilitiesTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
use Sermonator\Model\Capabilities;

final class CapabilitiesTest extends WP_UnitTestCase {
    public function test_administrator_can_edit_sermons(): void {
        ( new Capabilities() )->grant();
        $admin = get_role( 'administrator' );
        $this->assertTrue( $admin->has_cap( 'edit_sermonator_sermons' ) );
        $this->assertTrue( $admin->has_cap( 'publish_sermonator_sermons' ) );
        $this->assertTrue( $admin->has_cap( 'manage_sermonator_categories' ) );
        $this->assertTrue( $admin->has_cap( 'manage_sermonator_settings' ) );
    }

    public function test_editor_can_manage_but_not_settings(): void {
        ( new Capabilities() )->grant();
        $editor = get_role( 'editor' );
        $this->assertTrue( $editor->has_cap( 'edit_others_sermonator_sermons' ) );
        $this->assertTrue( $editor->has_cap( 'manage_sermonator_categories' ) );
        $this->assertFalse( $editor->has_cap( 'manage_sermonator_settings' ) );
    }

    public function test_author_can_edit_own_but_not_others(): void {
        ( new Capabilities() )->grant();
        $author = get_role( 'author' );
        $this->assertTrue( $author->has_cap( 'edit_sermonator_sermons' ) );
        $this->assertFalse( $author->has_cap( 'edit_others_sermonator_sermons' ) );
    }

    public function test_grant_is_idempotent(): void {
        ( new Capabilities() )->grant();
        ( new Capabilities() )->grant();
        $this->assertTrue( get_role( 'administrator' )->has_cap( 'edit_sermonator_sermons' ) );
    }
}
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `composer test:integration -- --filter CapabilitiesTest`
Expected: FAIL — caps not granted / class missing.

- [ ] **Step 3: Write `src/Model/Capabilities.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Model;

use Sermonator\Schema\Identifiers;

final class Capabilities {
    /** Caps every editing role gets. */
    private const BASE_CAPS = array(
        'edit_sermonator_sermon',
        'read_sermonator_sermon',
        'delete_sermonator_sermon',
        'edit_sermonator_sermons',
        'edit_published_sermonator_sermons',
        'publish_sermonator_sermons',
        'delete_sermonator_sermons',
        'delete_published_sermonator_sermons',
        'read_private_sermonator_sermons',
        'manage_sermonator_categories',
    );

    /** Caps only editor + administrator get. */
    private const ELEVATED_CAPS = array(
        'edit_others_sermonator_sermons',
        'delete_others_sermonator_sermons',
        'edit_private_sermonator_sermons',
        'delete_private_sermonator_sermons',
    );

    /** Caps only administrator gets. */
    private const ADMIN_ONLY_CAPS = array(
        'manage_sermonator_settings',
    );

    public function grant(): void {
        $this->addTo( 'administrator', array_merge( self::BASE_CAPS, self::ELEVATED_CAPS, self::ADMIN_ONLY_CAPS ) );
        $this->addTo( 'editor', array_merge( self::BASE_CAPS, self::ELEVATED_CAPS ) );
        $this->addTo( 'author', self::BASE_CAPS );
    }

    /**
     * Capability map for register_post_type( capability_type => sermonator_sermon ).
     *
     * @return array<string,string>
     */
    public static function sermonCaps(): array {
        return array(
            'manage_sermonator_categories' => 'manage_sermonator_categories',
            'manage_sermonator_settings'   => 'manage_sermonator_settings',
        );
    }

    /** @param list<string> $caps */
    private function addTo( string $roleName, array $caps ): void {
        $role = get_role( $roleName );
        if ( null === $role ) {
            return;
        }
        foreach ( $caps as $cap ) {
            if ( ! $role->has_cap( $cap ) ) {
                $role->add_cap( $cap );
            }
        }
    }
}
```

- [ ] **Step 4: Run integration test to verify it passes**

Run: `composer test:integration -- --filter CapabilitiesTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Capabilities.php tests/Integration/Model/CapabilitiesTest.php
git commit -m "feat: sermonator capabilities granted to admin/editor/author"
```

---

### Task 8: Activation — seed book canon & flush rewrites (integration-tested)

**Files:**
- Create: `src/Model/Activation.php`
- Modify: `sermonator.php` (register activation hook)
- Test: `tests/Integration/Model/ActivationTest.php`

**Interfaces:**
- Consumes: `Schema\Identifiers`, `Schema\BibleCanon`, `Model\Capabilities`, `Model\Registrar`.
- Produces: `Sermonator\Model\Activation::activate(): void` — registers post types/taxonomies (so they exist during activation), grants caps, seeds the `sermonator_book` taxonomy from `BibleCanon::defaultBooks()` (idempotent — skips books that already exist), records `sermonator_version`, and flushes rewrite rules. Registered via `register_activation_hook` in `sermonator.php`.

- [ ] **Step 1: Write the failing integration test — `tests/Integration/Model/ActivationTest.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
use Sermonator\Model\Activation;
use Sermonator\Schema\BibleCanon;
use Sermonator\Schema\Identifiers;

final class ActivationTest extends WP_UnitTestCase {
    public function test_seeds_full_default_canon(): void {
        ( new Activation() )->activate();
        $terms = get_terms( array( 'taxonomy' => Identifiers::TAX_BOOK, 'hide_empty' => false ) );
        $names = wp_list_pluck( $terms, 'name' );
        foreach ( BibleCanon::defaultBooks() as $book ) {
            $this->assertContains( $book, $names, "$book not seeded" );
        }
    }

    public function test_seeding_is_idempotent(): void {
        ( new Activation() )->activate();
        ( new Activation() )->activate();
        $count = count( get_terms( array( 'taxonomy' => Identifiers::TAX_BOOK, 'hide_empty' => false ) ) );
        $this->assertSame( count( BibleCanon::defaultBooks() ), $count );
    }

    public function test_does_not_delete_admin_added_books(): void {
        ( new Activation() )->activate();
        wp_insert_term( 'Tobit', Identifiers::TAX_BOOK );
        ( new Activation() )->activate(); // re-activation must not remove custom books
        $names = wp_list_pluck(
            get_terms( array( 'taxonomy' => Identifiers::TAX_BOOK, 'hide_empty' => false ) ),
            'name'
        );
        $this->assertContains( 'Tobit', $names );
    }

    public function test_records_version(): void {
        ( new Activation() )->activate();
        $this->assertSame( SERMONATOR_VERSION, get_option( 'sermonator_version' ) );
    }
}
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `composer test:integration -- --filter ActivationTest`
Expected: FAIL — `Sermonator\Model\Activation` not found.

- [ ] **Step 3: Write `src/Model/Activation.php`**

```php
<?php

declare(strict_types=1);

namespace Sermonator\Model;

use Sermonator\Schema\BibleCanon;
use Sermonator\Schema\Identifiers;

final class Activation {
    public function activate(): void {
        // Ensure post types & taxonomies exist during activation.
        ( new Registrar() )->register();
        ( new Capabilities() )->grant();
        $this->seedBibleBooks();

        if ( ! get_option( 'sermonator_version' ) ) {
            add_option( 'sermonator_version', SERMONATOR_VERSION, '', 'no' );
        } else {
            update_option( 'sermonator_version', SERMONATOR_VERSION, 'no' );
        }

        flush_rewrite_rules();
    }

    /** Seed default canon; never removes existing or admin-added terms. */
    private function seedBibleBooks(): void {
        foreach ( BibleCanon::defaultBooks() as $book ) {
            if ( ! term_exists( $book, Identifiers::TAX_BOOK ) ) {
                wp_insert_term( $book, Identifiers::TAX_BOOK );
            }
        }
    }
}
```

- [ ] **Step 4: Register the activation hook in `sermonator.php`**

Add immediately after the `\Sermonator\Plugin::boot();` line:

```php
register_activation_hook( __FILE__, static function (): void {
    ( new \Sermonator\Model\Activation() )->activate();
} );
```

- [ ] **Step 5: Run integration test to verify it passes**

Run: `composer test:integration -- --filter ActivationTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suites to confirm nothing regressed**

Run: `composer test:unit && composer test:integration`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add src/Model/Activation.php sermonator.php tests/Integration/Model/ActivationTest.php
git commit -m "feat: activation seeds Bible canon, grants caps, flushes rewrites"
```

---

## Self-Review

**Spec coverage (Plan A's slice of the spec):**
- §2 platform floor → Task 3 (VersionGate) + Task 1 (boot gate). ✓
- §5 post types (`sermonator_sermon` editor-supported, `sermonator_podcast`) → Task 6. ✓
- §5 five taxonomies, non-hierarchical → Task 6. ✓
- §5 capabilities → Task 7. ✓
- §5 "seeded-but-extensible" books → Task 5 (canon) + Task 8 (idempotent seed, never deletes custom). ✓
- §5 `sermonator_version` option → Task 8. ✓
- Single source of truth for identifiers → Task 4. ✓
- No custom tables → nothing creates tables. ✓
- "Never touch legacy data" → no task reads or writes `wpfc_*`. ✓

**Deferred to later plans (correctly out of Plan A):** the Mapping Contract, mappers, detector, writer, orchestrator, batch processor, verifier, rollback, finalizer, wizard, CLI (all Plan B/C); the `sermonator_*` settings options (migrated in Plan B); REST field exposure beyond `show_in_rest`.

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** `Registrar::hook()`/`register()`, `Capabilities::grant()`/`sermonCaps()`, `Activation::activate()`, `VersionGate::isSatisfied()`/`failureMessage()`, `Identifiers::sermonTaxonomies()`/`metaKeys()`, `BibleCanon::defaultBooks()` are referenced consistently across tasks and tests.

**Known follow-ups for Plan B (noted, not gaps):** `Capabilities::sermonCaps()` returns the cap map but Task 6's `register_post_type` uses `capability_type` + `map_meta_cap` (WP auto-derives caps); wiring `sermonCaps()` into the post-type `capabilities` arg is a refinement deferred until the editing UI (sub-project 2) needs it.
