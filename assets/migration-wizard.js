/**
 * Sermonator migration wizard — minimal, dependency-free driver.
 *
 * The data-critical logic lives entirely in the gated PHP services + the thin
 * MigrationController. This script only: posts to the AJAX actions with the nonce,
 * loops the chunked `run` updating a progress bar, gates destructive actions behind a
 * confirm, and reloads so the server re-renders the step for the new phase.
 */
( function () {
	'use strict';

	var cfg = window.SermonatorMigration || {};
	if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	var root = document.querySelector( '.sermonator-migrate' );
	if ( ! root ) {
		return;
	}
	var logEl = root.querySelector( '.sermonator-migrate-log' );

	function log( message, isError ) {
		if ( ! logEl ) {
			return;
		}
		logEl.textContent = message || '';
		logEl.className = 'sermonator-migrate-log' + ( isError ? ' is-error' : '' );
	}

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', cfg.actionPrefix + action );
		body.set( 'nonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				body.set( k, extra[ k ] );
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return { success: false, data: { message: 'Unexpected server response.' } };
			} );
		} );
	}

	function setBusy( busy ) {
		root.querySelectorAll( 'button[data-sermonator-action]' ).forEach( function ( b ) {
			b.disabled = !! busy;
		} );
	}

	function updateProgress( status ) {
		if ( ! status ) {
			return;
		}
		var bar = root.querySelector( '.sermonator-migrate-progress progress' );
		var label = root.querySelector( '.sermonator-migrate-progress-label' );
		var done = status.done || 0;
		var total = ( status.done || 0 ) + ( status.remaining || 0 );
		if ( bar ) {
			bar.max = Math.max( 1, total );
			bar.value = done;
		}
		if ( label ) {
			label.textContent = done + ' of ' + total + ' records migrated';
		}
	}

	// Loop the chunked `run` until migrated, or until a hard stop / no forward progress.
	function runLoop() {
		setBusy( true );
		log( 'Migrating…' );
		var lastDone = -1;
		var stall = 0;

		function step() {
			post( 'run', { batch_size: 50 } ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					log( ( res && res.data && res.data.message ) || 'Migration failed.', true );
					setBusy( false );
					return;
				}
				var status = res.data.status || {};
				var progress = res.data.progress || {};
				updateProgress( status );

				var phase = status.phase || '';
				var flags = progress.flags || [];

				if ( phase === 'migrated' || phase === 'verified' || phase === 'finalized' ) {
					log( 'Migration complete.' );
					window.location.reload();
					return;
				}
				if ( flags.indexOf( 'locked' ) !== -1 || flags.indexOf( 'not_detected' ) !== -1 ) {
					log( 'Stopped: ' + flags.join( ', ' ), true );
					setBusy( false );
					return;
				}
				// Forward-progress watchdog: if done isn't moving and a blocking flag
				// persists, stop rather than spin (the server also caps this).
				var done = status.done || 0;
				if ( done === lastDone && flags.length ) {
					stall++;
					if ( stall >= 3 ) {
						log( 'Migration is not advancing: ' + flags.join( ', ' ) + '. Reloading.', true );
						window.location.reload();
						return;
					}
				} else {
					stall = 0;
				}
				lastDone = done;
				step();
			} ).catch( function () {
				log( 'Network error during migration.', true );
				setBusy( false );
			} );
		}
		step();
	}

	function confirmDestructive( btn ) {
		var requires = btn.getAttribute( 'data-sermonator-requires' );
		if ( requires ) {
			var box = root.querySelector( '.' + requires );
			if ( box && ! box.checked ) {
				log( 'Tick the confirmation checkbox first.', true );
				return false;
			}
			return true;
		}
		return window.confirm( 'Are you sure? This cannot be undone from here.' );
	}

	root.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-sermonator-action]' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();
		var action = btn.getAttribute( 'data-sermonator-action' );
		var destructive = btn.getAttribute( 'data-sermonator-destructive' ) === '1';
		var loop = btn.getAttribute( 'data-sermonator-loop' ) === '1';

		if ( destructive && ! confirmDestructive( btn ) ) {
			return;
		}
		if ( action === 'run' && loop ) {
			runLoop();
			return;
		}

		setBusy( true );
		log( 'Working…' );
		var extra = destructive ? { confirm: '1' } : null;
		post( action, extra ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				log( ( res && res.data && res.data.message ) || 'Action failed.', true );
				setBusy( false );
				return;
			}
			if ( res.data.refused ) {
				log( 'Refused: ' + res.data.refused, true );
				setBusy( false );
				return;
			}
			// Verify reports its result in-place; only reload on a clean pass.
			if ( action === 'verify' && res.data.report ) {
				var report = res.data.report;
				if ( report.complete ) {
					log( 'Verification passed.' );
					window.location.reload();
					return;
				}
				var lines = [ 'Verification did not pass. Review the issues below, then re-run Detect and Migrate if needed.' ];
				lines.push( '- Drift: ' + ( report.drift || 0 ) );
				lines.push( '- Missing: ' + ( report.missing || 0 ) );
				lines.push( '- Open failure flags: ' + ( report.openFlags && report.openFlags.length ? report.openFlags.length : 0 ) );
				if ( report.openFlags && report.openFlags.length ) {
					lines.push( 'Flags: ' + report.openFlags.join( ', ' ) );
				}
				log( lines.join( '\n' ), true );
				setBusy( false );
				return;
			}
			window.location.reload();
		} ).catch( function () {
			log( 'Network error.', true );
			setBusy( false );
		} );
	} );
}() );
