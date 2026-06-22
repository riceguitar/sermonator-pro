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

    /** @return array{counts: array<string,int>, checksums: array<int,string>} */
    public function toArray(): array {
        return array( 'counts' => $this->counts, 'checksums' => $this->checksums );
    }

    /** @param array{counts?: array<string,int>, checksums?: array<int,string>} $data */
    public static function fromArray( array $data ): self {
        return new self( $data['counts'] ?? array(), $data['checksums'] ?? array() );
    }
}
