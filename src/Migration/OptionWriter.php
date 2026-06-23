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
 *  - KNOWN id-bearing options (the CLOSED set: sermonator_default_series,
 *    sermonator_default_preacher, sermonator_default_service_type,
 *    sermonator_default_book, sermonator_default_topic) embed legacy term ids in
 *    their VALUE — either as a scalar integer/string OR nested inside an array.
 *    These are translated recursively via TermCrosswalk::newTermId() BEFORE the
 *    write so the stored value always points at the new term (type-preserving:
 *    int→int, string→string). An unresolvable positive id is left verbatim AND a
 *    missing_option_id_crosswalk:<optionName> flag is recorded under
 *    OPTION_MIGRATION_PROGRESS['options']['option_id_flags'] so a later pass
 *    (once the term is migrated) can re-run and clear it. option_id_flags uses
 *    REPLACE semantics — an empty set on self-heal unconditionally deletes any
 *    stale flag so the Verifier can gate on "no flags" reliably. Attachment ids
 *    embedded in options are SHARED globals — they never change — so they are left
 *    verbatim and are intentionally excluded from TERM_ID_OPTIONS;
 *  - legacy options are NEVER mutated.
 *
 * @phpstan-type MigrateResult array{written: int, backed_up: int}
 */
final class OptionWriter {
    /** Sub-key under OPTION_MIGRATION_PROGRESS where option written-keys live. */
    private const PROGRESS_KEY = 'options';

    /**
     * The CLOSED set of new option names whose VALUES embed legacy TERM IDs —
     * either as a top-level scalar or recursively within an array. All are
     * translated via TermCrosswalk::newTermId() (type-preserving; recursing into
     * arrays) before writing. Any unresolvable positive id is left verbatim and
     * flagged with missing_option_id_crosswalk:<optionName>.
     *
     * Attachment ids in options are SHARED globals (same id in both schemas)
     * and are intentionally excluded — they never need remapping.
     *
     * @var list<string>
     */
    private const TERM_ID_OPTIONS = array(
        'sermonator_default_series',
        'sermonator_default_preacher',
        'sermonator_default_service_type',
        'sermonator_default_book',
        'sermonator_default_topic',
    );

    /**
     * @return array{written: int, backed_up: int}
     */
    public function migrate(): array {
        $legacyOptions = $this->readLegacySermonManagerOptions();
        $mapped        = OptionMapper::map( $legacyOptions );

        $backedUp    = 0;
        $written     = 0;
        $idFlags     = array();
        $crosswalk   = new TermCrosswalk();

        foreach ( $mapped as $name => $value ) {
            // Translate known id-bearing option values before writing.
            $remapped = $this->remapEmbeddedIds( $name, $value, $crosswalk );
            $value    = $remapped['value'];
            $idFlags  = array_merge( $idFlags, $remapped['flags'] );

            if ( $this->writeOption( $name, $value ) ) {
                ++$backedUp;
            }
            ++$written;
        }

        // Persist id-crosswalk flags (REPLACE semantics): always call, including
        // with an empty array, so a self-heal re-run that resolves all ids clears
        // any stale missing_option_id_crosswalk flags rather than leaving them
        // forever. Mirrors SermonWriter/PodcastWriter::writeFlags() replace semantics.
        $this->recordOptionFlags( array_values( array_unique( $idFlags ) ) );

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
     * For KNOWN id-bearing options (TERM_ID_OPTIONS), translate all embedded
     * legacy term ids to new term ids via TermCrosswalk — recursing into arrays
     * so both scalar and array-valued options are covered (type-preserving:
     * int→int, string→string). Returns the (possibly remapped) value and any
     * missing-crosswalk flags.
     *
     * An unresolvable positive id is left VERBATIM and a
     * missing_option_id_crosswalk:<optionName> flag is returned so a later
     * self-heal pass can re-run once the term is migrated.
     *
     * Attachment ids are SHARED globals (same id in both schemas) — they do not
     * appear in TERM_ID_OPTIONS and are intentionally left verbatim.
     *
     * @return array{value: mixed, flags: list<string>}
     */
    private function remapEmbeddedIds( string $optionName, mixed $value, TermCrosswalk $crosswalk ): array {
        $flags = array();

        if ( in_array( $optionName, self::TERM_ID_OPTIONS, true ) ) {
            $value = $this->remapTermValue( $optionName, $value, $crosswalk, $flags );
        }

        return array( 'value' => $value, 'flags' => $flags );
    }

    /**
     * Recursively translate legacy term ids embedded in a value (scalar or array).
     *
     * Positive integer values (int or numeric string) are looked up via
     * TermCrosswalk::newTermId(). An unresolvable positive id is left verbatim and
     * appends a missing_option_id_crosswalk:<optionName> flag. Non-positive or
     * non-numeric scalars pass through unchanged. Arrays are recursed into.
     * Type is preserved: an int input yields an int output; a string input yields a
     * string output.
     *
     * @param list<string> $flags Accumulated by reference.
     * @return mixed The (possibly remapped) value.
     */
    private function remapTermValue( string $optionName, mixed $value, TermCrosswalk $crosswalk, array &$flags ): mixed {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $out[ $k ] = $this->remapTermValue( $optionName, $v, $crosswalk, $flags );
            }
            return $out;
        }

        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && '' !== $value ) ) {
            $legacyTermId = (int) $value;
            if ( $legacyTermId > 0 ) {
                $newTermId = $crosswalk->newTermId( $legacyTermId );
                if ( null !== $newTermId ) {
                    // Type-preserving: if original was string, return string.
                    return is_string( $value ) ? (string) $newTermId : $newTermId;
                }
                // Crosswalk not yet available — leave verbatim and flag for self-heal.
                $flags[] = 'missing_option_id_crosswalk:' . $optionName;
            }
        }

        return $value;
    }

    /**
     * Persist id-crosswalk flags into OPTION_MIGRATION_PROGRESS['options']['option_id_flags']
     * with REPLACE semantics: an empty $flags array DELETES the sub-key so a
     * self-heal re-run leaves the flags absent rather than a stale array. Mirrors
     * SermonWriter/PodcastWriter::writeFlags() replace semantics — a Verifier gating
     * on empty flags reads "never-clean" unless the sub-key is actually absent.
     *
     * @param list<string> $flags
     */
    private function recordOptionFlags( array $flags ): void {
        $progress = get_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        if ( ! is_array( $progress ) ) {
            $progress = array();
        }
        if ( ! isset( $progress[ self::PROGRESS_KEY ] ) || ! is_array( $progress[ self::PROGRESS_KEY ] ) ) {
            $progress[ self::PROGRESS_KEY ] = array();
        }

        if ( $flags === array() ) {
            // Replace semantics: empty → remove the sub-key entirely.
            unset( $progress[ self::PROGRESS_KEY ]['option_id_flags'] );
        } else {
            $progress[ self::PROGRESS_KEY ]['option_id_flags'] = $flags;
        }

        if ( ! add_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress ) ) {
            update_option( Identifiers::OPTION_MIGRATION_PROGRESS, $progress );
        }
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
        // Record the written-key marker BEFORE the irreversible add_option/
        // update_option so a crash between the value write and the marker can never
        // leave the value stamped without the marker. If we crashed the other way
        // (marker stamped, value not yet written) the resume simply re-writes the
        // value below — idempotent — and the keyAlreadyWritten guard correctly
        // skips a (now-bogus) backup of whatever is currently stored.
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
            return false;
        }

        $backedUp = false;
        // Pre-existing value. Back it up once, only if we have not already migrated
        // (overwritten) this key in a prior run AND the currently-stored value is
        // not already the value we are about to write. The value-equality check is
        // the crash-window guard: if a prior run stamped our migrated value but died
        // before recording the marker, the stored value already equals $value, so we
        // must NOT back it up as if it were native — that would let rollback restore
        // the migrated value instead of the true native one.
        if ( ! $alreadyWritten && $current !== $value ) {
            $this->backupOption( $optionName, $current );
            $backedUp = true;
        }

        $this->markKeyWritten( $optionName );
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
