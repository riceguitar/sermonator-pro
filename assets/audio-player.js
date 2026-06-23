/**
 * Sermonator audio player — progressive enhancement only.
 * The native <audio controls> already works without JS; this adds a playback-speed toggle.
 */
( function () {
	'use strict';
	var SPEEDS = [ 1, 1.5, 2 ];

	function enhance( wrap ) {
		var audio = wrap.querySelector( '.sermonator-audio__el' );
		if ( ! audio || wrap.querySelector( '.sermonator-audio__speed' ) ) {
			return;
		}
		var i = 0;
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'sermonator-audio__speed';
		btn.textContent = '1×';
		btn.setAttribute( 'aria-label', 'Playback speed' );
		btn.addEventListener( 'click', function () {
			i = ( i + 1 ) % SPEEDS.length;
			audio.playbackRate = SPEEDS[ i ];
			btn.textContent = SPEEDS[ i ] + '×';
		} );
		wrap.appendChild( btn );
	}

	function init() {
		document.querySelectorAll( '.sermonator-audio' ).forEach( enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
