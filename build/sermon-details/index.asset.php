<?php
/**
 * Asset manifest for the Sermon Details sidebar panel.
 *
 * Hand-authored because the @wordpress/scripts build could not complete in this environment.
 * To rebuild from JSX source when a working toolchain is available, run:
 *   npm install && npm run build
 */
return array(
    'dependencies' => array(
        'wp-api-fetch',
        'wp-components',
        'wp-core-data',
        'wp-data',
        'wp-date',
        'wp-editor',
        'wp-element',
        'wp-i18n',
        'wp-media-utils',
        'wp-plugins',
    ),
    'version'      => SERMONATOR_VERSION,
);
