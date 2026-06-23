# Sermonator "Sermon Details" Authoring Layer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> executing-plans. Steps use checkbox (`- [ ]`) syntax. Spec:
> `docs/superpowers/specs/2026-06-23-sermonator-authoring-layer-design.md` — read it first;
> all gate outcomes (tenth-man / time-travel / rollback) are folded into the spec and MUST be
> honored.

**Goal:** A native Gutenberg "Sermon Details" sidebar panel (built with `@wordpress/scripts`)
so editors can set every sermon field — preached date, scripture, audio (auto duration/size/
MIME), video, notes/bulletin — backed by a server-side meta contract. Data preservation is #1.

**Status:** Designed, all forum gates run, **NOT yet built**. Spec pending final user approval
before implementation (handed off to a new agent).

## Global Constraints (verbatim from spec)
- WP 7.0+, PHP 8.1+; GPL-2.0+, zero monetization.
- Writes ONLY existing `Schema\Identifiers` keys in existing formats — `sermonator_date` stays
  a **Unix-timestamp integer**; nothing downstream (front end, feed, migration) changes.
- **Every meta write is sanitized + auth-gated server-side** (`register_post_meta` with
  `auth_callback` + per-field `sanitize_callback`).
- **Migration safety:** the WHOLE authoring write surface (panel enqueue, REST endpoint, meta
  auth callbacks) is inert when `MigrationState::phase()` ∉ `{none, finalized}`.
- **Timezone:** preached date is **date-only**, all JS conversions via `@wordpress/date`
  (site timezone), never raw JS `Date`.
- **Audio size/duration:** editor writes them ONLY on an explicit audio change or an explicit
  "Fetch audio details" click — never on panel load (must not clobber a migrated value).
- Use ONLY stable (non-`__experimental`) `@wordpress/*` APIs; pin `@wordpress/scripts`.

## File Structure
| File | Responsibility |
|---|---|
| `package.json` (new) | `@wordpress/scripts` dev dep + `build`/`start` scripts |
| `src/Schema/VideoEmbedPolicy.php` (new) | the single video-embed kses allowlist; Renderer + sanitizer both call it |
| `src/Frontend/Renderer.php` (modify) | `allowedVideoHtml()` delegates to `VideoEmbedPolicy` (no behavior change) |
| `src/Admin/Authoring/MigrationGuard.php` (new) | `editingAllowed(): bool` — phase ∈ {none,finalized} |
| `src/Admin/Authoring/SermonMetaRegistrar.php` (new) | `register_post_meta` for all fields (show_in_rest + auth + sanitize) |
| `src/Admin/Authoring/AudioMetaController.php` (new) | REST `sermonator/v1/audio-metadata` (attachment meta or HEAD) |
| `src/Admin/Authoring/SermonDateNormalizer.php` (new) | `save_post` fill-only date default (migration-gated) |
| `src/Admin/Authoring/AuthoringServiceProvider.php` (new) | wires meta + REST + normalizer + editor-asset enqueue |
| `src/Frontend/Feed/AudioSizeBackfill.php` (modify) | extract the HEAD fetcher for reuse; (follow-up) also write duration |
| `src/editor/sermon-details/index.js` (new, JSX) | the PluginDocumentSettingPanel |
| `build/sermon-details/*` (committed build output) | from `npm run build` |
| `src/Plugin.php` (modify) | boot `AuthoringServiceProvider` under `registerAdmin()` |
| `.gitignore` (modify) | ignore `node_modules`, NOT `build/` |

## Task A — Shared video-embed policy (de-dupe, time-travel #5)
- [ ] Create `Schema\VideoEmbedPolicy::allowed(): array` holding the iframe/video/source kses
  allowlist currently in `Renderer::allowedVideoHtml()` (NO `style` attr — keep as-is).
- [ ] `Renderer::allowedVideoHtml()` returns `VideoEmbedPolicy::allowed()`.
- [ ] Unit test asserting `Renderer` video output is unchanged + the embed sanitizer (Task B)
  and Renderer use the identical array.

## Task B — `SermonMetaRegistrar` (the write contract)
- [ ] `register_post_meta(POST_TYPE_SERMON, <key>, [...])` for every editable key (see spec §2
  list): `single`, typed `show_in_rest`, `auth_callback => fn($a,$k,$id) =>
  MigrationGuard::editingAllowed() && current_user_can('edit_post', $id)`, per-field
  `sanitize_callback` (text/url/absint/0|1; embed → `wp_kses(.., VideoEmbedPolicy::allowed())`).
- [ ] Register the underscore keys (`_sermonator_audio_size`, `_sermonator_audio_duration`)
  with explicit `show_in_rest` + the same auth.
- [ ] Integration test: a non-`edit_post` user is DENIED a REST meta write; a valid editor
  round-trips each field with sanitization; writes are denied while phase ∉ {none,finalized}.

## Task C — `AudioMetaController` (REST audio-metadata)
- [ ] Extract the hardened HEAD fetcher from `AudioSizeBackfill` (size cap, `reject_unsafe_urls`,
  scheme check) into a reusable `Frontend\Feed\AudioHeadProbe` (or a public static); both the
  backfill and this controller use it.
- [ ] `register_rest_route('sermonator/v1', '/audio-metadata', [...])`,
  `permission_callback => fn() => MigrationGuard::editingAllowed() && current_user_can('edit_' . POST_TYPE_SERMON . 's')`.
- [ ] Input `attachmentId` → `wp_get_attachment_metadata()` (length + filesize + mime, no
  network); `url` → `AudioHeadProbe` (size + mime; duration ''). Return `{duration,size,mime}`.
- [ ] Integration test: attachment path returns stored metadata; permission enforced; gated
  during migration.

## Task D — `SermonDateNormalizer` (fill-only, migration-gated)
- [ ] On `save_post_<sermon>` + `rest_after_insert_<sermon>`: if `!MigrationGuard::editingAllowed()`
  return; if `sermonator_date` empty/0 → set to `get_post_time('U', true, $id)` +
  `sermonator_date_auto=1`; else `date_auto=0`. **Never overwrite an existing date.**
- [ ] Integration tests: empty → filled from publish date + date_auto=1; explicit → date_auto=0;
  **does NOT fire during a migration write and the `Verifier` still passes** (the load-bearing
  test — construct a migration in `migrating`/`verified` phase, insert a sermon, assert the
  normalizer made no write and Verify is unaffected).

## Task E — Build toolchain + the JSX panel
- [ ] `package.json`: `devDependencies: { "@wordpress/scripts": "<pin to WP 7.0 line>" }`,
  `scripts: { build: "wp-scripts build src/editor/sermon-details/index.js --output-path=build/sermon-details", start: "wp-scripts start ..." }`. `.gitignore` `node_modules`.
- [ ] `src/editor/sermon-details/index.js` — `registerPlugin('sermonator-sermon-details', {...})`
  with a `PluginDocumentSettingPanel` (from `@wordpress/editor`) rendered only when
  `getCurrentPostType() === 'sermonator_sermon'`. Use `useEntityProp('postType', type, 'meta')`.
  Fields per spec §5: date-only picker via `@wordpress/components` + `@wordpress/date`
  (site tz); scripture TextControl; audio `MediaUpload` (audio) + external-URL TextControl +
  **"Fetch audio details" button** → `apiFetch('/sermonator/v1/audio-metadata?...')` →
  write duration/size (explicit only); video URL + embed Textarea; notes/bulletin `MediaUpload`.
- [ ] `npm install && npm run build`; **commit `build/sermon-details/`** (incl. `index.asset.php`).
- [ ] If the build can't run in this env, hand the JSX source to the user to build, OR
  hand-author a minimal `build/` — but prefer the real build. Document the result.

## Task F — Wire + verify + review + PR
- [ ] `AuthoringServiceProvider::hook()` — registers meta (Task B), REST (Task C), normalizer
  (Task D), and `enqueue_block_editor_assets` (only on the sermon screen, only when
  `editingAllowed()`, using `build/sermon-details/index.asset.php` for deps+version). Boot it
  from `Plugin::registerAdmin()`.
- [ ] Unit + full integration green (wp-env, WP 7.0).
- [ ] **Live (Local):** open `…/wp-admin/post.php?post=5&action=edit` — confirm the "Sermon
  Details" panel renders with NO console errors; set date (check the stored timestamp matches
  the chosen day in site tz), pick an audio attachment (duration/size auto-fill), set
  video/notes; save; confirm meta persisted + the front-end single + feed reflect it. Verify a
  day-boundary timezone case. Verify the panel is read-only during a simulated migration phase.
- [ ] Adversarial review — EXTRA rigor on: the migration gate (can any write slip through
  mid-migration?), the timezone round-trip (day-boundary), the editor-never-clobbers-migrated-
  size rule, and the public-REST exposure of the underscore keys. Fix to convergence → PR.

## Follow-ups (recorded, not in this plan)
- Extend `AudioSizeBackfill` to also populate **duration** (currently size-only) so the migrated
  back catalog gets durations in bulk (time-travel #3).
- Runtime error-boundary on the panel + CI rebuild-smoke (long-horizon #1).
- Optional service-time field if multi-service churches need it (long-horizon #2).
