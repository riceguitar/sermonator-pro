<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * Immutable display model for a sermon's resolved scripture references.
 *
 * Sibling of {@see SermonView}: produced by the impure {@see BibleResolver} at
 * template time and consumed by the pure {@see Renderer}. Each ref carries the
 * human-readable label, the external lookup URL (axis-A link version — the always-
 * available 3a LINK), the version code used to build that URL, the `inlineEligible`
 * structural gate, and the OPTIONAL Phase 3b `inline` payload:
 *
 *   inline === null               → render the 3a LINK (byte-identical to 3a).
 *   inline === {translation,       → render the inline verse TEXT section + badge.
 *              attribution,
 *              verses:[{number,
 *                nodes:[{type,text}]}]}
 *
 * The payload carries TYPED nodes (`type` ∈ `text|wordsOfJesus|note`), NEVER raw
 * HTML — the pure {@see Renderer} escapes every leaf and builds its own markup. A
 * ref's `inline` is non-null ONLY when it cleared the full L1–L9 never-fail-WRONG
 * predicate in {@see BibleResolver}; otherwise it is null (the ref fell open to the
 * link, observably). It holds NO raw HTML — escaping happens at the Renderer boundary.
 *
 * Construction guarantees a non-empty, re-indexed list: a sermon with zero
 * resolvable references is represented by `null` (fail-open), never by an empty
 * ResolvedScripture, so the Renderer can branch on a single null check.
 *
 * @phpstan-type InlinePayload array{translation:string,attribution:string,verses:list<array{number:int,nodes:list<array{type:string,text:string}>}>}
 * @phpstan-type ScriptureRef array{label:string,linkUrl:string,version:string,inlineEligible:bool,inline?:InlinePayload|null}
 */
final class ResolvedScripture {
    /**
     * @var list<array{label:string,linkUrl:string,version:string,inlineEligible:bool,inline?:array{translation:string,attribution:string,verses:list<array{number:int,nodes:list<array{type:string,text:string}>}>}|null}>
     */
    private array $refs;

    /**
     * @param list<array{label:string,linkUrl:string,version:string,inlineEligible:bool,inline?:array{translation:string,attribution:string,verses:list<array{number:int,nodes:list<array{type:string,text:string}>}>}|null}> $refs
     */
    public function __construct( array $refs ) {
        $this->refs = array_values( $refs );
    }

    /**
     * The resolved references, in document order.
     *
     * @return list<array{label:string,linkUrl:string,version:string,inlineEligible:bool,inline?:array{translation:string,attribution:string,verses:list<array{number:int,nodes:list<array{type:string,text:string}>}>}|null}>
     */
    public function refs(): array {
        return $this->refs;
    }

    public function isEmpty(): bool {
        return array() === $this->refs;
    }
}
