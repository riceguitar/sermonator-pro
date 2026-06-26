<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * Immutable display model for a sermon's resolved scripture references.
 *
 * Sibling of {@see SermonView}: produced by the impure {@see BibleResolver} at
 * template time and consumed by the pure {@see Renderer}. In Phase 3a (link
 * mode) each ref carries the human-readable label, the external lookup URL
 * (axis-A link version), the version code used to build that URL, and the
 * `inlineEligible` gate (carried for 3b; every ref still renders as a link in
 * 3a). It holds NO raw HTML — escaping happens at the Renderer boundary.
 *
 * Construction guarantees a non-empty, re-indexed list: a sermon with zero
 * resolvable references is represented by `null` (fail-open), never by an empty
 * ResolvedScripture, so the Renderer can branch on a single null check.
 */
final class ResolvedScripture {
    /**
     * @var list<array{label:string,linkUrl:string,version:string,inlineEligible:bool}>
     */
    private array $refs;

    /**
     * @param list<array{label:string,linkUrl:string,version:string,inlineEligible:bool}> $refs
     */
    public function __construct( array $refs ) {
        $this->refs = array_values( $refs );
    }

    /**
     * The resolved references, in document order.
     *
     * @return list<array{label:string,linkUrl:string,version:string,inlineEligible:bool}>
     */
    public function refs(): array {
        return $this->refs;
    }

    public function isEmpty(): bool {
        return array() === $this->refs;
    }
}
