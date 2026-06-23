<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

/** Immutable RSS/iTunes item for one sermon episode. */
final class FeedItem {
    public function __construct(
        public readonly string $title,
        public readonly string $link,
        public readonly string $guid,
        public readonly string $description,
        public readonly int $pubTimestamp,
        public readonly string $audioUrl,
        public readonly string $audioType,
        public readonly int $audioSize,
        public readonly string $duration,
        public readonly bool $explicit,
        public readonly string $imageUrl = ''
    ) {}
}
