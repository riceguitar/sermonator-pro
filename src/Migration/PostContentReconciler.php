<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure reconciliation of the sermon body, capturing BOTH places Sermon Manager
 * stored it. SM kept the body in the `sermon_description` meta AND/OR the native
 * `post_content` (the `post_content_enabled` option — default on): a sermon's text
 * could live in either or both. The migration therefore must render either case.
 *
 * The canonical visible body going forward is the old sermon_description, but any
 * unique-substantive legacy post_content (real text or a media/shortcode payload
 * NOT already represented in the description) is MERGED INTO the visible body — not
 * hidden — so a sermon whose text lived in the post_content (with an empty/short
 * description) still renders. When the description is empty, the legacy post_content
 * BECOMES the body. A degraded auto-generated post_content that merely repeats the
 * description is recognised as already-represented and dropped (no duplicate).
 *
 * The verbatim merged legacy post_content is ALSO kept as a flagged backup (audit
 * trail / reversibility): `backup` is the merged piece, `flag` marks that a merge
 * happened. Both 'content' and 'backup' carry it; nothing visible is ever lost.
 *
 * post_content_temp has its OWN single canonical home: the writer copies it verbatim
 * as its own meta row. It is NEVER merged or backed up here (that would be a second
 * home + a double-flag). It is fed in only as an additional "already-represented"
 * corpus, so an $oldPostContent fragment already captured by the temp row is not
 * redundantly merged/backed-up.
 */
final class PostContentReconciler {
    /**
     * @return array{content: string, backup: ?string, flag: bool}
     */
    public static function reconcile( string $oldPostContent, ?string $description, ?string $postContentTemp = null ): array {
        $content = (string) $description;

        // The corpus already preserved elsewhere: the canonical description body
        // PLUS the post_content_temp row (its own canonical home). $oldPostContent
        // is unique only if substantive relative to BOTH.
        $representedCorpus = (string) $description;
        if ( $postContentTemp !== null && trim( $postContentTemp ) !== '' ) {
            $representedCorpus .= "\n\n" . $postContentTemp;
        }

        // Only $oldPostContent can be merged/backed-up. post_content_temp is never
        // touched here (single canonical home = its own meta row → no double-flag).
        $pieces = array();
        if ( self::isUniqueSubstantive( $oldPostContent, $representedCorpus ) ) {
            // A structural payload (iframe/audio/video/img/embed/object/shortcode)
            // carries irreplaceable data that strip_tags() empties — so it must be
            // kept REGARDLESS of visible text. Only the purely-textual branch keeps
            // the visibleText !== '' suppression (a blank-after-strip text blob has
            // nothing to preserve).
            if ( self::hasStructuralPayload( $oldPostContent, $representedCorpus )
                || self::visibleText( $oldPostContent ) !== '' ) {
                $pieces[] = $oldPostContent;
            }
        }

        $unique = count( $pieces ) > 0 ? implode( "\n\n", $pieces ) : null;

        // MERGE the unique legacy post_content INTO the visible body (capture both
        // sources). When the description is empty/whitespace the post_content BECOMES
        // the body; otherwise it is appended after it. Nothing visible is hidden.
        if ( null !== $unique ) {
            $content = '' === trim( $content ) ? $unique : $content . "\n\n" . $unique;
        }

        // Keep the merged piece as the flagged backup too (verbatim audit trail).
        return array( 'content' => $content, 'backup' => $unique, 'flag' => null !== $unique );
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
