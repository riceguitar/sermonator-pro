<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Bible;

/**
 * Hardened, FAIL-OPEN fetch of one helloao chapter object.
 *
 * Reuses the {@see \Sermonator\Frontend\Feed\AudioHeadProbe} safety idiom verbatim
 * (short timeout, bounded redirects, `reject_unsafe_urls`, scheme validation,
 * `error_log` on failure) so the only external-network read on the Bible path
 * carries the same SSRF/timeout guardrails as the audio probe.
 *
 * SPINE — never-fail-WRONG: this method returns the RAW helloao chapter array on a
 * clean 200-with-expected-shape response, or `null` on ANY error (transport error,
 * non-200 status, empty/undecodable body, unexpected JSON shape, or an unexpected
 * throwable). It NEVER throws. Every null path is a fall-open to the 3a link — a
 * silent network hiccup must never become a rendered wrong verse, and a render
 * context never sees this class at all (it is a WARM/VENDOR-context fetch; the
 * render path reads disk/cache only — design §3.6).
 *
 * Normalization is NOT this class's job: it returns the raw helloao `chapter`
 * object ({number, content[], footnotes[]}) exactly as the API delivered it, for
 * {@see ChapterNormalizer::normalize()} to fold into the flat render shape.
 */
final class ChapterFetcher {
    /**
     * helloao Free Use Bible API base. A chapter lives at
     * `{API_BASE}{translation}/{book}/{chapter}.json` (book is the USFM-ish code
     * helloao keys its data on, e.g. GEN/JHN). HTTPS only.
     */
    private const API_BASE = 'https://bible.helloao.org/api/';

    /**
     * Fetch one chapter from helloao, returning the raw chapter array or null.
     *
     * @param string $translation helloao translation id (e.g. ENGWEBP).
     * @param string $bookUsfm    USFM book code (e.g. JHN).
     * @param int    $chapter     1-based chapter number.
     *
     * @return array<string,mixed>|null Raw helloao chapter object, or null on ANY failure.
     */
    public static function fetch( string $translation, string $bookUsfm, int $chapter ): ?array {
        try {
            $translation = trim( $translation );
            $bookUsfm    = trim( $bookUsfm );
            if ( '' === $translation || '' === $bookUsfm || $chapter < 1 ) {
                return null;
            }

            $url = self::API_BASE
                . rawurlencode( $translation ) . '/'
                . rawurlencode( $bookUsfm ) . '/'
                . $chapter . '.json';

            // Scheme validation — the AudioHeadProbe idiom. The base is a constant
            // https URL, but we re-assert it so a future base change can never
            // silently open a non-https (or file://) path.
            $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
            if ( 'https' !== $scheme ) {
                return null;
            }

            $response = wp_remote_get(
                $url,
                array(
                    'timeout'            => 5,
                    'redirection'        => 2,
                    'reject_unsafe_urls' => true,
                )
            );

            if ( is_wp_error( $response ) ) {
                error_log( sprintf(
                    'Sermonator ChapterFetcher: wp_remote_get failed for %s: %s',
                    $url,
                    $response->get_error_message()
                ) );
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                error_log( sprintf(
                    'Sermonator ChapterFetcher: non-200 (%d) for %s',
                    $code,
                    $url
                ) );
                return null;
            }

            $body = wp_remote_retrieve_body( $response );
            if ( ! is_string( $body ) || '' === $body ) {
                return null;
            }

            $decoded = json_decode( $body, true );
            if ( ! is_array( $decoded ) ) {
                return null;
            }

            // Shape validation: helloao wraps the chapter in a `chapter` key whose
            // `content` is the verse-run array ChapterNormalizer consumes. A body
            // missing that shape (an error envelope, an HTML interstitial decoded
            // to a list, a renamed field) fails OPEN to the link.
            $chapterData = $decoded['chapter'] ?? null;
            if (
                ! is_array( $chapterData )
                || ! isset( $chapterData['content'] )
                || ! is_array( $chapterData['content'] )
            ) {
                error_log( sprintf(
                    'Sermonator ChapterFetcher: unexpected chapter shape for %s',
                    $url
                ) );
                return null;
            }

            return $chapterData;
        } catch ( \Throwable $e ) {
            // never-fail-WRONG: ANY unexpected throwable falls open to the link.
            error_log( sprintf(
                'Sermonator ChapterFetcher: unexpected error: %s',
                $e->getMessage()
            ) );
            return null;
        }
    }
}
