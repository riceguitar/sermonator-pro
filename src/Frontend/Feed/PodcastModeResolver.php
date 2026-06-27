<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Schema\Identifiers;

/**
 * Reads the per-podcast `sermons_to_show` MODE out of the already-migrated
 * {@see Identifiers::META_PODCAST_SETTINGS} blob and classifies whether the feed can serve it
 * FAITHFULLY today.
 *
 * Legacy Sermon Manager Pro let a podcast pick which medium its feed carried via a
 * `sermons_to_show` select (`includes/podcasting/functions.php` :271):
 *
 *   - empty / absent  → "Audio sermons only"  (the ONLY mode Sermonator serves today)
 *   - `video`         → video sermons only    (drops the audio enclosure)
 *   - `audio_priority`→ audio, falling back to video
 *   - `video_priority`→ video, falling back to audio
 *
 * The key is NOT a taxonomy reference, so {@see \Sermonator\Migration\PodcastWriter} copies it
 * through VERBATIM under the same name and {@see \Sermonator\Schema\PodcastMetaSchema} preserves it
 * (unrecognized-but-hardened) — so the migrated blob carries `sermons_to_show` exactly as Pro stored
 * it. If the key is ABSENT from the migrated blob (a podcast configured before the option existed, or
 * never touched), that is the audio-only default — faithful, no signal.
 *
 * Bundle 2 / T9 (spec §2.10): the audio/video modes are a RECORDED §63 deferral. Audio-only ships
 * faithfully; `video`/`*_priority` faithfulness is UNBUILT. So for any podcast requesting a non-audio
 * mode this resolver reports it as UNSUPPORTED, and {@see PodcastFeed} fires the observable
 * `sermonator_feed_mode_unsupported` signal and KEEPS (never retires) that podcast's review notice —
 * the feed still serves audio-only (today's behavior), but the discrepancy is fail-visible rather
 * than silently-wrong.
 *
 * Pure-ish: the only WordPress contact is `get_post_meta()`.
 */
final class PodcastModeResolver {
    /**
     * The migrated `sermons_to_show` value, if the podcast requests a NON-AUDIO mode whose feed
     * faithfulness is unbuilt (`video` / `audio_priority` / `video_priority`, or any other non-empty
     * value Pro would have treated as "show video" — `podcasting_manager.php` :143 keys the whole
     * video branch off a non-empty value, so ANY non-audio value changes the item set). Returns NULL
     * for the faithful audio-only cases: the key is absent, the value is empty (Pro's `empty()` =
     * audio-only, which includes `''` and `'0'`), the value is the explicit `audio` label, or the
     * stored value is a non-scalar we cannot interpret (treated as the safe default).
     *
     * Gating on the key's PRESENCE-and-value (not merely existence) is deliberate: an absent key is
     * the common pre-option default and must never fire the signal.
     */
    public function unsupportedMode( int $podcastId ): ?string {
        $settings = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        if ( ! is_array( $settings ) || ! array_key_exists( Identifiers::PODCAST_SETTING_FEED_MODE, $settings ) ) {
            return null;
        }

        $raw = $settings[ Identifiers::PODCAST_SETTING_FEED_MODE ];
        if ( ! is_scalar( $raw ) ) {
            return null;
        }

        $mode = strtolower( trim( (string) $raw ) );

        // Pro's `empty( $settings['sermons_to_show'] )` is the audio-only default — `''` and `'0'`
        // are both empty() in PHP. `audio` is the explicit audio-only label. All faithful → no signal.
        if ( $mode === '' || $mode === '0' || $mode === 'audio' ) {
            return null;
        }

        return $mode;
    }

    /**
     * Whether this podcast's per-feed review notice must be KEPT (NOT retired) because it requests a
     * non-audio `sermons_to_show` mode whose faithfulness is unbuilt (the §63 deferral). The feed
     * scope path retires the review notice ("earned silence") only when a podcast is faithfully
     * served; an unsupported mode is the inverse — the notice stays until mode faithfulness ships.
     * True iff {@see self::unsupportedMode()} reports a mode.
     */
    public function keepsReviewNotice( int $podcastId ): bool {
        return $this->unsupportedMode( $podcastId ) !== null;
    }
}
