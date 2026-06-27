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
    /**
     * Above this verse count an inline passage is wrapped in a native, collapsible
     * `<details>` so a long reading never dominates the page; shorter readings render
     * expanded inline. Purely presentational — never gates whether text shows.
     */
    private const INLINE_DETAILS_VERSE_THRESHOLD = 4;

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
     * Per ref, the {@see BibleResolver} hands us EITHER:
     *   - `inline === null` (Phase 3a LINK): an `<a>` to the axis-A link version
     *     (Bible Gateway) plus a visible version badge (e.g. "(ESV)"). When EVERY
     *     ref is null this method is BYTE-IDENTICAL to the shipped 3a output, so the
     *     inline feature can never ship a link-mode regression (pinned in tests).
     *   - `inline === {translation, attribution, verses:[{number, nodes:[{type,text}]}]}`
     *     (Phase 3b INLINE): a verse-text section with a translation attribution badge.
     *
     * Every leaf — link label/url/version AND every inline verse node — is escaped
     * here with esc_html / esc_url / esc_attr. The inline nodes are TYPED values WE
     * construct ({@see ResolvedScripture} carries no raw HTML), so esc_html on each
     * leaf is the correct, stricter choice and we build the `<sup>`/`<span>` markup
     * tag-by-tag. We NEVER call wp_kses here — that is for untrusted STORED HTML, not
     * for values we assemble ourselves.
     */
    public function scripture( SermonView $v, ?ResolvedScripture $s ): string {
        unset( $v ); // Part of the pure contract (SermonView in); unused.

        if ( $s === null || $s->isEmpty() ) {
            return '';
        }

        $items = '';
        foreach ( $s->refs() as $ref ) {
            $inline = $ref['inline'] ?? null;

            if ( is_array( $inline ) && isset( $inline['verses'] ) && is_array( $inline['verses'] ) && $inline['verses'] !== array() ) {
                $items .= $this->scriptureInlineItem( $ref, $inline );
            } else {
                // 3a LINK path — kept verbatim so the all-null case stays byte-identical.
                $items .= '<li class="sermonator-scripture__ref">'
                    . '<a class="sermonator-scripture__link" href="' . esc_url( $ref['linkUrl'] ) . '">'
                    . esc_html( $ref['label'] )
                    . '</a>'
                    . ' <span class="sermonator-scripture__version">(' . esc_html( $ref['version'] ) . ')</span>'
                    . '</li>';
            }
        }

        return '<section class="sermonator-scripture">'
            . '<h2 class="sermonator-scripture__heading">' . esc_html__( 'Scripture', 'sermonator' ) . '</h2>'
            . '<ul class="sermonator-scripture__list">' . $items . '</ul>'
            . '</section>';
    }

    /**
     * Render ONE ref's Phase 3b inline verse-text payload: the reference label, an
     * attribution badge (e.g. "(World English Bible)"), and each verse as a
     * `<span class="sermonator-scripture__verse">` with a numbered `<sup>` plus its
     * typed nodes. Long passages are wrapped in a collapsible native `<details>`.
     *
     * Every leaf is esc_html-escaped (the payload carries typed text, never HTML).
     *
     * @param array{label:string,linkUrl:string,version:string,inlineEligible:bool} $ref
     * @param array{translation:string,attribution:string,verses:list<array{number:int,nodes:list<array{type:string,text:string}>}>} $inline
     */
    private function scriptureInlineItem( array $ref, array $inline ): string {
        $label       = esc_html( (string) ( $ref['label'] ?? '' ) );
        $attribution = isset( $inline['attribution'] ) ? (string) $inline['attribution'] : '';
        $badge       = $attribution !== ''
            ? ' <span class="sermonator-scripture__attribution">(' . esc_html( $attribution ) . ')</span>'
            : '';

        $verses = '';
        $count  = 0;
        foreach ( $inline['verses'] as $verse ) {
            if ( ! is_array( $verse ) ) {
                continue;
            }
            $verses .= $this->scriptureVerse( $verse );
            ++$count;
        }

        $header = '<span class="sermonator-scripture__label">' . $label . '</span>' . $badge;
        $body   = '<div class="sermonator-scripture__text">' . $verses . '</div>';

        if ( $count > self::INLINE_DETAILS_VERSE_THRESHOLD ) {
            // Collapse a long reading so it never dominates the page.
            $inner = '<details class="sermonator-scripture__details">'
                . '<summary class="sermonator-scripture__summary">' . $header . '</summary>'
                . $body
                . '</details>';
        } else {
            $inner = $header . $body;
        }

        return '<li class="sermonator-scripture__ref sermonator-scripture__ref--inline">' . $inner . '</li>';
    }

    /**
     * Render one inline verse: a `<sup>` verse number followed by its typed nodes.
     *
     * @param array{number?:int,nodes?:list<array{type:string,text:string}>} $verse
     */
    private function scriptureVerse( array $verse ): string {
        $number = isset( $verse['number'] ) ? (string) (int) $verse['number'] : '';
        $num    = $number !== ''
            ? '<sup class="sermonator-scripture__num">' . esc_html( $number ) . '</sup>'
            : '';

        $nodes = '';
        if ( isset( $verse['nodes'] ) && is_array( $verse['nodes'] ) ) {
            foreach ( $verse['nodes'] as $node ) {
                if ( is_array( $node ) ) {
                    $nodes .= $this->scriptureNode( $node );
                }
            }
        }

        return '<span class="sermonator-scripture__verse">' . $num . $nodes . '</span>';
    }

    /**
     * Render one typed inline node, escaping its text leaf with esc_html (NEVER
     * wp_kses): `wordsOfJesus` → a styled `<span>`, `note` → a `<sup>`, and `text`
     * (or any unknown type, conservatively) → the plain escaped text. An unknown type
     * can therefore never emit raw markup — it degrades to escaped plain text.
     *
     * @param array{type?:string,text?:string} $node
     */
    private function scriptureNode( array $node ): string {
        $type = isset( $node['type'] ) && is_string( $node['type'] ) ? $node['type'] : 'text';
        $text = isset( $node['text'] ) && is_string( $node['text'] ) ? $node['text'] : '';
        $safe = esc_html( $text );

        switch ( $type ) {
            case 'wordsOfJesus':
                return '<span class="sermonator-scripture__woj">' . $safe . '</span>';
            case 'note':
                return '<sup class="sermonator-scripture__note">' . $safe . '</sup>';
            case 'text':
            default:
                return $safe;
        }
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
     * A grid of linked taxonomy-term images (used by the sermon-images block). Pure: takes
     * already-resolved {name,url,imageHtml,description} entries built OUTSIDE the renderer
     * (the block does the get_terms / OPTION_TERM_IMAGES[tt_id] / wp_get_attachment_image
     * resolution). `imageHtml` is core wp_get_attachment_image() output — already-safe HTML
     * passed through verbatim; the name is escaped (esc_html), the link esc_url'd, and the
     * term description (only when `$showDescription`) run through wp_kses_post (a curated term
     * description may carry safe inline HTML). `$showTitle` gates the per-item name. Empty
     * input → '' (empty-state).
     *
     * @param list<array{name:string,url:string,imageHtml:string,description:string}> $items
     */
    public function termImageGrid( array $items, string $label = '', int $columns = 3, bool $showTitle = true, bool $showDescription = false ): string {
        if ( $items === array() ) {
            return '';
        }
        $columns = max( 1, min( 6, $columns ) );

        $cells = '';
        foreach ( $items as $item ) {
            $name = $showTitle
                ? '<span class="sermonator-image-grid__name">' . esc_html( $item['name'] ) . '</span>'
                : '';
            $description = ( $showDescription && ( $item['description'] ?? '' ) !== '' )
                ? '<div class="sermonator-image-grid__description">' . wp_kses_post( $item['description'] ) . '</div>'
                : '';
            $inner = $item['imageHtml'] . $name . $description;
            $cells .= '<li class="sermonator-image-grid__item">'
                . ( $item['url'] !== ''
                    ? '<a href="' . esc_url( $item['url'] ) . '">' . $inner . '</a>'
                    : $inner )
                . '</li>';
        }

        $heading = $label !== ''
            ? '<h2 class="sermonator-image-grid__label">' . esc_html( $label ) . '</h2>'
            : '';
        return '<div class="sermonator-image-grid-wrap">' . $heading
            . '<ul class="sermonator-image-grid" data-columns="' . esc_attr( (string) $columns ) . '">'
            . $cells . '</ul></div>';
    }

    /**
     * The latest series as a single image + title + description card (used by the
     * latest-series block). Pure: takes ONE already-resolved
     * {name,url,imageHtml,description} entry built OUTSIDE the renderer. `imageHtml` is core
     * wp_get_attachment_image() output passed through as already-safe; the title is escaped
     * (esc_html), the link esc_url'd, and the term description run through wp_kses_post (a
     * curated term description may carry safe inline HTML). Empty input (or an entry with no
     * series name) → '' (empty-state).
     *
     * @param array{name:string,url:string,imageHtml:string,description:string} $item
     */
    public function latestSeries( array $item, bool $showTitle = true, bool $showDescription = true ): string {
        if ( ( $item['name'] ?? '' ) === '' ) {
            return '';
        }

        $image = $item['imageHtml'];
        if ( $image !== '' ) {
            $media = $item['url'] !== ''
                ? '<a class="sermonator-latest-series__media" href="' . esc_url( $item['url'] ) . '">' . $image . '</a>'
                : '<figure class="sermonator-latest-series__media">' . $image . '</figure>';
        } else {
            $media = '';
        }

        $title = '';
        if ( $showTitle ) {
            $name   = esc_html( $item['name'] );
            $titleInner = $item['url'] !== ''
                ? '<a href="' . esc_url( $item['url'] ) . '">' . $name . '</a>'
                : $name;
            $title = '<h2 class="sermonator-latest-series__title">' . $titleInner . '</h2>';
        }

        $description = '';
        if ( $showDescription && ( $item['description'] ?? '' ) !== '' ) {
            $description = '<div class="sermonator-latest-series__description">'
                . wp_kses_post( $item['description'] ) . '</div>';
        }

        return '<div class="sermonator-latest-series">' . $media . $title . $description . '</div>';
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
