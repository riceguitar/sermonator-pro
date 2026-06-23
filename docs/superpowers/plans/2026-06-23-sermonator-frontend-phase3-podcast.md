# Sermonator Front-End — Phase 3 (Podcast RSS + Enclosure Backfill + Subscribe) Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development / executing-plans.

**Goal:** An Apple-Podcasts-compatible RSS feed for the default (or a chosen) podcast, a
reversible WP-CLI **backfill** that persists audio byte sizes so the feed never does
render-time network I/O, and a podcast-subscribe block.

**Architecture:** `Frontend\Feed\*` — `PodcastConfig`/`FeedItem` value objects, a pure
`FeedBuilder` (RSS+iTunes XML), `PodcastFeed` (registers `add_feed`, resolves the podcast,
queries sermons, emits XML). `EnclosureResolver` reads the persisted `_sermonator_audio_size`;
the `Cli\AudioBackfillCommand` populates it (HEAD request) — the ONLY DB write in the whole
front-end effort. Read-only otherwise.

## Global Constraints
- WP 7.0+, PHP 8.1+, canonical ids from `Schema\Identifiers`, escape at boundaries.
- **Backfill data-preservation guardrails (rollback-story):** writes ONLY native
  `_sermonator_audio_size` on `sermonator_sermon` posts; fills missing/zero only (never
  overwrites); records every touched post id in option `sermonator_enclosure_backfill_log`
  for exact reversibility; idempotent + chunked; `--dry-run` + `--rollback`.
- Feed reads persisted size; if unknown at render, **omit the item + count it** (never a
  silent partial feed; never a network call at render time).

## File Structure
| File | Responsibility |
|---|---|
| `src/Frontend/Feed/PodcastConfig.php` | Immutable channel config (title/author/owner/category/explicit/image/link…) |
| `src/Frontend/Feed/FeedItem.php` | Immutable item (title/link/guid/desc/pubTs/audioUrl/audioType/audioSize/duration) |
| `src/Frontend/Feed/ItunesCategory.php` | Fixed Apple-category allowlist + map (free text → nearest valid) |
| `src/Frontend/Feed/FeedBuilder.php` | PURE: build(PodcastConfig, list<FeedItem>): string — RSS 2.0 + itunes/content ns |
| `src/Frontend/Feed/PodcastConfigFactory.php` | Build PodcastConfig from a podcast post (settings + post + thumbnail) |
| `src/Frontend/Feed/EnclosureResolver.php` | size/MIME for a sermon's audio (read persisted meta) |
| `src/Frontend/Feed/PodcastFeed.php` | add_feed('sermonator-podcast'); resolve podcast; query; emit XML + headers + skipped count |
| `src/Cli/AudioBackfillCommand.php` | `wp sermonator audio backfill [--dry-run] [--rollback]` — persist sizes / revert |
| `src/Frontend/Blocks/PodcastSubscribeBlock.php` + `blocks/podcast-subscribe/block.json` | subscribe links (Apple/RSS/Spotify) |
| `src/Frontend/FrontendServiceProvider.php` (modify) | register feed + subscribe block |
| `src/Plugin.php` (modify) | register the CLI command (under WP_CLI) |

## Task A — Value objects + ItunesCategory + FeedBuilder (pure, unit-tested)
- [ ] `PodcastConfig`, `FeedItem` readonly objects.
- [ ] `ItunesCategory::normalize(string): array{category:string,subcategory:?string}` against
  the fixed Apple taxonomy (default to "Religion & Spirituality").
- [ ] `FeedBuilder::build()` — `<rss version="2.0" xmlns:itunes=… xmlns:content=…>`: channel
  (title/link/description/language/itunes:author/itunes:summary/itunes:owner/itunes:image/
  itunes:category/itunes:explicit/copyright) + one `<item>` per FeedItem (title/link/guid
  isPermaLink=false/pubDate RFC-822/description CDATA/enclosure url+length+type/
  itunes:duration/itunes:explicit). XML-escape all text; CDATA for descriptions. Unit-test
  XML shape (well-formed, required tags, enclosure length present).

## Task B — PodcastConfigFactory + EnclosureResolver
- [ ] Factory: read `sermonator_podcast_settings` keys (title/subtitle/author/summary/
  owner_name/owner_email/category/explicit/language) with fallbacks to post_title/excerpt
  + featured image + permalink; map category via ItunesCategory.
- [ ] `EnclosureResolver::resolve(int $sermonId): ?array{url,size,type,duration}` — read
  `sermonator_audio` + `_sermonator_audio_size` + `_sermonator_audio_duration`; return null
  if no audio or size unknown (caller counts the skip). Integration-test.

## Task C — PodcastFeed endpoint
- [ ] `add_feed('sermonator-podcast', [$this,'render'])` + flush on activation (rewrite).
- [ ] resolve podcast: `?podcast=ID` else `sermonator_default_podcast`; if none → empty but
  valid channel + (admin notice elsewhere).
- [ ] query published sermons (SermonQuery, large perPage) → FeedItems via EnclosureResolver
  (skip+count unknown) → `FeedBuilder` → `header('Content-Type: application/rss+xml')` + echo.
- Integration-test: feed renders valid XML w/ iTunes tags + enclosure; draft excluded; an
  audio-less sermon skipped.

## Task D — AudioBackfillCommand (the DB write — extra care)
- [ ] `wp sermonator audio backfill` — find published sermons with `sermonator_audio` set and
  `_sermonator_audio_size` missing/zero; `wp_remote_head` the URL → Content-Length →
  `update_post_meta(_sermonator_audio_size)`; append id to
  `sermonator_enclosure_backfill_log`. Chunked (cursor), idempotent.
- [ ] `--dry-run` (report only), `--rollback` (delete `_sermonator_audio_size` for logged
  ids, clear the log). Native-only, fill-missing-only.
- Integration-test: backfill writes only missing sizes, logs ids, rollback restores exactly;
  never touches legacy `_wpfc_sermon_size` or non-sermon posts.

## Task E — Subscribe block + wiring
- [ ] `blocks/podcast-subscribe/block.json` (attrs: `podcastId`, `showApple`,`showSpotify`,
  `showRss`) + `PodcastSubscribeBlock` → links (RSS = feed URL; Apple = podcasts:// or the
  feed; Spotify optional manual URL from settings).
- [ ] Register feed + subscribe block in `FrontendServiceProvider`; register CLI in `Plugin`.
- [ ] CSS for subscribe buttons.

## Task F — Verify + review + PR
- [ ] Unit + full integration green (WP 7.0).
- [ ] Live: hit `/feed/sermonator-podcast/` on Local, validate well-formed XML + iTunes tags
  + enclosures; run the backfill (dry-run + real + rollback) on seeded data; place the
  subscribe block.
- [ ] Adversarial review — EXTRA rigor on the backfill (data-preservation, reversibility,
  native-only) + Apple feed correctness → fix to convergence → PR.

## Out of scope
schema.org/OG + cross-theme pass (Phase 4); per-episode chapters; transcripts; multiple-feed
UI beyond `?podcast=ID`.
