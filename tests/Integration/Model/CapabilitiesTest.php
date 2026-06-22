<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Model;

use WP_UnitTestCase;
use Sermonator\Model\Capabilities;

final class CapabilitiesTest extends WP_UnitTestCase {
    public function test_administrator_can_edit_sermons(): void {
        ( new Capabilities() )->grant();
        $admin = get_role( 'administrator' );
        $this->assertTrue( $admin->has_cap( 'edit_sermonator_sermons' ) );
        $this->assertTrue( $admin->has_cap( 'publish_sermonator_sermons' ) );
        $this->assertTrue( $admin->has_cap( 'manage_sermonator_categories' ) );
        $this->assertTrue( $admin->has_cap( 'manage_sermonator_settings' ) );
    }

    public function test_editor_can_manage_but_not_settings(): void {
        ( new Capabilities() )->grant();
        $editor = get_role( 'editor' );
        $this->assertTrue( $editor->has_cap( 'edit_others_sermonator_sermons' ) );
        $this->assertTrue( $editor->has_cap( 'manage_sermonator_categories' ) );
        $this->assertFalse( $editor->has_cap( 'manage_sermonator_settings' ) );
    }

    public function test_author_can_edit_own_but_not_others(): void {
        ( new Capabilities() )->grant();
        $author = get_role( 'author' );
        $this->assertTrue( $author->has_cap( 'edit_sermonator_sermons' ) );
        $this->assertFalse( $author->has_cap( 'edit_others_sermonator_sermons' ) );
    }

    public function test_grant_is_idempotent(): void {
        ( new Capabilities() )->grant();
        ( new Capabilities() )->grant();
        $this->assertTrue( get_role( 'administrator' )->has_cap( 'edit_sermonator_sermons' ) );
    }
}
