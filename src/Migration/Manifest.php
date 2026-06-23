<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Immutable snapshot of the legacy data's shape at detection time: per-entity
 * counts plus per-sermon content checksums. Because the migration never alters
 * legacy data, this manifest IS the backup oracle — the Verifier (B2) compares
 * the migrated result against it.
 */
final class Manifest {
    /**
     * @param array<string,int>    $counts    Entity key → count.
     * @param array<int,string>    $checksums Legacy post ID → content checksum.
     */
    public function __construct(
        private readonly array $counts,
        private readonly array $checksums = array()
    ) {}

    public function count( string $key ): int {
        return $this->counts[ $key ] ?? 0;
    }

    /** @return array<string,int> */
    public function counts(): array {
        return $this->counts;
    }

    public function checksum( int $legacyId ): ?string {
        return $this->checksums[ $legacyId ] ?? null;
    }

    /**
     * The legacy post ids the manifest checksummed at detect time, ascending.
     * This is the authoritative source-of-record the Verifier enumerates from —
     * driving verification off the MANIFEST (not the current live DB) is what lets
     * a legacy post deleted/inserted AFTER detect be caught (a vanished id stays in
     * this set and is flagged missing; a newly-inserted live id is absent from it
     * and is cross-checked separately).
     *
     * @return list<int>
     */
    public function checksummedLegacyIds(): array {
        $ids = array_map( 'intval', array_keys( $this->checksums ) );
        sort( $ids );
        return array_values( $ids );
    }

    /** @return array{counts: array<string,int>, checksums: array<int,string>} */
    public function toArray(): array {
        return array( 'counts' => $this->counts, 'checksums' => $this->checksums );
    }

    /** @param array{counts?: array<string,int>, checksums?: array<int,string>} $data */
    public static function fromArray( array $data ): self {
        return new self( $data['counts'] ?? array(), $data['checksums'] ?? array() );
    }
}
