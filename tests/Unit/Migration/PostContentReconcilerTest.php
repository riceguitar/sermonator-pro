<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\PostContentReconciler;

final class PostContentReconcilerTest extends TestCase {
    public function test_uses_description_as_content(): void {
        $out = PostContentReconciler::reconcile( 'auto-generated blob', '<p>Real body</p>' );
        $this->assertSame( '<p>Real body</p>', $out['content'] );
    }

    public function test_discards_blob_when_its_text_is_within_description(): void {
        // Old post_content is SM's degraded text version of the same body.
        $out = PostContentReconciler::reconcile( 'Real body', '<p>Real body</p>' );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_empty_description_with_empty_blob(): void {
        $out = PostContentReconciler::reconcile( '   ', null );
        $this->assertSame( '', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_preserves_and_flags_unique_blob_content(): void {
        // Rare case: real content lived only in the editor (post_content), not in the description.
        $out = PostContentReconciler::reconcile( 'Unique content only here', null );
        $this->assertSame( '', $out['content'] ); // description is the canonical body going forward...
        $this->assertSame( 'Unique content only here', $out['backup'] ); // ...but unique text is never dropped
        $this->assertTrue( $out['flag'] );
    }

    public function test_whitespace_only_blob_is_not_backed_up(): void {
        $out = PostContentReconciler::reconcile( "\n\t  ", 'desc' );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_body_only_in_post_content_temp_is_preserved(): void {
        $out = PostContentReconciler::reconcile( '', null, 'Only in temp backup' );
        $this->assertSame( '', $out['content'] );
        $this->assertStringContainsString( 'Only in temp backup', (string) $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }

    public function test_temp_text_within_description_not_backed_up(): void {
        $out = PostContentReconciler::reconcile( '', '<p>Full body here</p>', 'Full body here' );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_shortcode_blob_not_discarded_even_if_text_contained(): void {
        $out = PostContentReconciler::reconcile( '[audio src="x.mp3"]Intro', '<p>Intro</p>', null );
        $this->assertNotNull( $out['backup'] );   // shortcode carries data the plain text doesn't
        $this->assertTrue( $out['flag'] );
    }

    public function test_old_and_temp_both_unique_single_flag_distinct_backup(): void {
        $out = PostContentReconciler::reconcile( 'Alpha unique', 'desc', 'Beta unique' );
        $this->assertStringContainsString( 'Alpha unique', (string) $out['backup'] );
        $this->assertStringContainsString( 'Beta unique', (string) $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }
}
