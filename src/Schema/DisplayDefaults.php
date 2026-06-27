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
 *   1. the MIGRATED prefix-swap container (`sermonator_general` /
 *      `sermonator_display`) — the only copy that survives the Finalizer's
 *      `sermonmanager_*` deletion, written when OptionMapper prefix-swaps the
 *      legacy option NAME (the value, a serialized settings array, is copied
 *      verbatim);
 *   2. the pre-finalize LEGACY container (`sermonmanager_general` /
 *      `sermonmanager_display`);
 *   3. a hard constant.
 *
 * STORAGE SHAPE (validated against the legacy Sermon Manager plugin + the repo's
 * own realistic fixtures + the capability inventory §2.5): Sermon Manager's CMB2
 * settings are NOT discrete top-level options. Each settings TAB is persisted as a
 * single serialized array option named `sermonmanager_<tab>`, and the individual
 * fields live as ARRAY KEYS inside it:
 *
 *   - `sermonmanager_general` => array( 'archive_slug' => …, 'preacher_label' => …, … )
 *   - `sermonmanager_display` => array( 'default_image' => <url>, 'default_image_id' => <id>, … )
 *
 * (A CMB2 image/file field stores the URL under `default_image` and the attachment
 * id under the companion `default_image_id` key, both inside the display
 * container.) The migration's `OptionWriter` reads every `option_name LIKE
 * 'sermonmanager_%'` row and `OptionMapper`/`MappingContract::mapOptionName`
 * prefix-swaps the NAME only — so a migrated church has `sermonator_general` /
 * `sermonator_display` ARRAYS, never a discrete `sermonator_archive_slug`. These
 * resolvers therefore read the church's value from the array KEY where it actually
 * lives — preserving #1 data-preservation across the migration.
 *
 * NOTE: these migrated/legacy containers are the SEED sources only. The live keys
 * the settings UI reads/writes are the DISTINCT {@see Identifiers::OPTION_ARCHIVE_SLUG}
 * / {@see Identifiers::OPTION_DEFAULT_IMAGE_ID} (and the 1:1
 * {@see Identifiers::OPTION_PREACHER_LABEL}); seeding from the artifacts here keeps
 * a migration re-run from clobbering a saved admin edit.
 */
final class DisplayDefaults {
    private const LEGACY_PREFIX = 'sermonmanager_';

    /** The CMB2 settings-tab container sub-key holding `archive_slug` / `preacher_label`. */
    private const CONTAINER_GENERAL = 'general';

    /** The CMB2 settings-tab container sub-key holding `default_image` / `default_image_id`. */
    private const CONTAINER_DISPLAY = 'display';

    /** Hard fallbacks (the last resort when neither a migrated nor a legacy row exists). */
    public const HARD_ARCHIVE_SLUG   = 'sermons';
    public const HARD_IMAGE_ID       = 0;
    public const HARD_PREACHER_LABEL = 'Preacher';

    /**
     * The CPT archive (and single-sermon permalink) base slug.
     *
     * Seed order: migrated `sermonator_general['archive_slug']` → legacy
     * `sermonmanager_general['archive_slug']` → `'sermons'`. A value counts only
     * when it is a non-empty string; the raw stored value is returned verbatim
     * (sanitizing / collision-guarding the live key is the write-path's job, not
     * the seed's).
     */
    public static function defaultArchiveSlug(): string {
        foreach ( self::generalContainers() as $optionName ) {
            $candidate = self::containerValue( $optionName, 'archive_slug' );

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
     * Seed order walks BOTH the id-named and the (URL-valued) bare `default_image`
     * keys WITHIN each provenance level's display container: migrated
     * `sermonator_display['default_image_id']` → migrated
     * `sermonator_display['default_image']` → legacy
     * `sermonmanager_display['default_image_id']` → legacy
     * `sermonmanager_display['default_image']` → `0`. A value counts only when it
     * resolves to a positive integer attachment id; a legacy URL-valued
     * `default_image` is NOT an id and is skipped here (its one-time URL→id
     * resolution is the EffectiveImage write-path's concern, not the seed's).
     */
    public static function defaultImageId(): int {
        foreach ( self::displayContainers() as $optionName ) {
            foreach ( array( 'default_image_id', 'default_image' ) as $key ) {
                $candidate = self::containerValue( $optionName, $key );

                if ( ( is_int( $candidate ) || ( is_string( $candidate ) && ctype_digit( $candidate ) ) )
                    && (int) $candidate > 0
                ) {
                    return (int) $candidate;
                }
            }
        }

        return self::HARD_IMAGE_ID;
    }

    /**
     * The singular preacher taxonomy label (e.g. "Preacher" / "Speaker").
     *
     * Seed order: migrated `sermonator_general['preacher_label']` → legacy
     * `sermonmanager_general['preacher_label']` → `'Preacher'`. A value counts
     * only when it is a non-empty string. (The live 1:1 key
     * {@see Identifiers::OPTION_PREACHER_LABEL} is a DISTINCT discrete option the
     * settings UI owns — not read here, to keep a migration re-run from clobbering
     * a saved admin edit.)
     */
    public static function preacherLabel(): string {
        foreach ( self::generalContainers() as $optionName ) {
            $candidate = self::containerValue( $optionName, 'preacher_label' );

            if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
                return $candidate;
            }
        }

        return self::HARD_PREACHER_LABEL;
    }

    /**
     * The General-tab containers, migrated-first then legacy.
     *
     * @return list<string>
     */
    private static function generalContainers(): array {
        return array(
            Identifiers::OPTION_PREFIX . self::CONTAINER_GENERAL,
            self::LEGACY_PREFIX . self::CONTAINER_GENERAL,
        );
    }

    /**
     * The Display-tab containers, migrated-first then legacy.
     *
     * @return list<string>
     */
    private static function displayContainers(): array {
        return array(
            Identifiers::OPTION_PREFIX . self::CONTAINER_DISPLAY,
            self::LEGACY_PREFIX . self::CONTAINER_DISPLAY,
        );
    }

    /**
     * Read a single field value out of a serialized CMB2 settings-tab container
     * option, or null when the option is absent / not an array / lacks the key.
     *
     * @return mixed
     */
    private static function containerValue( string $optionName, string $key ) {
        $container = get_option( $optionName );

        if ( is_array( $container ) && array_key_exists( $key, $container ) ) {
            return $container[ $key ];
        }

        return null;
    }
}
