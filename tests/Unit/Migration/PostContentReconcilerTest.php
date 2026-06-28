<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\PostContentReconciler;

/**
 * The reconciler must CAPTURE BOTH places Sermon Manager stored the sermon body:
 * the `sermon_description` meta AND the native `post_content`. Whichever holds the
 * text must end up in the visible body — never silently dropped to a hidden backup.
 */
final class PostContentReconcilerTest extends TestCase {
    // --- the body source matrix --------------------------------------------

    public function test_description_only_when_post_content_is_a_redundant_copy(): void {
        // SM's auto-generated post_content repeats the description → not unique → dropped.
        $out = PostContentReconciler::reconcile( 'Real body', '<p>Real body</p>' );
        $this->assertSame( '<p>Real body</p>', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_post_content_BECOMES_body_when_description_is_empty(): void {
        // THE HEADLINE CASE (real corpus: ~5% of sermons): the body was authored in the
        // editor (post_content), the sermon_description meta is empty. The post_content
        // must become the visible body — not vanish into a hidden backup.
        $body = 'As Jesus traveled through the villages of Galilee, large crowds gathered.';
        $out  = PostContentReconciler::reconcile( $body, '' );
        $this->assertSame( $body, $out['content'], 'post_content body must render when the description is empty' );
        $this->assertSame( $body, $out['backup'], 'and is also kept verbatim as the audit-trail backup' );
        $this->assertTrue( $out['flag'] );
    }

    public function test_post_content_becomes_body_when_description_is_null(): void {
        $out = PostContentReconciler::reconcile( 'Unique content only here', null );
        $this->assertSame( 'Unique content only here', $out['content'] );
        $this->assertSame( 'Unique content only here', $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }

    public function test_merges_BOTH_when_description_and_post_content_differ(): void {
        // Both sources carry distinct substantive text → both must be visible,
        // description FIRST then the post_content (the order SM showed them).
        $out = PostContentReconciler::reconcile( 'Editor-only paragraph', '<p>Meta description</p>' );
        $this->assertStringContainsString( 'Meta description', $out['content'] );
        $this->assertStringContainsString( 'Editor-only paragraph', $out['content'] );
        $this->assertLessThan(
            strpos( $out['content'], 'Editor-only paragraph' ),
            strpos( $out['content'], 'Meta description' ),
            'description must precede the merged post_content'
        );
        $this->assertSame( 'Editor-only paragraph', $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }

    public function test_partial_overlap_keeps_both_even_if_it_duplicates(): void {
        // Known, accepted behavior: when the post_content shares text with the
        // description but is not fully contained, the whole post_content is merged
        // (duplicating the shared part) — over-showing beats silently dropping the
        // unique remainder. Rare on real SM-Pro data (the hijack keeps them equal).
        $out = PostContentReconciler::reconcile( 'Shared text plus extra', '<p>Shared text</p>' );
        $this->assertStringContainsString( 'Shared text', $out['content'] );
        $this->assertStringContainsString( 'plus extra', $out['content'] );
        $this->assertTrue( $out['flag'] );
    }

    // --- nothing-to-merge cases --------------------------------------------

    public function test_empty_description_with_empty_blob(): void {
        $out = PostContentReconciler::reconcile( '   ', null );
        $this->assertSame( '', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_whitespace_only_blob_is_not_merged(): void {
        $out = PostContentReconciler::reconcile( "\n\t  ", 'desc' );
        $this->assertSame( 'desc', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    // --- media / shortcode structural payloads -----------------------------

    public function test_iframe_only_blob_with_empty_description_becomes_body(): void {
        $iframe = '<iframe src="https://player.example/embed/1"></iframe>';
        $out    = PostContentReconciler::reconcile( $iframe, null );
        $this->assertSame( $iframe, $out['content'], 'a media-only post_content body must render' );
        $this->assertSame( $iframe, $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }

    public function test_audio_video_embed_only_blobs_become_body(): void {
        foreach ( array(
            '<audio src="https://media.example/s.mp3"></audio>',
            '<video><source src="https://media.example/s.mp4"></video>',
            '<embed src="https://media.example/s.swf">',
        ) as $media ) {
            $out = PostContentReconciler::reconcile( $media, '' );
            $this->assertSame( $media, $out['content'], "media-only blob must render: $media" );
            $this->assertTrue( $out['flag'] );
        }
    }

    public function test_shortcode_blob_is_merged_even_if_text_contained(): void {
        // The shortcode carries data the plain description text does not.
        $out = PostContentReconciler::reconcile( '[audio src="x.mp3"]Intro', '<p>Intro</p>', null );
        $this->assertStringContainsString( '[audio src="x.mp3"]', $out['content'] );
        $this->assertNotNull( $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }

    // --- post_content_temp owns its own canonical home (never merged here) --

    public function test_post_content_temp_is_never_merged_or_backed_up(): void {
        // temp has its OWN meta row (the writer copies it verbatim); the reconciler
        // must not also pull it into the body or the backup.
        $out = PostContentReconciler::reconcile( '', null, 'Only in temp backup' );
        $this->assertSame( '', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_post_content_already_in_temp_row_not_re_merged(): void {
        // The old post_content's text already lives in the temp row → not unique → dropped.
        $out = PostContentReconciler::reconcile( 'Shared body', 'desc', 'Shared body' );
        $this->assertSame( 'desc', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_post_content_text_within_description_not_merged(): void {
        $out = PostContentReconciler::reconcile( 'Full body here', '<p>Full body here</p>', null );
        $this->assertSame( '<p>Full body here</p>', $out['content'] );
        $this->assertNull( $out['backup'] );
        $this->assertFalse( $out['flag'] );
    }

    public function test_only_post_content_unique_to_both_is_merged(): void {
        // post_content carries text absent from BOTH description and temp → it is merged;
        // the temp content stays only in its own home (never here).
        $out = PostContentReconciler::reconcile( 'Alpha unique', 'desc', 'Beta unique' );
        $this->assertStringContainsString( 'Alpha unique', $out['content'] );
        $this->assertStringNotContainsString( 'Beta unique', $out['content'], 'temp content is never pulled in here' );
        $this->assertSame( 'Alpha unique', $out['backup'] );
        $this->assertTrue( $out['flag'] );
    }
}
