<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * The declarative legacy→new mapping. Single source of truth consumed by both
 * the mappers (to transform) and the verifier (to check). `sermon_description`
 * is intentionally absent from metaKeyMap — it becomes post_content (see
 * SermonMetaMapper / PostContentReconciler).
 */
final class MappingContract {
    /** @return array<string,string> */
    public static function postTypeMap(): array {
        return array(
            LegacyIdentifiers::POST_TYPE_SERMON  => Identifiers::POST_TYPE_SERMON,
            LegacyIdentifiers::POST_TYPE_PODCAST => Identifiers::POST_TYPE_PODCAST,
        );
    }

    /** @return array<string,string> Legacy taxonomy → new taxonomy, paired by canonical order. */
    public static function taxonomyMap(): array {
        return array_combine(
            LegacyIdentifiers::sermonTaxonomies(),
            Identifiers::sermonTaxonomies()
        );
    }

    /** @return array<string,string> Legacy sermon meta key → new meta key. Excludes dropped/special keys. */
    public static function metaKeyMap(): array {
        return array(
            LegacyIdentifiers::META_DATE           => Identifiers::META_DATE,
            LegacyIdentifiers::META_DATE_AUTO      => Identifiers::META_DATE_AUTO,
            LegacyIdentifiers::META_BIBLE_PASSAGE  => Identifiers::META_BIBLE_PASSAGE,
            LegacyIdentifiers::META_AUDIO          => Identifiers::META_AUDIO,
            LegacyIdentifiers::META_AUDIO_ID       => Identifiers::META_AUDIO_ID,
            LegacyIdentifiers::META_AUDIO_DURATION => Identifiers::META_AUDIO_DURATION,
            LegacyIdentifiers::META_AUDIO_SIZE     => Identifiers::META_AUDIO_SIZE,
            LegacyIdentifiers::META_VIDEO          => Identifiers::META_VIDEO_EMBED,
            LegacyIdentifiers::META_VIDEO_LINK     => Identifiers::META_VIDEO_URL,
            LegacyIdentifiers::META_NOTES          => Identifiers::META_NOTES,
            LegacyIdentifiers::META_BULLETIN       => Identifiers::META_BULLETIN,
            LegacyIdentifiers::META_VIEWS          => Identifiers::META_VIEWS,
        );
    }

    /** @return list<string> Legacy keys NOT carried as meta. */
    public static function droppedMetaKeys(): array {
        return array(
            LegacyIdentifiers::META_SERVICE_TYPE_DENORM, // taxonomy term is authoritative
            LegacyIdentifiers::META_DESCRIPTION,         // becomes post_content
        );
    }

    /** @return string|null `sermonmanager_X` → `sermonator_X`; null if not a sermonmanager_ option. */
    public static function mapOptionName( string $legacyName ): ?string {
        $prefix = LegacyIdentifiers::OPTION_PREFIX;
        if ( ! str_starts_with( $legacyName, $prefix ) ) {
            return null;
        }
        return Identifiers::OPTION_PREFIX . substr( $legacyName, strlen( $prefix ) );
    }

    private function __construct() {}
}
