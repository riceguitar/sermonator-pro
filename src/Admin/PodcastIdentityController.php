<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Migration\Detector;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\Identifiers;
use Sermonator\Schema\PodcastMetaSchema;

/**
 * The `admin-post.php` handler for the settings page's Form 2 (podcast identity).
 *
 * The settings page is a HYBRID (spec §1.1): Form 1 is the Settings API on the shared
 * options group; Form 2 — this controller — is a dedicated `admin-post.php` form that writes
 * THROUGH to the single canonical source of truth, the {@see Identifiers::META_PODCAST_SETTINGS}
 * post meta on the default podcast, which {@see \Sermonator\Frontend\Feed\PodcastConfigFactory}
 * reads and {@see \Sermonator\Frontend\Feed\PodcastFeed} emits to Apple / Spotify. A PARALLEL
 * option for podcast identity is deliberately rejected — it would drift from the meta the feed
 * actually reads, the #1-standard failure.
 *
 * PHASE GATE (spec §1.5). Reading + writing podcast identity is unsafe while a migration is
 * mid-flight: the migration imports its OWN podcast(s) and {@see \Sermonator\Migration\OptionWriter}
 * repoints {@see Identifiers::OPTION_DEFAULT_PODCAST} at the imported one — so an admin who
 * configured a fresh podcast #A before importing podcast #B would be silently split-brained, and a
 * supported migration RE-RUN's `delete_post_meta` + re-add would wipe the admin's identity edits.
 * So this controller:
 *   - AUTO-CREATES the default podcast (and sets {@see Identifiers::OPTION_DEFAULT_PODCAST}) only on
 *     a genuinely fresh site — {@see Detector::hasLegacyData()} reports NO legacy Sermon-Manager
 *     data (which is also true post-Finalize, the legacy CPT having been force-deleted);
 *   - REFUSES the write — with an `add_settings_error` "configure after migration completes" notice
 *     and no mutation — when legacy data still exists and the lifecycle is not `finalized`.
 *
 * WRITE DISCIPLINE (spec §1.6). On an allowed write the posted fields are sanitized through the
 * shared {@see PodcastMetaSchema} (the SAME typed allowlist the feed reader intersects against, so
 * reader and writer never drift) and MERGED into the existing meta array — never a wholesale
 * replace — so the migration's per-taxonomy term-filter SCOPE keys (which decide WHICH sermons the
 * feed carries) and any unsubmitted identity field survive verbatim. The merged array is persisted
 * with {@see \update_post_meta()}, the podcast's object cache is busted, and a notice is funnelled
 * back through `add_settings_error` so the settings page renders ONE consolidated notice surface.
 *
 * This clears the §5.D audio-backfill bar for the identity write path: sanitize-at-write (via the
 * schema), reversible (a merge that only ever narrows recognized values, never drops a key or
 * invents data), and gated behind a nonce + `manage_options`.
 *
 * TESTABLE CORE. {@see self::handle()} performs every gate + the write and returns a structured
 * result (it never redirects or exits), so the unit tests drive the phase-gate decision and the
 * sanitize+merge write directly; {@see self::dispatch()} is the thin `admin-post.php` adapter that
 * persists the settings-errors across the redirect and `wp_safe_redirect`s back to the settings
 * page so {@see \settings_errors()} can render them.
 */
final class PodcastIdentityController {
    /** The `admin-post.php` action slug (the form's hidden `action` field). */
    public const ACTION = 'sermonator_save_podcast_identity';

    /** The nonce action paired with {@see self::NONCE_FIELD}. */
    public const NONCE_ACTION = 'sermonator_save_podcast_identity';

    /** The form's nonce field name (kept distinct from core's default `_wpnonce`). */
    public const NONCE_FIELD = 'sermonator_podcast_identity_nonce';

    /** The capability required to configure podcast identity. */
    public const CAPABILITY = 'manage_options';

    /**
     * The settings-page submenu slug Form 2 redirects back to. Defined here so this controller
     * has no hard dependency on the not-yet-built {@see SettingsPage} (Task 8), which reuses it.
     */
    public const PAGE_SLUG = 'sermonator-settings';

    /** The `add_settings_error` group/setting slug — the page reads it via `settings_errors()`. */
    public const NOTICE_SLUG = 'sermonator_podcast_identity';

    /**
     * Register the `admin-post.php` handler. Wired behind `is_admin()` in {@see \Sermonator\Plugin}
     * — the `admin_post_*` hook only ever fires on `admin-post.php`, an admin-context endpoint.
     */
    public function hook(): void {
        add_action( 'admin_post_' . self::ACTION, array( $this, 'dispatch' ) );
    }

    /**
     * The thin `admin-post.php` adapter: read the request, run the gated core, persist the
     * settings-errors across the redirect, and bounce back to the settings page. Kept trivial so
     * the data logic lives in {@see self::handle()} (which the tests drive directly).
     */
    public function dispatch(): void {
        // wp_unslash so the nonce compares cleanly and values are de-slashed before sanitize;
        // handle() verifies the nonce, so reading $_POST here is safe.
        $request = is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verifies the nonce.

        $result = $this->handle( (array) $request );

        if ( 'denied' === $result['status'] ) {
            // A failed nonce/capability gate is not a routine refusal — hard-stop like every
            // other admin-post handler rather than redirecting with a notice.
            wp_die(
                esc_html__( 'You are not allowed to configure the podcast, or your session expired. Reload and try again.', 'sermonator' ),
                '',
                array( 'response' => 403 )
            );
        }

        // add_settings_error populated the in-request global; persist it so settings_errors()
        // can render it AFTER the redirect (the standard admin-post → options-page handoff).
        set_transient( 'settings_errors', get_settings_errors(), 30 );

        wp_safe_redirect( $this->redirectUrl( $request ) );
        exit;
    }

    /**
     * The testable, gated core. Enforces capability + nonce, applies the phase gate, and — when
     * allowed — sanitizes + merges + write-throughs the identity meta, busting the feed cache.
     * Registers a notice via `add_settings_error` for every outcome. NEVER redirects or exits, and
     * on any gate failure NOTHING is mutated.
     *
     * @param array<string,mixed> $request The (already wp_unslash'd) request fields.
     * @return array{status:'denied'|'refused'|'saved'|'error', podcastId?:int}
     */
    public function handle( array $request ): array {
        // GATE A: capability.
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return array( 'status' => 'denied' );
        }

        // GATE B: nonce.
        $nonce = isset( $request[ self::NONCE_FIELD ] ) ? (string) $request[ self::NONCE_FIELD ] : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return array( 'status' => 'denied' );
        }

        // GATE C: migration phase. A migration imports its own podcast and repoints the default;
        // a re-run wipes identity edits. So refuse while legacy data exists and we're not finalized.
        $hasLegacy = ( new Detector() )->hasLegacyData();
        $phase     = ( new MigrationState() )->phase();

        if ( $hasLegacy && 'finalized' !== $phase ) {
            add_settings_error(
                self::NOTICE_SLUG,
                'sermonator_podcast_identity_migration_pending',
                __( 'Podcast identity can be configured after the migration completes. Finish (or roll back) the migration first.', 'sermonator' ),
                'error'
            );
            return array( 'status' => 'refused' );
        }

        // Resolve the write target: the default podcast post.
        $podcastId = $this->resolveDefaultPodcast( $hasLegacy );
        if ( $podcastId <= 0 ) {
            add_settings_error(
                self::NOTICE_SLUG,
                'sermonator_podcast_identity_no_target',
                __( 'Could not resolve a default podcast to save to. Complete the migration, then try again.', 'sermonator' ),
                'error'
            );
            return array( 'status' => 'error' );
        }

        $this->writeThrough( $podcastId, $request );

        add_settings_error(
            self::NOTICE_SLUG,
            'sermonator_podcast_identity_saved',
            __( 'Podcast settings saved.', 'sermonator' ),
            'success'
        );

        return array( 'status' => 'saved', 'podcastId' => $podcastId );
    }

    /**
     * Resolve the default podcast post id, auto-creating one ONLY on a genuinely fresh site.
     *
     * On a fresh site (no legacy data) with no default podcast yet, a default podcast is created
     * and {@see Identifiers::OPTION_DEFAULT_PODCAST} is pointed at it (the first-save bootstrap).
     * When legacy data exists we are here only because the lifecycle is `finalized`; auto-creation
     * is withheld (spec §1.5 restricts it to no-legacy-data) and an already-stored default podcast
     * is used. A stored id that no longer points at a real podcast post is treated as absent.
     */
    private function resolveDefaultPodcast( bool $hasLegacy ): int {
        $stored = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );
        if ( $stored > 0 && Identifiers::POST_TYPE_PODCAST === get_post_type( $stored ) ) {
            return $stored;
        }

        // Auto-create only on a genuinely fresh site — never when legacy data is present.
        if ( $hasLegacy ) {
            return 0;
        }

        return $this->createDefaultPodcast();
    }

    /**
     * Create a minimal default podcast post and register it as {@see Identifiers::OPTION_DEFAULT_PODCAST}.
     * Identity meta is NOT seeded here — the write-through that follows persists every submitted
     * field. Returns the new post id, or 0 on failure.
     */
    private function createDefaultPodcast(): int {
        $title = (string) get_bloginfo( 'name' );
        if ( '' === trim( $title ) ) {
            $title = __( 'Podcast', 'sermonator' );
        }

        $newId = wp_insert_post(
            array(
                'post_type'   => Identifiers::POST_TYPE_PODCAST,
                'post_status' => 'publish',
                'post_title'  => $title,
            ),
            true
        );

        if ( ! is_int( $newId ) || $newId <= 0 ) {
            return 0;
        }

        update_option( Identifiers::OPTION_DEFAULT_PODCAST, $newId );

        return $newId;
    }

    /**
     * Sanitize the posted identity fields through the shared schema and MERGE them into the
     * existing {@see Identifiers::META_PODCAST_SETTINGS} array (never a wholesale replace), then
     * persist + bust the podcast's object cache.
     *
     * Only the recognized identity keys actually PRESENT in the request are collected, so a field
     * the form omitted is left untouched (it is not clobbered to empty), and non-identity keys
     * already on the row — notably the migration's per-taxonomy term-filter scope keys — survive
     * the merge verbatim. The collected subset is sanitized via {@see PodcastMetaSchema::sanitize()}
     * (the same allowlist the feed reader intersects against), and the merged result is re-slashed
     * for {@see \update_post_meta()} (which unslashes its input).
     *
     * @param array<string,mixed> $request
     */
    private function writeThrough( int $podcastId, array $request ): void {
        $incoming = array();
        foreach ( PodcastMetaSchema::keys() as $key ) {
            if ( array_key_exists( $key, $request ) ) {
                $incoming[ $key ] = $request[ $key ];
            }
        }
        $incoming = PodcastMetaSchema::sanitize( $incoming );

        $existing = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        $existing = is_array( $existing ) ? $existing : array();

        $merged = array_merge( $existing, $incoming );

        update_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, wp_slash( $merged ) );

        $this->bustFeedCache( $podcastId );
    }

    /**
     * Bust the cached representation of the podcast so the next feed render reads the fresh meta.
     * The feed is rendered live (there is no feed-level transient layer today), so the meaningful
     * invalidation is the post's object cache — `clean_post_cache` flushes the post + its meta
     * cache. A `sermonator_podcast_identity_saved` action is fired so a future caching layer (or an
     * add-on) can hook its own invalidation without a core edit.
     */
    private function bustFeedCache( int $podcastId ): void {
        clean_post_cache( $podcastId );

        /**
         * Fires after the podcast identity meta has been written through to the default podcast.
         *
         * @param int $podcastId The default podcast post id whose settings changed.
         */
        do_action( 'sermonator_podcast_identity_saved', $podcastId );
    }

    /**
     * Build the redirect target: the settings page, with `settings-updated` set so
     * `settings_errors()` reads the persisted transient. Prefers the form's own
     * `_wp_http_referer` (the page the form was submitted from) and falls back to a constructed
     * settings-page URL; `wp_safe_redirect` independently validates the host either way.
     *
     * @param array<string,mixed> $request
     */
    private function redirectUrl( array $request ): string {
        $referer = isset( $request['_wp_http_referer'] ) ? (string) $request['_wp_http_referer'] : '';
        $base    = '' !== $referer
            ? $referer
            : admin_url( 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON . '&page=' . self::PAGE_SLUG );

        return add_query_arg( 'settings-updated', 'true', $base );
    }
}
