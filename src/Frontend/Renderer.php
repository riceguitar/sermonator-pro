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
            return '<div class="sermonator-video">'
                . '<a href="' . esc_url( $v->videoUrl ) . '">' . esc_html( $v->videoUrl ) . '</a>'
                . '</div>';
        }
        return '';
    }

    public function dateLabel( SermonView $v ): string {
        if ( $v->preachedTimestamp === null ) {
            return '';
        }
        return (string) wp_date( (string) get_option( 'date_format' ), $v->preachedTimestamp );
    }

    /**
     * Allowed HTML for a stored video embed. wp_kses_post() strips <iframe>, which would
     * silently delete YouTube/Vimeo embeds, so we extend the post allowlist with iframe (and
     * <video>/<source> for self-hosted) limited to safe, embed-relevant attributes.
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
            'style'           => true,
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
            'style'    => true,
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
