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
        $rows .= $this->termRow( 'preacher', __( 'Preacher', 'sermonator' ), $v->preachers );
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
     * A grid of sermon cards with an empty-state and pagination.
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

        return '<div class="sermonator-grid" data-columns="' . esc_attr( (string) $columns ) . '">' . $cards . '</div>'
            . $this->pagination( $result );
    }

    /**
     * A list of taxonomy term links (used by the taxonomy-filter block). Pure: takes
     * already-resolved {name,url} pairs.
     *
     * @param list<array{name:string,url:string,count:int}> $terms
     */
    public function taxonomyLinks( array $terms, string $label = '' ): string {
        if ( $terms === array() ) {
            return '';
        }
        $items = '';
        foreach ( $terms as $t ) {
            $text = esc_html( $t['name'] );
            if ( $t['count'] > 0 ) {
                $text .= ' <span class="sermonator-termlist__count">(' . esc_html( (string) $t['count'] ) . ')</span>';
            }
            $items .= $t['url'] !== ''
                ? '<li><a href="' . esc_url( $t['url'] ) . '">' . $text . '</a></li>'
                : '<li>' . $text . '</li>';
        }
        $heading = $label !== '' ? '<h2 class="sermonator-termlist__label">' . esc_html( $label ) . '</h2>' : '';
        return '<nav class="sermonator-termlist">' . $heading . '<ul>' . $items . '</ul></nav>';
    }

    public function pagination( QueryResult $result ): string {
        if ( $result->totalPages <= 1 ) {
            return '';
        }
        $links = paginate_links( array(
            'total'     => $result->totalPages,
            'current'   => $result->page,
            'type'      => 'list',
            'prev_text' => esc_html__( '« Previous', 'sermonator' ),
            'next_text' => esc_html__( 'Next »', 'sermonator' ),
        ) );
        if ( ! is_string( $links ) || $links === '' ) {
            return '';
        }
        // paginate_links() returns markup that is already escaped/built by core.
        return '<nav class="sermonator-pagination" aria-label="' . esc_attr__( 'Sermons pagination', 'sermonator' ) . '">' . $links . '</nav>';
    }

    /**
     * Allowed HTML for a stored video embed. wp_kses_post() strips <iframe>, which would
     * silently delete YouTube/Vimeo embeds, so we extend the post allowlist with iframe (and
     * <video>/<source> for self-hosted) limited to safe, embed-relevant attributes.
     *
     * `style` is deliberately NOT allowed: kses does not parse CSS, so an allowed style
     * attribute would let an editor turn an iframe into a full-page invisible overlay
     * (clickjacking). Sizing is handled by width/height + the stylesheet's max-width.
     *
     * @return array<string,array<string,bool>>
     */
    private function allowedVideoHtml(): array {
        $allowed           = wp_kses_allowed_html( 'post' );
        $allowed['iframe'] = array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'title'           => true,
            'loading'         => true,
            'referrerpolicy'  => true,
            'name'            => true,
            'class'           => true,
            'sandbox'         => true,
        );
        $allowed['video']  = array(
            'src'      => true,
            'width'    => true,
            'height'   => true,
            'controls' => true,
            'preload'  => true,
            'poster'   => true,
            'class'    => true,
        );
        $allowed['source'] = array(
            'src'  => true,
            'type' => true,
        );
        return $allowed;
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
