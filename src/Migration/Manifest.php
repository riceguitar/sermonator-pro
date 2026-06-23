<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Immutable snapshot of the legacy data's shape at detection time: per-entity
 * counts plus per-sermon AND per-podcast content checksums. Because the migration
 * never alters legacy data, this manifest IS the backup oracle — the Verifier (B2)
 * compares the migrated result against it.
 *
 * Sermon and podcast checksums are kept in SEPARATE maps on purpose: downstream
 * code (Verifier, Finalizer) distinguishes a legacy SERMON from a legacy PODCAST by
 * `checksum($id) !== null` (sermon map only), so folding podcasts into the same map
 * would break that discriminator. Both maps still give every post a source-fixity
 * oracle so a post-detect edit to either type is caught before Finalize deletes it.
 */
final class Manifest {
    /**
     * @param array<string,int>    $counts          Entity key → count.
     * @param array<int,string>    $checksums       Legacy SERMON post ID → content checksum.
     * @param array<int,string>    $podcastChecksums Legacy PODCAST post ID → content checksum.
     */
    public function __construct(
        private readonly array $counts,
        private readonly array $checksums = array(),
        private readonly array $podcastChecksums = array()
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

    /** The detect-time content checksum for a legacy PODCAST id, or null. */
    public function podcastChecksum( int $legacyId ): ?string {
        return $this->podcastChecksums[ $legacyId ] ?? null;
    }

    /**
     * The legacy PODCAST ids the manifest checksummed at detect time, ascending.
     * Symmetric with checksummedLegacyIds() (sermons): drives podcast verification
     * off the immutable MANIFEST so a podcast deleted/inserted/edited AFTER detect is
     * caught (vanished id flagged missing; inserted id cross-checked; edited id drifts).
     *
     * @return list<int>
     */
    public function checksummedPodcastLegacyIds(): array {
        $ids = array_map( 'intval', array_keys( $this->podcastChecksums ) );
        sort( $ids );
        return array_values( $ids );
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

    /** @return array{counts: array<string,int>, checksums: array<int,string>, podcastChecksums: array<int,string>} */
    public function toArray(): array {
        return array(
            'counts'           => $this->counts,
            'checksums'        => $this->checksums,
            'podcastChecksums' => $this->podcastChecksums,
        );
    }

    /** @param array{counts?: array<string,int>, checksums?: array<int,string>, podcastChecksums?: array<int,string>} $data */
    public static function fromArray( array $data ): self {
        return new self(
            $data['counts'] ?? array(),
            $data['checksums'] ?? array(),
            $data['podcastChecksums'] ?? array()
        );
    }
}
