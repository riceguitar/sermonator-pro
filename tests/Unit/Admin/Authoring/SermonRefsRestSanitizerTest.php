<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Admin\Authoring {
    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use Sermonator\Admin\Authoring\SermonRefsRestSanitizer;
    use Sermonator\Bible\DerivedExactClassifier;
    use Sermonator\Bible\RefsCapture;

    /**
     * Unit coverage for {@see SermonRefsRestSanitizer::stamp()}: the server-side envelope
     * authority for the confirm-chip REST write. The client supplies only WHICH structural
     * refs were confirmed; the trusted provenance (source/confidence/srcVersification*) is
     * re-derived here and any client-supplied value for it is discarded.
     *
     * The migration-gate ("mid-migration write rejected", which short-circuits before
     * stamp() in sanitize()) is exercised in the integration test, where a real
     * WP_REST_Request exists.
     */
    final class SermonRefsRestSanitizerTest extends TestCase {
        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
            Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => $value );
            Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
                return 'sermonator_bible_link_version' === $name ? 'ESV' : $default;
            } );
        }

        protected function tearDown(): void {
            Monkey\tearDown();
            parent::tearDown();
        }

        /** @return array<string,mixed> */
        private function decode( string $json ): array {
            return '' === $json ? array() : (array) json_decode( $json, true );
        }

        public function test_client_confidence_and_source_overwritten_server_side(): void {
            // Client tries to forge provenance + smuggle an extra key. All discarded.
            $submitted = array(
                'v'    => 1,
                'refs' => array(
                    array(
                        'bookUSFM'                   => 'JHN',
                        'chapterStart'               => 3,
                        'verseStart'                 => 16,
                        'verseEnd'                   => 16,
                        'chapterEnd'                 => null,
                        'raw'                        => 'John 3:16',
                        'source'                     => 'evil-import',
                        'confidence'                 => 'probable',
                        'srcVersification'           => 'KJV',
                        'srcVersificationConfidence' => 'authored',
                        'injected'                   => 'do-not-trust',
                    ),
                ),
            );

            $env = $this->decode( ( new SermonRefsRestSanitizer() )->stamp( $submitted ) );
            $ref = $env['refs'][0];

            $this->assertSame( 1, $env['v'] );
            $this->assertSame( 'authoring', $ref['source'], 'source forced server-side' );
            $this->assertSame( 'exact', $ref['confidence'], 'confidence forced to exact server-side' );
            $this->assertSame( 'ESV', $ref['srcVersification'], 'srcVersification stamped from the live link version' );
            $this->assertSame(
                RefsCapture::SRC_VERSIFICATION_CONFIDENCE_AUTHORED,
                $ref['srcVersificationConfidence']
            );
            $this->assertArrayNotHasKey( 'injected', $ref, 'client-injected keys are stripped' );
            // Structural fields preserved.
            $this->assertSame( 'JHN', $ref['bookUSFM'] );
            $this->assertSame( 3, $ref['chapterStart'] );
            $this->assertSame( 16, $ref['verseStart'] );
        }

        public function test_client_supplied_derived_exact_is_rejected_server_stamp_wins(): void {
            // De-store enforcement (design §3.4): a client cannot pre-stamp the de-stored
            // render-time tier `derived-exact*` to clear the inline floor past the
            // classifier. The confirm-chip path discards client confidence entirely and the
            // SERVER stamp (`exact`) wins — the persisted value is NEVER `derived-exact*`.
            foreach (
                array(
                    DerivedExactClassifier::FLOOR_DERIVED_EXACT,
                    DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
                ) as $forged
            ) {
                $submitted = array(
                    'refs' => array(
                        array(
                            'bookUSFM'     => 'JHN',
                            'chapterStart' => 3,
                            'verseStart'   => 16,
                            'verseEnd'     => 16,
                            'chapterEnd'   => null,
                            'raw'          => 'John 3:16',
                            'confidence'   => $forged,
                        ),
                    ),
                );

                $ref = $this->decode( ( new SermonRefsRestSanitizer() )->stamp( $submitted ) )['refs'][0];

                $this->assertSame( 'exact', $ref['confidence'], 'Server stamp wins over the forged tier.' );
                $this->assertNotSame( $forged, $ref['confidence'], 'The de-stored floor tier never persists.' );
            }
        }

        public function test_invalid_ref_is_dropped(): void {
            $submitted = array(
                'refs' => array(
                    array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => 16, 'chapterEnd' => null, 'raw' => 'John 3:16' ),
                    // Out-of-canon book — RefValidator rejects it.
                    array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Bogus 1:1' ),
                    // Structurally invalid — chapter 0.
                    array( 'bookUSFM' => 'ROM', 'chapterStart' => 0, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Romans 0:1' ),
                ),
            );

            $env = $this->decode( ( new SermonRefsRestSanitizer() )->stamp( $submitted ) );

            $this->assertCount( 1, $env['refs'], 'Only the in-canon, structurally-valid ref survives.' );
            $this->assertSame( 'JHN', $env['refs'][0]['bookUSFM'] );
        }

        public function test_ref_count_is_capped(): void {
            $refs = array();
            for ( $i = 1; $i <= 60; $i++ ) {
                $refs[] = array(
                    'bookUSFM'     => 'PSA',
                    'chapterStart' => $i,
                    'verseStart'   => 1,
                    'verseEnd'     => 1,
                    'chapterEnd'   => null,
                    'raw'          => 'Psalm ' . $i . ':1',
                );
            }

            $env = $this->decode( ( new SermonRefsRestSanitizer() )->stamp( array( 'refs' => $refs ) ) );

            $this->assertCount( SermonRefsRestSanitizer::MAX_REFS, $env['refs'] );
        }

        public function test_decodes_json_string_envelope(): void {
            // The editor submits JSON.stringify(envelope); the sanitizer re-decodes it.
            $json = json_encode( array(
                'refs' => array(
                    array( 'bookUSFM' => 'ROM', 'chapterStart' => 8, 'verseStart' => 28, 'verseEnd' => 28, 'chapterEnd' => null, 'raw' => 'Romans 8:28' ),
                ),
            ) );

            $env = $this->decode( ( new SermonRefsRestSanitizer() )->stamp( $json ) );

            $this->assertCount( 1, $env['refs'] );
            $this->assertSame( 'ROM', $env['refs'][0]['bookUSFM'] );
            $this->assertSame( 'exact', $env['refs'][0]['confidence'] );
        }

        public function test_returns_empty_string_when_no_valid_refs(): void {
            $submitted = array(
                'refs' => array(
                    array( 'bookUSFM' => 'ZZZ', 'chapterStart' => 1, 'verseStart' => 1, 'verseEnd' => 1, 'chapterEnd' => null, 'raw' => 'Bogus 1:1' ),
                ),
            );

            $this->assertSame( '', ( new SermonRefsRestSanitizer() )->stamp( $submitted ) );
        }

        public function test_garbage_submission_yields_empty_string(): void {
            $this->assertSame( '', ( new SermonRefsRestSanitizer() )->stamp( 'not json' ) );
            $this->assertSame( '', ( new SermonRefsRestSanitizer() )->stamp( null ) );
            $this->assertSame( '', ( new SermonRefsRestSanitizer() )->stamp( array() ) );
        }
    }
}
