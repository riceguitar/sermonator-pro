<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure reconciliation of the body. The canonical body going forward is the old
 * sermon_description (→ post_content). The old auto-generated post_content blob
 * is discarded, EXCEPT when it holds substantive text not represented in the
 * description — that text is preserved as a backup and flagged, never dropped.
 *
 * post_content_temp has its OWN single canonical home: the writer copies it
 * verbatim as its own meta row. It is therefore NEVER routed to the backup body
 * here (that would be a second home + a double-flag). It is fed in only as an
 * additional "already-represented" corpus, so that an $oldPostContent fragment
 * whose substantive text is already captured by the temp row is recognised as
 * preserved and is not redundantly backed up either.
 */
final class PostContentReconciler {
    /**
     * @return array{content: string, backup: ?string, flag: bool}
     */
    public static function reconcile( string $oldPostContent, ?string $description, ?string $postContentTemp = null ): array {
        $content = $description ?? '';

        // The corpus already preserved elsewhere: the canonical description body
        // PLUS the post_content_temp row (its own canonical home). $oldPostContent
        // is backed up only if it is substantive relative to BOTH.
        $representedCorpus = (string) $description;
        if ( $postContentTemp !== null && trim( $postContentTemp ) !== '' ) {
            $representedCorpus .= "\n\n" . $postContentTemp;
        }

        // Only $oldPostContent can land in the backup. post_content_temp is never
        // backed up (single canonical home = its own meta row → no double-flag).
        $pieces = array();
        if ( self::isUniqueSubstantive( $oldPostContent, $representedCorpus ) ) {
            $visibleText = self::visibleText( $oldPostContent );
            if ( $visibleText !== '' ) {
                $pieces[] = $oldPostContent;
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
