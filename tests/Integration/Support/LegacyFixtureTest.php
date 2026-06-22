<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Support;

use WP_UnitTestCase;
use Sermonator\Migration\LegacyIdentifiers;

final class LegacyFixtureTest extends WP_UnitTestCase {
    public function test_creates_a_legacy_sermon_with_meta(): void {
        $fx = new LegacyFixture();
        $fx->registerLegacySchema();
        $id = $fx->createSermon( array( 'bible_passage' => array( 'John 3:16' ) ) );

        $this->assertSame( LegacyIdentifiers::POST_TYPE_SERMON, get_post_type( $id ) );
        $this->assertSame( 'John 3:16', get_post_meta( $id, 'bible_passage', true ) );
        $this->assertNotEmpty( get_post_meta( $id, 'sermon_date', true ) );
    }

    public function test_creates_terms_and_podcasts(): void {
        $fx = new LegacyFixture();
        $fx->registerLegacySchema();
        $term = $fx->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $this->assertIsInt( $term );
        $podcast = $fx->createPodcast( 'Sunday Service' );
        $this->assertSame( LegacyIdentifiers::POST_TYPE_PODCAST, get_post_type( $podcast ) );
        $this->assertNotEmpty( get_post_meta( $podcast, 'sm_podcast_settings', true ) );
    }
}
