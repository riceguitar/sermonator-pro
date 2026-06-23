<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * Immutable result of a {@see SermonQuery}: the hydrated sermon views for the current page
 * plus pagination facts. Carries no WP_Query/WP_Post — the front end only ever sees views.
 */
final class QueryResult {
    /** @param list<SermonView> $sermons */
    public function __construct(
        public readonly array $sermons,
        public readonly int $total,
        public readonly int $totalPages,
        public readonly int $page
    ) {}

    public function isEmpty(): bool {
        return $this->sermons === array();
    }
}
