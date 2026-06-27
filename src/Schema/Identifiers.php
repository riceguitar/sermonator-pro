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

    /**
     * Versioned JSON envelope of structured Bible references
     * `{"v":1,"refs":[Ref,…]}` written by BOTH authoring-capture and
     * backfill/import (one schema, multiple producers). META_BIBLE_PASSAGE
     * is NEVER mutated and remains the preserved human display label / parser
     * input; this key is the structured companion, not a replacement.
     */
    public const META_BIBLE_REFS             = 'sermonator_bible_refs';

    /**
     * Sentinel companion (mirrors META_DATE_UNPARSEABLE) stamped when backfill
     * parses a non-empty passage to zero refs, so a per-post fail-open is
     * measurable rather than silently indistinguishable from "no passage".
     */
    public const META_BIBLE_REFS_UNPARSEABLE = 'sermonator_bible_refs_unparseable';

    public const OPTION_PREFIX                  = 'sermonator_';
    public const META_PODCAST_SETTINGS          = 'sermonator_podcast_settings';

    /**
     * Sub-key INSIDE the {@see self::META_PODCAST_SETTINGS} blob selecting which sermon medium the
     * feed serves (legacy Pro "Sermons to show"). Migrated VERBATIM from the legacy
     * `sm_podcast_settings['sermons_to_show']` (it is not a taxonomy key, so {@see
     * \Sermonator\Migration\PodcastWriter} passes it through unchanged and {@see
     * \Sermonator\Schema\PodcastMetaSchema} preserves it). Legacy values: empty/absent = audio-only
     * (today's faithful behavior), `video` / `audio_priority` / `video_priority` = non-audio modes
     * whose faithfulness is a recorded §63 deferral. Read by {@see
     * \Sermonator\Frontend\Feed\PodcastModeResolver}.
     */
    public const PODCAST_SETTING_FEED_MODE      = 'sermons_to_show';
    public const OPTION_DEFAULT_PODCAST         = 'sermonator_default_podcast';
    public const OPTION_TERM_IMAGES             = 'sermonator_term_images';
    public const OPTION_TERM_IMAGES_SETTINGS    = 'sermonator_term_images_settings';
    public const OPTION_MIGRATION_STATE         = 'sermonator_migration_state';
    public const OPTION_PRE_MIGRATION_BACKUP    = 'sermonator_pre_migration_backup';
    public const OPTION_MIGRATION_PROGRESS      = 'sermonator_migration_progress';
    public const OPTION_LEGACY_FEED_SNAPSHOT    = 'sermonator_legacy_feed_snapshot';
    /**
     * Precomputed migration prevalence rollup (Bundle 2, §63 / T11) — written ONLY on the
     * write-gated detect/verify path, read by the wizard report. Mirrors OPTION_BIBLE_STATS:
     * never written on a GET/report read path.
     */
    public const OPTION_MIGRATION_PREVALENCE    = 'sermonator_migration_prevalence';

    /** Axis A: bible-link version; default mirrors legacy verse_bible_version (e.g. ESV). */
    public const OPTION_BIBLE_LINK_VERSION      = 'sermonator_bible_link_version';
    /** Axis B: inline-translation id; default ENGWEBP. */
    public const OPTION_BIBLE_INLINE_TRANSLATION = 'sermonator_bible_translation';
    /** Shared settings group (neither Bundle 3 nor 4 hardcodes it). */
    public const OPTION_GROUP_SETTINGS          = 'sermonator_settings';
    /** Int cache-buster for the warmed/normalized chapter cache. */
    public const OPTION_BIBLE_CACHE_GEN         = 'sermonator_bible_cache_gen';
    /** Precomputed corpus-audit rollup written by CoverageAudit. */
    public const OPTION_BIBLE_STATS             = 'sermonator_bible_stats';
    /** Exact-reverse id log for BibleRefsBackfill (the reversibility mechanism). */
    public const OPTION_BIBLE_REFS_BACKFILL_LOG = 'sermonator_bible_refs_backfill_log';

    /**
     * Phase 3b (inline Bible verse text) option keys — all on the shared
     * {@see self::OPTION_GROUP_SETTINGS} group.
     *
     * Master kill-switch for inline rendering. Physically un-enableable until a full
     * vendor + warm pass has completed (no half-on / ships-dark) — see design §3.4.
     */
    public const OPTION_BIBLE_INLINE_ENABLED         = 'sermonator_bible_inline_enabled';
    /**
     * Admin attestation that ALL references use one English-tradition link version.
     * Required for `srcVersificationConfidence == 'site-default'` (backfill/absent)
     * refs to clear the L6 provenance gate; `authored` refs skip it (design §2 L6).
     */
    public const OPTION_BIBLE_INLINE_ATTESTATION     = 'sermonator_bible_inline_attestation';
    /**
     * Confidence floor an inline-eligible ref must clear (default `exact`). Admin
     * opt-in may widen the allowed set (design §2 L2 / §3.5).
     */
    public const OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR = 'sermonator_bible_inline_confidence_floor';

    /**
     * Axis-2 human spot-check acknowledgement (bool) — the THIRD gate the per-ref
     * `derived-exact-perseg` floor is un-selectable until set (design §3.3/§3.6, step 4).
     * Set ONLY by the logged CLI spot-check (`wp sermonator bible audit --inline --sample=N`,
     * T-I), never a Settings-API field: an admin lowering the floor to `derived-exact-perseg`
     * without this ack is floored back to STRICT `derived-exact` by the sanitize callback.
     * The 49→76% perseg delta is exactly the Psalm-bearing lectionary bundles whose safety
     * rides the single attestation boolean and which the axis-1 audit is structurally blind to.
     */
    public const OPTION_BIBLE_INLINE_PERSEG_ACK = 'sermonator_bible_inline_perseg_ack';

    /**
     * Reconciliation generation stamped at the moment inline rendering was enabled — the
     * {@see \Sermonator\Bible\CoverageAudit} report `generated_at` of the fresh audit the
     * enable soft-gate reconciled against (design §3.6, decision 6). Lets Site Health
     * (T-K) warn when the LIVE audit generation has moved past the one enable reconciled
     * against (corpus drift between audit and enable). Stamped by `sanitizeInlineEnabled`
     * on a successful enable, alongside the {@see self::OPTION_BIBLE_CACHE_GEN} bump.
     */
    public const OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN = 'sermonator_bible_inline_enabled_audit_gen';

    /**
     * Stamped into every vendored/normalized per-chapter JSON file and folded into
     * the chapter-cache transient key. Bump to invalidate every cached chapter when
     * the normalized node shape changes (design §3.4 / §3.6). Distinct from the
     * admin-bumpable {@see self::OPTION_BIBLE_CACHE_GEN} cache-buster: this is a
     * code-owned structural version, that is an operator-owned generation counter.
     */
    public const BIBLE_CACHE_SCHEMA_VERSION = 1;

    /**
     * Sub-directory of `wp-content/uploads/` holding the on-demand vendored chapter
     * snapshots (`<BIBLE_VENDOR_DIR>/<TRANSLATION>/<BOOK>/<chapter>.json`). NOT in
     * the committed repo/SVN — populated by `wp sermonator bible vendor` (design §3.4).
     */
    public const BIBLE_VENDOR_DIR = 'sermonator-bible';

    /**
     * Bundle 4 (Config & display) live option keys. On the shared
     * {@see self::OPTION_GROUP_SETTINGS} group.
     *
     * `archive_slug` and `default_image_id` use DISTINCT live keys (note the
     * `_sermon_` infix) — NOT the `sermonator_archive_slug` /
     * `sermonator_default_image_id` migration prefix-swap artifacts. The
     * migration's OptionMapper prefix-swaps every `sermonmanager_*` row and
     * OptionWriter overwrites it verbatim on a supported (pre-Finalize) re-run;
     * keeping the live keys distinct means a migration re-run only ever touches
     * the artifact rows, never an admin's saved display config (and never fires a
     * spurious rewrite flush). {@see \Sermonator\Schema\DisplayDefaults} seeds the
     * live keys from those artifacts. `preacher_label` is the lone 1:1 key (a
     * single cosmetic string; residual re-run reset is recorded in the design).
     */
    public const OPTION_ARCHIVE_SLUG            = 'sermonator_sermon_archive_slug';
    public const OPTION_DEFAULT_IMAGE_ID        = 'sermonator_sermon_default_image_id';
    public const OPTION_PREACHER_LABEL          = 'sermonator_preacher_label';

    /**
     * Legacy Sermon Manager archive ordering defaults, migrated VERBATIM by
     * OptionWriter's wholesale `sermonmanager_*`→`sermonator_*` prefix-swap (see
     * MappingContract::mapOptionName). The Bundle 2 `[sermons]` shim consults these
     * as the default `order`/`orderby` and to resolve `orderby=date` exactly as
     * SM's display_sermons() did (date→published ONLY when archive_orderby==='date',
     * else preached). SM's own defaults when the option is ABSENT: orderby
     * `date_preached`, order `desc` (class-sm-settings-display.php:69/80).
     */
    public const OPTION_ARCHIVE_ORDERBY         = 'sermonator_archive_orderby';
    public const OPTION_ARCHIVE_ORDER           = 'sermonator_archive_order';

    /**
     * Persistent flag set by SlugRewriteFlusher ONLY on a real archive-slug value
     * change; an `init@99` handler scoped to admin/cron flushes rewrite rules
     * exactly once then clears it, so a front-end visitor never pays the flush.
     */
    public const OPTION_REWRITE_FLUSH_PENDING   = 'sermonator_rewrite_flush_pending';

    /**
     * Durable legacy-podcast-id -> new-podcast-id map, the post-Finalize-safe
     * resolver for legacy podcast feed URLs. Unlike the Crosswalk LEGACY_POST_ID
     * back-ref meta (which the Finalizer strips), this option must survive Finalize
     * so /?feed=rss2&post_type=wpfc_sermon&id=<legacy> keeps resolving forever.
     *
     * Populated at migrate time by PodcastWriter (legacy podcast id => new podcast id),
     * written alongside the Crosswalk back-ref so it exists BEFORE Finalize can strip it.
     * The Finalizer never deletes it (it is a sermonator_* option, not in the legacy
     * sermonmanager_* delete set) and hard-refuses to finalize a multi-podcast site whose
     * map is incomplete, so the legacy→new correspondence is never silently lost.
     */
    public const OPTION_LEGACY_PODCAST_MAP      = 'sermonator_legacy_podcast_map';

    /**
     * Durable per-episode legacy RSS <guid>, stamped on the NEW sermonator_sermon
     * post id so already-subscribed podcast apps never re-download the back catalogue
     * after the switch (rollback story 1). The post-Finalize-safe counterpart to the
     * legacy-keyed OPTION_LEGACY_FEED_SNAPSHOT: where the snapshot is keyed by the
     * LEGACY post id and is only reachable PRE-Finalize via the Crosswalk LEGACY_POST_ID
     * back-ref (which the Finalizer strips), this meta is keyed by the durable NEW post
     * id and is NEVER in Crosswalk::strippableBackRefs(), so the GUID replay survives
     * Finalize. Finalize stamps it from the snapshot BEFORE stripping the back-ref.
     */
    public const META_LEGACY_GUID               = 'sermonator_legacy_guid';
    public const META_DATE_NORMALIZED           = 'sermonator_date_normalized';

    /**
     * Sentinel companion value written for a non-numeric sermon_date row that the
     * DateNormalizer could NOT parse. Writing a companion for EVERY non-numeric row
     * (parseable or not) keeps META_DATE_NORMALIZED[i] positionally aligned with
     * the NON-NUMERIC SUBSEQUENCE of META_DATE (numeric rows are skipped with
     * `continue` in applyDateNormalization() and receive no companion); this marker
     * flags the unparseable position so a consumer never mistakes a missing companion
     * for a parseable row and never mis-indexes within the non-numeric subsequence.
     */
    public const META_DATE_UNPARSEABLE          = 'sermonator_date_unparseable';

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

    /**
     * @return list<string> Every sermonator_* meta key stored on a sermon.
     *
     * NOTE: metaKeys() membership is a catalog (cosmetic / test-only) — it is
     * NOT a reversibility mechanism. Backfill reversibility is hand-wired via a
     * LOG_OPTION + reverse method (the AudioSizeBackfill pattern), never inferred
     * from this list. See spec §3.
     */
    public static function metaKeys(): array {
        return array(
            self::META_DATE,
            self::META_DATE_AUTO,
            self::META_BIBLE_PASSAGE,
            self::META_BIBLE_REFS,
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
