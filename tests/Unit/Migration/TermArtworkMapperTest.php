<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\TermArtworkMapper;

final class TermArtworkMapperTest extends TestCase {
    public function test_remaps_tt_ids_attachment_verbatim(): void {
        $out = TermArtworkMapper::remapImages([12 => 500, 13 => 501], [12 => 900, 13 => 901]);
        $this->assertSame([900 => 500, 901 => 501], $out['images']);
        $this->assertSame([], $out['dropped']);
    }

    public function test_numeric_string_keys_resolve(): void {
        $out = TermArtworkMapper::remapImages(['12' => 500], [12 => 900]);
        $this->assertSame([900 => 500], $out['images']);
    }

    public function test_missing_crosswalk_dropped(): void {
        $out = TermArtworkMapper::remapImages([99 => 500], [12 => 900]);
        $this->assertSame([], $out['images']);
        $this->assertSame([99], $out['dropped']);
    }

    public function test_collision_recorded_not_overwritten(): void {
        $out = TermArtworkMapper::remapImages([12 => 500, 13 => 600], [12 => 900, 13 => 900]);
        $this->assertContains(900, $out['conflicts']);
    }

    public function test_collision_records_discarded_attachment_for_recovery(): void {
        // IMPORTANT #8: the losing attachment_id (600) on a tt_id collision was
        // previously discarded unrecoverably — only the winning new tt_id was kept.
        // remapImages must now surface the FULL conflict detail (losing legacy tt_id
        // + discarded attachment_id + winning attachment_id) so an admin can recover.
        $out = TermArtworkMapper::remapImages(
            [12 => 500, 13 => 600],
            [12 => 900, 13 => 900]
        );

        $this->assertArrayHasKey('conflict_details', $out);
        $this->assertSame(
            [
                [
                    'new_tt_id'               => 900,
                    'legacy_tt_id'            => 13,
                    'discarded_attachment_id' => 600,
                    'winning_attachment_id'   => 500,
                ],
            ],
            $out['conflict_details'],
            'the discarded attachment_id (and losing legacy tt_id) must be recoverable from the conflict detail'
        );
    }

    public function test_settings_remap_taxonomy_keys_passthrough_globals(): void {
        $out = TermArtworkMapper::remapSettings(['wpfc_sermon_series' => 1, 'image_size' => 'medium']);
        $this->assertArrayHasKey('sermonator_series', $out);
        $this->assertSame(1, $out['sermonator_series']);
        $this->assertSame('medium', $out['image_size']);   // global passes through
    }
}
