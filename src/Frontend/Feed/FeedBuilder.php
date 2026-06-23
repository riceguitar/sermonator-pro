<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

/**
 * Builds an Apple-Podcasts-compatible RSS 2.0 feed (with the itunes: and content:
 * namespaces) from a {@see PodcastConfig} and a list of {@see FeedItem}s. PURE: no WordPress
 * dependency, no I/O — text in, XML string out — so it is fully unit-testable. All dynamic
 * text is XML-escaped; descriptions use CDATA.
 */
final class FeedBuilder {
    private const ITUNES  = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    /** @param list<FeedItem> $items */
    public function build( PodcastConfig $config, array $items ): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:itunes="' . self::ITUNES . '" xmlns:content="' . self::CONTENT . '">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= $this->channel( $config );
        foreach ( $items as $item ) {
            $xml .= $this->item( $item, $config );
        }
        $xml .= '</channel>' . "\n";
        $xml .= '</rss>' . "\n";
        return $xml;
    }

    private function channel( PodcastConfig $c ): string {
        $out  = $this->tag( 'title', $c->title );
        $out .= $this->tag( 'link', $c->link );
        $out .= '<atom:link xmlns:atom="http://www.w3.org/2005/Atom" href="' . $this->attr( $c->feedUrl ) . '" rel="self" type="application/rss+xml"/>' . "\n";
        $out .= $this->cdata( 'description', $c->description !== '' ? $c->description : $c->summary );
        if ( $c->language !== '' ) {
            $out .= $this->tag( 'language', $c->language );
        }
        if ( $c->copyright !== '' ) {
            $out .= $this->tag( 'copyright', $c->copyright );
        }
        if ( $c->author !== '' ) {
            $out .= $this->tag( 'itunes:author', $c->author );
        }
        $out .= $this->cdata( 'itunes:summary', $c->summary !== '' ? $c->summary : $c->description );
        $out .= $this->tag( 'itunes:explicit', $c->explicit ? 'true' : 'false' );
        if ( $c->imageUrl !== '' ) {
            $out .= '<itunes:image href="' . $this->attr( $c->imageUrl ) . '"/>' . "\n";
        }
        $out .= $this->category( $c->category, $c->subcategory );
        if ( $c->ownerName !== '' || $c->ownerEmail !== '' ) {
            $out .= '<itunes:owner>' . "\n";
            if ( $c->ownerName !== '' ) {
                $out .= $this->tag( 'itunes:name', $c->ownerName );
            }
            if ( $c->ownerEmail !== '' ) {
                $out .= $this->tag( 'itunes:email', $c->ownerEmail );
            }
            $out .= '</itunes:owner>' . "\n";
        }
        return $out;
    }

    private function item( FeedItem $i, PodcastConfig $c ): string {
        $out  = '<item>' . "\n";
        $out .= $this->tag( 'title', $i->title );
        $out .= $this->tag( 'link', $i->link );
        $out .= '<guid isPermaLink="false">' . $this->text( $i->guid ) . '</guid>' . "\n";
        $out .= $this->tag( 'pubDate', gmdate( 'D, d M Y H:i:s', $i->pubTimestamp ) . ' +0000' );
        $out .= $this->cdata( 'description', $i->description );
        $out .= '<enclosure url="' . $this->attr( $i->audioUrl ) . '" length="' . $i->audioSize . '" type="' . $this->attr( $i->audioType ) . '"/>' . "\n";
        if ( $i->duration !== '' ) {
            $out .= $this->tag( 'itunes:duration', $i->duration );
        }
        $out .= $this->tag( 'itunes:explicit', $i->explicit ? 'true' : 'false' );
        if ( $i->imageUrl !== '' ) {
            $out .= '<itunes:image href="' . $this->attr( $i->imageUrl ) . '"/>' . "\n";
        } elseif ( $c->imageUrl !== '' ) {
            $out .= '<itunes:image href="' . $this->attr( $c->imageUrl ) . '"/>' . "\n";
        }
        $out .= '</item>' . "\n";
        return $out;
    }

    private function category( string $category, ?string $subcategory ): string {
        $out = '<itunes:category text="' . $this->attr( $category ) . '">';
        if ( $subcategory !== null && $subcategory !== '' ) {
            $out .= '<itunes:category text="' . $this->attr( $subcategory ) . '"/>';
        }
        $out .= '</itunes:category>' . "\n";
        return $out;
    }

    private function tag( string $name, string $value ): string {
        return '<' . $name . '>' . $this->text( $value ) . '</' . $name . '>' . "\n";
    }

    private function cdata( string $name, string $value ): string {
        // Guard against the CDATA terminator appearing in the content.
        $safe = str_replace( ']]>', ']]]]><![CDATA[>', $value );
        return '<' . $name . '><![CDATA[' . $safe . ']]></' . $name . '>' . "\n";
    }

    private function text( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    private function attr( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }
}
