<?php

declare(strict_types=1);

namespace Sermonator\Model;

final class Capabilities {
    /** Caps every editing role gets. */
    private const BASE_CAPS = array(
        'edit_sermonator_sermon',
        'read_sermonator_sermon',
        'delete_sermonator_sermon',
        'edit_sermonator_sermons',
        'edit_published_sermonator_sermons',
        'publish_sermonator_sermons',
        'delete_sermonator_sermons',
        'delete_published_sermonator_sermons',
        'read_private_sermonator_sermons',
        'manage_sermonator_categories',
    );

    /** Caps only editor + administrator get. */
    private const ELEVATED_CAPS = array(
        'edit_others_sermonator_sermons',
        'delete_others_sermonator_sermons',
        'edit_private_sermonator_sermons',
        'delete_private_sermonator_sermons',
    );

    /** Caps only administrator gets. */
    private const ADMIN_ONLY_CAPS = array(
        'manage_sermonator_settings',
    );

    public function grant(): void {
        $this->addTo( 'administrator', array_merge( self::BASE_CAPS, self::ELEVATED_CAPS, self::ADMIN_ONLY_CAPS ) );
        $this->addTo( 'editor', array_merge( self::BASE_CAPS, self::ELEVATED_CAPS ) );
        $this->addTo( 'author', self::BASE_CAPS );
    }

    /** @param list<string> $caps */
    private function addTo( string $roleName, array $caps ): void {
        $role = get_role( $roleName );
        if ( null === $role ) {
            return;
        }
        foreach ( $caps as $cap ) {
            if ( ! $role->has_cap( $cap ) ) {
                $role->add_cap( $cap );
            }
        }
    }
}
