<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;

/**
 * Registers the three LIVE Display options (Bundle 4, spec §3 / Task 2) against
 * the shared {@see Identifiers::OPTION_GROUP_SETTINGS} settings group:
 *
 *   - {@see Identifiers::OPTION_ARCHIVE_SLUG}     — CPT archive + single-sermon
 *     permalink base; sanitize_title, rejecting empty / WordPress-reserved /
 *     page-colliding submissions back to the CURRENTLY STORED value (never a
 *     guess), so a fat-fingered save can't silently repoint every permalink.
 *   - {@see Identifiers::OPTION_DEFAULT_IMAGE_ID} — fallback featured-image
 *     attachment id; absint + a real-attachment existence check, else 0.
 *   - {@see Identifiers::OPTION_PREACHER_LABEL}   — the singular preacher
 *     taxonomy label; sanitize_text_field + a length cap.
 *
 * DISTINCT-KEY PROVENANCE (spec §1.2). These are deliberately DISTINCT live keys
 * (`sermonator_sermon_archive_slug`, `sermonator_sermon_default_image_id`),
 * separate from the migration's prefix-swap artifact containers
 * (`sermonator_general` / `sermonator_display`) that {@see DisplayDefaults}
 * SEEDS from. The migration's `OptionWriter` overwrites the artifact verbatim on
 * a (supported, pre-Finalize) re-run; because the live keys are distinct, a
 * re-run touches only the artifact and never clobbers an admin's saved edit.
 *
 * EXPLICIT DEFAULTS, NOT REGISTERED ONES (spec §1.3). `register_setting`'s
 * `default` filter only attaches on `admin_init` / `rest_api_init`, so it is
 * ABSENT at `init@5` and on the front end. The registered defaults here merely
 * mirror {@see DisplayDefaults}; every READER ({@see \Sermonator\Model\Registrar},
 * {@see \Sermonator\Frontend\TemplateData}) must still pass its OWN explicit
 * fallback from the same {@see DisplayDefaults} seed to `get_option()`.
 *
 * BIBLE OPTIONS ARE NOT TOUCHED HERE. {@see SettingsRegistrar} owns the two
 * Bible options (link version + inline translation) AND the cache-generation
 * bump. This registrar MUST NOT re-register them — a double `register_setting`
 * would attach a second sanitize filter and double-run it — nor wire any
 * cache-gen listener. Bundle 4's settings PAGE adds only `add_settings_field`
 * UI for the Bible options.
 *
 * Registration runs on both `admin_init` (Settings API / options.php save) and
 * `rest_api_init` (so `show_in_rest` exposure works in the block editor's
 * non-admin REST context). The class never resolves or escapes for output —
 * sanitize is a write-boundary concern; escaping lives at the Renderer boundary.
 */
final class DisplaySettingsRegistrar {
    /**
     * Maximum stored length (in characters) of the preacher label. A taxonomy
     * label is chrome text, not prose; capping keeps a pasted essay out of every
     * admin column header and front-end meta line.
     */
    private const MAX_LABEL_LENGTH = 100;

    /**
     * WordPress reserved terms an archive base must never collide with: every one
     * is a public query var / rewrite endpoint, so adopting it as the CPT archive
     * slug breaks core routing (feeds, pagination, author/date archives, the REST
     * `embed` endpoint, …). Mirrors the canonical core list (see
     * `wp-includes/rewrite.php` + `WP::$public_query_vars`). Filterable so a site
     * with custom endpoints can extend it without a code edit.
     *
     * @var list<string>
     */
    private const RESERVED_SLUGS = array(
        'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat',
        'category', 'category_name', 'comments', 'cpage', 'custom', 'customize_messenger_channel',
        'day', 'debug', 'embed', 'error', 'exact', 'feed', 'fields', 'hour', 'link_category',
        'm', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nonce', 'nopaging', 'offset',
        'order', 'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'pb', 'perm', 'post',
        'post_format', 'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts',
        'preview', 'robots', 'rss', 'rss2', 's', 'search', 'second', 'sentence', 'showposts',
        'static', 'status', 'subpost', 'subpost_id', 'tag', 'tag_id', 'taxonomy', 'tb', 'term',
        'terms', 'theme', 'title', 'trackback', 'type', 'types', 'w', 'withcomments',
        'withoutcomments', 'year',
    );

    public function hook(): void {
        // BOTH contexts on purpose (spec §1.3): admin_init powers the options.php
        // Settings-API save + the registered-default filter; rest_api_init powers
        // the show_in_rest exposure the block editor reads in a non-admin context.
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'rest_api_init', array( $this, 'register' ) );
    }

    public function register(): void {
        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_ARCHIVE_SLUG,
            array(
                'type'              => 'string',
                'default'           => DisplayDefaults::defaultArchiveSlug(),
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeArchiveSlug' ),
            )
        );

        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_DEFAULT_IMAGE_ID,
            array(
                'type'              => 'integer',
                'default'           => DisplayDefaults::defaultImageId(),
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizeImageId' ),
            )
        );

        register_setting(
            Identifiers::OPTION_GROUP_SETTINGS,
            Identifiers::OPTION_PREACHER_LABEL,
            array(
                'type'              => 'string',
                'default'           => DisplayDefaults::preacherLabel(),
                'show_in_rest'      => true,
                'sanitize_callback' => array( $this, 'sanitizePreacherLabel' ),
            )
        );
    }

    /**
     * Archive-slug sanitize: `sanitize_title` the submission, then REJECT it back
     * to the currently-stored value when it is empty, a WordPress-reserved term,
     * or collides with an existing page path. Rejection returns the STORED value
     * (or, if none is stored yet, the {@see DisplayDefaults} seed) — never a
     * fabricated guess — because this slug drives the CPT archive AND every
     * single-sermon permalink; a bad save would break inbound links sitewide.
     *
     * The stored value is read mid-save (sanitize runs BEFORE the DB write), so
     * `get_option()` here returns the PREVIOUS live slug, which is exactly the
     * "current value" we want to preserve on rejection.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeArchiveSlug( $value ): string {
        $slug = sanitize_title( is_string( $value ) ? $value : '' );

        if ( '' === $slug ) {
            add_settings_error(
                Identifiers::OPTION_ARCHIVE_SLUG,
                'sermonator_slug_empty',
                __( 'Archive slug not saved: the submitted value is empty or contains only invalid characters. The current slug is kept.', 'sermonator' ),
                'error'
            );
            return $this->storedArchiveSlug();
        }

        if ( $this->isReservedSlug( $slug ) ) {
            add_settings_error(
                Identifiers::OPTION_ARCHIVE_SLUG,
                'sermonator_slug_reserved',
                sprintf(
                    /* translators: %s: the submitted slug value */
                    __( 'Archive slug not saved: "%s" is a WordPress-reserved term and would break core routing. The current slug is kept.', 'sermonator' ),
                    esc_html( $slug )
                ),
                'error'
            );
            return $this->storedArchiveSlug();
        }

        if ( $this->collidesWithPage( $slug ) ) {
            add_settings_error(
                Identifiers::OPTION_ARCHIVE_SLUG,
                'sermonator_slug_page_collision',
                sprintf(
                    /* translators: %s: the submitted slug value */
                    __( 'Archive slug not saved: "%s" collides with an existing page URL. The current slug is kept.', 'sermonator' ),
                    esc_html( $slug )
                ),
                'error'
            );
            return $this->storedArchiveSlug();
        }

        return $slug;
    }

    /**
     * Default-image sanitize: coerce to a non-negative int, then VERIFY it points
     * at a real attachment; anything else (a deleted attachment, a non-attachment
     * post id, a non-numeric value) floors to 0 so the front end falls through to
     * "no fallback image" rather than rendering a broken/foreign attachment.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizeImageId( $value ): int {
        $id = absint( $value );

        if ( 0 === $id || get_post_type( $id ) !== 'attachment' ) {
            return 0;
        }

        return $id;
    }

    /**
     * Preacher-label sanitize: `sanitize_text_field` then cap to
     * {@see self::MAX_LABEL_LENGTH} characters. An empty result falls back to the
     * currently-stored label (or the {@see DisplayDefaults} seed) — a blank
     * taxonomy label would degrade every admin column header and front-end meta
     * line, so it is treated like the slug: never stored empty.
     *
     * @param mixed $value Raw submitted value.
     */
    public function sanitizePreacherLabel( $value ): string {
        $label = sanitize_text_field( is_string( $value ) ? $value : '' );

        if ( '' === $label ) {
            return $this->storedPreacherLabel();
        }

        // mb_substr keeps the cap codepoint-correct for non-ASCII labels (e.g.
        // "Predicador" / "傳道人") rather than truncating mid-multibyte-sequence.
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $label, 0, self::MAX_LABEL_LENGTH );
        }

        return substr( $label, 0, self::MAX_LABEL_LENGTH );
    }

    /**
     * Whether a sanitized slug is a WordPress-reserved term. Filterable so a site
     * with custom rewrite endpoints can extend the guard.
     */
    private function isReservedSlug( string $slug ): bool {
        /**
         * Filters the archive-slug reserved-term blocklist.
         *
         * @param list<string> $reserved Lower-case reserved slugs.
         */
        $reserved = apply_filters( 'sermonator_reserved_archive_slugs', self::RESERVED_SLUGS );

        return in_array( $slug, (array) $reserved, true );
    }

    /**
     * Whether a sanitized slug collides with an existing page path. A page at
     * `/sermons` would shadow the CPT archive at the same base, so such a slug is
     * rejected back to the stored value.
     */
    private function collidesWithPage( string $slug ): bool {
        return null !== get_page_by_path( $slug );
    }

    /**
     * The currently-stored live archive slug, explicitly defaulted to the
     * {@see DisplayDefaults} seed so a rejection on a never-saved site still
     * returns the migrated/legacy/hard value — never a guess.
     */
    private function storedArchiveSlug(): string {
        $stored = get_option( Identifiers::OPTION_ARCHIVE_SLUG, DisplayDefaults::defaultArchiveSlug() );

        return is_string( $stored ) && '' !== $stored ? $stored : DisplayDefaults::defaultArchiveSlug();
    }

    /**
     * The currently-stored live preacher label, explicitly defaulted to the
     * {@see DisplayDefaults} seed (same never-a-guess discipline as the slug).
     */
    private function storedPreacherLabel(): string {
        $stored = get_option( Identifiers::OPTION_PREACHER_LABEL, DisplayDefaults::preacherLabel() );

        return is_string( $stored ) && '' !== $stored ? $stored : DisplayDefaults::preacherLabel();
    }
}
