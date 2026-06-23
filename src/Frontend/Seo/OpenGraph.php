<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Seo;

use Sermonator\Frontend\SermonView;

/**
 * Builds Open Graph + Twitter Card meta tags for a sermon. PURE: returns an ordered list of
 * [attr, key, content] triples (attr is 'property' for og:* / 'name' for twitter:*) so the
 * emitter can escape and print them. No WordPress dependency.
 */
final class OpenGraph {
    /**
     * @param array{siteName?:string,image?:string,description?:string} $context
     * @return list<array{attr:string,key:string,content:string}>
     */
    public function forSermon( SermonView $view, array $context = array() ): array {
        $description = isset( $context['description'] ) && $context['description'] !== ''
            ? (string) $context['description']
            : $view->biblePassage;
        $image = isset( $context['image'] ) ? (string) $context['image'] : '';

        $tags = array();
        $tags[] = $this->og( 'og:type', 'article' );
        $tags[] = $this->og( 'og:title', $view->title );
        $tags[] = $this->og( 'og:url', $view->permalink );
        if ( isset( $context['siteName'] ) && $context['siteName'] !== '' ) {
            $tags[] = $this->og( 'og:site_name', (string) $context['siteName'] );
        }
        if ( $description !== '' ) {
            $tags[] = $this->og( 'og:description', $description );
        }
        if ( $image !== '' ) {
            $tags[] = $this->og( 'og:image', $image );
        }
        if ( $view->audioUrl !== '' ) {
            $tags[] = $this->og( 'og:audio', $view->audioUrl );
        }
        if ( $view->videoUrl !== '' ) {
            $tags[] = $this->og( 'og:video', $view->videoUrl );
        }

        // Twitter Card — summary_large_image when we have an image, else summary.
        $tags[] = $this->twitter( 'twitter:card', $image !== '' ? 'summary_large_image' : 'summary' );
        $tags[] = $this->twitter( 'twitter:title', $view->title );
        if ( $description !== '' ) {
            $tags[] = $this->twitter( 'twitter:description', $description );
        }
        if ( $image !== '' ) {
            $tags[] = $this->twitter( 'twitter:image', $image );
        }

        return $tags;
    }

    /** @return array{attr:string,key:string,content:string} */
    private function og( string $key, string $content ): array {
        return array( 'attr' => 'property', 'key' => $key, 'content' => $content );
    }

    /** @return array{attr:string,key:string,content:string} */
    private function twitter( string $key, string $content ): array {
        return array( 'attr' => 'name', 'key' => $key, 'content' => $content );
    }
}
