<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

/**
 * Maps a free-text podcast category onto Apple's FIXED iTunes category taxonomy. Apple
 * rejects feeds whose itunes:category is not from this list, so we normalise to the nearest
 * valid value, defaulting to "Religion & Spirituality" (the natural home for sermons).
 */
final class ItunesCategory {
    public const DEFAULT_CATEGORY = 'Religion & Spirituality';

    /**
     * A representative subset of Apple's taxonomy covering what a church site needs, plus
     * common top-level categories. category => list of valid subcategories.
     *
     * @var array<string,list<string>>
     */
    private const TAXONOMY = array(
        'Religion & Spirituality' => array( 'Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality' ),
        'Education'               => array( 'Courses', 'How To', 'Language Learning', 'Self-Improvement' ),
        'Society & Culture'       => array( 'Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships' ),
        'Music'                   => array( 'Music Commentary', 'Music History', 'Music Interviews' ),
        'Kids & Family'           => array( 'Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids' ),
        'Arts'                    => array( 'Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts' ),
    );

    /**
     * @return array{category:string,subcategory:?string}
     */
    public static function normalize( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return array( 'category' => self::DEFAULT_CATEGORY, 'subcategory' => null );
        }

        // Exact category match.
        foreach ( self::TAXONOMY as $category => $subs ) {
            if ( strcasecmp( $raw, $category ) === 0 ) {
                return array( 'category' => $category, 'subcategory' => null );
            }
            foreach ( $subs as $sub ) {
                if ( strcasecmp( $raw, $sub ) === 0 ) {
                    return array( 'category' => $category, 'subcategory' => $sub );
                }
            }
        }

        // Heuristic: anything mentioning faith terms → Religion & Spirituality / Christianity.
        $lower = strtolower( $raw );
        if ( str_contains( $lower, 'sermon' ) || str_contains( $lower, 'church' )
            || str_contains( $lower, 'christ' ) || str_contains( $lower, 'faith' )
            || str_contains( $lower, 'gospel' ) || str_contains( $lower, 'religio' ) ) {
            return array( 'category' => self::DEFAULT_CATEGORY, 'subcategory' => 'Christianity' );
        }

        return array( 'category' => self::DEFAULT_CATEGORY, 'subcategory' => null );
    }
}
