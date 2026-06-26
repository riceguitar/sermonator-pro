<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Frontend\Blocks\SermonMetaBlock;
use Sermonator\Frontend\Blocks\AudioPlayerBlock;
use Sermonator\Frontend\Blocks\VideoBlock;
use Sermonator\Frontend\Blocks\FeaturedImageBlock;
use Sermonator\Frontend\Blocks\BulletinBlock;
use Sermonator\Frontend\Blocks\NotesBlock;
use Sermonator\Frontend\Blocks\SermonCardBlock;
use Sermonator\Frontend\Blocks\SermonGridBlock;
use Sermonator\Frontend\Blocks\TaxonomyFilterBlock;
use Sermonator\Frontend\Blocks\PodcastSubscribeBlock;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\Feed\LegacyFeedRouter;
use Sermonator\Frontend\Compat\LegacyShortcodes;
use Sermonator\Frontend\Seo\SeoHead;

/**
 * Wires the read-only front-end display layer: dynamic blocks, block templates (FSE), the
 * classic-theme template fallbacks, the [sermonator_sermons] shortcode, archive ordering,
 * and conditional assets. Instantiated by Plugin::boot in all contexts (the editor needs
 * block/template registration); front-end-only hooks self-scope.
 */
final class FrontendServiceProvider {
    private Assets $assets;

    public function __construct() {
        $this->assets = new Assets();
    }

    public function hook(): void {
        add_action( 'init', array( $this, 'onInit' ) );
        ( new ClassicTemplates() )->hook();
        ( new ArchiveOrdering() )->hook();
        ( new Shortcode() )->hook();
        ( new LegacyShortcodes() )->hook();
        ( new PodcastFeed() )->hook();
        ( new LegacyFeedRouter() )->hook();
        ( new SeoHead() )->hook();
        $this->assets->hook();
    }

    public function onInit(): void {
        // Register asset handles first so block.json style/viewScript references resolve.
        $this->assets->register();

        ( new SermonMetaBlock() )->register();
        ( new AudioPlayerBlock() )->register();
        ( new VideoBlock() )->register();
        ( new FeaturedImageBlock() )->register();
        ( new BulletinBlock() )->register();
        ( new NotesBlock() )->register();
        ( new SermonCardBlock() )->register();
        ( new SermonGridBlock() )->register();
        ( new TaxonomyFilterBlock() )->register();
        ( new PodcastSubscribeBlock() )->register();

        ( new BlockTemplates() )->register();
    }
}
