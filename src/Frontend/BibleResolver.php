<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Bible\RefValidator;
use Sermonator\Bible\TranslationRegistry;
use Sermonator\Schema\BibleBookMap;
use Sermonator\Schema\Identifiers;

/**
 * Impure orchestrator (Phase 3a, LINK mode) that turns a post's stored
 * {@see Identifiers::META_BIBLE_REFS} envelope into a {@see ResolvedScripture}
 * value object for the pure Renderer to escape and emit.
 *
 * Contract:
 *   resolve(int $postId): ?ResolvedScripture
 *
 * It NEVER throws and NEVER mutates meta. It reads the versioned JSON envelope
 * `{"v":1,"refs":[Ref,…]}` and, for each Ref:
 *   - validates via {@see RefValidator} (skips any ref that is not in-canon or
 *     not structurally valid — never fail-*wrong*),
 *   - builds a human label from {@see BibleBookMap} (USFM → display name) plus
 *     a chapter:verse(-verse)(+cross-chapter) suffix,
 *   - builds a Bible Gateway lookup URL against the configured axis-A link
 *     version ({@see TranslationRegistry::linkVersion()}),
 *   - carries the `inlineEligible` flag (used in 3b; in 3a every ref is a link).
 *
 * Fail-open at two granularities: a missing/empty/corrupt envelope, or a
 * post where no ref resolves, yields `null` (the Renderer then emits today's
 * byte-identical plain-text meta row). Observability hooks fire per outcome:
 *   - `sermonator_bible_resolved` ( array $ref, string $version )
 *   - `sermonator_bible_fallback` ( string $passage, string $reason )
 *
 * Impure only via `get_post_meta` and the option reads inside
 * {@see TranslationRegistry::current()}. No network/disk I/O (link mode).
 */
final class BibleResolver {
    private const BIBLEGATEWAY_BASE = 'https://www.biblegateway.com/passage/?search=';

    /**
     * Resolve a post's stored references into a ResolvedScripture, or null.
     */
    public static function resolve( int $postId ): ?ResolvedScripture {
        $refs = self::readEnvelopeRefs( $postId );
        if ( null === $refs ) {
            return null;
        }

        $version  = TranslationRegistry::current()->linkVersion();
        $resolved = array();

        foreach ( $refs as $ref ) {
            if ( ! is_array( $ref ) ) {
                continue;
            }

            $passage = isset( $ref['raw'] ) && is_string( $ref['raw'] ) ? $ref['raw'] : '';

            $flags = RefValidator::validate( $ref );

            if ( ! $flags['inCanon'] || ! $flags['structurallyValid'] ) {
                /** This single ref can't resolve; the rest of the passage still can. */
                do_action(
                    'sermonator_bible_fallback',
                    $passage,
                    ! $flags['inCanon'] ? 'not-in-canon' : 'structurally-invalid'
                );
                continue;
            }

            $label = self::label( $ref );
            if ( '' === $label ) {
                do_action( 'sermonator_bible_fallback', $passage, 'unlabelable' );
                continue;
            }

            $resolved[] = array(
                'label'          => $label,
                'linkUrl'        => self::linkUrl( $label, $version ),
                'version'        => $version,
                'inlineEligible' => $flags['inlineEligible'],
            );

            /** Ground-truth observability: a real ref resolved to a link. */
            do_action( 'sermonator_bible_resolved', $ref, $version );
        }

        if ( array() === $resolved ) {
            return null;
        }

        return new ResolvedScripture( $resolved );
    }

    /**
     * Read + decode the META_BIBLE_REFS envelope into its raw ref list, or null
     * when the envelope is absent, empty, or not a well-formed `{refs:[…]}`.
     *
     * @return list<mixed>|null
     */
    private static function readEnvelopeRefs( int $postId ): ?array {
        $stored = get_post_meta( $postId, Identifiers::META_BIBLE_REFS, true );

        if ( ! is_string( $stored ) || '' === $stored ) {
            return null;
        }

        $decoded = json_decode( $stored, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $refs = $decoded['refs'] ?? null;
        if ( ! is_array( $refs ) || array() === $refs ) {
            return null;
        }

        return array_values( $refs );
    }

    /**
     * Build the human-readable reference label from a validated Ref.
     *
     * Mirrors the way a reader writes a citation:
     *   whole chapter            -> "John 3"
     *   chapter range            -> "Matthew 5-7"
     *   single verse             -> "John 3:16"
     *   verse range              -> "John 3:16-18"
     *   cross-chapter range      -> "Matthew 5:1-7:29"
     *
     * Returns '' when the book code is unknown (caller treats as fallback).
     *
     * @param array<string,mixed> $ref
     */
    private static function label( array $ref ): string {
        $book = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
        $name = self::bookName( $book );
        if ( '' === $name ) {
            return '';
        }

        $chapterStart = (int) ( $ref['chapterStart'] ?? 0 );
        $verseStart   = isset( $ref['verseStart'] ) && null !== $ref['verseStart'] ? (int) $ref['verseStart'] : null;
        $verseEnd     = isset( $ref['verseEnd'] ) && null !== $ref['verseEnd'] ? (int) $ref['verseEnd'] : null;
        $chapterEnd   = isset( $ref['chapterEnd'] ) && null !== $ref['chapterEnd'] ? (int) $ref['chapterEnd'] : null;

        if ( null === $verseStart ) {
            // Whole-chapter or chapter-range reference (no specific verse).
            $suffix = (string) $chapterStart;
            if ( null !== $chapterEnd ) {
                $suffix .= '-' . $chapterEnd;
            }

            return $name . ' ' . $suffix;
        }

        if ( null !== $chapterEnd ) {
            // Cross-chapter range: chapterStart:verseStart-chapterEnd:verseEnd.
            $endVerse = null !== $verseEnd ? $verseEnd : $verseStart;

            return sprintf( '%s %d:%d-%d:%d', $name, $chapterStart, $verseStart, $chapterEnd, $endVerse );
        }

        if ( null !== $verseEnd ) {
            return sprintf( '%s %d:%d-%d', $name, $chapterStart, $verseStart, $verseEnd );
        }

        return sprintf( '%s %d:%d', $name, $chapterStart, $verseStart );
    }

    /**
     * USFM code -> canonical display name (e.g. JHN -> "John"), or '' if unknown.
     */
    private static function bookName( string $usfm ): string {
        $byCode = array_flip( BibleBookMap::usfm() );

        return $byCode[ $usfm ] ?? '';
    }

    /**
     * Build the external Bible Gateway lookup URL for a label + link version.
     */
    private static function linkUrl( string $label, string $version ): string {
        return self::BIBLEGATEWAY_BASE
            . rawurlencode( $label )
            . '&version='
            . rawurlencode( $version );
    }
}
