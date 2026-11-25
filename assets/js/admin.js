/**
 * Admin: "Check again" on the order screen and "Test secret key" on the settings page.
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.afrihexAdmin || {};

	function post( params, out, done ) {
		out.textContent = cfg.i18n.checking;
		out.style.color = '';
		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams( params ) } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				out.textContent = ( r.data && r.data.message ) || '';
				out.style.color = r.success ? '#007017' : '#b32d2e';
				if ( done ) { done( r ); }
			} )
			.catch( function () { out.textContent = cfg.i18n.failed; out.style.color = '#b32d2e'; } );
	}

	var recheck = document.getElementById( 'afx-recheck' );
	if ( recheck ) {
		recheck.addEventListener( 'click', function () {
			var code = document.getElementById( 'afx-recheck-code' );
			recheck.disabled = true;
			post( {
				action: 'afrihex_recheck',
				order_id: recheck.dataset.order,
				gps_code: code ? code.value : '',
				_ajax_nonce: recheck.dataset.nonce,
			}, document.getElementById( 'afx-recheck-result' ), function ( r ) {
				recheck.disabled = false;
				if ( r.success ) { window.location.reload(); }
			} );
		} );
	}

	var test = document.getElementById( 'afx-test-connection' );
	if ( test ) {
		test.addEventListener( 'click', function () {
			post( { action: 'afrihex_test_connection', _ajax_nonce: test.dataset.nonce }, document.getElementById( 'afx-test-result' ) );
		} );
	}
} )( window, document );
