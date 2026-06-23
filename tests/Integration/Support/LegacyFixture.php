<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Support;

use Sermonator\Migration\LegacyIdentifiers;

/**
 * Builds a legacy Sermon Manager (wpfc_*) dataset in the WordPress test DB so
 * the Detector (and B2's end-to-end test) can run against realistic source data.
 */
final class LegacyFixture {
    public function registerLegacySchema(): void {
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_SERMON ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_SERMON, array( 'public' => true, 'label' => 'Legacy Sermon' ) );
        }
        if ( ! post_type_exists( LegacyIdentifiers::POST_TYPE_PODCAST ) ) {
            register_post_type( LegacyIdentifiers::POST_TYPE_PODCAST, array( 'public' => false, 'label' => 'Legacy Podcast' ) );
        }
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                register_taxonomy( $taxonomy, LegacyIdentifiers::POST_TYPE_SERMON, array( 'public' => true ) );
            }
        }
    }

    /**
     * @param array<string, list<string>> $overrides Meta overrides (key → list of values).
     */
    public function createSermon( array $overrides = array() ): int {
        $id = (int) wp_insert_post( array(
            'post_type'    => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_title'   => 'Legacy Sermon ' . wp_generate_uuid4(),
            'post_status'  => 'publish',
            'post_content' => 'Auto-generated blob',
        ) );

        $defaults = array(
            'sermon_date'        => array( '1612137600' ),
            'sermon_date_auto'   => array( '0' ),
            'bible_passage'      => array( 'John 3:16' ),
            'sermon_description' => array( '<p>The real body of the sermon.</p>' ),
        );

        foreach ( array_merge( $defaults, $overrides ) as $key => $values ) {
            foreach ( (array) $values as $value ) {
                add_post_meta( $id, $key, $value );
            }
        }

        return $id;
    }

    /**
     * Seed a legacy post meta value so the row in the DB holds the EXACT bytes
     * supplied (literal backslashes, escaped quotes, serialized inner strings),
     * mirroring how real legacy data lives in the DB. add_post_meta()'s internal
     * wp_unslash() would otherwise strip a backslash level off the seeded value, so
     * we wp_slash() the input first — the value read back via get_post_meta() is then
     * byte-identical to $value.
     *
     * @param mixed $value Scalar or array meta value (wp_slash recurses into arrays).
     */
    public function seedRawMeta( int $postId, string $key, mixed $value ): void {
        add_post_meta( $postId, $key, wp_slash( $value ) );
    }

    /**
     * Seed a legacy COMMENT meta value so the DB row holds the EXACT bytes
     * supplied (literal backslashes, escaped quotes, serialized inner strings),
     * mirroring how real legacy commentmeta lives in the DB. add_comment_meta()'s
     * internal wp_unslash() would otherwise strip a backslash level off the seeded
     * value, so we wp_slash() the input first — the value read back via
     * get_comment_meta() is then byte-identical to $value. This makes the test
     * exercise the WRITER copy path, not the fixture.
     *
     * @param mixed $value Scalar or array meta value (wp_slash recurses into arrays).
     */
    public function seedRawCommentMeta( int $commentId, string $key, mixed $value ): void {
        add_comment_meta( $commentId, $key, wp_slash( $value ) );
    }

    public function createTerm( string $taxonomy, string $name ): int {
        $result = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $result ) ) {
            $existing = get_term_by( 'name', $name, $taxonomy );
            return $existing ? (int) $existing->term_id : 0;
        }
        return (int) $result['term_id'];
    }

    /**
     * Create a legacy term whose stored name/description hold the EXACT bytes
     * supplied — including literal backslashes and escaped quotes — mirroring how
     * real legacy data lives in the wp_terms/wp_term_taxonomy rows.
     *
     * wp_insert_term()/wp_update_term() internally wp_unslash() their inputs, so
     * we wp_slash() first; the value read back via get_term() is then
     * byte-identical to $name/$description.
     */
    public function createTermRaw( string $taxonomy, string $name, string $description = '', ?string $slug = null ): int {
        $args = array( 'description' => wp_slash( $description ) );
        if ( $slug !== null ) {
            $args['slug'] = $slug;
        }

        $result = wp_insert_term( wp_slash( $name ), $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( 'createTermRaw failed: ' . $result->get_error_message() );
        }
        return (int) $result['term_id'];
    }

    /**
     * Simulate the crash window between TermWriter stamping its LEGACY_SLUG
     * ownership marker and Crosswalk::markLegacyTerm() writing the back-ref: a
     * NEW term lands in the target taxonomy carrying the legacy slug AND the
     * LEGACY_SLUG ownership stamp but NO LEGACY_TERM_ID back-ref. This is the
     * writer's own crash orphan — distinct from a church's native term (which
     * never carries LEGACY_SLUG) — and a resumed run must ADOPT it, not treat it
     * as a native collision.
     *
     * @return int The orphan term id (the back-ref-less crash artifact).
     */
    public function injectCrashOrphanTerm( string $targetTaxonomy, string $name, string $slug, string $description = '' ): int {
        $result = wp_insert_term(
            wp_slash( $name ),
            $targetTaxonomy,
            array( 'slug' => $slug, 'description' => wp_slash( $description ) )
        );
        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( 'injectCrashOrphanTerm failed: ' . $result->get_error_message() );
        }
        $termId = (int) $result['term_id'];

        // The writer stamps LEGACY_SLUG (the original legacy slug) BEFORE the
        // back-ref, so a crash between the two leaves exactly this marker and no
        // back-ref. Reproduce that faithfully.
        add_term_meta( $termId, \Sermonator\Migration\Crosswalk::LEGACY_SLUG, $slug, true );

        return $termId;
    }

    /**
     * Simulate the EARLIER crash window — between wp_insert_term and the
     * LEGACY_SLUG ownership stamp. The orphan term carries the legacy NAME and
     * legacy SLUG but NEITHER the LEGACY_SLUG marker NOR the LEGACY_TERM_ID
     * back-ref. To the LEGACY_SLUG-joined adoption probe it is invisible, and in
     * a non-hierarchical taxonomy a re-insert collides on NAME and throws — the
     * crash-wedge this regression guards. A resumed run must still resolve this
     * same-NAME back-ref-less term and ADOPT it rather than throw.
     *
     * @return int The orphan term id (carrying NO migration markers at all).
     */
    public function injectMarkerlessCrashOrphanTerm( string $targetTaxonomy, string $name, string $slug, string $description = '' ): int {
        $result = wp_insert_term(
            wp_slash( $name ),
            $targetTaxonomy,
            array( 'slug' => $slug, 'description' => wp_slash( $description ) )
        );
        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( 'injectMarkerlessCrashOrphanTerm failed: ' . $result->get_error_message() );
        }

        // No LEGACY_SLUG, no back-ref — the term looks exactly like a bare insert
        // whose run died before the ownership marker was stamped.
        return (int) $result['term_id'];
    }

    /**
     * Simulate the post-level crash window between wp_insert_post and the separate
     * Crosswalk::markLegacy back-ref stamp (the OLD non-atomic writer order): a NEW
     * post lands in the target post type carrying the legacy identity columns (GMT
     * date + title + slug) but NO LEGACY_POST_ID back-ref. To the authoritative
     * back-ref probe it is invisible, so a resumed run that does not reconcile would
     * insert a SECOND visible post. A correct resume must ADOPT this orphan.
     *
     * @param array<string,mixed> $columns Override post columns (post_title, etc.).
     * @return int The orphan post id (carrying NO back-ref).
     */
    public function injectBackRefLessPostOrphan( string $postType, array $columns = array() ): int {
        kses_remove_filters();
        try {
            $id = (int) wp_insert_post( wp_slash( array_merge( array(
                'post_type'   => $postType,
                'post_status' => 'publish',
            ), $columns ) ) );
        } finally {
            kses_init_filters();
        }

        // No LEGACY_POST_ID back-ref — exactly the state an abort between the
        // insert and the (old) separate markLegacy stamp would leave behind.
        return $id;
    }

    public function createPodcast( string $title = 'Default' ): int {
        $id = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        add_post_meta( $id, LegacyIdentifiers::META_PODCAST_SETTINGS, array( 'itunes_author' => 'Church' ) );
        return $id;
    }

    /**
     * Create a legacy podcast with an explicit body and a sm_podcast_settings
     * payload (left exactly as supplied), plus any extra per-key meta.
     *
     * @param array<string,mixed>         $settings  The sm_podcast_settings array.
     * @param array<string, list<string>> $extraMeta Additional meta (key → list of values).
     */
    public function createPodcastWithSettings( array $settings, string $title = 'Feed', string $content = '', array $extraMeta = array() ): int {
        // Seed the body the way real legacy data exists in the DB — with KSES OFF
        // and slashes applied — so the fixture itself does not strip an iframe /
        // shortcode before the writer ever reads it.
        kses_remove_filters();
        try {
            $id = (int) wp_insert_post( wp_slash( array(
                'post_type'    => LegacyIdentifiers::POST_TYPE_PODCAST,
                'post_title'   => $title,
                'post_status'  => 'publish',
                'post_content' => $content,
            ) ) );
        } finally {
            kses_init_filters();
        }
        add_post_meta( $id, LegacyIdentifiers::META_PODCAST_SETTINGS, $settings );
        foreach ( $extraMeta as $key => $values ) {
            foreach ( (array) $values as $value ) {
                add_post_meta( $id, $key, $value );
            }
        }
        return $id;
    }

    /**
     * Insert a legacy comment on a legacy post, READ-ONLY for the writer.
     *
     * Accepts an explicit comment_approved so spam/trash comments can be seeded
     * (get_comments default 'all' excludes those — the writer must use 'any').
     * Suppresses the spam/trash status sanitization that wp_insert_comment would
     * otherwise apply by writing the row with the requested status preserved.
     *
     * @param array<string,mixed> $args Extra/override comment columns.
     */
    public function createComment( int $legacyPostId, string $approved = '1', array $args = array() ): int {
        $commentarr = array_merge( array(
            'comment_post_ID'      => $legacyPostId,
            'comment_author'       => 'Legacy Author',
            'comment_author_email' => 'legacy@example.com',
            'comment_content'      => 'Legacy comment body',
            'comment_approved'     => $approved,
        ), $args );

        $id = (int) wp_insert_comment( $commentarr );

        // wp_insert_comment may normalize comment_approved; force the requested
        // status (e.g. 'spam'/'trash') onto the row so the fixture seeds exactly
        // what real legacy data looks like.
        if ( $id > 0 && (string) get_comment( $id )->comment_approved !== $approved ) {
            global $wpdb;
            $wpdb->update( $wpdb->comments, array( 'comment_approved' => $approved ), array( 'comment_ID' => $id ) );
            clean_comment_cache( $id );
        }

        return $id;
    }

    /**
     * Create a legacy podcast whose sm_podcast_settings meta row holds a
     * SERIALIZED STRING (not an array). Sermon Manager historically stored the
     * settings as a serialized string in a single meta row; reading it back via
     * get_post_meta(...,true) WP auto-unserializes, but a row whose stored bytes
     * are a serialized string (because it was double-serialized / stored as a
     * string) round-trips through get_post_meta(...,false) as a STRING. We
     * reproduce that exact on-disk shape: add_post_meta with a pre-serialized
     * string, wp_slash'd so the backslashes/quotes survive and the row holds the
     * literal serialized payload as a string value.
     *
     * @param array<string,mixed> $settings The settings to serialize into the row.
     */
    public function createPodcastWithSerializedStringSettings( array $settings, string $title = 'Feed' ): int {
        $id = (int) wp_insert_post( array(
            'post_type'   => LegacyIdentifiers::POST_TYPE_PODCAST,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );

        // Store the settings as a SERIALIZED STRING value. wp_slash so
        // add_post_meta's internal wp_unslash leaves the serialized payload
        // byte-intact; get_post_meta(...,false) then returns this as a string
        // (WP only auto-unserializes once, and the stored scalar is a string that
        // happens to BE a serialized array — the exact legacy shape).
        $serialized = serialize( $settings );
        add_post_meta( $id, LegacyIdentifiers::META_PODCAST_SETTINGS, wp_slash( $serialized ) );

        return $id;
    }

    public function setOption( string $name, mixed $value ): void {
        update_option( $name, $value );
    }

    /**
     * Seed the legacy Sermon Image Plugin options.
     *
     * @param array<int, int>      $images   Legacy term_taxonomy_id => attachment_id.
     * @param array<string, mixed> $settings Legacy settings (taxonomy-name keys + globals).
     */
    public function seedArtwork( array $images, array $settings = array() ): void {
        update_option( LegacyIdentifiers::OPTION_TERM_IMAGES, $images );
        if ( $settings !== array() ) {
            update_option( LegacyIdentifiers::OPTION_TERM_IMAGES_SETTINGS, $settings );
        }
    }
}
