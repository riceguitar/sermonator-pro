<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * The ONLY front-end class that builds piece-HTML. Pure: a {@see SermonView} in, an escaped
 * HTML string out. Every dynamic value is escaped at this boundary (esc_html / esc_url /
 * esc_attr; stored video embed via wp_kses_post). Absent fields are omitted entirely — a
 * missing value never renders an empty row or the literal "0".
 */
final class Renderer {
    public function meta( SermonView $v ): string {
        $rows = '';

        if ( $v->biblePassage !== '' ) {
            $rows .= $this->row( 'passage', __( 'Scripture', 'sermonator' ), esc_html( $v->biblePassage ) );
        }
        $preacherLabel = $v->preacherLabel !== '' ? $v->preacherLabel : __( 'Preacher', 'sermonator' );
        $rows         .= $this->termRow( 'preacher', $preacherLabel, $v->preachers );
        $rows .= $this->termRow( 'series', __( 'Series', 'sermonator' ), $v->series );

        $date = $this->dateLabel( $v );
        if ( $date !== '' ) {
            $rows .= $this->row( 'date', __( 'Date', 'sermonator' ), esc_html( $date ) );
        }

        $rows .= $this->termRow( 'service-type', __( 'Service', 'sermonator' ), $v->serviceTypes );
        $rows .= $this->termRow( 'book', __( 'Book', 'sermonator' ), $v->books );
        $rows .= $this->termRow( 'topic', __( 'Topics', 'sermonator' ), $v->topics );

        if ( $rows === '' ) {
            return '';
        }
        return '<dl class="sermonator-meta">' . $rows . '</dl>';
    }

    /**
     * Render the resolved scripture references as a dedicated section.
     *
     * PURE, like every method here: a {@see SermonView} plus an already-resolved
     * {@see ResolvedScripture} in, an escaped HTML string out. ALL resolution
     * (option reads, meta reads, validation) happens OUTSIDE this method, in the
     * impure {@see BibleResolver} called at template time.
     *
     * Fail-open contract: `null` (or an empty ResolvedScripture) → '' so the
     * existing escaped meta() "Scripture" row stays byte-identical and this
     * feature can never ship a regression. meta() is UNCHANGED — this is an
     * additive section, NOT a meta-row mutation.
     *
     * Phase 3a is LINK mode: per ref, an `<a>` to the axis-A link version
     * (Bible Gateway) plus a visible version badge (e.g. "(ESV)") that exists
     * ONLY on this resolved path. Every leaf is escaped here (esc_html / esc_url);
     * the markup is constructed tag-by-tag — never wp_kses, which is for
     * untrusted stored HTML, not values we build ourselves.
     */
    public function scripture( SermonView $v, ?ResolvedScripture $s ): string {
        unset( $v ); // Part of the pure contract (SermonView in); unused in link mode.

        if ( $s === null || $s->isEmpty() ) {
            return '';
        }

        $items = '';
        foreach ( $s->refs() as $ref ) {
            $items .= '<li class="sermonator-scripture__ref">'
                . '<a class="sermonator-scripture__link" href="' . esc_url( $ref['linkUrl'] ) . '">'
                . esc_html( $ref['label'] )
                . '</a>'
                . ' <span class="sermonator-scripture__version">(' . esc_html( $ref['version'] ) . ')</span>'
                . '</li>';
        }

        return '<section class="sermonator-scripture">'
            . '<h2 class="sermonator-scripture__heading">' . esc_html__( 'Scripture', 'sermonator' ) . '</h2>'
            . '<ul class="sermonator-scripture__list">' . $items . '</ul>'
            . '</section>';
    }

    public function audioPlayer( SermonView $v ): string {
        if ( $v->audioUrl === '' ) {
            return '';
        }
        $url = esc_url( $v->audioUrl );
        $dur = $v->audioDuration !== '' ? ' data-duration="' . esc_attr( $v->audioDuration ) . '"' : '';

        return '<div class="sermonator-audio"' . $dur . '>'
            . '<audio class="sermonator-audio__el" controls preload="metadata" src="' . $url . '"></audio>'
            . '<a class="sermonator-audio__download" href="' . $url . '" download>'
            . esc_html__( 'Download', 'sermonator' ) . '</a>'
            . '</div>';
    }

    public function video( SermonView $v ): string {
        if ( $v->videoEmbed !== '' ) {
            return '<div class="sermonator-video">' . wp_kses( $v->videoEmbed, $this->allowedVideoHtml() ) . '</div>';
        }
        if ( $v->videoUrl !== '' ) {
            // Prefer an inline oEmbed player (cached by WP; only known providers resolve);
            // fall back to a plain link when the URL is not oEmbeddable.
            $oembed = wp_oembed_get( $v->videoUrl );
            $inner  = ( is_string( $oembed ) && $oembed !== '' )
                ? $oembed
                : '<a href="' . esc_url( $v->videoUrl ) . '">' . esc_html( $v->videoUrl ) . '</a>';
            return '<div class="sermonator-video">' . $inner . '</div>';
        }
        return '';
    }


    public function featuredImage( SermonView $v ): string {
        if ( $v->imageId > 0 ) {
            // Real post thumbnail (responsive srcset via the post-thumbnail API).
            $img = get_the_post_thumbnail( $v->id, 'large', array( 'class' => 'sermonator-single__image', 'loading' => 'eager' ) );
        } elseif ( $v->effectiveImageId > 0 ) {
            // No thumbnail: render the configured site-wide default image (legacy
            // `default_image` parity). The id is resolved impurely in TemplateData
            // ({@see EffectiveImage}) so this method stays free of get_option.
            $img = wp_get_attachment_image(
                $v->effectiveImageId,
                'large',
                false,
                array( 'class' => 'sermonator-single__image', 'loading' => 'eager' )
            );
        } else {
            return '';
        }
        return is_string( $img ) && $img !== '' ? '<figure class="sermonator-single__media">' . $img . '</figure>' : '';
    }

    public function bulletin( SermonView $v ): string {
        if ( $v->bulletinUrl === '' ) {
            return '';
        }
        $url = esc_url( $v->bulletinUrl );
        return '<div class="sermonator-bulletin">'
            . '<a class="button" href="' . $url . '" download>'
            . esc_html__( 'Download bulletin', 'sermonator' )
            . '</a></div>';
    }

    public function notes( SermonView $v ): string {
        if ( $v->notes === '' ) {
            return '';
        }
        $url = esc_url( $v->notes );
        return '<div class="sermonator-notes">'
            . '<a class="button" href="' . $url . '" download>'
            . esc_html__( 'Download sermon notes', 'sermonator' )
            . '</a></div>';
    }

    public function dateLabel( SermonView $v ): string {
        if ( $v->preachedTimestamp === null ) {
            return '';
        }
        return (string) wp_date( (string) get_option( 'date_format' ), $v->preachedTimestamp );
    }

    /** A compact sermon card for archive/grid lists. */
    public function card( SermonView $v ): string {
        $thumb = get_the_post_thumbnail( $v->id, 'medium', array( 'class' => 'sermonator-card__thumb', 'loading' => 'lazy' ) );
        $thumb = is_string( $thumb ) ? $thumb : '';

        $title = '<a href="' . esc_url( $v->permalink ) . '">' . esc_html( $v->title ) . '</a>';

        $lines = '';
        $date  = $this->dateLabel( $v );
        if ( $date !== '' ) {
            $lines .= '<span class="sermonator-card__date">' . esc_html( $date ) . '</span>';
        }
        if ( $v->preachers !== array() ) {
            $names  = array_map( static fn( $p ) => $p['name'], $v->preachers );
            $lines .= '<span class="sermonator-card__preacher">' . esc_html( implode( ', ', $names ) ) . '</span>';
        }
        if ( $v->biblePassage !== '' ) {
            $lines .= '<span class="sermonator-card__passage">' . esc_html( $v->biblePassage ) . '</span>';
        }
        $badge = $v->audioUrl !== ''
            ? '<span class="sermonator-card__badge" aria-label="' . esc_attr__( 'Has audio', 'sermonator' ) . '">♪</span>'
            : '';

        return '<article class="sermonator-card">'
            . ( $thumb !== '' ? '<a class="sermonator-card__media" href="' . esc_url( $v->permalink ) . '">' . $thumb . '</a>' : '' )
            . '<div class="sermonator-card__body">'
            . '<h3 class="sermonator-card__title">' . $title . $badge . '</h3>'
            . ( $lines !== '' ? '<div class="sermonator-card__meta">' . $lines . '</div>' : '' )
            . '</div>'
            . '</article>';
    }

    /**
     * A grid of sermon cards with an empty-state. Embedded grids (block/shortcode) show a
     * fixed count and do NOT paginate — paginated browsing is the job of the sermon archive
     * (which uses the theme's own pagination on the main query). This avoids generating
     * pagination links that cannot resolve for a secondary query on a static page.
     *
     * @param array{columns?:int} $opts
     */
    public function grid( QueryResult $result, array $opts = array() ): string {
        if ( $result->isEmpty() ) {
            return '<p class="sermonator-grid__empty">' . esc_html__( 'No sermons found.', 'sermonator' ) . '</p>';
        }
        $columns = isset( $opts['columns'] ) ? max( 1, min( 6, (int) $opts['columns'] ) ) : 3;

        $cards = '';
        foreach ( $result->sermons as $view ) {
            $cards .= $this->card( $view );
        }

        return '<div class="sermonator-grid" data-columns="' . esc_attr( (string) $columns ) . '">' . $cards . '</div>';
    }

    /**
     * A list of taxonomy term links (used by the taxonomy-filter block). Pure: takes
     * already-resolved {name,url} pairs.
     *
     * @param list<array{name:string,url:string,count:int}> $terms
     */
    public function taxonomyLinks( array $terms, string $label = '', bool $showCount = true ): string {
        if ( $terms === array() ) {
            return '';
        }
        $items = '';
        foreach ( $terms as $t ) {
            $text = esc_html( $t['name'] );
            if ( $showCount && $t['count'] > 0 ) {
                $text .= ' <span class="sermonator-termlist__count">(' . esc_html( (string) $t['count'] ) . ')</span>';
            }
            $items .= $t['url'] !== ''
                ? '<li><a href="' . esc_url( $t['url'] ) . '">' . $text . '</a></li>'
                : '<li>' . $text . '</li>';
        }
        $heading = $label !== '' ? '<h2 class="sermonator-termlist__label">' . esc_html( $label ) . '</h2>' : '';
        return '<nav class="sermonator-termlist">' . $heading . '<ul>' . $items . '</ul></nav>';
    }

    /**
     * Subscribe links for a podcast (RSS / Apple / Spotify …). Pure: takes resolved
     * {label,url,service} entries.
     *
     * @param list<array{label:string,url:string,service:string}> $links
     */
    public function subscribeLinks( array $links, string $label = '' ): string {
        if ( $links === array() ) {
            return '';
        }
        $buttons = '';
        foreach ( $links as $link ) {
            $buttons .= '<a class="sermonator-subscribe__link sermonator-subscribe__link--' . esc_attr( $link['service'] ) . '"'
                . ' href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
        }
        $heading = $label !== '' ? '<span class="sermonator-subscribe__label">' . esc_html( $label ) . '</span>' : '';
        return '<div class="sermonator-subscribe">' . $heading . $buttons . '</div>';
    }

    /**
     * Allowed HTML for a stored video embed. Delegates to the shared policy so the authoring
     * layer and the renderer can never drift.
     *
     * @return array<string,array<string,bool>>
     */
    private function allowedVideoHtml(): array {
        return \Sermonator\Schema\VideoEmbedPolicy::allowed();
    }

    private function row( string $key, string $label, string $valueHtml ): string {
        return '<div class="sermonator-meta__' . esc_attr( $key ) . '">'
            . '<dt>' . esc_html( $label ) . '</dt><dd>' . $valueHtml . '</dd></div>';
    }

    /** @param list<array{name:string,url:string}> $terms */
    private function termRow( string $key, string $label, array $terms ): string {
        if ( $terms === array() ) {
            return '';
        }
        $links = array();
        foreach ( $terms as $t ) {
            $links[] = $t['url'] !== ''
                ? '<a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['name'] ) . '</a>'
                : esc_html( $t['name'] );
        }
        return $this->row( $key, $label, implode( ', ', $links ) );
    }
}
