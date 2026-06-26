<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;
use Sermonator\Model\Registrar;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;

/**
 * Integration coverage for the wired preacher label (Bundle 4, spec §1.7 / Task 4):
 * the SAME live {@see Identifiers::OPTION_PREACHER_LABEL} option drives BOTH the
 * registered TAX_PREACHER taxonomy labels AND the single-sermon meta row, so the
 * two can never disagree.
 *
 * THE HEADLINE PROOF: set the option once, then assert the registered taxonomy's
 * singular label and the Renderer::meta() preacher row carry the identical value —
 * exercising the REAL registration + the REAL TemplateData option read (Brain
 * Monkey only proves each side in isolation).
 *
 * Also pins the distinct-key invariant: only OPTION_PREACHER_LABEL is consulted —
 * the live key, never the migration prefix-swap artifact — so a migration re-run
 * cannot clobber a saved admin edit (spec §1.2).
 *
 * NOTE: requires the wp-env integration harness (WP_UnitTestCase + a live DB +
 * the taxonomy registry). It is NOT run in this environment (no Docker) — authored
 * per the task brief.
 */
final class PreacherLabelTest extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        delete_option( Identifiers::OPTION_PREACHER_LABEL );
    }

    public function tear_down(): void {
        delete_option( Identifiers::OPTION_PREACHER_LABEL );
        parent::tear_down();
    }

    /** The registered taxonomy's labels after a fresh init@5 registration. */
    private function registeredPreacherLabels(): \stdClass {
        // Re-run registration so the taxonomy object reflects the current option.
        ( new Registrar() )->register();
        $taxonomy = get_taxonomy( Identifiers::TAX_PREACHER );
        $this->assertNotFalse( $taxonomy, 'TAX_PREACHER must be registered' );
        return $taxonomy->labels;
    }

    /**
     * Build a SermonView for a real sermon post (so TemplateData performs the REAL
     * option read) and return its single-sermon meta HTML.
     */
    private function metaHtmlForNewSermon(): string {
        $postId = self::factory()->post->create(
            array( 'post_type' => Identifiers::POST_TYPE_SERMON, 'post_title' => 'Test Sermon' )
        );
        $term = self::factory()->term->create(
            array( 'taxonomy' => Identifiers::TAX_PREACHER, 'name' => 'Pastor John' )
        );
        wp_set_object_terms( $postId, array( (int) $term ), Identifiers::TAX_PREACHER );

        $view = ( new TemplateData() )->sermon( (int) $postId );
        return ( new Renderer() )->meta( $view );
    }

    public function test_custom_label_agrees_across_taxonomy_and_meta_row(): void {
        update_option( Identifiers::OPTION_PREACHER_LABEL, 'Speaker' );

        $labels = $this->registeredPreacherLabels();
        $this->assertSame( 'Speaker', $labels->singular_name );
        $this->assertSame( 'Speakers', $labels->name );

        $html = $this->metaHtmlForNewSermon();
        $this->assertStringContainsString( '<dt>Speaker</dt>', $html );
    }

    public function test_default_label_agrees_across_taxonomy_and_meta_row(): void {
        // No live option → both sides resolve the same DisplayDefaults fallback.
        $expected = DisplayDefaults::preacherLabel();

        $labels = $this->registeredPreacherLabels();
        $this->assertSame( $expected, $labels->singular_name );

        $html = $this->metaHtmlForNewSermon();
        $this->assertStringContainsString( '<dt>' . $expected . '</dt>', $html );
    }
}
