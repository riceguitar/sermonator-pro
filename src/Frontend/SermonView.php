<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * Immutable display model for one sermon. Built by {@see TemplateData} and consumed by
 * {@see Renderer}; carries only the data the front end renders, already coerced to display
 * types (timestamps as int, sizes as int, missing values as '' / null / []).
 */
final class SermonView {
    /**
     * @param list<array{name:string,url:string}> $preachers
     * @param list<array{name:string,url:string}> $series
     * @param list<array{name:string,url:string}> $topics
     * @param list<array{name:string,url:string}> $books
     * @param list<array{name:string,url:string}> $serviceTypes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $permalink,
        public readonly ?int $preachedTimestamp,
        public readonly string $biblePassage,
        public readonly string $audioUrl,
        public readonly string $audioDuration,
        public readonly int $audioSize,
        public readonly string $videoEmbed,
        public readonly string $videoUrl,
        public readonly int $views,
        public readonly int $imageId = 0,
        public readonly string $bulletinUrl = '',
        public readonly string $notes = '',
        public readonly array $preachers = array(),
        public readonly array $series = array(),
        public readonly array $topics = array(),
        public readonly array $books = array(),
        public readonly array $serviceTypes = array(),
        public readonly string $preacherLabel = '',
        /**
         * The effective featured-image attachment id — the real thumbnail when
         * set, else the configured site-wide default ({@see EffectiveImage}).
         * Resolved impurely in {@see TemplateData} so the pure {@see Renderer}
         * never reads `get_option`. Falls back to the default image only when
         * {@see self::$imageId} is 0 (legacy `default_image` parity).
         */
        public readonly int $effectiveImageId = 0
    ) {}
}
