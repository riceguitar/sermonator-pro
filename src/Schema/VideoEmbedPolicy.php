<?php

declare(strict_types=1);

namespace Sermonator\Schema;

/**
 * The single source of truth for what HTML is allowed in a stored video embed.
 *
 * wp_kses_post() strips <iframe>, which would silently delete YouTube/Vimeo embeds, so we
 * extend the post allowlist with iframe (and <video>/<source> for self-hosted) limited to
 * safe, embed-relevant attributes.
 *
 * `style` is deliberately NOT allowed: kses does not parse CSS, so an allowed style attribute
 * would let an editor turn an iframe into a full-page invisible overlay (clickjacking). Sizing
 * is handled by width/height + the stylesheet's max-width.
 *
 * Both the front-end Renderer and the authoring-layer embed sanitizer MUST call this function
 * so a freshly-authored embed and a migrated one can never diverge.
 *
 * @return array<string,array<string,bool>>
 */
final class VideoEmbedPolicy {
    public static function allowed(): array {
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
}
