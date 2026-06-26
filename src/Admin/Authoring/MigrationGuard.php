<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Migration\MigrationState;

/**
 * Central gate: the entire authoring write surface is inert while a migration is active.
 *
 * The migration's copy-forward is non-destructive, but the Verifier compares live target data
 * against the detect-time manifest. A stray native-meta edit during an active migration could
 * diverge a record from the manifest and produce a false Verifier failure — or, worse, be
 * finalized on corrupted data. This guard is used by the panel enqueue check, the REST
 * permission callback, and every meta auth_callback.
 */
final class MigrationGuard {
    public static function editingAllowed(): bool {
        $phase = ( new MigrationState() )->phase();
        return in_array( $phase, array( 'none', 'finalized' ), true );
    }
}
