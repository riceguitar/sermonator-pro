<?php
declare(strict_types=1);
namespace Sermonator\Tests\Unit\Migration;
use PHPUnit\Framework\TestCase;
use Sermonator\Schema\Identifiers;
use Sermonator\Migration\Crosswalk;
final class B2ConstantsTest extends TestCase {
    public function test_new_identifier_options(): void {
        $this->assertSame('sermonator_term_images_settings', Identifiers::OPTION_TERM_IMAGES_SETTINGS);
        $this->assertSame('sermonator_migration_state', Identifiers::OPTION_MIGRATION_STATE);
        $this->assertSame('sermonator_pre_migration_backup', Identifiers::OPTION_PRE_MIGRATION_BACKUP);
        $this->assertSame('sermonator_migration_progress', Identifiers::OPTION_MIGRATION_PROGRESS);
        $this->assertSame('sermonator_date_normalized', Identifiers::META_DATE_NORMALIZED);
    }
    public function test_new_crosswalk_keys_hidden_and_distinct(): void {
        foreach ([Crosswalk::LEGACY_TERM_TT_ID, Crosswalk::LEGACY_COMMENT_ID, Crosswalk::MIGRATION_COMPLETE, Crosswalk::LEGACY_SLUG, Crosswalk::MIGRATION_FLAGS, Crosswalk::LEGACY_POST_CONTENT] as $k) {
            $this->assertStringStartsWith('_sermonator_', $k);
        }
        $this->assertNotSame(Crosswalk::LEGACY_TERM_ID, Crosswalk::LEGACY_TERM_TT_ID);
    }
    public function test_strippable_allowlist_excludes_preserved_content(): void {
        $strip = Crosswalk::strippableBackRefs();
        $this->assertContains(Crosswalk::LEGACY_POST_ID, $strip);
        $this->assertContains(Crosswalk::MIGRATION_COMPLETE, $strip);
        $this->assertNotContains(Crosswalk::LEGACY_POST_CONTENT, $strip);
        $this->assertNotContains(Crosswalk::MIGRATION_FLAGS, $strip);
    }
}
