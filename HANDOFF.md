# Sermonator — Project Handoff

This document hands the project to a new agent/session. It is self-contained (don't rely on
any prior agent's private memory). Read this top-to-bottom first, then the specs/plans it
points to.

---

## 1. What this project is

**Sermonator** is ONE standalone WordPress plugin (namespace `sermonator_` / PHP namespace
`Sermonator\`) that **replaces** both the free *Sermon Manager* and the paid *Sermon Manager
Pro*. It is a **drop-in replacement**: on install it runs a one-time, lossless, reversible,
verified, non-destructive **migration** of legacy `wpfc_sermon` / `wpfc_*` / `sermon_*` /
`sermonmanager_*` data into the `sermonator_*` namespace.

### The #1 governing standard: DATA PRESERVATION
"We cannot lose any critical data in any way, shape, or form." Legacy data stays
**byte-immutable until a single gated Finalize step**. Every design decision is subordinate to
this. The legacy plugins' front-end (Twig/page-builder templates) was deliberately **discarded
and rewritten** — only DATA continuity matters, not front-end compatibility.

### Licensing / monetization
GPL-2.0-or-later, **zero monetization today**, no warranties. (Monetize later via add-ons; do
NOT add paid dependencies like ACF Pro.)

### Platform
PHP 8.1+, **WordPress 7.0+** (floor raised from 6.0 to unlock `register_block_template`, the
Block Bindings API, etc. — old installs "must modernize first," per the owner).

---

## 2. Current state (what's done vs. what's left)

### DONE and merged to `main`
- **Migration engine** (`src/Migration/`, `src/Cli/MigrationCommand.php`) — durable
  state machine (`none→detected→migrating→migrated→verified→finalized`), advisory lock,
  chunked/resumable, write-once detect-time manifest as a fixity oracle, non-destructive
  copy-forward, Finalize as the only destructive step. Plus a guided **migration wizard**
  admin UI (`src/Admin/Migration*`). Survived heavy adversarial review.
- **Front-end display layer** — built in 4 merged phases (PRs #5–#8):
  - Phase 1: `Sermonator\Frontend\` core — `TemplateData → SermonView → Renderer` (single
    sources of truth), single-sermon view, 3 blocks (sermon-meta/audio-player/video),
    `register_block_template` + classic `single_template` fallback.
  - Phase 2: sermon archive + 5 taxonomy archives, `sermon-grid`/`sermon-card`/`taxonomy-filter`
    blocks, `[sermonator_sermons]` shortcode, preached-date ordering.
  - Phase 3: Apple-compatible **podcast RSS feed** (`src/Frontend/Feed/`) + the reversible
    **audio-size backfill** `wp sermonator audio backfill` + subscribe block.
  - Phase 4: schema.org JSON-LD + Open Graph (`src/Frontend/Seo/`); cross-theme verified.
- **Test status on `main`:** unit **119**, integration **386** — all green on WP 7.0.

### DESIGNED but NOT built (your immediate next task)
- **The "Sermon Details" authoring layer.** Today wp-admin has NO UI to edit any
  sermon-specific field (preached date, scripture, audio, video, notes) — only title/body/
  featured-image/taxonomies. A new sermon created in wp-admin gets none of that meta. This is
  the biggest gap to the plugin being genuinely usable (not just a migration target).
  - **Spec:** `docs/superpowers/specs/2026-06-23-sermonator-authoring-layer-design.md`
  - **Plan:** `docs/superpowers/plans/2026-06-23-sermonator-authoring-layer.md`  ← execute this
  - Decision (owner-owned ADR): native Gutenberg sidebar panel built with `@wordpress/scripts`
    (JSX). All forum gates (tenth-man / time-travel / rollback) are run and folded into the
    spec — **honor them** (migration-gated writes, site-timezone date-only, editor never
    clobbers a migrated audio size, shared video allowlist).
  - This branch (`authoring-layer`) contains the spec + plan. The owner will likely want a
    final review of the spec before you implement.

### DEFERRED / backlog (not yet designed)
- Extend the audio backfill to populate **duration** (currently size-only) for the back catalog.
- Editor inspector JS for the existing front-end blocks (they're server-rendered/insertable but
  have no edit-time controls).
- Version bump (still `0.1.0`) + WordPress.org/distribution + update channel + plugin headers
  (Author/Plugin URI).
- AJAX taxonomy filtering; richer schema.org `VideoObject`; Block Bindings; Elementor widgets.
- See `docs/superpowers/specs/2026-06-23-sermonator-b2a-known-limitations.md` for migration-side
  deferrals (e.g. podcast CPT capability scheme before a podcast UI).

---

## 3. How to run / test (READ THIS — non-obvious)

### Unit tests (fast, local, no Docker)
```
composer install        # vendor/ is gitignored — regenerate after any branch switch
composer test:unit      # PHPUnit + Brain Monkey
```

### Integration tests (wp-env / Docker)
`.wp-env.json` pins **WordPress 7.0** + PHP 8.1.
```
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sermonator-pro \
  vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration
```
(Filter with `--filter <TestName>`. The integration bootstrap loads this repo as the active
plugin inside the container.)

### Live test bed — "Local by WP Engine" (a.k.a. FlyEngine Local)
A real WP 7.0 site for clicking around + live verification, with the plugin **symlinked** in
(so repo edits are live) and seeded with representative sermons + a podcast.
- Site: `/Users/david/Local Sites/sermonator-test`, URL `http://sermonator-test.local/`,
  wp-admin `admin` / `Tacos3000`. Active theme: Twenty Twenty-Five (block). Twenty Twenty-One
  (classic) is also installed for cross-theme checks.
- There is **no global `wp`/`mysql`**. Drive WP-CLI via a wrapper that exports Local's PHP
  8.4 + the site's `PHPRC` (so mysqli finds the Local socket) — recreate it per session:
  ```bash
  #!/usr/bin/env bash
  set -euo pipefail
  SITE_ID="xZ7KeYHRQ"
  export PHPRC="/Users/david/Library/Application Support/Local/run/${SITE_ID}/conf/php"
  export PATH="/Users/david/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin:/Users/david/Library/Application Support/Local/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin:/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix:$PATH"
  cd "/Users/david/Local Sites/sermonator-test/app/public"
  exec wp "$@"
  ```
  The Local app must be **running** (the MySQL socket must be live) or every `wp` call fails.
  The Local site ID (`xZ7KeYHRQ`) and the php/mysql service versions can change — if the
  wrapper fails, re-derive from `…/Local Sites/sermonator-test/app/.envrc`.
- The plugin symlink: `…/sermonator-test/app/public/wp-content/plugins/sermonator ->` this repo.
  If missing: `ln -sfn <repo> "<plugins>/sermonator"`.

---

## 4. Conventions & discipline (the owner cares about these)

- **PSR-4:** `Sermonator\` → `src/`. Canonical identifiers live in `src/Schema/Identifiers.php`
  — NEVER hardcode post-type/taxonomy/meta-key/option strings; reference the constants.
- **TDD:** write the failing test first; unit (Brain Monkey, pure logic) + integration
  (wp-env `WP_UnitTestCase`). Escape ALL output at the `Renderer` boundary.
- **Design-before-build:** any new feature goes through the `forum` skill (brainstorming +
  mandatory anti-groupthink gates: adr-on-rails, tenth-man, time-travel-critic, rollback-story)
  → spec in `docs/superpowers/specs/` → plan in `docs/superpowers/plans/` → implement →
  **adversarial review to convergence** (multi-lens reviewers → fix → re-verify) → PR.
- **Adversarial review has repeatedly caught real bugs** here (data-loss, security, feed
  breakage). Do not skip it, especially on write paths and anything touching the migration.
- **PR ceremony matters.** One PR per coherent unit; descriptive body; end commit messages with
  the `Co-Authored-By` / `Claude-Session` trailers the owner uses (match existing commits).
  GitHub remote: `github.com/riceguitar/sermonator-pro`.
- **`vendor/` is gitignored** — run `composer install` after switching branches.

---

## 5. Architectural landmines (don't relearn these the hard way)

- **Migration native-shared-taxonomy constraint:** native (category/post_tag) term
  relationships are mirrored via direct `$wpdb` WITHOUT bumping the shared
  `wp_term_taxonomy.count`; Rollback strips them directly + recounts once; Finalize recounts
  deferred tt_ids once. Never route these through `wp_set_object_terms`/`wp_delete_post`.
- **`sermonator_date` is a SIGNED Unix timestamp** (pre-1970 sermons are negative). Validate
  with `ctype_digit(ltrim($v,'-'))`, never bare `ctype_digit`. It's the ordering key; archives
  LEFT-JOIN so dateless sermons still list (sorted last).
- **The audio-size backfill is the only front-end DB write.** It is native-only, fill-missing-
  only, exactly reversible (touched-IDs log `sermonator_enclosure_backfill_log`), and refuses
  to run mid-migration. Any new write path must clear the same bar.
- **Video embed HTML** is sanitized with a custom kses allowlist that re-allows `<iframe>`
  (which `wp_kses_post` strips) but NOT `style` (clickjacking). The authoring layer must reuse
  the SAME allowlist source (the plan extracts it to `Schema\VideoEmbedPolicy`).
- **Feed content-type** is forced to `application/rss+xml` via the `feed_content_type` filter
  (WP defaults a custom feed to `application/octet-stream`).
- **wp-env is pinned to WP 7.0** — `register_block_template` needs ≥ 6.7; don't lower it.

---

## 6. Where everything is

- **Specs:** `docs/superpowers/specs/` (data-foundation, B2 design notes, b2a known-limitations,
  the 4 front-end phase context, **authoring-layer-design** ← newest).
- **Plans:** `docs/superpowers/plans/` (Plan A, B1, B2a, B2b, Plan C wizard, frontend phases
  1–4, **authoring-layer** ← newest, your next task).
- **Code:** `src/` (Schema, Model, Migration, Admin, Cli, Frontend, Frontend/Feed, Frontend/Seo).
- **Tests:** `tests/Unit/`, `tests/Integration/`.
- **Git:** PRs #1–#8 merged to `main`; this handoff + the authoring spec/plan are on branch
  `authoring-layer` (and pushed).

## 7. Suggested first move for the new agent
1. `git checkout main && composer install`; confirm `composer test:unit` is green.
2. Read the authoring **spec** + **plan** (§2 above) and the owner's note on the spec.
3. Start the Local app + verify the WP-CLI wrapper works; open the sermon edit screen to see
   the gap firsthand.
4. Implement the authoring plan task-by-task (TDD), review adversarially, PR.
