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
    public static function reconcile( string $oldPostContent, ?string $description ): array {
        $content = $description ?? '';

        $blob = trim( $oldPostContent );
        if ( '' === $blob ) {
            return array( 'content' => $content, 'backup' => null, 'flag' => false );
        }

        // If every non-trivial word of the blob already appears in the description's
        // text, the blob is the degraded derivative — discard it.
        if ( self::isContainedIn( $blob, (string) $description ) ) {
            return array( 'content' => $content, 'backup' => null, 'flag' => false );
        }

        // Unique substantive text — preserve verbatim and flag for human review.
        return array( 'content' => $content, 'backup' => $oldPostContent, 'flag' => true );
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
