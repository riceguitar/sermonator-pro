<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Rollback;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\TermCrosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Migration\MappingContract;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 7 / design-notes item 21: End-to-end full-cycle migration.
 *
 * Drives a RICH legacy dataset (terms with description + explicit slug; a
 * slug-colliding native term forcing the LEGACY_SLUG path; an artwork seed mapping
 * a legacy tt_id to an attachment; threaded comments; a sermon with a serialized
 * array meta + a custom/unknown meta key; a sermon whose body lives ONLY in
 * post_content_temp; a sermon with a non-numeric sermon_date string; orphan + shared
 * terms; a podcast with taxonomy-filter settings; sermonmanager_* options incl. one
 * with a pre-existing sermonator_* native value to back up) through the FULL
 * lifecycle and asserts, at every stage, the highest bar — DATA PRESERVATION:
 *
 *  - happy-path continuity: detect → run* (to migrated) → verify (complete, state
 *    verified) with ZERO legacy mutation (full byte-equal snapshot) and field-by-field
 *    continuity through the crosswalk (counts per taxonomy = manifest; term
 *    name/slug/description; slug-collision via LEGACY_SLUG; artwork old tt_id → new
 *    tt_id → same attachment; serialized meta arrays intact; threaded comments with
 *    remapped parents; temp-only body preserved + flagged; non-numeric date normalized
 *    alongside the untouched raw);
 *  - idempotent full re-run is a CLEAN no-op (no duplicate posts/terms/comments/meta);
 *  - rollback path → pristine start (zero back-refs, restored options, legacy untouched);
 *  - finalize path → refuses until verified, then deletes only verified legacy records,
 *    preserves _sermonator_legacy_post_content, state finalized;
 *  - drift oracle catches a post-detect legacy edit;
 *  - crash-injection mid-sermon-batch then resume → no duplicate sermons/comments.
 *
 * Every legacy-read entry point in the production code calls
 * LegacySchemaRegistrar::ensureRegistered(), so this whole cycle works with the
 * legacy plugin deactivated.
 */
final class EndToEndTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Rich dataset
    // -------------------------------------------------------------------------

    /**
     * Seed the richest realistic legacy dataset the lifecycle must carry intact.
     *
     * @return array{
     *   preacher:int, series:int, orphanTopic:int, collidingSeries:int, nativeSeriesSlug:string,
     *   sermons:list<int>, tempSermon:int, dateSermon:int, serializedSermon:int,
     *   serializedPayload:array<string,mixed>, customKey:string, customVal:string,
     *   podcast:int, rootComment:int, childComment:int,
     *   preacherTtId:int, attachmentId:int, nativeCatTtId:int, nativeCatTermId:int
     * }
     */
    private function seedRichDataset(): array {
        // Terms WITH description + explicit slug.
        $preacher = $this->fixture->createTermRaw(
            LegacyIdentifiers::TAX_PREACHER,
            'Pastor Bob',
            'The senior pastor.',
            'pastor-bob'
        );
        $series = $this->fixture->createTermRaw(
            LegacyIdentifiers::TAX_SERIES,
            'Advent Series',
            'A four-week Advent journey.',
            'advent-series'
        );
        // An ORPHAN topic term (no sermon references it) — must still migrate.
        $orphanTopic = $this->fixture->createTermRaw(
            LegacyIdentifiers::TAX_TOPIC,
            'Orphan Topic',
            'A topic with no sermons.',
            'orphan-topic'
        );

        // SLUG-COLLISION: a NATIVE sermonator_series term already owns the slug a
        // legacy series term wants, forcing TermWriter to disambiguate and preserve
        // the ORIGINAL legacy slug in LEGACY_SLUG (verified via LEGACY_SLUG, not
        // legacy==new).
        $nativeSeriesSlug = 'grace';
        wp_insert_term( 'Native Grace', Identifiers::TAX_SERIES, array( 'slug' => $nativeSeriesSlug ) );
        $collidingSeries = $this->fixture->createTermRaw(
            LegacyIdentifiers::TAX_SERIES,
            'Grace Legacy',
            'A legacy series whose slug collides with a native term.',
            $nativeSeriesSlug
        );

        // A NATIVE shared category assigned to a legacy sermon — exercises the
        // direct-$wpdb native relationship mirror (rollback/finalize recount path).
        $nativeCatTermId = (int) self::factory()->category->create( array( 'name' => 'Shared Church Category' ) );
        $nativeCatTtId   = (int) get_term_field( 'term_taxonomy_id', $nativeCatTermId, 'category' );

        // Two plain sermons, both tagged preacher + series.
        $sermons = array();
        for ( $i = 0; $i < 2; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            wp_set_object_terms( $sid, array( $series ), LegacyIdentifiers::TAX_SERIES );
            $sermons[] = $sid;
        }
        // The native shared category on the first sermon.
        wp_set_object_terms( $sermons[0], array( $nativeCatTermId ), 'category' );

        // A sermon whose body lives ONLY in post_content_temp (empty description +
        // empty post_content) — the reconciler must surface it as the body AND keep
        // post_content_temp as its verbatim canonical row.
        $tempSermon = $this->fixture->createSermon( array(
            LegacyIdentifiers::META_DESCRIPTION       => array( '' ),
            LegacyIdentifiers::META_POST_CONTENT_TEMP => array( '<p>Body only in temp.</p>' ),
        ) );
        wp_set_object_terms( $tempSermon, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );

        // A sermon with a NON-NUMERIC sermon_date string — normalized companion
        // written alongside the untouched raw.
        $dateSermon = $this->fixture->createSermon( array(
            LegacyIdentifiers::META_DATE => array( 'March 5, 2021' ),
        ) );
        wp_set_object_terms( $dateSermon, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );

        // A sermon with a SERIALIZED ARRAY meta value + a custom/unknown meta key —
        // both must round-trip onto the target (array as array; unknown verbatim).
        $serializedPayload = array( 'a' => 1, 'b' => array( 2, 3 ), 'c' => 'x' );
        $customKey         = '_yoast_wpseo_title';
        $customVal         = 'Custom SEO Title';
        $serializedSermon  = $this->fixture->createSermon();
        wp_set_object_terms( $serializedSermon, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
        add_post_meta( $serializedSermon, 'custom_payload', $serializedPayload );
        add_post_meta( $serializedSermon, $customKey, $customVal );

        // THREADED comments on the first sermon (a root + a reply) — the writer
        // copies depth-first and remaps comment_parent to the new id.
        $rootComment  = $this->fixture->createComment( $sermons[0], '1', array(
            'comment_content' => 'Root comment body',
        ) );
        $childComment = $this->fixture->createComment( $sermons[0], '1', array(
            'comment_content' => 'Reply comment body',
            'comment_parent'  => $rootComment,
        ) );

        // A podcast with taxonomy-filter settings.
        $podcast = $this->fixture->createPodcastWithSettings(
            array( 'itunes_author' => 'Church', 'filtering' => array( 'taxonomy' => LegacyIdentifiers::TAX_SERIES ) ),
            'Sunday Feed',
            '<p>Feed body.</p>'
        );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        // sermonmanager_* options: a plain one, PLUS the artwork option whose
        // sermonator_* native counterpart already exists (must be backed up).
        $this->fixture->setOption( 'sermonmanager_general', array( 'archive_slug' => 'sermons' ) );
        update_option( Identifiers::OPTION_TERM_IMAGES, array( 'native' => 'preexisting' ) );

        // Artwork seed: a legacy tt_id (the preacher's) → an attachment id.
        $preacherTtId = (int) get_term_field( 'term_taxonomy_id', $preacher, LegacyIdentifiers::TAX_PREACHER );
        $attachmentId = (int) self::factory()->post->create( array(
            'post_type'   => 'attachment',
            'post_title'  => 'Preacher Artwork',
            'post_status' => 'inherit',
        ) );
        $this->fixture->seedArtwork( array( $preacherTtId => $attachmentId ) );

        return array(
            'preacher'          => $preacher,
            'series'            => $series,
            'orphanTopic'       => $orphanTopic,
            'collidingSeries'   => $collidingSeries,
            'nativeSeriesSlug'  => $nativeSeriesSlug,
            'sermons'           => $sermons,
            'tempSermon'        => $tempSermon,
            'dateSermon'        => $dateSermon,
            'serializedSermon'  => $serializedSermon,
            'serializedPayload' => $serializedPayload,
            'customKey'         => $customKey,
            'customVal'         => $customVal,
            'podcast'           => $podcast,
            'rootComment'       => $rootComment,
            'childComment'      => $childComment,
            'preacherTtId'      => $preacherTtId,
            'attachmentId'      => $attachmentId,
            'nativeCatTtId'     => $nativeCatTtId,
            'nativeCatTermId'   => $nativeCatTermId,
        );
    }

    // -------------------------------------------------------------------------
    // Driving helpers
    // -------------------------------------------------------------------------

    private function migrateToCompletion(): void {
        $orch  = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'Setup must reach migrated.' );
    }

    /**
     * A FULL byte-level snapshot of every legacy row the migration must leave
     * untouched until Finalize: legacy posts (+ meta), legacy terms (+ relationships),
     * legacy comments (+ meta), legacy attachments (+ meta), and the legacy options.
     *
     * @return array<string,mixed>
     */
    private function legacySnapshot(): array {
        global $wpdb;

        $legacyPostTypes = array( LegacyIdentifiers::POST_TYPE_SERMON, LegacyIdentifiers::POST_TYPE_PODCAST );
        $placeholders    = implode( ',', array_fill( 0, count( $legacyPostTypes ), '%s' ) );

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) ORDER BY ID ASC",
                ...$legacyPostTypes
            ),
            ARRAY_A
        );
        $postIds = array_map( static fn( $r ) => (int) $r['ID'], $posts );

        $postMeta = array();
        foreach ( $postIds as $pid ) {
            $postMeta[ $pid ] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
                    $pid
                ),
                ARRAY_A
            );
        }

        $legacyTaxonomies = LegacyIdentifiers::sermonTaxonomies();
        $taxPlaceholders  = implode( ',', array_fill( 0, count( $legacyTaxonomies ), '%s' ) );
        $termTax = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, tt.count, t.name, t.slug"
                . " FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
                . " WHERE tt.taxonomy IN ( {$taxPlaceholders} ) ORDER BY tt.term_taxonomy_id ASC",
                ...$legacyTaxonomies
            ),
            ARRAY_A
        );

        $relationships = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.object_id, tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " WHERE tt.taxonomy IN ( {$taxPlaceholders} ) ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC",
                ...$legacyTaxonomies
            ),
            ARRAY_A
        );

        // NATIVE shared taxonomies (category/post_tag) the migration must NOT mutate
        // until Finalize: the church's own data. Capture (a) the count column for every
        // native term — SermonWriter mirrors a native relationship onto the NEW sermon
        // via a direct $wpdb insert WITHOUT bumping this shared count (deferred to
        // native_term_recount_tt_ids), so it must read byte-equal through migrate; and
        // (b) the legacy posts' OWN native membership rows, scoped to legacy post types
        // so the migrated mirror rows (on sermonator_* posts) are excluded — proving the
        // legacy post→category relationship is untouched up to the point of deletion.
        $nativeTaxonomies   = array( 'category', 'post_tag' );
        $nativePlaceholders = implode( ',', array_fill( 0, count( $nativeTaxonomies ), '%s' ) );
        $nativeTermTax = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.term_taxonomy_id, tt.taxonomy, tt.count FROM {$wpdb->term_taxonomy} tt"
                . " WHERE tt.taxonomy IN ( {$nativePlaceholders} ) ORDER BY tt.term_taxonomy_id ASC",
                ...$nativeTaxonomies
            ),
            ARRAY_A
        );
        $nativeRelationships = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.object_id, tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id"
                . " WHERE tt.taxonomy IN ( {$nativePlaceholders} ) AND p.post_type IN ( {$placeholders} )"
                . " ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC",
                ...array_merge( $nativeTaxonomies, $legacyPostTypes )
            ),
            ARRAY_A
        );

        $comments     = array();
        $commentMeta  = array();
        if ( $postIds !== array() ) {
            $idPlaceholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
            $comments       = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ( {$idPlaceholders} ) ORDER BY comment_ID ASC",
                    ...$postIds
                ),
                ARRAY_A
            );
            foreach ( $comments as $c ) {
                $cid = (int) $c['comment_ID'];
                $commentMeta[ $cid ] = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d ORDER BY meta_id ASC",
                        $cid
                    ),
                    ARRAY_A
                );
            }
        }

        // Legacy attachments must never be mutated (referenced by id only).
        $attachments = $wpdb->get_results(
            "SELECT * FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC",
            ARRAY_A
        );

        return array(
            'posts'               => $posts,
            'postMeta'            => $postMeta,
            'termTax'             => $termTax,
            'relationships'       => $relationships,
            'nativeTermTax'       => $nativeTermTax,
            'nativeRelationships' => $nativeRelationships,
            'comments'            => $comments,
            'commentMeta'         => $commentMeta,
            'attachments'         => $attachments,
            'options'       => array(
                'default_podcast' => get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST ),
                'general'         => get_option( 'sermonmanager_general' ),
                'sm_term_images'  => get_option( LegacyIdentifiers::OPTION_TERM_IMAGES ),
            ),
        );
    }

    private function countBackRef( string $metaKey ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $metaKey )
        );
    }

    private function countTermBackRef( string $metaKey ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s", $metaKey )
        );
    }

    private function countCommentBackRef( string $metaKey ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = %s", $metaKey )
        );
    }

    private function newPostCount( string $postType ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'auto-draft'",
                $postType
            )
        );
    }

    private function newCommentCount( int $postId ): int {
        return (int) get_comments( array(
            'post_id' => $postId,
            'status'  => 'any',
            'count'   => true,
        ) );
    }

    /** The stored shared count for a native (category/post_tag) term taxonomy row. */
    private function sharedCategoryCount( int $ttId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
        );
    }

    // -------------------------------------------------------------------------
    // 1) Happy path: continuity + zero legacy mutation + state verified
    // -------------------------------------------------------------------------

    public function test_happy_path_continuity_and_zero_legacy_mutation(): void {
        $data   = $this->seedRichDataset();
        $before = $this->legacySnapshot();

        $orch     = new Orchestrator();
        $manifest = $orch->detect();

        // Drive to migrated.
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // Verify → complete, state verified.
        $report = ( new Verifier() )->verify( $manifest );
        $this->assertSame( array(), $report->drift, 'No drift on a clean migrate.' );
        $this->assertSame( array(), $report->missing, 'No missing counterpart on a clean migrate.' );
        $this->assertSame( array(), $report->openFlags, 'No open failure flag on a clean migrate.' );
        $this->assertTrue( $report->complete, 'A clean full migrate must verify complete.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );

        // INVARIANT: ZERO legacy mutation up to Finalize.
        $this->assertEquals( $before, $this->legacySnapshot(),
            'Legacy data must be byte-equal after detect→migrate→verify.' );

        // ---- Field-by-field continuity through the crosswalk ----

        // Counts per taxonomy = manifest.
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $legacyTax ) {
            $targetTax = MappingContract::taxonomyMap()[ $legacyTax ];
            $legacyCount = (int) $manifest->count( 'terms_' . $legacyTax );
            $migrated = 0;
            foreach ( get_terms( array( 'taxonomy' => $legacyTax, 'hide_empty' => false ) ) as $lt ) {
                if ( Crosswalk::findNewTermByLegacyId( (int) $lt->term_id, $targetTax ) !== null ) {
                    $migrated++;
                }
            }
            $this->assertSame( $legacyCount, $migrated,
                "Every legacy {$legacyTax} term must have a migrated counterpart." );
        }

        // Term name/slug/description carried (preacher).
        $newPreacher = Crosswalk::findNewTermByLegacyId( $data['preacher'], Identifiers::TAX_PREACHER );
        $this->assertNotNull( $newPreacher );
        $newPreacherTerm = get_term( $newPreacher, Identifiers::TAX_PREACHER );
        $this->assertSame( 'Pastor Bob', $newPreacherTerm->name );
        $this->assertSame( 'pastor-bob', $newPreacherTerm->slug );
        $this->assertSame( 'The senior pastor.', $newPreacherTerm->description );

        // Orphan term migrated.
        $this->assertNotNull( Crosswalk::findNewTermByLegacyId( $data['orphanTopic'], Identifiers::TAX_TOPIC ),
            'An orphan (unreferenced) term must still migrate.' );

        // Slug-collision term: new slug DIFFERS from the native-owned slug, and the
        // ORIGINAL legacy slug is preserved in LEGACY_SLUG.
        $newColliding = Crosswalk::findNewTermByLegacyId( $data['collidingSeries'], Identifiers::TAX_SERIES );
        $this->assertNotNull( $newColliding );
        $newCollidingTerm = get_term( $newColliding, Identifiers::TAX_SERIES );
        $this->assertNotSame( $data['nativeSeriesSlug'], $newCollidingTerm->slug,
            'A slug-colliding term must be disambiguated to a NEW slug.' );
        $this->assertSame( $data['nativeSeriesSlug'],
            (string) get_term_meta( $newColliding, Crosswalk::LEGACY_SLUG, true ),
            'The ORIGINAL legacy slug must be preserved in LEGACY_SLUG.' );

        // Artwork: old tt_id → new tt_id → SAME attachment id.
        $ttMap     = ( new TermCrosswalk() )->ttIdMap();
        $this->assertArrayHasKey( $data['preacherTtId'], $ttMap, 'Legacy preacher tt_id must remap.' );
        $newTtId   = $ttMap[ $data['preacherTtId'] ];
        $newArtwork = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertIsArray( $newArtwork );
        $this->assertArrayHasKey( $newTtId, $newArtwork, 'Artwork must be keyed by the NEW tt_id.' );
        $this->assertSame( $data['attachmentId'], (int) $newArtwork[ $newTtId ],
            'Remapped artwork must point at the SAME attachment id.' );

        // Serialized array meta intact (as an array) + custom/unknown key verbatim.
        $newSerialized = Crosswalk::findNewByLegacyId( $data['serializedSermon'], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newSerialized );
        $this->assertSame( $data['serializedPayload'],
            get_post_meta( $newSerialized, 'custom_payload', true ),
            'Serialized array meta must round-trip as an array.' );
        $this->assertSame( $data['customVal'],
            get_post_meta( $newSerialized, $data['customKey'], true ),
            'A custom/unknown meta key must pass through verbatim.' );

        // Threaded comments: both copied onto the new first sermon; the reply's
        // comment_parent remapped to the NEW root comment id.
        $newFirstSermon = Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newFirstSermon );
        $newComments = get_comments( array(
            'post_id' => $newFirstSermon,
            'status'  => 'any',
            'orderby' => 'comment_ID',
            'order'   => 'ASC',
        ) );
        $this->assertCount( 2, $newComments, 'Both threaded comments must be copied.' );
        $newRoot = null;
        $newChild = null;
        foreach ( $newComments as $c ) {
            $legacyCid = (int) get_comment_meta( $c->comment_ID, Crosswalk::LEGACY_COMMENT_ID, true );
            if ( $legacyCid === $data['rootComment'] ) {
                $newRoot = $c;
            } elseif ( $legacyCid === $data['childComment'] ) {
                $newChild = $c;
            }
        }
        $this->assertNotNull( $newRoot, 'The root comment must be copied with a back-ref.' );
        $this->assertNotNull( $newChild, 'The child comment must be copied with a back-ref.' );
        $this->assertSame( (int) $newRoot->comment_ID, (int) $newChild->comment_parent,
            'The reply must be re-parented to the NEW root comment id.' );

        // Temp-only body PRESERVED: post_content_temp is its single canonical home,
        // copied verbatim as its own meta row (the reconciler never routes it into
        // post_content nor double-homes it into the backup body — design-notes
        // gating-1). Nothing in that body is ever silently dropped.
        $newTempSermon = Crosswalk::findNewByLegacyId( $data['tempSermon'], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newTempSermon );
        $this->assertSame( array( '<p>Body only in temp.</p>' ),
            get_post_meta( $newTempSermon, LegacyIdentifiers::META_POST_CONTENT_TEMP, false ),
            'A temp-only body must be preserved verbatim as its canonical post_content_temp row.' );

        // Non-numeric date: a normalized companion is written; the RAW is untouched
        // on the target too (verbatim copy).
        $newDateSermon = Crosswalk::findNewByLegacyId( $data['dateSermon'], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newDateSermon );
        $this->assertSame( 'March 5, 2021',
            (string) get_post_meta( $newDateSermon, Identifiers::META_DATE, true ),
            'The raw non-numeric date must be copied verbatim.' );
        $normalized = (int) get_post_meta( $newDateSermon, Identifiers::META_DATE_NORMALIZED, true );
        $this->assertGreaterThan( 0, $normalized,
            'A normalized companion timestamp must be written for a non-numeric date.' );
        $this->assertContains( 'legacy_nonnumeric_date',
            (array) get_post_meta( $newDateSermon, Crosswalk::MIGRATION_FLAGS, true ),
            'A non-numeric date must record the legacy_nonnumeric_date flag.' );

        // Default-podcast pointer resolves to the NEW podcast id.
        $newPodcast = Crosswalk::findNewByLegacyId( $data['podcast'], Identifiers::POST_TYPE_PODCAST );
        $this->assertNotNull( $newPodcast );
        $this->assertSame( $newPodcast, (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST ),
            'The default-podcast option must resolve to the new podcast id.' );
    }

    // -------------------------------------------------------------------------
    // 2) Idempotent full re-run is a clean no-op
    // -------------------------------------------------------------------------

    public function test_idempotent_full_rerun_is_a_clean_no_op(): void {
        $data = $this->seedRichDataset();

        $this->migrateToCompletion();

        // Snapshot the migrated-target shape after the FIRST full migrate.
        $sermonCount  = $this->newPostCount( Identifiers::POST_TYPE_SERMON );
        $podcastCount = $this->newPostCount( Identifiers::POST_TYPE_PODCAST );
        $termCount    = $this->countTermBackRef( Crosswalk::LEGACY_TERM_ID );
        $postBackRefs = $this->countBackRef( Crosswalk::LEGACY_POST_ID );

        $newFirstSermon = Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newFirstSermon );
        $commentCount = $this->newCommentCount( $newFirstSermon );
        $metaRows     = count( get_post_meta( $newFirstSermon ) );

        // Re-run the WHOLE lifecycle from a fresh Orchestrator (re-detect + re-run).
        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );

        // No duplicate posts/terms/comments/meta/back-refs.
        $this->assertSame( $sermonCount, $this->newPostCount( Identifiers::POST_TYPE_SERMON ),
            'Re-run must not duplicate sermons.' );
        $this->assertSame( $podcastCount, $this->newPostCount( Identifiers::POST_TYPE_PODCAST ),
            'Re-run must not duplicate podcasts.' );
        $this->assertSame( $termCount, $this->countTermBackRef( Crosswalk::LEGACY_TERM_ID ),
            'Re-run must not duplicate term back-refs.' );
        $this->assertSame( $postBackRefs, $this->countBackRef( Crosswalk::LEGACY_POST_ID ),
            'Re-run must not duplicate post back-refs.' );
        $this->assertSame( $commentCount, $this->newCommentCount( $newFirstSermon ),
            'Re-run must not duplicate comments.' );
        $this->assertSame( $metaRows, count( get_post_meta( $newFirstSermon ) ),
            'Re-run must not duplicate meta rows.' );
    }

    // -------------------------------------------------------------------------
    // 3) Rollback path → pristine start
    // -------------------------------------------------------------------------

    public function test_rollback_returns_to_pristine_start(): void {
        $data   = $this->seedRichDataset();
        $before = $this->legacySnapshot();
        // The B2a HARD CONSTRAINT, exercised full-cycle: the church's SHARED native
        // category count must be restored to its TRUE pre-migration value — rollback
        // strips the mirrored native relationships directly via $wpdb and recounts once,
        // NEVER decrementing the shared count below its true value.
        $nativeCountBefore = $this->sharedCategoryCount( $data['nativeCatTtId'] );

        $this->migrateToCompletion();

        // Sanity: there ARE migrated records + a backed-up native option.
        $this->assertGreaterThan( 0, $this->countBackRef( Crosswalk::LEGACY_POST_ID ) );
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertIsArray( $backup );
        $this->assertArrayHasKey( Identifiers::OPTION_TERM_IMAGES, $backup );

        $rollback = new Rollback();
        $rollback->run();

        // ZERO back-refs remain anywhere.
        $this->assertSame( 0, $this->countBackRef( Crosswalk::LEGACY_POST_ID ) );
        $this->assertSame( 0, $this->countTermBackRef( Crosswalk::LEGACY_TERM_ID ) );
        $this->assertSame( 0, $this->countCommentBackRef( Crosswalk::LEGACY_COMMENT_ID ) );
        $this->assertSame( 0, $this->countBackRef( Crosswalk::MIGRATION_COMPLETE ) );

        // Migrated posts gone.
        $this->assertSame( array(), Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );
        $this->assertSame( array(), Crosswalk::migratedPostIds( Identifiers::POST_TYPE_PODCAST ) );

        // The backed-up native artwork option restored to its native value; the
        // migration-created default-podcast option removed.
        $this->assertSame( array( 'native' => 'preexisting' ), get_option( Identifiers::OPTION_TERM_IMAGES ) );
        $this->assertFalse( get_option( Identifiers::OPTION_DEFAULT_PODCAST, false ) );

        // State retreated → detected.
        $this->assertSame( 'detected', ( new MigrationState() )->phase() );

        // HARD CONSTRAINT: the shared native category count is back to its TRUE value
        // (never decremented below it by the rollback of the mirrored relationships).
        $this->assertSame( $nativeCountBefore, $this->sharedCategoryCount( $data['nativeCatTtId'] ),
            'Shared native category count must be restored to its TRUE pre-migration value after rollback.' );

        // INVARIANT: legacy byte-equal — pristine start. The snapshot now ALSO covers
        // the native (category/post_tag) count column and the legacy posts' own native
        // membership rows, so this byte-equal proves the church's shared-taxonomy data
        // is untouched through the whole migrate→rollback cycle.
        $this->assertEquals( $before, $this->legacySnapshot(),
            'Legacy data must be byte-equal (pristine) after rollback.' );

        // And a fresh full migrate after rollback succeeds again (pristine restart).
        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(),
            'A re-migrate after rollback must reach migrated again.' );
        $this->assertNotNull(
            Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON ),
            'The re-migrate must produce a fresh counterpart.' );
    }

    // -------------------------------------------------------------------------
    // 4) Finalize path → gated, then destructive (legacy content preserved)
    // -------------------------------------------------------------------------

    public function test_finalize_is_gated_then_deletes_only_verified_legacy(): void {
        $data = $this->seedRichDataset();

        $this->migrateToCompletion();

        // Finalize REFUSES until verified.
        $premature = ( new Finalizer() )->run( true );
        $this->assertIsString( $premature['refused'], 'Finalize must refuse before verify.' );
        $this->assertNotNull( get_post( $data['sermons'][0] ), 'Nothing deleted on a refused finalize.' );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // Verify → verified.
        $report = ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        $this->assertTrue( $report->complete );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );

        // The temp-only sermon's preserved divergent body must survive the strip.
        $newTempSermon = Crosswalk::findNewByLegacyId( $data['tempSermon'], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newTempSermon );
        $preservedBefore = (string) get_post_meta( $newTempSermon, Crosswalk::LEGACY_POST_CONTENT, true );
        $this->assertNotSame( '', $preservedBefore, 'Setup: a divergent body must be preserved.' );

        // Finalize (verified + fresh + confirmed) → deletes verified legacy.
        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'], 'A verified, fresh, confirmed finalize must run.' );

        // Verified legacy posts/options gone.
        foreach ( array_merge( $data['sermons'], array( $data['tempSermon'], $data['dateSermon'], $data['serializedSermon'], $data['podcast'] ) ) as $legacyId ) {
            $this->assertNull( get_post( $legacyId ), "Verified legacy id {$legacyId} must be deleted." );
        }
        $this->assertFalse( get_option( 'sermonmanager_general', false ),
            'A migrated legacy option must be deleted at finalize.' );

        // Migrated records survive; allowlist stripped; preserved content survives.
        $this->assertInstanceOf( \WP_Post::class, get_post( $newTempSermon ) );
        $this->assertSame( '', (string) get_post_meta( $newTempSermon, Crosswalk::LEGACY_POST_ID, true ),
            'LEGACY_POST_ID must be stripped at finalize.' );
        $this->assertSame( $preservedBefore,
            (string) get_post_meta( $newTempSermon, Crosswalk::LEGACY_POST_CONTENT, true ),
            '_sermonator_legacy_post_content must survive finalize byte-equal.' );

        // HARD CONSTRAINT at the point of no return: the deferred native shared count
        // is recounted EXACTLY ONCE at finalize, settling to its true authoritative
        // value (what a fresh canonical WP recount yields — never left stale, never
        // double-bumped). The legacy post that carried the membership is now deleted,
        // so the authoritative value reflects that.
        $storedNativeCount = $this->sharedCategoryCount( $data['nativeCatTtId'] );
        wp_update_term_count_now( array( $data['nativeCatTtId'] ), 'category' );
        $authoritativeNativeCount = $this->sharedCategoryCount( $data['nativeCatTtId'] );
        $this->assertSame( $authoritativeNativeCount, $storedNativeCount,
            'Native shared count must be recounted to its true authoritative value at finalize (never left stale).' );

        // State → finalized; Rollback now refuses.
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
        $rollback = ( new Rollback() )->run();
        $this->assertNotEmpty( $rollback['warnings'], 'Rollback must refuse after finalize.' );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // 5) Drift oracle catches a post-detect legacy edit
    // -------------------------------------------------------------------------

    public function test_drift_oracle_catches_post_detect_legacy_edit(): void {
        $data = $this->seedRichDataset();

        $orch     = new Orchestrator();
        $manifest = $orch->detect();

        // Edit a legacy meta value AFTER detect but before migrate — the source
        // fixity has drifted from the manifest checksum.
        update_post_meta( $data['sermons'][0], LegacyIdentifiers::META_BIBLE_PASSAGE, 'Romans 8:28 (edited after detect)' );

        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        $report = ( new Verifier() )->verify( $manifest );
        $this->assertNotEmpty( $report->drift, 'A post-detect legacy edit must surface as drift.' );
        $this->assertContains( $data['sermons'][0], $report->drift,
            'The drifted legacy sermon id must be flagged.' );
        $this->assertFalse( $report->complete, 'Drift must withhold completeness.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase(),
            'State must NOT advance to verified while drift is open.' );
    }

    // -------------------------------------------------------------------------
    // 6) Crash-injection mid-sermon-batch then resume → no duplicates
    // -------------------------------------------------------------------------

    public function test_crash_mid_sermon_batch_then_resume_no_duplicates(): void {
        $data   = $this->seedRichDataset();
        $before = $this->legacySnapshot();

        $orch = new Orchestrator();
        $orch->detect();

        // Advance until the terms gate is open (so the sermon phase can run), but stop
        // before sermons complete.
        $guard = 0;
        while ( ! ( new MigrationState() )->phaseComplete( 'terms' ) && $guard < 50 ) {
            $orch->run( 50 );
            $guard++;
        }
        $this->assertTrue( ( new MigrationState() )->phaseComplete( 'terms' ),
            'Setup must complete the terms gate before the sermon batch.' );

        // Inject a crash on the FIRST sermon insert of the batch: wp_insert_post fires
        // save_post for the new sermonator_sermon; throwing there aborts run() AFTER
        // the writer has written the crash-safety back-ref (markLegacy uses meta_input
        // in the same insert) but BEFORE MIGRATION_COMPLETE — a stamped-but-partial
        // record the resume must redo, never duplicate.
        $thrown = false;
        $boom   = static function ( $postId, $post ) {
            if ( $post instanceof \WP_Post && $post->post_type === Identifiers::POST_TYPE_SERMON ) {
                throw new \RuntimeException( 'injected crash mid sermon insert @ ' . $postId );
            }
        };
        add_action( 'save_post', $boom, 10, 2 );
        try {
            // Loop run() until the crash fires (terms/artwork/podcast phases run first,
            // then the sermon batch trips the boom).
            $crashGuard = 0;
            while ( ! $thrown && $crashGuard < 50 ) {
                try {
                    $orch->run( 50 );
                } catch ( \RuntimeException $e ) {
                    $thrown = true;
                }
                $crashGuard++;
            }
        } finally {
            remove_action( 'save_post', $boom, 10 );
        }
        $this->assertTrue( $thrown, 'The injected mid-sermon-batch crash must have fired.' );

        // Resume with a FRESH orchestrator: drive to migrated.
        $resume = new Orchestrator();
        $guard  = 0;
        do {
            $progress = $resume->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(),
            'A fresh run() after the crash must resume to migrated.' );

        // EXACTLY one migrated counterpart per legacy sermon — no duplicate from the
        // partial.
        foreach ( array_merge( $data['sermons'], array( $data['tempSermon'], $data['dateSermon'], $data['serializedSermon'] ) ) as $legacyId ) {
            $this->assertSame( 1, Crosswalk::countNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON ),
                "Legacy sermon {$legacyId} must map to EXACTLY one counterpart after crash-resume." );
        }

        // No duplicate comments on the first sermon's counterpart.
        $newFirstSermon = Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newFirstSermon );
        $this->assertSame( 2, $this->newCommentCount( $newFirstSermon ),
            'Crash-resume must not duplicate the threaded comments.' );

        // A clean verify after the resume.
        $report = ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        $this->assertTrue( $report->complete, 'A crash-resumed migrate must still verify complete.' );

        // INVARIANT: legacy byte-equal across the crash + resume.
        $this->assertEquals( $before, $this->legacySnapshot(),
            'Legacy data must be byte-equal after a crash + resume.' );
    }

    // -------------------------------------------------------------------------
    // 7) Drift oracle covers PODCASTS too (B2b review legacy-immutability-0)
    // -------------------------------------------------------------------------

    public function test_drift_oracle_catches_post_detect_legacy_podcast_edit(): void {
        // A podcast carries full post_content copied forward, so a post-detect edit to
        // the legacy podcast must be caught as drift — otherwise Finalize would
        // force-delete the only copy of that edit. Podcasts are now checksummed at
        // detect (symmetric with sermons), so this edit lands in drift[].
        $data = $this->seedRichDataset();

        $orch     = new Orchestrator();
        $manifest = $orch->detect();

        // Edit the legacy podcast body AFTER detect but before finalize.
        wp_update_post( array(
            'ID'           => $data['podcast'],
            'post_content' => '<p>Feed body EDITED after detect.</p>',
        ) );

        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        $report = ( new Verifier() )->verify( $manifest );
        $this->assertNotEmpty( $report->drift, 'A post-detect legacy PODCAST edit must surface as drift.' );
        $this->assertContains( $data['podcast'], $report->drift,
            'The drifted legacy podcast id must be flagged.' );
        $this->assertFalse( $report->complete, 'Podcast drift must withhold completeness.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase(),
            'State must NOT advance to verified while podcast drift is open.' );

        // And Finalize must refuse (GATE-2 fresh rescan also sees the podcast drift),
        // so the edited legacy podcast is NOT force-deleted.
        $result = ( new Finalizer() )->run( true );
        $this->assertIsString( $result['refused'], 'Finalize must refuse while podcast drift is open.' );
        $this->assertNotNull( get_post( $data['podcast'] ), 'The edited legacy podcast must NOT be deleted.' );
    }

    // -------------------------------------------------------------------------
    // 8) Re-detect after migration does NOT re-baseline the fixity oracle
    //    (B2b review verifier-soundness-0)
    // -------------------------------------------------------------------------

    public function test_redetect_after_migrate_does_not_poison_the_drift_oracle(): void {
        // Re-running detect() after migration must NOT overwrite the detect-time
        // manifest — otherwise it would re-pin checksums to post-edit values, silently
        // defeating the drift oracle and letting a drifted legacy record verify clean
        // and be finalized (irreversible loss of the church's true source).
        $data = $this->seedRichDataset();

        $orch     = new Orchestrator();
        $manifest = $orch->detect();

        // Edit a legacy sermon AFTER detect (drift the source from the manifest).
        update_post_meta( $data['sermons'][0], LegacyIdentifiers::META_BIBLE_PASSAGE, 'Romans 8:28 (edited after detect)' );

        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // The attack: re-run detect() at the 'migrated' phase. It must be a SAFE no-op
        // that returns the immutable detect-time manifest unchanged — never re-baseline.
        $reManifest = $orch->detect();
        $this->assertSame(
            $manifest->checksum( $data['sermons'][0] ),
            $reManifest->checksum( $data['sermons'][0] ),
            'Re-detect must NOT re-pin the checksum to the post-edit value.'
        );

        // Verify against the (still-immutable) stored manifest → drift STILL caught.
        $report = ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        $this->assertContains( $data['sermons'][0], $report->drift,
            'Drift must still be caught after a post-migration re-detect.' );
        $this->assertFalse( $report->complete, 'A re-detect must not be able to manufacture a false GREEN.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );

        // Finalize must refuse — the drifted legacy sermon is NOT deleted.
        $result = ( new Finalizer() )->run( true );
        $this->assertIsString( $result['refused'] );
        $this->assertNotNull( get_post( $data['sermons'][0] ),
            'The drifted legacy sermon must NOT be force-deleted.' );
    }

    // -------------------------------------------------------------------------
    // 9) Rollback from the 'verified' phase retreats to detected (B2b review
    //    rollback-correctness-0) — no wedge, re-migrate works, legacy intact
    // -------------------------------------------------------------------------

    public function test_rollback_from_verified_phase_retreats_and_re_migrates(): void {
        $data   = $this->seedRichDataset();
        $before = $this->legacySnapshot();

        $this->migrateToCompletion();

        // Reach the normal pre-finalize review state.
        $report = ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        $this->assertTrue( $report->complete );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );

        // A legitimate review-then-reject: rollback BEFORE finalizing. It must NOT refuse
        // (legacy is byte-intact at 'verified') and MUST retreat the phase to 'detected'.
        $result = ( new Rollback() )->run();
        $this->assertSame( array(), $result['warnings'], 'Rollback from verified must not refuse or warn.' );
        $this->assertSame( 'detected', ( new MigrationState() )->phase(),
            'Rollback from verified must retreat the lifecycle to detected (no wedge).' );

        // Zero migrated data + legacy byte-equal (pristine), exactly like other rollbacks.
        $this->assertSame( 0, $this->countBackRef( Crosswalk::LEGACY_POST_ID ) );
        $this->assertSame( array(), Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );
        $this->assertEquals( $before, $this->legacySnapshot(),
            'Legacy data must be byte-equal (pristine) after a rollback from verified.' );

        // And a fresh full migrate after the verified-phase rollback succeeds again.
        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(),
            'A re-migrate after a verified-phase rollback must reach migrated again.' );
        $this->assertNotNull(
            Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON ),
            'The re-migrate must produce a fresh counterpart.' );
    }
}
