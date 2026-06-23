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

    public function test_post_content_temp_is_never_routed_to_backup(): void {
        // post_content_temp has its OWN single canonical home (the writer copies it
        // verbatim as its own meta row). The reconciler must NOT also route it to
        // the backup body — that would be a second home + a double-flag. With no
        // OTHER substantive source, the backup is therefore empty.
        $out = PostContentReconciler::reconcile( '', null, 'Only in temp backup' );
        $this->assertSame( '', $out['content'] );
        $this->assertNull( $out['backup'], 'post_content_temp must not be backed up (single canonical home)' );
        $this->assertFalse( $out['flag'] );
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

    public function test_old_content_already_in_temp_row_not_double_backed_up(): void {
        // The old post_content's substantive text already lives in the temp row
        // (its own canonical home), so it must NOT be backed up again — only text
        // absent from BOTH description and the temp row is preserved.
        $out = PostContentReconciler::reconcile( 'Shared body', 'desc', 'Shared body' );
        $this->assertNull( $out['backup'], 'text already in the temp row is not re-backed-up' );
        $this->assertFalse( $out['flag'] );
    }

    public function test_only_old_content_unique_to_both_is_backed_up(): void {
        // Old post_content carries text absent from BOTH description and temp →
        // it is the only thing backed up; the temp content stays in its own home.
        $out = PostContentReconciler::reconcile( 'Alpha unique', 'desc', 'Beta unique' );
        $this->assertStringContainsString( 'Alpha unique', (string) $out['backup'] );
        $this->assertStringNotContainsString( 'Beta unique', (string) $out['backup'], 'temp content is never in the backup' );
        $this->assertTrue( $out['flag'] );
    }
}
