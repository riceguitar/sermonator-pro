# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Sermonator** is ONE standalone WordPress plugin (PHP namespace `Sermonator\`, data namespace
`sermonator_`) that **replaces** the free *Sermon Manager* and paid *Sermon Manager Pro*. On
install it runs a one-time, lossless, reversible, verified, non-destructive **migration** of
legacy `wpfc_sermon` / `wpfc_*` / `sermon_*` / `sermonmanager_*` data into the `sermonator_*`
namespace, then serves a fully rewritten read-only front end. PHP 8.1+, WordPress 7.0+,
GPL-2.0-or-later, zero monetization today (no paid deps like ACF Pro).

**The #1 governing standard is DATA PRESERVATION.** "We cannot lose any critical data in any
way, shape, or form." Legacy data stays byte-immutable until a single gated Finalize step.
Every design decision is subordinate to this — especially anything on a write path or touching
the migration. `HANDOFF.md` is the canonical, self-contained project briefing; read it for full
context, current state, and the backlog.

## Commands

PHP dependencies are in `vendor/` which is **gitignored** — run `composer install` after every
branch switch or the autoloader and PHPUnit are missing.

```bash
# Unit tests — fast, local, no Docker. Brain Monkey mocks WP; pure logic only.
composer test:unit
vendor/bin/phpunit --bootstrap tests/bootstrap-unit.php --testsuite unit --filter RendererTest

# Integration tests — require wp-env (Docker). .wp-env.json pins WordPress 7.0 + PHP 8.1.
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro \
  vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration
# add --filter <TestName> to run one test class
```

```bash
# JS authoring-panel build (Gutenberg sidebar + meta box). Outputs to build/, which IS committed.
npm install
npm run build            # builds both sermon-details and meta-box bundles
npm run start            # watch mode for the sermon-details panel
npm run lint:js          # wp-scripts eslint over src/editor
```

There is no global `wp`/`mysql`. To drive the live "Local by WP Engine" test site
(`http://sermonator-test.local/`, plugin symlinked in, seeded), recreate the WP-CLI wrapper per
`HANDOFF.md` §3 — it exports Local's PHP + `PHPRC` so mysqli finds the socket.

## Architecture

**Boot flow** (`sermonator.php` → `Sermonator\Plugin::boot()`): a `VersionGate` aborts with an
admin notice on unsupported PHP/WP. Otherwise it wires four layers via per-context guards —
model registration always; the authoring layer always; the admin migration wizard only under
`is_admin()`; CLI commands only under `WP_CLI`; the front-end service provider in all contexts
(the block editor needs block/template registration, and the front-end-only hooks self-scope).
Activation runs `Model\Activation`.

**Three durable subsystems:**

1. **Migration** (`src/Migration/`, `src/Cli/MigrationCommand.php`, `src/Admin/Migration*`) — a
   state machine `none→detected→migrating→migrated→verified→finalized` behind an advisory lock.
   Chunked/resumable. A write-once detect-time `Manifest` is the fixity oracle; copy-forward is
   non-destructive; **Finalize is the only destructive step** and `Rollback` undoes everything
   before it. CLI: `wp sermonator migration {detect|status|verify|rollback|finalize}`. The
   wizard (`Admin\MigrationWizard` + thin AJAX `Admin\MigrationController`) is pure UI over these
   gated services and adds no migration logic.

2. **Read-only front end** (`src/Frontend/`) — wired by `Frontend\FrontendServiceProvider`.
   Data flows through three single-sources-of-truth in order: **`TemplateData`** (reads/derives
   raw values from a post) → **`SermonView`** (typed, presentation-ready value object) →
   **`Renderer`** (the ONLY class that builds piece-HTML; pure `SermonView` in, escaped string
   out). Ten dynamic blocks (`src/Frontend/Blocks/`, JSON in `blocks/`) plus `register_block_template`
   for FSE themes and `templates/classic/*` fallbacks via `single_template`/archive filters; a
   `[sermonator_sermons]` shortcode; and the Apple-compatible podcast RSS feed (`src/Frontend/Feed/`)
   and schema.org/OpenGraph head (`src/Frontend/Seo/`). This layer never writes data — except the
   audio-size backfill (see landmines).

3. **Authoring layer** (`src/Admin/Authoring/`) — the "Sermon Details" editing surface: a meta
   write contract (`SermonMetaRegistrar`/`SermonMetaSanitizer`), a REST audio-metadata endpoint
   (`AudioMetaController`), a save-time preached-date normalizer, and the Gutenberg sidebar panel
   (`src/editor/` → `build/sermon-details/`). All writes are migration-gated via `MigrationGuard`.

## Conventions & landmines

- **Never hardcode identifiers.** Every post-type, taxonomy, meta-key, and option string lives
  as a constant in `src/Schema/Identifiers.php` — reference the constants. Legacy-side strings
  live in `src/Migration/LegacyIdentifiers.php`.
- **TDD is expected:** write the failing test first. Unit = Brain Monkey + pure logic; integration =
  `WP_UnitTestCase` under wp-env. Escape ALL output at the `Renderer` boundary (`esc_html`/`esc_url`/
  `esc_attr`; stored video embed via the kses allowlist), and omit absent fields entirely (never
  render an empty row or a literal `0`).
- **`sermonator_date` is a SIGNED Unix timestamp** — pre-1970 sermons are negative. Validate with
  `ctype_digit(ltrim($v,'-'))`, never bare `ctype_digit`. It is the ordering key; archives LEFT-JOIN
  so dateless sermons still list (sorted last).
- **The audio-size backfill (`wp sermonator audio backfill`) is the only front-end DB write.** It is
  native-only, fill-missing-only, exactly reversible (touched-IDs log
  `sermonator_enclosure_backfill_log`), and refuses to run mid-migration. Any new write path must
  clear that same bar.
- **Video embed HTML** is sanitized through `Schema\VideoEmbedPolicy` — a custom kses allowlist that
  re-allows `<iframe>` (which `wp_kses_post` strips) but NOT `style` (clickjacking). The renderer and
  the authoring sanitizer MUST share this single source.
- **Migration native-shared-taxonomy constraint:** native category/post_tag relationships are mirrored
  via direct `$wpdb` WITHOUT bumping `wp_term_taxonomy.count`; Rollback strips them and recounts,
  Finalize recounts deferred tt_ids once. Never route these through `wp_set_object_terms`/`wp_delete_post`.
- **Feed content-type** is forced to `application/rss+xml` via the `feed_content_type` filter (WP
  defaults a custom feed to `application/octet-stream`).
- **wp-env is pinned to WP 7.0** — `register_block_template` needs ≥ 6.7; don't lower it.

## Process (the owner enforces this)

New features go through the `forum` skill (brainstorming + anti-groupthink gates: adr-on-rails,
tenth-man, time-travel-critic, rollback-story) → spec in `docs/superpowers/specs/` → plan in
`docs/superpowers/plans/` → TDD implementation → **adversarial multi-lens review to convergence**
→ PR (one per coherent unit). Adversarial review has repeatedly caught real data-loss, security,
and feed bugs here — do not skip it on write paths or migration changes. GitHub remote:
`github.com/riceguitar/sermonator-pro`.
