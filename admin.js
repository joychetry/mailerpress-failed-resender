/**
 * MailerPress Failed Resender — admin page controller.
 *
 * - Loads failed campaigns from /mp-json/mpfr/v1/failed-campaigns.
 * - "Retry Failed Emails" enqueues a background batch via Action Scheduler
 *   (POST /mpfr/v1/campaign/{id}/resend-failed) and starts a polling loop
 *   against GET /mpfr/v1/campaign/{id}/resend-progress.
 * - A floating progress card shows a live bar + counters + ETA + Cancel.
 * - "Reset Chunks" still calls POST /mpfr/v1/batch/{id}/reset (legacy flow).
 *
 * No external dependencies beyond wp-api-fetch (bundled with WordPress core).
 */
( function ( wp, document ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch ) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var __       = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	var sprintf  = ( wp.i18n && wp.i18n.sprintf ) ? wp.i18n.sprintf : function ( s, v ) { return s.replace( '%s', v ).replace( '%d', v ); };
	var data     = window.MPFR_DATA || {};

	var elTbody   = document.getElementById( 'mpfr-tbody' );
	var elStatus  = document.getElementById( 'mpfr-status' );
	var elRefresh = document.getElementById( 'mpfr-refresh' );

	if ( ! elTbody ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( data.nonce ) );

	// Hidden campaigns state
	var hiddenCampaigns = [];
	var showHiddenActive = false;
	var elShowHidden = null;

	// ------------------------------------------------------------------
	// Status banner
	// ------------------------------------------------------------------
	function setStatus( type, message ) {
		if ( ! elStatus ) {
			return;
		}
		elStatus.className = 'mpfr-status mpfr-status--' + type;
		elStatus.textContent = message;
		elStatus.hidden = false;
		if ( type === 'success' || type === 'info' ) {
			setTimeout( function () { elStatus.hidden = true; }, 5000 );
		}
	}

	// ------------------------------------------------------------------
	// Utilities
	// ------------------------------------------------------------------
	function esc( str ) {
		if ( str === null || str === undefined ) return '';
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function formatDate( iso ) {
		if ( ! iso ) return '—';
		try {
			var d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
			if ( isNaN( d.getTime() ) ) return iso;
			return d.toLocaleString();
		} catch ( e ) { return iso; }
	}

	function formatEta( seconds ) {
		seconds = parseInt( seconds, 10 ) || 0;
		if ( seconds <= 0 ) return '—';
		if ( seconds < 60 ) return seconds + 's';
		var m = Math.floor( seconds / 60 );
		var s = seconds % 60;
		return m + 'm ' + s + 's';
	}

	function progressPct( progress ) {
		var total = parseInt( progress.total, 10 ) || 0;
		if ( total <= 0 ) return 0;
		var sent     = parseInt( progress.sent,     10 ) || 0;
		var failed   = parseInt( progress.failed,   10 ) || 0;
		var skipped  = parseInt( progress.skipped,  10 ) || 0;
		var done     = sent + failed + skipped;
		return Math.min( 100, Math.round( ( done / total ) * 100 ) );
	}

	// ------------------------------------------------------------------
	// Hidden campaigns
	// ------------------------------------------------------------------
	function loadHiddenCampaigns() {
		return apiFetch( { path: '/mpfr/v1/user/hidden-campaigns' } )
			.then( function ( response ) {
				hiddenCampaigns = response.hidden_campaigns || [];
				updateShowHiddenButton();
				applyHiddenState();
				updateHideButtons();
			} )
			.catch( function ( err ) {
				console.error( 'Failed to load hidden campaigns:', err );
			} );
	}

	function updateHideButtons() {
		for ( var i = 0; i < hiddenCampaigns.length; i++ ) {
			var btn = elTbody.querySelector( 'button.mpfr-btn--hide[data-campaign="' + hiddenCampaigns[i] + '"]' );
			if ( btn ) {
				btn.dataset.hidden = 'true';
				var icon = btn.querySelector( '.dashicons' );
				if ( icon ) {
					icon.className = 'dashicons dashicons-hidden';
				}
				btn.title = __( 'Unhide', 'mpfr' );
			}
		}
		// Reset buttons that are not hidden
		var allHideBtns = elTbody.querySelectorAll( 'button.mpfr-btn--hide' );
		for ( var j = 0; j < allHideBtns.length; j++ ) {
			var campaignId = parseInt( allHideBtns[j].dataset.campaign, 10 );
			if ( hiddenCampaigns.indexOf( campaignId ) === -1 ) {
				allHideBtns[j].dataset.hidden = 'false';
				var icon = allHideBtns[j].querySelector( '.dashicons' );
				if ( icon ) {
					icon.className = 'dashicons dashicons-visibility';
				}
				allHideBtns[j].title = __( 'Hide', 'mpfr' );
			}
		}
	}

	function hideCampaign( campaignId, btn ) {
		if ( ! campaignId ) {
			setStatus( 'error', __( 'Invalid campaign id.', 'mpfr' ) );
			return;
		}
		
		// Determine if we're hiding or unhiding
		var isCurrentlyHidden = hiddenCampaigns.indexOf( campaignId ) !== -1;
		var action = isCurrentlyHidden ? 'remove' : 'add';
		var busyLabel = isCurrentlyHidden ? __( 'Unhiding...', 'mpfr' ) : __( 'Hiding...', 'mpfr' );
		
		setBusy( btn, true, busyLabel );
		
		apiFetch( {
			path: '/mpfr/v1/user/hidden-campaigns',
			method: 'POST',
			data: { action: action, campaign_id: campaignId }
		} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					throw new Error( ( response && response.message ) || 'Failed to update campaign visibility' );
				}
				
				hiddenCampaigns = response.hidden_campaigns || [];
				
				// Find the row and animate it
				var row = elTbody.querySelector( 'tr[data-campaign="' + campaignId + '"]' );
				if ( row ) {
					if ( isCurrentlyHidden ) {
						// Unhiding: show the row
						row.classList.remove( 'mpfr-row--hidden' );
						row.classList.add( 'mpfr-row--fading' );
						setTimeout( function () {
							row.classList.remove( 'mpfr-row--fading' );
						}, 30 );
					} else {
						// Hiding: hide the row
						row.classList.add( 'mpfr-row--fading' );
						setTimeout( function () {
							row.classList.add( 'mpfr-row--hidden' );
							row.classList.remove( 'mpfr-row--fading' );
						}, 300 );
					}
				}
				
				updateShowHiddenButton();
				updateHideButtons();
				var statusMessage = isCurrentlyHidden ? __( 'Campaign unhidden.', 'mpfr' ) : __( 'Campaign hidden.', 'mpfr' );
				setStatus( 'info', statusMessage );
			} )
			.catch( function ( err ) {
				setStatus( 'error', sprintf( __( 'Failed to update visibility: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) );
			} )
			.finally( function () {
				setBusy( btn, false );
			} );
	}

	function toggleShowHidden() {
		showHiddenActive = !showHiddenActive;
		
		if ( showHiddenActive ) {
			// Show hidden rows
			var hiddenRows = elTbody.querySelectorAll( 'tr.mpfr-row--hidden' );
			for ( var i = 0; i < hiddenRows.length; i++ ) {
				hiddenRows[i].classList.remove( 'mpfr-row--hidden' );
				hiddenRows[i].classList.add( 'mpfr-row--fading' );
				( function ( row ) {
					setTimeout( function () {
						row.classList.remove( 'mpfr-row--fading' );
					}, 30 );
				} )( hiddenRows[i] );
			}
		} else {
			// Hide hidden rows again
			applyHiddenState();
		}
		
		updateShowHiddenButton();
	}

	function updateShowHiddenButton() {
		if ( ! elShowHidden ) {
			elShowHidden = document.getElementById( 'mpfr-show-hidden' );
		}
		
		if ( ! elShowHidden ) {
			// Create the button if it doesn't exist
			elShowHidden = document.createElement( 'button' );
			elShowHidden.type = 'button';
			elShowHidden.className = 'page-title-action mpfr-btn mpfr-btn--show-hidden';
			elShowHidden.id = 'mpfr-show-hidden';
			elShowHidden.innerHTML = '<span class="dashicons dashicons-hidden" aria-hidden="true"></span> ' + __( 'Show Hidden', 'mpfr' );
			elShowHidden.addEventListener( 'click', toggleShowHidden );
			
			// Insert after refresh button
			if ( elRefresh && elRefresh.parentNode ) {
				elRefresh.parentNode.insertBefore( elShowHidden, elRefresh.nextSibling );
			}
		}
		
		var count = hiddenCampaigns.length;
		var buttonText = showHiddenActive ? __( 'Hide Hidden', 'mpfr' ) : __( 'Show Hidden', 'mpfr' );
		var countText = count > 0 ? ' (' + count + ')' : '';
		
		elShowHidden.innerHTML = '<span class="dashicons dashicons-hidden" aria-hidden="true"></span> ' + buttonText + countText;
		elShowHidden.style.display = count > 0 ? '' : 'none';
		
		if ( showHiddenActive ) {
			elShowHidden.classList.add( 'mpfr-show-hidden--active' );
		} else {
			elShowHidden.classList.remove( 'mpfr-show-hidden--active' );
		}
	}

	function applyHiddenState() {
		if ( ! showHiddenActive && hiddenCampaigns.length > 0 ) {
			for ( var i = 0; i < hiddenCampaigns.length; i++ ) {
				var row = elTbody.querySelector( 'tr[data-campaign="' + hiddenCampaigns[i] + '"]' );
				if ( row ) {
					row.classList.add( 'mpfr-row--hidden' );
				}
			}
		}
	}

	// ------------------------------------------------------------------
	// Progress card (one per running resend, on document.body)
	// ------------------------------------------------------------------
	var cards = Object.create( null );

	function ensureCard( campaignId, title ) {
		var id = 'mpfr-progress-card-' + campaignId;
		var el = document.getElementById( id );
		if ( el ) return el;
		el = document.createElement( 'div' );
		el.id = id;
		el.className = 'mpfr-progress-card';
		el.setAttribute( 'data-campaign', String( campaignId ) );
		el.innerHTML = [
			'<div class="mpfr-progress-head">',
				'<span class="mpfr-progress-title"></span>',
				'<button type="button" class="mpfr-progress-close" aria-label="Close">×</button>',
			'</div>',
			'<div class="mpfr-progress-bar"><div class="mpfr-progress-fill" style="width:0%"></div></div>',
			'<div class="mpfr-progress-stats"></div>',
			'<div class="mpfr-progress-eta"></div>',
			'<div class="mpfr-progress-actions">',
				'<button type="button" class="button mpfr-progress-cancel">Cancel</button>',
			'</div>'
		].join( '' );
		el.querySelector( '.mpfr-progress-title' ).textContent = title
			? ( 'Resending campaign #' + campaignId + ' — ' + title )
			: ( 'Resending campaign #' + campaignId );
		document.body.appendChild( el );
		el.querySelector( '.mpfr-progress-close' ).addEventListener( 'click', function () {
			el.remove();
		} );
		el.querySelector( '.mpfr-progress-cancel' ).addEventListener( 'click', function () {
			cancelResend( campaignId );
		} );
		return el;
	}

	function updateCard( campaignId, progress ) {
		var el = document.getElementById( 'mpfr-progress-card-' + campaignId );
		if ( ! el ) return;
		var status = progress.status || 'running';
		el.className = 'mpfr-progress-card mpfr-progress-card--' + status;

		var pct = progressPct( progress );
		el.querySelector( '.mpfr-progress-fill' ).style.width = pct + '%';

		var total     = parseInt( progress.total,     10 ) || 0;
		var sent      = parseInt( progress.sent,      10 ) || 0;
		var failed    = parseInt( progress.failed,    10 ) || 0;
		var skipped   = parseInt( progress.skipped,   10 ) || 0;
		var remaining = parseInt( progress.remaining, 10 ) || 0;
		var eta       = parseInt( progress.eta_seconds, 10 ) || 0;

		el.querySelector( '.mpfr-progress-stats' ).innerHTML = [
			'<span>Total: <strong>', total, '</strong></span>',
			'<span style="color:#00a32a">Sent: <strong>', sent, '</strong></span>',
			( failed > 0 ? '<span style="color:#d63638">Failed: <strong>' + failed + '</strong></span>' : '' ),
			( skipped > 0 ? '<span style="color:#dba617">Skipped: <strong>' + skipped + '</strong></span>' : '' ),
			'<span>Remaining: <strong>', remaining, '</strong></span>'
		].join( '' );

		var etaEl = el.querySelector( '.mpfr-progress-eta' );
		if ( status === 'done' ) {
			etaEl.textContent = 'Done — ' + sent + ' resent';
			etaEl.style.color = '#00a32a';
		} else if ( status === 'cancelled' ) {
			etaEl.textContent = 'Cancelled — ' + sent + ' resent, ' + remaining + ' still queued';
			etaEl.style.color = '#dba617';
		} else if ( status === 'error' ) {
			etaEl.textContent = 'Error: ' + ( progress.error_message || 'unknown' );
			etaEl.style.color = '#d63638';
		} else if ( remaining === 0 && total > 0 ) {
			etaEl.textContent = 'Finalising…';
		} else {
			etaEl.textContent = 'ETA: ~' + formatEta( eta );
			etaEl.style.color = '';
		}

		// Hide the cancel button on terminal states.
		var cancelBtn = el.querySelector( '.mpfr-progress-cancel' );
		var isTerminal = ( status === 'done' || status === 'cancelled' || status === 'error' );
		cancelBtn.style.display = isTerminal ? 'none' : '';
	}

	function removeCard( campaignId ) {
		var el = document.getElementById( 'mpfr-progress-card-' + campaignId );
		if ( el ) el.remove();
	}

	// ------------------------------------------------------------------
	// Polling
	// ------------------------------------------------------------------
	function pollProgress( campaignId, title ) {
		var stop = false;
		var pinged = 0;
		var tick = function () {
			if ( stop ) return;
			// Every 3rd tick, ping wp-cron to make sure Action Scheduler
			// picks up the queued batch (REST requests don't trigger cron).
			pinged++;
			if ( pinged % 3 === 0 ) {
				var cron = new Image();
				cron.src = ( window.MPFR_DATA && window.MPFR_DATA.cronUrl ) || ( '/wp-cron.php?doing_wp_cron=' + Date.now() );
			}
			apiFetch( { path: '/mpfr/v1/campaign/' + campaignId + '/resend-progress' } )
				.then( function ( progress ) {
					if ( stop ) return;
					if ( ! progress || progress.status === 'idle' ) {
						// No active run; nothing to show.
						return;
					}
					ensureCard( campaignId, title );
					updateCard( campaignId, progress );
					if ( progress.status === 'done' || progress.status === 'cancelled' || progress.status === 'error' ) {
						stop = true;
						delete cards[ campaignId ];
						loadRows();
						setTimeout( function () { removeCard( campaignId ); }, 6000 );
						return;
					}
					setTimeout( tick, 600 );
				} )
				.catch( function () {
					if ( stop ) return;
					setTimeout( tick, 1500 );
				} );
		};
		// First tick fires immediately.
		tick();
		return function () { stop = true; };
	}

	// ------------------------------------------------------------------
	// Resend + cancel
	// ------------------------------------------------------------------
	function runResend( btn ) {
		var campaignId = parseInt( btn.dataset.campaign, 10 );
		var title      = btn.dataset.title || '';
		if ( ! campaignId ) {
			setStatus( 'error', __( 'Invalid campaign id.', 'mpfr' ) );
			return;
		}
		var ok = window.confirm( __( 'Resend every failed email for this campaign through the active ESP? (Runs in background — you can leave this page.)', 'mpfr' ) );
		if ( ! ok ) return;

		setBusy( btn, true, __( 'Starting…', 'mpfr' ) );
		setStatus( 'info', __( 'Resending failed emails in the background…', 'mpfr' ) );

		apiFetch( {
			path: '/mpfr/v1/campaign/' + campaignId + '/resend-failed',
			method: 'POST',
			data: { batch_size: 50 }
		} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					throw new Error( ( response && response.message ) || 'Unknown error' );
				}
				if ( response.scheduled ) {
					// Background: start polling for progress.
					setStatus( 'info', sprintf( __( 'Resend scheduled (%1$d emails queued). Watching progress…', 'mpfr' ), response.progress && response.progress.total || 0 ) );
					cards[ campaignId ] = pollProgress( campaignId, title );
				} else {
					// Legacy sync response (shouldn't happen with the new flow).
					setStatus( 'success', response.message || sprintf( __( 'Resent: %d.', 'mpfr' ), response.resent || 0 ) );
					loadRows();
				}
			} )
			.catch( function ( err ) {
				setStatus( 'error', sprintf( __( 'Failed: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) );
			} )
			.finally( function () {
				setBusy( btn, false );
			} );
	}

	function cancelResend( campaignId ) {
		apiFetch( {
			path: '/mpfr/v1/campaign/' + campaignId + '/resend-cancel',
			method: 'POST'
		} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					throw new Error( ( response && response.message ) || 'Cancel failed' );
				}
				setStatus( 'warning', __( 'Resend cancelled.', 'mpfr' ) );
				// The polling loop will pick up the cancelled status on the next tick.
			} )
			.catch( function ( err ) {
				setStatus( 'error', sprintf( __( 'Cancel failed: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) );
			} );
	}

	// ------------------------------------------------------------------
	// Chunk reset (legacy)
	// ------------------------------------------------------------------
	function runChunkReset( btn ) {
		var batchId = parseInt( btn.dataset.batch, 10 );
		if ( ! batchId ) {
			setStatus( 'error', __( 'Invalid batch id.', 'mpfr' ) );
			return;
		}
		var ok = window.confirm( __( 'This will reset ALL chunks of the batch (including completed) and zero the sent/error counters. Continue?', 'mpfr' ) );
		if ( ! ok ) return;

		setBusy( btn, true, __( 'Resetting…', 'mpfr' ) );
		setStatus( 'info', __( 'Resetting batch…', 'mpfr' ) );

		apiFetch( { path: '/mpfr/v1/batch/' + batchId + '/reset', method: 'POST' } )
			.then( function ( response ) {
				var msg = response && response.message
					? response.message
					: sprintf( __( 'Reset: %d chunk(s) rescheduled.', 'mpfr' ), response.chunks_rescheduled || 0 );
				setStatus( 'success', msg );
				return loadRows();
			} )
			.catch( function ( err ) {
				setStatus( 'error', sprintf( __( 'Failed: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) );
			} )
			.finally( function () {
				setBusy( btn, false );
			} );
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------
	function renderRows( rows ) {
		if ( ! rows || ! rows.length ) {
			elTbody.innerHTML = '<tr><td colspan="8" class="mpfr-empty">' +
				esc( __( 'No campaigns with failed emails.', 'mpfr' ) ) + '</td></tr>';
			return;
		}
		elTbody.innerHTML = rows.map( function ( r ) {
			var failedChunks = parseInt( r.failed_chunks, 10 ) || 0;
			var retryChunks  = parseInt( r.retry_chunks, 10 )  || 0;
			var processing   = parseInt( r.processing_chunks, 10 ) || 0;
			var failedEmails = parseInt( r.failed_email_logs, 10 ) || 0;
			var total        = parseInt( r.total_emails, 10 ) || 0;
			var sent         = parseInt( r.sent_emails, 10 ) || 0;
			var errors       = parseInt( r.error_emails, 10 ) || 0;
			var campaignId   = parseInt( r.id, 10 ) || 0;
			var batchPk      = parseInt( r.batch_pk, 10 ) || 0;
			var status       = r.batch_status || r.status || '—';
			var title        = r.title || '(no title)';
			var updatedAt    = formatDate( r.updated_at );

			return [
				'<tr data-campaign="', campaignId, '" data-batch="', batchPk, '">',
					'<td class="mpfr-col-campaign">',
						'<strong>', esc( title ), '</strong>',
						'<div class="mpfr-meta">#', campaignId, ' · ', esc( updatedAt ), '</div>',
					'</td>',
					'<td class="mpfr-col-status"><span class="mpfr-badge mpfr-badge--', esc( status ), '">', esc( status ), '</span></td>',
					'<td class="mpfr-col-num">', total, '</td>',
					'<td class="mpfr-col-num">', sent, '</td>',
					'<td class="mpfr-col-num mpfr-col-errors">', errors, '</td>',
					'<td class="mpfr-col-num mpfr-col-failed-emails">', failedEmails, '</td>',
					'<td class="mpfr-col-num">',
						'<span title="failed">', failedChunks, '</span> / ',
						'<span title="retry">', retryChunks, '</span> / ',
						'<span title="processing">', processing, '</span>',
					'</td>',
					'<td class="mpfr-col-actions">',
						'<button type="button" class="button button-primary mpfr-btn mpfr-btn--resend" data-campaign="', campaignId, '" data-title="', esc( title ), '">',
							esc( __( 'Retry', 'mpfr' ) ),
						'</button> ',
						'<button type="button" class="button mpfr-btn mpfr-btn--reset" data-batch="', batchPk, '">',
							esc( __( 'Reset Chunks', 'mpfr' ) ),
						'</button> ',
						'<button type="button" class="button button-secondary mpfr-btn mpfr-btn--hide" data-campaign="', campaignId, '" data-hidden="false" title="', esc( __( 'Hide', 'mpfr' ) ), '">',
							'<span class="dashicons dashicons-visibility" aria-hidden="true"></span>',
						'</button>',
					'</td>',
				'</tr>'
			].join( '' );
		} ).join( '' );
	}

	function loadRows() {
		return apiFetch( { path: '/mpfr/v1/failed-campaigns?limit=200' } )
			.then( function ( response ) {
				renderRows( response.rows || [] );
				if ( elStatus ) {
					elStatus.hidden = true;
				}
				// Apply hidden state after rendering rows
				return loadHiddenCampaigns();
			} )
			.catch( function ( err ) {
				elTbody.innerHTML = '<tr><td colspan="8" class="mpfr-error">' +
					esc( sprintf( __( 'Failed to load: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) ) +
					'</td></tr>';
			} );
	}

	function setBusy( btn, busy, busyLabel ) {
		if ( busy ) {
			btn.dataset.busy = '1';
			btn.dataset.label = btn.innerHTML;
			btn.disabled = true;
			btn.textContent = busyLabel || __( 'Working…', 'mpfr' );
		} else {
			btn.disabled = false;
			btn.innerHTML = btn.dataset.label || btn.innerHTML;
			delete btn.dataset.busy;
		}
	}

	// ------------------------------------------------------------------
	// Event delegation
	// ------------------------------------------------------------------
	if ( elRefresh ) {
		elRefresh.addEventListener( 'click', function () { loadRows(); } );
	}

	elTbody.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button.mpfr-btn' );
		if ( ! btn || btn.dataset.busy === '1' ) return;
		if ( btn.classList.contains( 'mpfr-btn--resend' ) ) {
			runResend( btn );
		} else if ( btn.classList.contains( 'mpfr-btn--reset' ) ) {
			runChunkReset( btn );
		} else if ( btn.classList.contains( 'mpfr-btn--hide' ) ) {
			var campaignId = parseInt( btn.dataset.campaign, 10 );
			hideCampaign( campaignId, btn );
		}
	} );

	// ------------------------------------------------------------------
	// Initial load + periodic refresh
	// ------------------------------------------------------------------
	loadRows();
	setInterval( function () {
		if ( document.hidden ) return;
		loadRows();
	}, 30000 );

	// ------------------------------------------------------------------
	// Resume: on page load, check whether any campaigns have an in-flight
	// resend (e.g. user navigated away and came back) and re-attach polling.
	// ------------------------------------------------------------------
	apiFetch( { path: '/mpfr/v1/failed-campaigns?limit=200' } )
		.then( function ( response ) {
			var rows = ( response && response.rows ) || [];
			var pending = rows.map( function ( r ) { return parseInt( r.id, 10 ) || 0; } ).filter( function ( id ) { return id > 0; } );
			pending.forEach( function ( cid ) {
				apiFetch( { path: '/mpfr/v1/campaign/' + cid + '/resend-progress' } )
					.then( function ( p ) {
						if ( p && ( p.status === 'running' || p.status === 'starting' ) ) {
							cards[ cid ] = pollProgress( cid, '' );
						}
					} )
					.catch( function () {} );
			} );
		} )
		.catch( function () {} );
} )( window.wp, document );
