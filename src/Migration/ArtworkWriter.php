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
        // MUST-FIX #1: re-register the legacy schema so a DEACTIVATED legacy plugin
        // does not make any term/post read in the artwork remap path observe an
        // unregistered source. Idempotent; a no-op when the legacy plugin is active.
        LegacySchemaRegistrar::ensureRegistered();

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

        $this->persistFlags( $remappedImages['dropped'], $remappedImages['conflicts'], $remappedImages['conflict_details'] );

        return array(
            'written'   => count( $remappedImages['images'] ),
            'dropped'   => $remappedImages['dropped'],
            'conflicts' => $remappedImages['conflicts'],
        );
    }

    /**
     * The dropped/conflicts flags this writer persisted on its last run, plus the
     * per-collision conflict_details (IMPORTANT #8) preserving the LOSING
     * attachment_id so an admin can recover the dropped artwork.
     *
     * @return array{dropped: list<int>, conflicts: list<int>, conflict_details: list<array{new_tt_id: int, legacy_tt_id: int, discarded_attachment_id: int, winning_attachment_id: int}>}
     */
    public static function persistedFlags(): array {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        $artwork  = is_array( $progress ) && isset( $progress[ self::PROGRESS_KEY ] ) && is_array( $progress[ self::PROGRESS_KEY ] )
            ? $progress[ self::PROGRESS_KEY ]
            : array();

        $details = array();
        if ( isset( $artwork['conflict_details'] ) && is_array( $artwork['conflict_details'] ) ) {
            foreach ( $artwork['conflict_details'] as $detail ) {
                if ( ! is_array( $detail ) ) {
                    continue;
                }
                $details[] = array(
                    'new_tt_id'               => isset( $detail['new_tt_id'] ) ? (int) $detail['new_tt_id'] : 0,
                    'legacy_tt_id'            => isset( $detail['legacy_tt_id'] ) ? (int) $detail['legacy_tt_id'] : 0,
                    'discarded_attachment_id' => isset( $detail['discarded_attachment_id'] ) ? (int) $detail['discarded_attachment_id'] : 0,
                    'winning_attachment_id'   => isset( $detail['winning_attachment_id'] ) ? (int) $detail['winning_attachment_id'] : 0,
                );
            }
        }

        return array(
            'dropped'          => isset( $artwork['dropped'] ) && is_array( $artwork['dropped'] ) ? array_values( array_map( 'intval', $artwork['dropped'] ) ) : array(),
            'conflicts'        => isset( $artwork['conflicts'] ) && is_array( $artwork['conflicts'] ) ? array_values( array_map( 'intval', $artwork['conflicts'] ) ) : array(),
            'conflict_details' => $details,
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
        $alreadyWritten = $this->keyAlreadyWritten( $optionName );

        // Detect existence without relying on add_option's ambiguous false return:
        // a unique sentinel default distinguishes "absent" from "present-but-falsey".
        $sentinel = "\0__sermonator_absent__\0";
        $current  = get_option( $optionName, $sentinel );

        if ( $current === $sentinel ) {
            // No pre-existing value: nothing to back up. Mark first, then write, so a
            // crash between the two can never leave the value stamped without a marker.
            $this->markKeyWritten( $optionName );
            add_option( $optionName, $value );
            return;
        }

        // Pre-existing value. Back it up once, only if we have not already migrated
        // (overwritten) this key in a prior run AND the currently-stored value is not
        // already the value we are about to write. The value-equality check is the
        // crash-window guard: if a prior run stamped our migrated value but died
        // before recording the marker, the stored value already equals $value, so we
        // must NOT back it up as if it were native — that would let rollback restore
        // the migrated value instead of the true native one.
        if ( ! $alreadyWritten && $current !== $value ) {
            $this->backupOption( $optionName, $current );
        }

        $this->markKeyWritten( $optionName );
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
     * @param list<int>                                                                                                  $dropped
     * @param list<int>                                                                                                  $conflicts
     * @param list<array{new_tt_id: int, legacy_tt_id: int, discarded_attachment_id: int, winning_attachment_id: int}>   $conflictDetails
     */
    private function persistFlags( array $dropped, array $conflicts, array $conflictDetails = array() ): void {
        $this->updateProgress( 'dropped', array_values( array_map( 'intval', $dropped ) ) );
        $this->updateProgress( 'conflicts', array_values( array_map( 'intval', $conflicts ) ) );
        // IMPORTANT #8: persist the LOSING attachment_id per collision so it is
        // recoverable from OPTION_MIGRATION_PROGRESS rather than discarded forever.
        $this->updateProgress( 'conflict_details', array_values( $conflictDetails ) );
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
