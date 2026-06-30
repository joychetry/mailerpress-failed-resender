/**
 * MailerPress Failed Resender — admin page controller.
 *
 * Loads failed campaigns from our own REST endpoint
 * ( /wp-json/mpfr/v1/failed-campaigns ) and wires the per-row
 * "Retry Failed Emails" and "Reset Chunks" buttons to:
 *   POST /wp-json/mpfr/v1/campaign/{id}/resend-failed  (resend every failed recipient)
 *   POST /wp-json/mpfr/v1/batch/{id}/reset             (zero counters, reset all chunks)
 *
 * No external dependencies beyond wp-api-fetch (bundled with WordPress core).
 */
( function ( wp, document ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch ) {
		// Core not loaded yet — bail; user will see "Loading…" until ready.
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

	/**
	 * Render a status banner above the table.
	 *
	 * @param {string} type    'success' | 'error' | 'info'
	 * @param {string} message
	 */
	function setStatus( type, message ) {
		if ( ! elStatus ) {
			return;
		}
		elStatus.className = 'mpfr-status mpfr-status--' + type;
		elStatus.textContent = message;
		elStatus.hidden = false;

		// Auto-dismiss success/info after 5s.
		if ( type === 'success' || type === 'info' ) {
			setTimeout( function () {
				elStatus.hidden = true;
			}, 5000 );
		}
	}

	/**
	 * Format a date string for display.
	 *
	 * @param {string} iso
	 * @returns {string}
	 */
	function formatDate( iso ) {
		if ( ! iso ) {
			return '—';
		}
		try {
			var d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
			if ( isNaN( d.getTime() ) ) {
				return iso;
			}
			return d.toLocaleString();
		} catch ( e ) {
			return iso;
		}
	}

	/**
	 * Escape HTML in untrusted text.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function esc( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Build the table rows from the API response.
	 *
	 * @param {Array} rows
	 */
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
						'<button type="button" class="button button-primary mpfr-btn mpfr-btn--resend" data-campaign="', campaignId, '">',
							'<span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ',
							esc( __( 'Retry Failed Emails', 'mpfr' ) ),
						'</button> ',
						'<button type="button" class="button mpfr-btn mpfr-btn--reset" data-batch="', batchPk, '">',
							esc( __( 'Reset Chunks', 'mpfr' ) ),
						'</button>',
					'</td>',
				'</tr>'
			].join( '' );
		} ).join( '' );
	}

	/**
	 * Load the failed campaigns list from our endpoint.
	 *
	 * @returns {Promise}
	 */
	function loadRows() {
		setStatus( 'info', __( 'Loading…', 'mpfr' ) );
		return apiFetch( { path: '/mpfr/v1/failed-campaigns?limit=200' } )
			.then( function ( response ) {
				renderRows( response.rows || [] );
				elStatus.hidden = true;
			} )
			.catch( function ( err ) {
				elTbody.innerHTML = '<tr><td colspan="8" class="mpfr-error">' +
					esc( sprintf( __( 'Failed to load: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) ) +
					'</td></tr>';
				setStatus( 'error', sprintf( __( 'Failed to load: %s', 'mpfr' ), ( err && err.message ) || 'unknown' ) );
			} );
	}

	/**
	 * Toggle button state while an action is running.
	 *
	 * @param {HTMLButtonElement} btn
	 * @param {boolean} busy
	 * @param {string} [busyLabel]
	 */
	function setBusy( btn, busy, busyLabel ) {
		if ( busy ) {
			btn.dataset.busy = '1';
			btn.dataset.label = btn.textContent;
			btn.disabled = true;
			btn.textContent = busyLabel || __( 'Working…', 'mpfr' );
		} else {
			btn.disabled = false;
			btn.textContent = btn.dataset.label || btn.textContent;
			delete btn.dataset.busy;
		}
	}

	/**
	 * Resend every failed email for a campaign.
	 */
	function runResend( btn ) {
		var campaignId = parseInt( btn.dataset.campaign, 10 );
		if ( ! campaignId ) {
			setStatus( 'error', __( 'Invalid campaign id.', 'mpfr' ) );
			return;
		}

		var ok = window.confirm( __( 'Resend every failed email for this campaign through the active ESP?', 'mpfr' ) );
		if ( ! ok ) {
			return;
		}

		setBusy( btn, true, __( 'Resending…', 'mpfr' ) );
		setStatus( 'info', __( 'Resending failed emails…', 'mpfr' ) );

		apiFetch( { path: '/mpfr/v1/campaign/' + campaignId + '/resend-failed', method: 'POST' } )
			.then( function ( response ) {
				var msg = response && response.message
					? response.message
					: sprintf( __( 'Resent: %d.', 'mpfr' ), response.resent || 0 );
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

	/**
	 * Reset chunks (legacy chunk-based retry, calls /batch/{id}/reset).
	 */
	function runChunkReset( btn ) {
		var batchId = parseInt( btn.dataset.batch, 10 );
		if ( ! batchId ) {
			setStatus( 'error', __( 'Invalid batch id.', 'mpfr' ) );
			return;
		}
		var ok = window.confirm( __( 'This will reset ALL chunks of the batch (including completed) and zero the sent/error counters. Continue?', 'mpfr' ) );
		if ( ! ok ) {
			return;
		}

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

	// ----- Event delegation ---------------------------------------------

	if ( elRefresh ) {
		elRefresh.addEventListener( 'click', function () {
			loadRows();
		} );
	}

	elTbody.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button.mpfr-btn' );
		if ( ! btn || btn.dataset.busy === '1' ) {
			return;
		}
		if ( btn.classList.contains( 'mpfr-btn--resend' ) ) {
			runResend( btn );
		} else if ( btn.classList.contains( 'mpfr-btn--reset' ) ) {
			runChunkReset( btn );
		}
	} );

	// Initial load.
	loadRows();

	// Auto-refresh every 30 seconds.
	setInterval( function () {
		if ( document.hidden ) {
			return;
		}
		loadRows();
	}, 30000 );
} )( window.wp, document );
