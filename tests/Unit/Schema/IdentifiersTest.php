<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sermonator\Schema\Identifiers;

final class IdentifiersTest extends TestCase {
    public function test_post_types_are_prefixed(): void {
        $this->assertSame( 'sermonator_sermon', Identifiers::POST_TYPE_SERMON );
        $this->assertSame( 'sermonator_podcast', Identifiers::POST_TYPE_PODCAST );
    }

    public function test_five_sermon_taxonomies_in_order(): void {
        $this->assertSame(
            array(
                'sermonator_preacher',
                'sermonator_series',
                'sermonator_topic',
                'sermonator_book',
                'sermonator_service_type',
            ),
            Identifiers::sermonTaxonomies()
        );
    }

    public function test_every_identifier_uses_the_prefix(): void {
        $all = array_merge(
            array( Identifiers::POST_TYPE_SERMON, Identifiers::POST_TYPE_PODCAST ),
            Identifiers::sermonTaxonomies(),
            Identifiers::metaKeys()
        );
        foreach ( $all as $id ) {
            $this->assertMatchesRegularExpression( '/^_?sermonator_/', $id, "$id is not prefixed" );
        }
    }

    public function test_identifiers_are_unique(): void {
        $all = array_merge(
            array( Identifiers::POST_TYPE_SERMON, Identifiers::POST_TYPE_PODCAST ),
            Identifiers::sermonTaxonomies(),
            Identifiers::metaKeys()
        );
        $this->assertSame( count( $all ), count( array_unique( $all ) ), 'duplicate identifier' );
    }

    public function test_meta_keys_include_core_sermon_fields(): void {
        $keys = Identifiers::metaKeys();
        foreach ( array( 'sermonator_date', 'sermonator_bible_passage', 'sermonator_audio', 'sermonator_views' ) as $expected ) {
            $this->assertContains( $expected, $keys );
        }
    }

    public function test_bundle3_bible_meta_constants(): void {
        $this->assertSame( 'sermonator_bible_refs', Identifiers::META_BIBLE_REFS );
        $this->assertSame( 'sermonator_bible_refs_unparseable', Identifiers::META_BIBLE_REFS_UNPARSEABLE );
    }

    public function test_bundle3_bible_option_constants(): void {
        $this->assertSame( 'sermonator_bible_link_version', Identifiers::OPTION_BIBLE_LINK_VERSION );
        $this->assertSame( 'sermonator_bible_translation', Identifiers::OPTION_BIBLE_INLINE_TRANSLATION );
        $this->assertSame( 'sermonator_settings', Identifiers::OPTION_GROUP_SETTINGS );
        $this->assertSame( 'sermonator_bible_cache_gen', Identifiers::OPTION_BIBLE_CACHE_GEN );
        $this->assertSame( 'sermonator_bible_stats', Identifiers::OPTION_BIBLE_STATS );
        $this->assertSame( 'sermonator_bible_refs_backfill_log', Identifiers::OPTION_BIBLE_REFS_BACKFILL_LOG );
    }

    public function test_meta_keys_include_bible_refs(): void {
        $this->assertContains( Identifiers::META_BIBLE_REFS, Identifiers::metaKeys() );
    }

    public function test_bundle4_config_option_constants(): void {
        // DISTINCT live keys (note the `_sermon_` infix) — NOT the
        // `sermonator_archive_slug` / `sermonator_default_image_id` migration
        // prefix-swap artifacts a re-run would clobber. `preacher_label` is 1:1.
        $this->assertSame( 'sermonator_sermon_archive_slug', Identifiers::OPTION_ARCHIVE_SLUG );
        $this->assertSame( 'sermonator_sermon_default_image_id', Identifiers::OPTION_DEFAULT_IMAGE_ID );
        $this->assertSame( 'sermonator_preacher_label', Identifiers::OPTION_PREACHER_LABEL );
        $this->assertSame( 'sermonator_rewrite_flush_pending', Identifiers::OPTION_REWRITE_FLUSH_PENDING );
    }

    public function test_bundle4_live_keys_are_not_the_migration_artifacts(): void {
        // The provenance-boundary invariant: the live keys must differ from the
        // prefix-swap artifact keys DisplayDefaults seeds them from, or a
        // migration re-run's OptionWriter would overwrite a saved admin edit.
        $this->assertNotSame( Identifiers::OPTION_PREFIX . 'archive_slug', Identifiers::OPTION_ARCHIVE_SLUG );
        $this->assertNotSame( Identifiers::OPTION_PREFIX . 'default_image_id', Identifiers::OPTION_DEFAULT_IMAGE_ID );
    }
}
