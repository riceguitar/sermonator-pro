<?php
/**
 * Asset manifest for the Sermon Details meta-box React UI.
 *
 * Hand-authored because the @wordpress/scripts build could not complete in this environment.
 * To rebuild from JSX source when a working toolchain is available, run:
 *   npm install && npm run build
 */
return array(
	'dependencies' => array(
		'wp-api-fetch',
		'wp-components',
		'wp-date',
		'wp-element',
		'wp-i18n',
		'wp-media-utils',
	),
	'version'      => SERMONATOR_VERSION,
);
