<?php

declare(strict_types=1);

namespace Sermonator\Schema;

/**
 * Single source of truth for the Bundle 4 (Config & display) option defaults.
 *
 * `register_setting()`'s `default` filter only attaches on `admin_init` /
 * `rest_api_init`, so it is ABSENT at `init@5` (CPT/taxonomy registration) and on
 * the front end. Every reader ({@see \Sermonator\Model\Registrar},
 * {@see \Sermonator\Frontend\TemplateData}) therefore passes an EXPLICIT fallback
 * to `get_option()`, and the registered defaults reference these same resolvers —
 * so the seeded value is identical no matter which path observes it.
 *
 * Each resolver mirrors {@see \Sermonator\Admin\SettingsRegistrar::defaultLinkVersion()}:
 * it honors the church's real legacy choice DURABLY across the migration lifecycle
 * by consulting, in order,
 *
 *   1. the MIGRATED prefix-swap row (`sermonator_*`) — the only copy that survives
 *      the Finalizer's `sermonmanager_*` deletion, written when OptionMapper
 *      prefix-swaps the legacy option;
 *   2. the pre-finalize LEGACY row (`sermonmanager_*`);
 *   3. a hard constant.
 *
 * NOTE: these migrated/legacy rows are the SEED sources only. The live keys the
 * settings UI reads/writes are the DISTINCT {@see Identifiers::OPTION_ARCHIVE_SLUG}
 * / {@see Identifiers::OPTION_DEFAULT_IMAGE_ID} (and the 1:1
 * {@see Identifiers::OPTION_PREACHER_LABEL}); seeding from the artifacts here keeps
 * a migration re-run from clobbering a saved admin edit.
 */
final class DisplayDefaults {
    private const LEGACY_PREFIX = 'sermonmanager_';

    /** Hard fallbacks (the last resort when neither a migrated nor a legacy row exists). */
    public const HARD_ARCHIVE_SLUG   = 'sermons';
    public const HARD_IMAGE_ID       = 0;
    public const HARD_PREACHER_LABEL = 'Preacher';

    /**
     * The CPT archive (and single-sermon permalink) base slug.
     *
     * Seed order: migrated `sermonator_archive_slug` → legacy
     * `sermonmanager_archive_slug` → `'sermons'`. A row counts only when it is a
     * non-empty string; the raw stored value is returned verbatim (sanitizing /
     * collision-guarding the live key is the write-path's job, not the seed's).
     */
    public static function defaultArchiveSlug(): string {
        foreach (
            array(
                Identifiers::OPTION_PREFIX . 'archive_slug',
                self::LEGACY_PREFIX . 'archive_slug',
            ) as $optionName
        ) {
            $candidate = get_option( $optionName );

            if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
                return $candidate;
            }
        }

        return self::HARD_ARCHIVE_SLUG;
    }

    /**
     * The fallback featured-image attachment id used when a sermon has no real
     * thumbnail.
     *
     * Seed order walks BOTH the id-named and the (older) bare `default_image`
     * rows at each provenance level: migrated `sermonator_default_image_id` →
     * migrated `sermonator_default_image` → legacy `sermonmanager_default_image_id`
     * → legacy `sermonmanager_default_image` → `0`. A row counts only when it
     * resolves to a positive integer attachment id; a legacy URL-valued
     * `default_image` is NOT an id and is skipped here (its one-time URL→id
     * resolution is the EffectiveImage write-path's concern, not the seed's).
     */
    public static function defaultImageId(): int {
        foreach (
            array(
                Identifiers::OPTION_PREFIX . 'default_image_id',
                Identifiers::OPTION_PREFIX . 'default_image',
                self::LEGACY_PREFIX . 'default_image_id',
                self::LEGACY_PREFIX . 'default_image',
            ) as $optionName
        ) {
            $candidate = get_option( $optionName );

            if ( ( is_int( $candidate ) || ( is_string( $candidate ) && ctype_digit( $candidate ) ) )
                && (int) $candidate > 0
            ) {
                return (int) $candidate;
            }
        }

        return self::HARD_IMAGE_ID;
    }

    /**
     * The singular preacher taxonomy label (e.g. "Preacher" / "Speaker").
     *
     * Seed order: migrated `sermonator_preacher_label` (which is also the live
     * 1:1 key {@see Identifiers::OPTION_PREACHER_LABEL}) → legacy
     * `sermonmanager_preacher_label` → `'Preacher'`. A row counts only when it is
     * a non-empty string.
     */
    public static function preacherLabel(): string {
        foreach (
            array(
                Identifiers::OPTION_PREFIX . 'preacher_label',
                self::LEGACY_PREFIX . 'preacher_label',
            ) as $optionName
        ) {
            $candidate = get_option( $optionName );

            if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
                return $candidate;
            }
        }

        return self::HARD_PREACHER_LABEL;
    }
}
