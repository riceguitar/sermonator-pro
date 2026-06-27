<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Migration\Detector;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;
use Sermonator\Schema\PodcastMetaSchema;

/**
 * The one opinionated Sermonator settings page (Bundle 4, spec §1 decision 1 / Task 8).
 *
 * A submenu under the Sermons CPT menu (cap `manage_options`) rendering ONE `.wrap` with three
 * `add_settings_section` blocks across TWO forms — the HYBRID binding the spec mandates:
 *
 *   - Form 1 (`options.php`, the Settings API) carries the Bible section + the Display section.
 *     The Bible section surfaces Bundle 3's two ALREADY-REGISTERED options
 *     ({@see Identifiers::OPTION_BIBLE_LINK_VERSION} / {@see Identifiers::OPTION_BIBLE_INLINE_TRANSLATION})
 *     with `add_settings_field` UI ONLY — they are owned by {@see SettingsRegistrar} and are NEVER
 *     re-registered here (a second `register_setting` would double-attach the sanitize filter). The
 *     Display section's three options ({@see Identifiers::OPTION_PREACHER_LABEL},
 *     {@see Identifiers::OPTION_DEFAULT_IMAGE_ID}, {@see Identifiers::OPTION_ARCHIVE_SLUG}) are
 *     registered by {@see DisplaySettingsRegistrar}; again this page adds UI only.
 *   - Form 2 (`admin-post.php`) carries the Podcast-identity section, posting to
 *     {@see PodcastIdentityController} which writes THROUGH to the canonical
 *     {@see Identifiers::META_PODCAST_SETTINGS} post meta the feed actually reads — never a parallel
 *     option that would drift (the #1-standard failure). The section is rendered READ-ONLY with a
 *     notice while a migration is mid-flight (legacy data present and phase ≠ `finalized`), because
 *     the migration imports its own podcast and a re-run would wipe identity edits (spec §1.5).
 *
 * The Form-1 and Form-2 sections live on DISTINCT `do_settings_sections` page slugs
 * ({@see self::PAGE_MAIN} vs {@see self::PAGE_PODCAST}) so each form renders only its own sections —
 * the options.php form never accidentally emits the admin-post podcast fields, and vice versa.
 *
 * EXPLICIT FALLBACKS, NOT REGISTERED DEFAULTS (spec §1.3). `register_setting`'s `default` filter is
 * absent outside `admin_init`/`rest_api_init`, so every current-value read here passes its OWN
 * explicit fallback from {@see DisplayDefaults} / {@see SettingsRegistrar::defaultLinkVersion()} —
 * the page never relies on the registered default to fill an empty field.
 *
 * SCREEN-SCOPED ASSETS (mirrors {@see MigrationWizard}). The media frame (default-image picker) and
 * the slug change-confirm JS enqueue ONLY on this page's hook suffix, so no other admin screen pays
 * for them. The page reads options/meta but performs NO writes itself — Form 1 saves go through the
 * Settings API, Form 2 saves through {@see PodcastIdentityController}; both are sanitized at their
 * own write boundary.
 *
 * settings_errors() is rendered ONCE at the top so both forms share a single notice surface (the
 * options.php "Settings saved." success AND the controller's `add_settings_error` funnel).
 */
final class SettingsPage {
    /** Page slug for the submenu + asset handle base. Reused by {@see PodcastIdentityController::PAGE_SLUG}. */
    public const PAGE_SLUG = 'sermonator-settings';

    /** The capability required to view + save the settings page. */
    public const CAPABILITY = 'manage_options';

    /** Asset handle base. */
    private const HANDLE = 'sermonator-settings';

    /** do_settings_sections page slug for the Form-1 (options.php) Bible + Display sections. */
    private const PAGE_MAIN = self::PAGE_SLUG;

    /** do_settings_sections page slug for the Form-2 (admin-post.php) Podcast-identity section. */
    private const PAGE_PODCAST = self::PAGE_SLUG . '-podcast';

    /** The add_submenu_page hook suffix, captured so asset enqueue is screen-scoped. */
    private string $hookSuffix = '';

    private Detector $detector;
    private MigrationState $state;

    /**
     * Whether the podcast section is rendered read-only (migration mid-flight). Resolved once per
     * render so the section heading, every field callback, and the submit button agree.
     */
    private bool $podcastReadOnly = false;

    /**
     * The default podcast's current identity meta, resolved once per render so each podcast field
     * callback reads from the same snapshot.
     *
     * @var array<string,mixed>
     */
    private array $podcastSettings = array();

    public function __construct( ?Detector $detector = null, ?MigrationState $state = null ) {
        $this->detector = $detector ?? new Detector();
        $this->state    = $state ?? new MigrationState();
    }

    /** Register the admin page, its sections/fields, and its screen-scoped asset enqueue. */
    public function hook(): void {
        add_action( 'admin_menu', array( $this, 'registerPage' ) );
        // Sections/fields are registered on admin_init so the globals do_settings_sections() reads
        // are populated by the time render() runs; this is presentation registration only — neither
        // section re-registers an option (Form 1's options are owned by SettingsRegistrar +
        // DisplaySettingsRegistrar; Form 2 writes through PodcastIdentityController).
        add_action( 'admin_init', array( $this, 'registerSections' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
    }

    /** Register the settings page as a submenu under the Sermons CPT menu. */
    public function registerPage(): void {
        $suffix = add_submenu_page(
            'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON,
            __( 'Sermonator Settings', 'sermonator' ),
            __( 'Settings', 'sermonator' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
        if ( is_string( $suffix ) ) {
            $this->hookSuffix = $suffix;
        }
    }

    /**
     * Register the three sections + their fields. Bible + Display live on {@see self::PAGE_MAIN}
     * (Form 1 / options.php); Podcast identity lives on {@see self::PAGE_PODCAST} (Form 2 /
     * admin-post.php). NO option is registered here — UI only.
     */
    public function registerSections(): void {
        // --- Section 1: Bible (UI only; options owned by SettingsRegistrar) -------------------
        add_settings_section(
            'sermonator_bible',
            __( 'Bible', 'sermonator' ),
            static function (): void {
                echo '<p>' . esc_html__( 'Choose how Scripture references are linked and which public-domain translation may be shown inline.', 'sermonator' ) . '</p>';
            },
            self::PAGE_MAIN
        );
        add_settings_field(
            Identifiers::OPTION_BIBLE_LINK_VERSION,
            __( 'Reference link version', 'sermonator' ),
            array( $this, 'fieldBibleLinkVersion' ),
            self::PAGE_MAIN,
            'sermonator_bible',
            array( 'label_for' => Identifiers::OPTION_BIBLE_LINK_VERSION )
        );
        add_settings_field(
            Identifiers::OPTION_BIBLE_INLINE_TRANSLATION,
            __( 'Inline translation', 'sermonator' ),
            array( $this, 'fieldBibleInlineTranslation' ),
            self::PAGE_MAIN,
            'sermonator_bible',
            array( 'label_for' => Identifiers::OPTION_BIBLE_INLINE_TRANSLATION )
        );

        // --- Section 2: Display (UI only; options owned by DisplaySettingsRegistrar) ----------
        add_settings_section(
            'sermonator_display',
            __( 'Display', 'sermonator' ),
            static function (): void {
                echo '<p>' . esc_html__( 'How sermons appear and where their archive lives.', 'sermonator' ) . '</p>';
            },
            self::PAGE_MAIN
        );
        add_settings_field(
            Identifiers::OPTION_PREACHER_LABEL,
            __( 'Preacher label', 'sermonator' ),
            array( $this, 'fieldPreacherLabel' ),
            self::PAGE_MAIN,
            'sermonator_display',
            array( 'label_for' => Identifiers::OPTION_PREACHER_LABEL )
        );
        add_settings_field(
            Identifiers::OPTION_DEFAULT_IMAGE_ID,
            __( 'Default image', 'sermonator' ),
            array( $this, 'fieldDefaultImage' ),
            self::PAGE_MAIN,
            'sermonator_display'
        );
        add_settings_field(
            Identifiers::OPTION_ARCHIVE_SLUG,
            __( 'Archive slug', 'sermonator' ),
            array( $this, 'fieldArchiveSlug' ),
            self::PAGE_MAIN,
            'sermonator_display',
            array( 'label_for' => Identifiers::OPTION_ARCHIVE_SLUG )
        );

        // --- Section 3: Podcast identity (Form 2; write-through PodcastIdentityController) -----
        add_settings_section(
            'sermonator_podcast',
            __( 'Podcast identity', 'sermonator' ),
            array( $this, 'podcastSectionIntro' ),
            self::PAGE_PODCAST
        );
        foreach ( $this->podcastFieldCatalog() as $key => $meta ) {
            add_settings_field(
                'sermonator_podcast_' . $key,
                $meta['label'],
                function () use ( $key, $meta ): void {
                    $this->renderPodcastField( $key, $meta['type'] );
                },
                self::PAGE_PODCAST,
                'sermonator_podcast',
                array( 'label_for' => 'sermonator_podcast_' . $key )
            );
        }
    }

    /**
     * Enqueue the media frame (image picker) + the slug change-confirm JS — ONLY on this page's
     * hook suffix, mirroring {@see MigrationWizard::enqueueAssets()}.
     */
    public function enqueueAssets( string $hook ): void {
        if ( '' === $this->hookSuffix || $hook !== $this->hookSuffix ) {
            return;
        }

        // The default-image picker uses the WordPress media frame.
        wp_enqueue_media();

        $base = defined( 'SERMONATOR_PLUGIN_URL' ) ? (string) SERMONATOR_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/sermonator.php' );
        $ver  = defined( 'SERMONATOR_VERSION' ) ? (string) SERMONATOR_VERSION : '1.0.0';

        wp_enqueue_script( self::HANDLE, $base . 'assets/settings-page.js', array( 'media-editor' ), $ver, true );
        wp_localize_script(
            self::HANDLE,
            'SermonatorSettings',
            array(
                'mediaTitle'  => __( 'Select default image', 'sermonator' ),
                'mediaButton' => __( 'Use this image', 'sermonator' ),
                'slugConfirm' => __( 'Changing the archive slug changes the URL of the sermon archive AND every single-sermon permalink. Existing inbound links and bookmarks to the old URLs will break until you add redirects. Continue?', 'sermonator' ),
            )
        );
    }

    /** Echo the page. Capability is re-checked (defence-in-depth behind the menu cap). */
    public function render(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access the Sermonator settings.', 'sermonator' ) );
        }

        // Resolve the podcast phase-gate + current meta ONCE for this render.
        $this->podcastReadOnly = $this->detector->hasLegacyData() && 'finalized' !== $this->state->phase();
        $this->podcastSettings = $this->resolvePodcastSettings();

        echo '<div class="wrap sermonator-settings">';
        echo '<h1>' . esc_html__( 'Sermonator Settings', 'sermonator' ) . '</h1>';

        // ONE shared notice surface for BOTH forms (options.php success + controller funnel).
        settings_errors();

        // Form 1 — Settings API (Bible + Display) on the shared option group.
        echo '<form action="options.php" method="post">';
        settings_fields( Identifiers::OPTION_GROUP_SETTINGS );
        do_settings_sections( self::PAGE_MAIN );
        submit_button();
        echo '</form>';

        // Form 2 — Podcast identity, posting to admin-post.php → PodcastIdentityController.
        echo '<hr>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        echo '<input type="hidden" name="action" value="' . esc_attr( PodcastIdentityController::ACTION ) . '">';
        wp_nonce_field( PodcastIdentityController::NONCE_ACTION, PodcastIdentityController::NONCE_FIELD );
        do_settings_sections( self::PAGE_PODCAST );
        if ( ! $this->podcastReadOnly ) {
            submit_button( __( 'Save podcast settings', 'sermonator' ) );
        }
        echo '</form>';

        echo '</div>'; // .wrap
    }

    // -------------------------------------------------------------------------
    // Bible section fields (UI only — options owned by SettingsRegistrar)
    // -------------------------------------------------------------------------

    /**
     * Axis A: the external reference-link version. Rendered as a select of the curated link
     * versions, but the CURRENTLY-STORED value is always present as a selected option even when it
     * is off-list (e.g. a migrated church's NLT/CSB) so saving Form 1 never silently floors it.
     */
    public function fieldBibleLinkVersion(): void {
        $current = (string) get_option( Identifiers::OPTION_BIBLE_LINK_VERSION, SettingsRegistrar::defaultLinkVersion() );
        $options = BibleTranslations::curatedLinkVersions();
        if ( '' !== $current && ! array_key_exists( $current, $options ) ) {
            // Keep the church's real off-list legacy choice selectable + selected.
            $options = array( $current => $current ) + $options;
        }

        echo '<select id="' . esc_attr( Identifiers::OPTION_BIBLE_LINK_VERSION ) . '" name="' . esc_attr( Identifiers::OPTION_BIBLE_LINK_VERSION ) . '">';
        foreach ( $options as $code => $label ) {
            echo '<option value="' . esc_attr( (string) $code ) . '"' . selected( $current, (string) $code, false ) . '>' . esc_html( (string) $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'The Bible version used when building external reference links.', 'sermonator' ) . '</p>';
    }

    /**
     * Axis B: the public-domain translation whose verse TEXT may be rendered inline. Constrained to
     * the inline-eligible curated list (a license-clean set).
     */
    public function fieldBibleInlineTranslation(): void {
        $current = (string) get_option( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION, BibleTranslations::DEFAULT_INLINE );

        echo '<select id="' . esc_attr( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION ) . '" name="' . esc_attr( Identifiers::OPTION_BIBLE_INLINE_TRANSLATION ) . '">';
        foreach ( BibleTranslations::curatedInline() as $code => $label ) {
            echo '<option value="' . esc_attr( (string) $code ) . '"' . selected( $current, (string) $code, false ) . '>' . esc_html( (string) $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Only public-domain translations may have their full text shown inline.', 'sermonator' ) . '</p>';
    }

    // -------------------------------------------------------------------------
    // Display section fields (UI only — options owned by DisplaySettingsRegistrar)
    // -------------------------------------------------------------------------

    public function fieldPreacherLabel(): void {
        $current = (string) get_option( Identifiers::OPTION_PREACHER_LABEL, DisplayDefaults::preacherLabel() );

        echo '<input type="text" class="regular-text" id="' . esc_attr( Identifiers::OPTION_PREACHER_LABEL ) . '" name="' . esc_attr( Identifiers::OPTION_PREACHER_LABEL ) . '" value="' . esc_attr( $current ) . '">';
        echo '<p class="description">' . esc_html__( 'The word used for the person who preached (e.g. Preacher, Speaker, Pastor).', 'sermonator' ) . '</p>';
    }

    /**
     * Default fallback image. A hidden input holds the attachment id; the JS-driven media frame
     * sets it and updates the preview. Degrades without JS to a plain numeric id field.
     */
    public function fieldDefaultImage(): void {
        $current = (int) get_option( Identifiers::OPTION_DEFAULT_IMAGE_ID, DisplayDefaults::defaultImageId() );
        $hasImg  = $current > 0 && 'attachment' === get_post_type( $current );

        echo '<div class="sermonator-default-image">';
        echo '<div id="sermonator-default-image-preview" class="sermonator-default-image-preview">';
        if ( $hasImg ) {
            // Core attachment markup is already escaped/safe.
            echo wp_get_attachment_image( $current, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds safe markup.
        }
        echo '</div>';
        echo '<input type="number" min="0" style="width:8em;" class="sermonator-default-image-id" id="' . esc_attr( Identifiers::OPTION_DEFAULT_IMAGE_ID ) . '" name="' . esc_attr( Identifiers::OPTION_DEFAULT_IMAGE_ID ) . '" value="' . esc_attr( (string) $current ) . '">';
        echo '<button type="button" class="button" id="sermonator-default-image-select">' . esc_html__( 'Select image', 'sermonator' ) . '</button> ';
        echo '<button type="button" class="button button-link-delete" id="sermonator-default-image-remove"' . ( $hasImg ? '' : ' hidden' ) . '>' . esc_html__( 'Remove image', 'sermonator' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Shown when a sermon (or series) has no image of its own. Enter an attachment ID, or use the button to pick one.', 'sermonator' ) . '</p>';
        echo '</div>';
    }

    public function fieldArchiveSlug(): void {
        $current = (string) get_option( Identifiers::OPTION_ARCHIVE_SLUG, DisplayDefaults::defaultArchiveSlug() );

        echo '<input type="text" class="regular-text" id="' . esc_attr( Identifiers::OPTION_ARCHIVE_SLUG ) . '" name="' . esc_attr( Identifiers::OPTION_ARCHIVE_SLUG ) . '" value="' . esc_attr( $current ) . '">';
        echo '<p class="description notice notice-warning inline"><strong>' . esc_html__( 'Warning:', 'sermonator' ) . '</strong> ' . esc_html__( 'This is the base of the sermon archive URL AND every single-sermon permalink. Changing it breaks existing inbound links and bookmarks to the old URLs until you add redirects.', 'sermonator' ) . '</p>';
    }

    // -------------------------------------------------------------------------
    // Podcast-identity section (Form 2; phase-aware read-only)
    // -------------------------------------------------------------------------

    public function podcastSectionIntro(): void {
        if ( $this->podcastReadOnly ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Podcast identity can be configured after the migration completes. Finish (or roll back) the migration first — these fields are read-only until then.', 'sermonator' ) . '</p></div>';
            return;
        }
        echo '<p>' . esc_html__( 'Identity for the podcast feed read by Apple Podcasts and Spotify.', 'sermonator' ) . '</p>';
    }

    /**
     * Render one podcast-identity field, pre-filled from the default podcast meta and disabled when
     * the section is read-only (migration mid-flight). Field NAMES are the bare
     * {@see PodcastMetaSchema::keys()} the controller reads from $_POST.
     */
    private function renderPodcastField( string $key, string $type ): void {
        $id       = 'sermonator_podcast_' . $key;
        $value    = $this->podcastSettings[ $key ] ?? '';
        $disabled = $this->podcastReadOnly ? ' disabled' : '';

        if ( 'checkbox' === $type ) {
            $checked = ! empty( $value ) ? ' checked' : '';
            // An unchecked HTML checkbox submits NO key, and PodcastIdentityController::writeThrough
            // collects by array_key_exists + array_merge — so without this companion an unchecked box
            // would leave the stored value untouched, making `explicit` (the only T_BOOL field, emitted
            // as <itunes:explicit> to Apple/Spotify) a one-way latch that can be turned ON but never
            // OFF through the UI. The standard WP hidden-companion guarantees the key is ALWAYS present:
            // checked posts "1", unchecked posts the hidden "0" (last value wins), and
            // PodcastMetaSchema::toBool('0') resolves to false so the merge can clear the flag.
            //
            // Suppressed when read-only (the checkbox is disabled and the controller refuses the write
            // regardless): no companion means a mid-migration save can't even appear to submit a value.
            if ( ! $this->podcastReadOnly ) {
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="0">';
            }
            echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="1"' . $checked . $disabled . '> ' . esc_html__( 'Yes', 'sermonator' ) . '</label>';
            return;
        }

        if ( 'textarea' === $type ) {
            echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . $disabled . '>' . esc_textarea( (string) $value ) . '</textarea>';
            return;
        }

        $inputType = in_array( $type, array( 'email', 'url' ), true ) ? $type : 'text';
        echo '<input type="' . esc_attr( $inputType ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '"' . $disabled . '>';
    }

    /**
     * The presentation catalog (label + input type) for every {@see PodcastMetaSchema::keys()} key.
     * Labels/types are a UI concern and live here, NOT in the schema (which Task 6 owns). A key
     * present in the schema but absent here degrades to a text field, so the two never silently
     * diverge.
     *
     * @return array<string,array{label:string,type:string}>
     */
    private function podcastFieldCatalog(): array {
        $catalog = array(
            'title'       => array( 'label' => __( 'Title', 'sermonator' ), 'type' => 'text' ),
            'summary'     => array( 'label' => __( 'Summary', 'sermonator' ), 'type' => 'textarea' ),
            'description' => array( 'label' => __( 'Description', 'sermonator' ), 'type' => 'textarea' ),
            'author'      => array( 'label' => __( 'Author', 'sermonator' ), 'type' => 'text' ),
            'owner_name'  => array( 'label' => __( 'Owner name', 'sermonator' ), 'type' => 'text' ),
            'owner_email' => array( 'label' => __( 'Owner email', 'sermonator' ), 'type' => 'email' ),
            'image'       => array( 'label' => __( 'Cover image URL', 'sermonator' ), 'type' => 'url' ),
            'category'    => array( 'label' => __( 'Apple category', 'sermonator' ), 'type' => 'text' ),
            'subcategory' => array( 'label' => __( 'Apple subcategory', 'sermonator' ), 'type' => 'text' ),
            'explicit'    => array( 'label' => __( 'Explicit', 'sermonator' ), 'type' => 'checkbox' ),
            'copyright'   => array( 'label' => __( 'Copyright', 'sermonator' ), 'type' => 'text' ),
            'language'    => array( 'label' => __( 'Language', 'sermonator' ), 'type' => 'text' ),
            'link'        => array( 'label' => __( 'Website link', 'sermonator' ), 'type' => 'url' ),
            'apple_url'   => array( 'label' => __( 'Apple Podcasts URL', 'sermonator' ), 'type' => 'url' ),
            'spotify_url' => array( 'label' => __( 'Spotify URL', 'sermonator' ), 'type' => 'url' ),
        );

        $ordered = array();
        foreach ( PodcastMetaSchema::keys() as $key ) {
            $ordered[ $key ] = $catalog[ $key ] ?? array( 'label' => $key, 'type' => 'text' );
        }
        return $ordered;
    }

    /**
     * The default podcast's current identity meta (or an empty array when no default podcast
     * exists yet). Read-only — the page never writes; Form 2 is the write surface.
     *
     * @return array<string,mixed>
     */
    private function resolvePodcastSettings(): array {
        $podcastId = (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST, 0 );
        if ( $podcastId <= 0 || Identifiers::POST_TYPE_PODCAST !== get_post_type( $podcastId ) ) {
            return array();
        }

        $settings = get_post_meta( $podcastId, Identifiers::META_PODCAST_SETTINGS, true );
        return is_array( $settings ) ? $settings : array();
    }
}
