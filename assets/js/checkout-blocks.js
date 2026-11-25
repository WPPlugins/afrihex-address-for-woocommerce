/**
 * Block Checkout: an inner block in the shipping-address step that mounts the
 * AfriHex field and sends the choice to the Store API as extension data.
 * Written with createElement so it needs no build step.
 */
( function ( wc, wp, window ) {
	'use strict';

	if ( ! wc || ! wc.blocksCheckout || ! wc.wcSettings || ! window.AfriHexAddressWidget ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var cfg = wc.wcSettings.getSetting( 'afrihex-address_data', null );
	var ERROR_ID = 'afrihex-location';
	var NS = 'afrihex-address';

	if ( ! cfg || ! cfg.publishableKey ) {
		return;
	}

	var metadata = {
		name: 'afrihex/delivery-location',
		parent: [ 'woocommerce/checkout-shipping-address-block' ],
		attributes: {
			// Locked blocks are inserted into the Checkout automatically, without editing the page.
			lock: { type: 'object', default: { remove: true, move: true } },
		},
	};

	function norm( s ) {
		return String( s || '' ).toLowerCase().replace( /\bregion\b/, '' ).replace( /[^a-z]/g, '' );
	}

	// Fill empty city/region in the shipping address from the chosen place.
	function fillAddress( loc ) {
		var cart = wp.data.select( 'wc/store/cart' );
		if ( ! cart || ! cart.getCustomerData ) {
			return;
		}
		var addr = cart.getCustomerData().shippingAddress || {};
		var patch = {};
		var city = loc.area || loc.district;
		if ( city && ! addr.city ) {
			patch.city = city;
		}
		if ( loc.region && ! addr.state && ( ! addr.country || addr.country === 'GH' ) && cfg.ghStates ) {
			Object.keys( cfg.ghStates ).some( function ( code ) {
				if ( norm( cfg.ghStates[ code ] ) === norm( loc.region ) ) {
					patch.state = code;
					return true;
				}
				return false;
			} );
		}
		if ( ! Object.keys( patch ).length ) {
			return;
		}
		var store = wp.data.dispatch( 'wc/store/cart' );
		store.setShippingAddress( Object.assign( {}, addr, patch ) );
		// The form only copies shipping to billing on its own edits, so do it here too.
		var checkout = wp.data.select( 'wc/store/checkout' );
		if ( checkout && checkout.getUseShippingAsBilling && checkout.getUseShippingAsBilling() && store.setBillingAddress ) {
			var billing = cart.getCustomerData().billingAddress || {};
			var bpatch = {};
			Object.keys( patch ).forEach( function ( k ) {
				if ( ! billing[ k ] ) {
					bpatch[ k ] = patch[ k ];
				}
			} );
			store.setBillingAddress( Object.assign( {}, billing, bpatch ) );
		}
	}

	function Block( props ) {
		var ref = useRef( null );
		var setExt = useRef( null );
		setExt.current = props.checkoutExtensionData && props.checkoutExtensionData.setExtensionData;

		var error = useSelect( function ( select ) {
			var v = select( 'wc/store/validation' );
			return v && v.getValidationError ? v.getValidationError( ERROR_ID ) : null;
		}, [] );

		useEffect( function () {
			var validation = wp.data.dispatch( 'wc/store/validation' );
			function setMissing( missing ) {
				if ( ! validation ) {
					return;
				}
				if ( missing && cfg.required ) {
					var current = wp.data.select( 'wc/store/validation' ).getValidationError( ERROR_ID );
					var errs = {};
					// Hidden until the customer tries to place the order.
					errs[ ERROR_ID ] = { message: cfg.labels.missing, hidden: current ? current.hidden : true };
					validation.setValidationErrors( errs );
				} else {
					validation.clearValidationError( ERROR_ID );
				}
			}
			setMissing( true );
			var widget = window.AfriHexAddressWidget.mount( ref.current, cfg, function ( loc ) {
				if ( setExt.current ) {
					setExt.current( NS, 'location', loc ? JSON.stringify( loc ) : null );
				}
				setMissing( ! loc );
				if ( loc && loc.lat != null ) {
					fillAddress( loc );
				}
			} );
			return function () {
				widget.destroy();
				if ( validation ) {
					validation.clearValidationError( ERROR_ID );
				}
			};
		}, [] );

		var showError = error && ! error.hidden;
		return el(
			'div',
			{ className: 'afrihex-location-block' + ( showError ? ' has-error' : '' ) },
			el( 'div', { ref: ref } ),
			showError ? el( 'div', { className: 'wc-block-components-validation-error', role: 'alert' }, el( 'p', null, error.message ) ) : null
		);
	}

	wc.blocksCheckout.registerCheckoutBlock( { metadata: metadata, component: Block } );
} )( window.wc, window.wp, window );
