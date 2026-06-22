<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure reconciliation of the body. The canonical body going forward is the old
 * sermon_description (→ post_content). The old auto-generated post_content blob
 * is discarded, EXCEPT when it holds substantive text not represented in the
 * description — that text is preserved as a backup and flagged, never dropped.
 */
final class PostContentReconciler {
    /**
     * @return array{content: string, backup: ?string, flag: bool}
     */
    public static function reconcile( string $oldPostContent, ?string $description, ?string $postContentTemp = null ): array {
        $content = $description ?? '';

        // Gather unique substantive pieces from both $oldPostContent and $postContentTemp.
        $pieces = array();
        $seenVisibleTexts = array();

        // Process $oldPostContent if present and unique.
        if ( self::isUniqueSubstantive( $oldPostContent, (string) $description ) ) {
            $visibleText = self::visibleText( $oldPostContent );
            if ( $visibleText !== '' && ! isset( $seenVisibleTexts[ $visibleText ] ) ) {
                $pieces[] = $oldPostContent;
                $seenVisibleTexts[ $visibleText ] = true;
            }
        }

        // Process $postContentTemp if present and unique.
        if ( $postContentTemp !== null && self::isUniqueSubstantive( $postContentTemp, (string) $description ) ) {
            $visibleText = self::visibleText( $postContentTemp );
            if ( $visibleText !== '' && ! isset( $seenVisibleTexts[ $visibleText ] ) ) {
                $pieces[] = $postContentTemp;
                $seenVisibleTexts[ $visibleText ] = true;
            }
        }

        // Build backup and flag.
        $backup = count( $pieces ) > 0 ? implode( "\n\n", $pieces ) : null;
        $flag = count( $pieces ) > 0;

        return array( 'content' => $content, 'backup' => $backup, 'flag' => $flag );
    }

    /**
     * True if the blob is unique and substantive relative to the description.
     * A blob is substantive if it contains a shortcode/media-structural payload
     * (which cannot be represented as plain text), or if its visible text is not
     * entirely contained within the description's visible text.
     */
    private static function isUniqueSubstantive( string $blob, string $description ): bool {
        $blob = trim( $blob );
        if ( '' === $blob ) {
            return false;
        }
        if ( self::hasStructuralPayload( $blob, $description ) ) {
            return true;
        }
        return ! str_contains( self::visibleText( $description ), self::visibleText( $blob ) );
    }

    /**
     * True if the blob contains a shortcode token or media/structural HTML tags
     * that represent data not present in plain description text.
     */
    private static function hasStructuralPayload( string $blob, string $description ): bool {
        $hasShortcode = (bool) preg_match( '/\[[a-z][a-z0-9_\-]*[\s\]]/i', $blob );
        $hasMediaHtml = (bool) preg_match( '/<(iframe|audio|video|img|script|embed|object)\b/i', $blob );
        return $hasShortcode || $hasMediaHtml;
    }

    /** True if the blob's visible text is a substring of the description's visible text. */
    private static function isContainedIn( string $blob, string $description ): bool {
        $needle   = self::visibleText( $blob );
        $haystack = self::visibleText( $description );
        if ( '' === $needle ) {
            return true;
        }
        return str_contains( $haystack, $needle );
    }

    /** Strip tags and collapse whitespace for a text-only comparison. */
    private static function visibleText( string $html ): string {
        $text = self::stripTags( $html );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( (string) $text );
    }

    /** WordPress-free tag stripping, so this class stays pure and unit-testable. */
    private static function stripTags( string $html ): string {
        $html = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
        return trim( strip_tags( (string) $html ) );
    }

    private function __construct() {}
}
