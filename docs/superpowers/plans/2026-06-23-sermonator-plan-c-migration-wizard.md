# Sermonator Plan C — Guided Migration Wizard (admin UI)

- **Date:** 2026-06-23
- **Depends on:** B1 + B2a + B2b (merged to `main`). The full lossless/reversible/verified migration engine + WP-CLI exists; this plan adds the admin-facing **guided wizard** the user chose ("A. guided migration wizard").
- **Governing standard:** data preservation ([[feedback-data-preservation-highest-bar]]). The wizard is a **THIN presentation layer** over the gated B2b services — it adds NO migration logic. Every data-safety gate (ordering, advisory lock, legacy→target completeness, verified+fresh-drift finalize gate, non-destructive rollback, write-once manifest) lives in the services and applies identically whether driven from the CLI, the wizard, or a test. The wizard therefore cannot lose data even if its UI is wrong; the worst a UI bug can do is mis-display state or fail to advance.

## Design

A single admin page that walks an operator through the lifecycle, rendering the step that matches `MigrationState::phase()`:

| phase | screen | actions |
|---|---|---|
| `none` (legacy present) | "Legacy data detected" | **Detect** |
| `none` (no legacy) | "Nothing to migrate" | — |
| `detected` | manifest counts (what will migrate) | **Start migration**, **Re-detect** |
| `migrating` | progress bar (`done`/`remaining`) | auto-runs chunks via AJAX; **Roll back** |
| `migrated` | "Migration complete — verify it" | **Verify**, **Roll back** |
| `verified` | verify report (counts; drift/missing/flags if any) + **blast radius** | **Finalize** (confirm checkbox), **Roll back** |
| `finalized` | "Done — point of no return passed" | — |

Rollback is offered from `detected`/`migrating`/`migrated`/`verified` (never `finalized`). Finalize requires an explicit confirm and prints the exact legacy ids/options it will delete first.

### Components (all under `Sermonator\Admin\`)

1. **`MigrationWizard`** — registers the submenu page (`add_submenu_page` under `edit.php?post_type=sermonator_sermon`, cap `manage_sermonator_settings`), renders the phase-appropriate step (escaped output), and enqueues the wizard JS/CSS only on its own screen. Pure presentation: reads `Orchestrator::status()` / `MigrationState` / `Verifier`/`Finalizer` previews; never mutates.
2. **`MigrationController`** — the `wp_ajax_*` action handlers: `detect`, `run` (one chunk), `verify`, `rollback`, `finalize`. EACH handler: (a) `check_ajax_referer` nonce, (b) `current_user_can('manage_sermonator_settings')`, then delegate to the service and return `wp_send_json_success([...])`. Destructive handlers (`rollback`, `finalize`) additionally require a truthy `confirm` arg and acquire the SAME `Orchestrator` advisory lock (refuse if held), exactly like the CLI. `finalize` passes `confirmed=true` to `Finalizer::run` (which still re-checks phase + fresh rescan). NO migration logic here.
3. **`LegacyDataNotice`** — an `admin_notices` banner shown when `Detector::hasLegacyData()` AND phase ∉ {`finalized`}, linking to the wizard. Dismissible per-session; never on the wizard page itself.
4. **`assets/migration-wizard.js`** — minimal vanilla JS: posts to the AJAX endpoints with the nonce, drives the chunked `run` loop updating a progress bar until `phase==='migrated'`, surfaces flags, and gates the destructive buttons behind a confirm checkbox. **`assets/migration-wizard.css`** — minimal layout.
5. **Wiring** in `Plugin::boot()` — register the page + AJAX handlers + notice only under `is_admin()` (and the page/handlers regardless of WP_CLI). No effect on front-end or CLI paths.

### Data-safety invariants the wizard MUST preserve (test these)
- **Capability gate:** a user lacking `manage_sermonator_settings` gets no page and every AJAX handler refuses.
- **Nonce gate:** a missing/invalid nonce refuses every handler (no state change).
- **Thin delegation:** each handler advances state ONLY via the service; the resulting `MigrationState::phase()` matches what the CLI/service would produce.
- **Destructive confirm:** `rollback`/`finalize` without `confirm` delete NOTHING; `finalize` before `verified` is refused by the service even with `confirm` (UI cannot bypass the gate).
- **Lock parity:** destructive handlers refuse when the advisory lock is held (no interleave with a running migrate).
- **Notice scoping:** shown only with legacy data present and not finalized; gone after finalize.

## Tasks (dependency-ordered)
1. `MigrationController` (AJAX handlers, gated + thin) + integration tests for every gate/transition. **(highest value — the data-adjacent surface)**
2. `MigrationWizard` page (registration, cap, per-phase render, asset enqueue) + tests for registration/cap/render-by-phase.
3. `LegacyDataNotice` + tests for scoping.
4. `assets/migration-wizard.js` + `.css` (minimal; JS not unit-tested — the PHP endpoints carry the logic).
5. `Plugin::boot()` wiring (admin-only) + a smoke test that the actions/page are registered.
6. Proportionate adversarial review (UI-specific lenses: gate-bypass / capability+nonce / state-correctness / no-legacy-mutation) → fix → PR + merge.

## Out of scope (consistent with the overhaul)
- Front-end rendering of sermons/podcasts (Twig/Divi/Elementor) — discarded, rewritten later.
- Multisite/network migration UI (deferred).
- Any monetization/licensing UI (the plugin is GPL-2.0+, zero monetization — [[feedback-no-monetization-open-source]]).
