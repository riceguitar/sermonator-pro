<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Migrates the legacy Sermon Image Plugin options (`sermon_image_plugin` and
 * `sermon_image_plugin_settings`) into the sermonator_* namespace.
 *
 * It reads the legacy options READ-ONLY, runs them through the pure
 * TermArtworkMapper against TermCrosswalk::ttIdMap() (legacy tt_id → new tt_id),
 * and writes:
 *   - Identifiers::OPTION_TERM_IMAGES           (new tt_id => attachment_id)
 *   - Identifiers::OPTION_TERM_IMAGES_SETTINGS  (taxonomy keys remapped, globals verbatim)
 *
 * Crash/clobber discipline ("add_option-first + backup"): a church may already
 * have a NATIVE sermonator_term_images / sermonator_term_images_settings. Before
 * overwriting either, the pre-existing value is backed up to
 * OPTION_PRE_MIGRATION_BACKUP so native config is never lost and rollback can
 * restore it. The backup is taken at most once per key (the first time WE touch
 * it) — a re-run never backs up the value we ourselves wrote, keeping the
 * operation idempotent.
 *
 * Orphaned legacy tt_ids (no migrated term) and new-tt_id collisions are
 * recorded — both returned AND persisted to OPTION_MIGRATION_PROGRESS so the
 * verifier / admin review can inspect them later.
 *
 * Legacy options are NEVER mutated.
 */
final class ArtworkWriter {
    /** Sub-key under OPTION_MIGRATION_PROGRESS where artwork dropped/conflicts/keys live. */
    private const PROGRESS_KEY = 'artwork';

    /**
     * @return array{written: int, dropped: list<int>, conflicts: list<int>}
     */
    public function migrate( TermCrosswalk $crosswalk ): array {
        $ttIdMap = $crosswalk->ttIdMap();

        $legacyImages   = $this->readArray( LegacyIdentifiers::OPTION_TERM_IMAGES );
        $legacySettings = $this->readArray( LegacyIdentifiers::OPTION_TERM_IMAGES_SETTINGS );

        $remappedImages   = TermArtworkMapper::remapImages( $legacyImages, $ttIdMap );
        $remappedSettings = TermArtworkMapper::remapSettings( $legacySettings );

        // Only write images if there is something to write — an empty result
        // (legacy plugin had no artwork, or every legacy tt_id was orphaned and
        // dropped) must not stamp an empty array over a church's native
        // sermonator_term_images. This mirrors the settings guard below: when
        // there is nothing to migrate we leave native config untouched and take
        // no backup. A clobbered-then-emptied native value would be recoverable
        // from OPTION_PRE_MIGRATION_BACKUP, but needlessly destroying a working
        // native config when we have nothing to offer is the wrong default.
        if ( $remappedImages['images'] !== array() ) {
            $this->writeOption( Identifiers::OPTION_TERM_IMAGES, $remappedImages['images'] );
        }

        // Only write settings if the legacy plugin had any — an empty settings
        // option should not stamp an empty array over a church's native settings.
        if ( $legacySettings !== array() ) {
            $this->writeOption( Identifiers::OPTION_TERM_IMAGES_SETTINGS, $remappedSettings );
        }

        $this->persistFlags( $remappedImages['dropped'], $remappedImages['conflicts'] );

        return array(
            'written'   => count( $remappedImages['images'] ),
            'dropped'   => $remappedImages['dropped'],
            'conflicts' => $remappedImages['conflicts'],
        );
    }

    /**
     * The dropped/conflicts flags this writer persisted on its last run.
     *
     * @return array{dropped: list<int>, conflicts: list<int>}
     */
    public static function persistedFlags(): array {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $artwork  = is_array( $progress ) && isset( $progress[ self::PROGRESS_KEY ] ) && is_array( $progress[ self::PROGRESS_KEY ] )
            ? $progress[ self::PROGRESS_KEY ]
            : array();

        return array(
            'dropped'   => isset( $artwork['dropped'] ) && is_array( $artwork['dropped'] ) ? array_values( array_map( 'intval', $artwork['dropped'] ) ) : array(),
            'conflicts' => isset( $artwork['conflicts'] ) && is_array( $artwork['conflicts'] ) ? array_values( array_map( 'intval', $artwork['conflicts'] ) ) : array(),
        );
    }

    /** Read a legacy option as an array (empty array if absent / scalar). */
    private function readArray( string $optionName ): array {
        $value = get_option( $optionName );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Write a target option with the add_option-first + backup discipline.
     *
     * - If the option does NOT yet exist (add_option succeeds), nothing to back
     *   up — done.
     * - If it DOES exist, back up the current value to OPTION_PRE_MIGRATION_BACKUP
     *   the first time we touch this key (tracked so a re-run does not re-backup
     *   the value we wrote ourselves), then update.
     */
    private function writeOption( string $optionName, array $value ): void {
        // add_option returns false when the option already exists.
        if ( add_option( $optionName, $value ) ) {
            $this->markKeyWritten( $optionName );
            return;
        }

        // Pre-existing value. Back it up once, only if we have not already
        // migrated (overwritten) this key in a prior run.
        if ( ! $this->keyAlreadyWritten( $optionName ) ) {
            $this->backupOption( $optionName, get_option( $optionName ) );
            $this->markKeyWritten( $optionName );
        }

        update_option( $optionName, $value );
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
        $written = $this->writtenKeys();
        return in_array( $optionName, $written, true );
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

    /**
     * @param list<int> $dropped
     * @param list<int> $conflicts
     */
    private function persistFlags( array $dropped, array $conflicts ): void {
        $this->updateProgress( 'dropped', array_values( array_map( 'intval', $dropped ) ) );
        $this->updateProgress( 'conflicts', array_values( array_map( 'intval', $conflicts ) ) );
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
