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

    public const OPTION_PREFIX                  = 'sermonator_';
    public const META_PODCAST_SETTINGS          = 'sermonator_podcast_settings';
    public const OPTION_DEFAULT_PODCAST         = 'sermonator_default_podcast';
    public const OPTION_TERM_IMAGES             = 'sermonator_term_images';
    public const OPTION_TERM_IMAGES_SETTINGS    = 'sermonator_term_images_settings';
    public const OPTION_MIGRATION_STATE         = 'sermonator_migration_state';
    public const OPTION_PRE_MIGRATION_BACKUP    = 'sermonator_pre_migration_backup';
    public const OPTION_MIGRATION_PROGRESS      = 'sermonator_migration_progress';
    public const OPTION_LEGACY_FEED_SNAPSHOT    = 'sermonator_legacy_feed_snapshot';

    /**
     * Durable legacy-podcast-id -> new-podcast-id map, the post-Finalize-safe
     * resolver for legacy podcast feed URLs. Unlike the Crosswalk LEGACY_POST_ID
     * back-ref meta (which the Finalizer strips), this option must survive Finalize
     * so /?feed=rss2&post_type=wpfc_sermon&id=<legacy> keeps resolving forever.
     *
     * TODO(parity-followup): population is a SEPARATE deferred task — this map must
     * be written at migrate time (legacy podcast id => new podcast id) and the
     * Finalizer must NOT strip it.
     */
    public const OPTION_LEGACY_PODCAST_MAP      = 'sermonator_legacy_podcast_map';
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
