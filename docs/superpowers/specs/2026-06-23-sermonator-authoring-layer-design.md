# Sermonator "Sermon Details" Authoring Layer — Design

- **Date:** 2026-06-23
- **Status:** Approved (forum gates run; tenth-man dissent accepted and folded in)
- **Scope:** A write path — the first admin UI to edit sermon-specific meta. Data preservation
  remains the #1 standard: the panel only sets the existing `Schema\Identifiers` keys in the
  exact formats the migration/front-end already use.
- **Floor:** WP 7.0+, PHP 8.1+. GPL-2.0-or-later, zero monetization.

## 1. Decision (ADR, user-owned)

**Choice:** B — native Gutenberg document-sidebar panel authored in JSX via
`@wordpress/scripts`.

**Why (user's words):** Preserve native UX but with extended functionality for the smoothest
possible interaction for people coming in — prioritizing native feel + maintainability/
extensibility of the UI source over avoiding the build toolchain.

**What would change the answer:** If the JS bundle becomes so heavy/unmaintainable that the
toolchain stops paying for itself — drop it and move to something like CMB2.

Alternatives considered: A (native sidebar, no build — plain `wp.element.createElement`);
C (CMB2 addon — classic meta box, not native sidebar).

## 2. Requirements (clarified)

- **Audio input = media library + external URL** (both). Media pick → auto duration + size +
  MIME from attachment metadata (no network). External URL → HEAD for size (duration optional).
- **Preached date auto-defaults from the publish date** when unset (`sermonator_date_auto=1`);
  explicit set → `date_auto=0`.
- **Views** are not shown in the editor.
- Editable fields: preached date, scripture passage, audio (url + attachment id + duration +
  size), video (url or embed), notes file, bulletin file.

## 3. Architecture

New `Sermonator\Admin\Authoring\` namespace + a `@wordpress/scripts`-built editor panel. The
**server owns the contract** (meta registration + sanitize + auth, the REST endpoint, the
save-time date rule); the **JS panel is a thin native UI** over it. Booted from
`Plugin::registerAdmin()` + `enqueue_block_editor_assets` + `rest_api_init`.

Critically, the panel writes only the existing keys in their existing formats — `sermonator_date`
stays a **Unix-timestamp integer** — so the front end, feed, and migration keep working
unchanged.

## 4. Server contract

`Admin\Authoring\SermonMetaRegistrar` registers every editable field via `register_post_meta(
POST_TYPE_SERMON, … )` with:
- `single => true`, typed `show_in_rest` schema.
- **`auth_callback`** = `current_user_can( 'edit_post', $objectId )` — the canonical write
  gate (also closes the prior video-embed no-auth gap).
- per-field **`sanitize_callback`**: `sanitize_text_field` (passage), `esc_url_raw`
  (audio/video/notes/bulletin URLs), `wp_kses` with the Renderer's video allowlist (embed),
  `absint` (date, audio_id, size), normalized `0|1` (date_auto).
- Underscore-protected keys (`_sermonator_audio_size`, `_sermonator_audio_duration`) are
  registered with explicit `show_in_rest` + `auth_callback` so the editor can persist them.
  (Note: these become publicly readable via REST — acceptable, they are already public feed
  data; do NOT copy this pattern for any sensitive key.)

`Admin\Authoring\AudioMetaController` — `register_rest_route( 'sermonator/v1', '/audio-metadata' )`:
- `permission_callback` = `current_user_can( 'edit_' . POST_TYPE_SERMON . 's' )` (edit sermons).
- Input `attachmentId` **or** `url`. Attachment → `wp_get_attachment_metadata()` → length +
  filesize + mime (zero network). URL → the **hardened HEAD fetcher reused from
  `AudioSizeBackfill`** (4 GB cap, `reject_unsafe_urls`, scheme check) → size + mime; duration
  unknown.
- Returns `{duration, size, mime}`. The endpoint never writes meta — the editor writes the
  returned values as ordinary meta on save.

`Admin\Authoring\SermonDateNormalizer` — fills the preached date when empty:
- **Gated (tenth-man #1):** runs ONLY when `MigrationState::phase()` ∈ `{none, finalized}` AND
  not during a migration write. It never fires mid-migration, so it cannot inject a date the
  migration didn't author or perturb the Verifier's legacy↔target fixity hash.
- On `save_post_<sermon>` / `rest_after_insert_<sermon>`: if `sermonator_date` is empty/0, set
  it to the post's publish-date timestamp and `sermonator_date_auto=1`; if present,
  `date_auto=0`. **Fill-only, never overwrites** an existing date.

## 5. The editor panel (JSX → committed `build/`)

A `PluginDocumentSettingPanel` titled "Sermon Details", registered for `sermonator_sermon`
only, reading/writing meta via `useEntityProp( 'postType', type, 'meta' )`:
- **Preached date** — a **date-only** picker. **Timezone contract (tenth-man #2):** all
  conversions go through the site timezone via `@wordpress/date` (`getSettings()` /
  `dateI18n`), NOT raw JS `Date`; the value is the chosen day's timestamp. A "clear → auto from
  publish date" hint reflects the server rule.
- **Scripture** — `TextControl`.
- **Audio** — `MediaUpload` (audio MIME) → sets `sermonator_audio` URL + `sermonator_audio_id`;
  **on explicit change only** (tenth-man #3) calls `apiFetch /audio-metadata` and writes
  duration/size — never on panel load, so it cannot clobber a migrated/legacy size just by
  opening the post. Plus an "external URL" `TextControl` → `apiFetch?url=` → size on change.
  Duration/size shown read-only with a manual-override affordance if auto fails.
- **Video** — URL `TextControl` + embed `TextareaControl`; embed wins when both present
  (mirrors the Renderer).
- **Notes / Bulletin** — `MediaUpload` (any file) → URL.

## 6. Build toolchain

`package.json` with `@wordpress/scripts` (dev dep), `build`/`start` scripts; source in
`src/editor/sermon-details/index.js` (JSX) → committed `build/sermon-details/{index.js,
index.asset.php}`. Enqueued on `enqueue_block_editor_assets` using the generated
`index.asset.php` (dependency array + version hash), guarded to the sermon edit screen.
`node_modules` git-ignored; **`build/` committed** so the plugin runs without npm.

**Frozen-bundle mitigations (tenth-man #1-toolchain):** use ONLY stable (non-`__experimental`)
`@wordpress/*` APIs; pin `@wordpress/scripts` to the WP-7.0 line; document "rebuild the bundle
on a major WP upgrade" in the README/build notes. The bundle externalizes `wp.*` (no bundled
React), so it stays small and uses the site's WP packages.

## 7. Error handling & data safety

Every meta write is sanitized server-side regardless of client. `auth_callback` /
`permission_callback` gate writes + the endpoint. Auto-metadata failure → fields blank + an
editor notice; never blocks save. The date normalizer is fill-only + migration-gated. The
editor writes size/duration only on explicit audio change, so the migration's preserved values
are never clobbered on load. All computed values are advisory; the feed's backfill remains the
bulk authority for migrated/external audio.

## 8. Testing

- **Unit:** sanitize callbacks; the audio-metadata resolver (attachment path + URL-HEAD path,
  injectable fetcher); date-normalizer fill-only logic; the timezone/day conversion helper.
- **Integration (wp-env):** meta registered with `show_in_rest` + `auth_callback` **denies a
  non-editor write**; REST endpoint returns metadata for an attachment + enforces permission;
  meta round-trips via REST with sanitization; save fills date from publish date when empty +
  sets `date_auto`, explicit date sets `date_auto=0`; **the normalizer does NOT fire during a
  migration write and the Verifier still passes**; a day-boundary timezone case stores the
  intended day.
- **Live (Local):** open the sermon edit screen, set every field (incl. media-picker
  auto-fill), save, confirm meta persisted + the front-end single/feed reflect it; verify the
  panel renders with no editor JS console errors.

## 9. Known risks (carried forward)

- **Frozen JS bundle vs moving WP** (tenth-man #1-toolchain) — mitigated by stable-APIs-only +
  pinned wp-scripts + a rebuild-on-major-WP note, not eliminated. The user's kill-criterion
  (bundle unmaintainable → CMB2) is the documented exit.
- **Protected meta now public-readable via REST** — benign for size/duration (already public),
  but a pattern to not repeat for sensitive keys.

## 10. Out of scope (v1)

Bulk quick-edit of sermon fields; custom audio waveform/uploader; transcript fields; per-field
revision UI; JS unit tests (no JS harness — covered by integration + live).
