/**
 * Sermonator settings page enhancements (screen-scoped, enqueued only on the
 * settings page by Admin\SettingsPage).
 *
 *   1. Default-image media picker — opens the WordPress media frame, writes the
 *      chosen attachment id into the hidden field, and updates the preview.
 *   2. Archive-slug change-confirm — the slug drives the archive AND every
 *      single-sermon permalink, so a change is confirmed before Form 1 submits.
 *
 * Both degrade gracefully: without this script the image field is a plain
 * attachment-id input and the slug field saves normally.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.SermonatorSettings || {};

		// --- Default-image media picker -------------------------------------
		var idInput = document.getElementById( 'sermonator_sermon_default_image_id' );
		var preview = document.getElementById( 'sermonator-default-image-preview' );
		var selectBtn = document.getElementById( 'sermonator-default-image-select' );
		var removeBtn = document.getElementById( 'sermonator-default-image-remove' );
		var frame;

		if ( selectBtn && idInput && window.wp && window.wp.media ) {
			selectBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) {
					frame.open();
					return;
				}
				frame = window.wp.media( {
					title: cfg.mediaTitle || 'Select default image',
					button: { text: cfg.mediaButton || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					idInput.value = att.id;
					if ( preview ) {
						var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
						preview.innerHTML = '';
						var img = document.createElement( 'img' );
						img.src = url;
						img.alt = '';
						preview.appendChild( img );
					}
					if ( removeBtn ) {
						removeBtn.hidden = false;
					}
				} );
				frame.open();
			} );
		}

		if ( removeBtn && idInput ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				idInput.value = '0';
				if ( preview ) {
					preview.innerHTML = '';
				}
				removeBtn.hidden = true;
			} );
		}

		// --- Archive-slug change-confirm ------------------------------------
		var slugInput = document.getElementById( 'sermonator_sermon_archive_slug' );
		if ( slugInput && slugInput.form ) {
			var original = slugInput.defaultValue;
			slugInput.form.addEventListener( 'submit', function ( e ) {
				if ( slugInput.value !== original ) {
					var msg = cfg.slugConfirm || 'Changing the archive slug breaks existing links. Continue?';
					if ( ! window.confirm( msg ) ) {
						e.preventDefault();
					}
				}
			} );
		}
	} );
} )();
