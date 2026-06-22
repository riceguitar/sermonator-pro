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
