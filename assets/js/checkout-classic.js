/**
 * Classic checkout: mounts the AfriHex field and keeps the hidden input in sync.
 */
( function ( $, window, document ) {
	'use strict';

	var cfg = window.afrihexAddress;
	var mounted = false;

	function norm( s ) {
		return String( s || '' ).toLowerCase().replace( /\bregion\b/, '' ).replace( /[^a-z]/g, '' );
	}

	// Fill empty city/region fields from the chosen place. Never overwrite what the customer typed.
	function fillAddress( loc ) {
		var type = $( '#ship-to-different-address-checkbox' ).is( ':checked' ) ? 'shipping' : 'billing';
		var $city = $( '#' + type + '_city' );
		var city = loc.area || loc.district;
		if ( city && $city.length && ! $city.val() ) {
			$city.val( city ).trigger( 'change' );
		}
		var $state = $( '#' + type + '_state' );
		if ( loc.region && $state.length && ! $state.val() && cfg.ghStates ) {
			Object.keys( cfg.ghStates ).some( function ( code ) {
				if ( norm( cfg.ghStates[ code ] ) === norm( loc.region ) ) {
					$state.val( code ).trigger( 'change' );
					return true;
				}
				return false;
			} );
		}
	}

	function mount() {
		var el = document.getElementById( 'afrihex-location-field' );
		var hidden = document.getElementById( 'afrihex_location' );
		if ( mounted || ! el || ! hidden || ! cfg || ! window.AfriHexAddressWidget ) {
			return;
		}
		mounted = true;
		var initial = null;
		try {
			initial = hidden.value ? JSON.parse( hidden.value ) : null;
		} catch ( e ) {
			initial = null;
		}
		window.AfriHexAddressWidget.mount( el, $.extend( {}, cfg, { initial: initial } ), function ( loc ) {
			hidden.value = loc ? JSON.stringify( loc ) : '';
			if ( loc && loc.lat != null ) {
				fillAddress( loc );
			}
		} );
	}

	$( mount );
	$( document.body ).on( 'updated_checkout', mount );
} )( jQuery, window, document );
