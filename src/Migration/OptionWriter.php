<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Migrates the legacy Sermon Manager settings options (every sermonmanager_*
 * option) into the sermonator_* namespace, plus the wpfc_sm_default_podcast
 * pointer.
 *
 * Discipline (data-preservation, the closed adversarial holes):
 *  - reads every sermonmanager_* option from the live DB and runs them through the
 *    pure OptionMapper (prefix swap, value/type copied VERBATIM);
 *  - add_option-FIRST + backup: before overwriting any target sermonator_* option
 *    that already exists NATIVELY, the pre-existing value is backed up to
 *    OPTION_PRE_MIGRATION_BACKUP so a church's native config is never lost and
 *    rollback can restore it. The backup is taken at most once per key (the first
 *    time WE touch it, tracked in OPTION_MIGRATION_PROGRESS), so a re-run never
 *    re-backs-up the value we ourselves wrote — keeping migrate() idempotent;
 *  - wpfc_sm_default_podcast (a legacy podcast post id) is remapped to
 *    sermonator_default_podcast via Crosswalk::findNewByLegacyId scoped to the
 *    PODCAST post type, so the new default points at the migrated podcast. An
 *    unresolved id is left out (never a dangling legacy id) — the podcast pass
 *    runs before options, so a resolvable id is normally present;
 *  - legacy options are NEVER mutated.
 *
 * @phpstan-type MigrateResult array{written: int, backed_up: int}
 */
final class OptionWriter {
    /** Sub-key under OPTION_MIGRATION_PROGRESS where option written-keys live. */
    private const PROGRESS_KEY = 'options';

    /**
     * @return array{written: int, backed_up: int}
     */
    public function migrate(): array {
        $legacyOptions = $this->readLegacySermonManagerOptions();
        $mapped        = OptionMapper::map( $legacyOptions );

        $backedUp = 0;
        $written  = 0;
        foreach ( $mapped as $name => $value ) {
            if ( $this->writeOption( $name, $value ) ) {
                ++$backedUp;
            }
            ++$written;
        }

        // Remap the default-podcast pointer (legacy post id → new post id).
        if ( $this->migrateDefaultPodcast( $backedUp ) ) {
            ++$written;
        }

        return array(
            'written'   => $written,
            'backed_up' => $backedUp,
        );
    }

    /**
     * Read every live sermonmanager_* option (name => value), READ-ONLY.
     *
     * @return array<string,mixed>
     */
    private function readLegacySermonManagerOptions(): array {
        global $wpdb;

        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( LegacyIdentifiers::OPTION_PREFIX ) . '%'
            )
        );

        $out = array();
        foreach ( (array) $names as $name ) {
            $name = (string) $name;
            // get_option returns the unserialized, typed value.
            $out[ $name ] = get_option( $name );
        }

        return $out;
    }

    /**
     * Remap wpfc_sm_default_podcast (legacy podcast id) → sermonator_default_podcast
     * (new podcast id) via the post crosswalk. Returns whether a target option was
     * written.
     *
     * @param int $backedUp Incremented by reference if a native target is backed up.
     */
    private function migrateDefaultPodcast( int &$backedUp ): bool {
        $legacy = get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST );
        if ( false === $legacy || '' === $legacy || null === $legacy ) {
            return false;
        }

        $legacyId = (int) $legacy;
        $newId    = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_PODCAST );
        if ( null === $newId ) {
            // The podcast was not migrated (yet); never write a dangling legacy id.
            return false;
        }

        if ( $this->writeOption( Identifiers::OPTION_DEFAULT_PODCAST, $newId ) ) {
            ++$backedUp;
        }

        return true;
    }

    /**
     * Write a target option with the add_option-first + backup discipline.
     *
     * @return bool Whether a pre-existing native value was backed up on this call.
     */
    private function writeOption( string $optionName, mixed $value ): bool {
        // add_option returns false when the option already exists.
        if ( add_option( $optionName, $value ) ) {
            $this->markKeyWritten( $optionName );
            return false;
        }

        $backedUp = false;
        // Pre-existing value. Back it up once, only if we have not already migrated
        // (overwritten) this key in a prior run.
        if ( ! $this->keyAlreadyWritten( $optionName ) ) {
            $this->backupOption( $optionName, get_option( $optionName ) );
            $this->markKeyWritten( $optionName );
            $backedUp = true;
        }

        update_option( $optionName, $value );

        return $backedUp;
    }

    /** Record a pre-existing native value under OPTION_PRE_MIGRATION_BACKUP, once. */
    private function backupOption( string $optionName, mixed $existingValue ): void {
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        if ( ! is_array( $backup ) ) {
            $backup = array();
        }

        // Never overwrite an existing backup entry.
        if ( array_key_exists( $optionName, $backup ) ) {
            return;
        }

        $backup[ $optionName ] = $existingValue;

        if ( ! add_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP, $backup ) ) {
            update_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP, $backup );
        }
    }

    private function keyAlreadyWritten( string $optionName ): bool {
        return in_array( $optionName, $this->writtenKeys(), true );
    }

    private function markKeyWritten( string $optionName ): void {
        $written = $this->writtenKeys();
        if ( ! in_array( $optionName, $written, true ) ) {
            $written[] = $optionName;
            $this->updateProgress( 'written_keys', $written );
        }
    }

    /** @return list<string> */
    private function writtenKeys(): array {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( is_array( $progress ) && isset( $progress[ self::PROGRESS_KEY ]['written_keys'] ) && is_array( $progress[ self::PROGRESS_KEY ]['written_keys'] ) ) {
            return array_values( $progress[ self::PROGRESS_KEY ]['written_keys'] );
        }
        return array();
    }

    private function updateProgress( string $subKey, mixed $value ): void {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress ) ) {
            $progress = array();
        }
        if ( ! isset( $progress[ self::PROGRESS_KEY ] ) || ! is_array( $progress[ self::PROGRESS_KEY ] ) ) {
            $progress[ self::PROGRESS_KEY ] = array();
        }

        $progress[ self::PROGRESS_KEY ][ $subKey ] = $value;

        if ( ! add_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress ) ) {
            update_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress );
        }
    }
}
