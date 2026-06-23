<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

/** Immutable RSS/iTunes channel configuration for one podcast feed. */
final class PodcastConfig {
    public function __construct(
        public readonly string $title,
        public readonly string $link,
        public readonly string $description,
        public readonly string $language,
        public readonly string $author,
        public readonly string $summary,
        public readonly string $ownerName,
        public readonly string $ownerEmail,
        public readonly string $imageUrl,
        public readonly string $category,
        public readonly ?string $subcategory,
        public readonly bool $explicit,
        public readonly string $copyright,
        public readonly string $feedUrl
    ) {}
}
