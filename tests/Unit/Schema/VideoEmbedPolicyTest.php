<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Schema\VideoEmbedPolicy;

final class VideoEmbedPolicyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'wp_kses_allowed_html' )->justReturn( array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_allows_iframe_with_safe_embed_attributes(): void {
        $allowed = VideoEmbedPolicy::allowed();

        $this->assertArrayHasKey( 'iframe', $allowed );
        $this->assertArrayHasKey( 'video', $allowed );
        $this->assertArrayHasKey( 'source', $allowed );

        $iframe = $allowed['iframe'];
        $this->assertTrue( $iframe['src'] );
        $this->assertTrue( $iframe['allowfullscreen'] );
        $this->assertTrue( $iframe['sandbox'] );

        // The clickjacking vector: style must NEVER be allowed.
        $this->assertArrayNotHasKey( 'style', $iframe );
    }

    public function test_includes_post_allowlist_as_base(): void {
        Functions\when( 'wp_kses_allowed_html' )->justReturn( array( 'a' => array( 'href' => true ) ) );
        $allowed = VideoEmbedPolicy::allowed();

        $this->assertArrayHasKey( 'a', $allowed );
        $this->assertArrayHasKey( 'iframe', $allowed );
    }
}
