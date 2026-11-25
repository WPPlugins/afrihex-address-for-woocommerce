/**
 * Editor side of the Checkout inner block: a placeholder so shop owners see
 * where the field sits. It is locked in place and appears automatically.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;

	wp.blocks.registerBlockType( 'afrihex/delivery-location', {
		apiVersion: 3,
		title: __( 'AfriHex delivery location', 'afrihex-address-for-woocommerce' ),
		description: __( 'GhanaPostGPS code, place or landmark directions, checked with AfriHex.', 'afrihex-address-for-woocommerce' ),
		category: 'woocommerce',
		icon: 'location',
		parent: [ 'woocommerce/checkout-shipping-address-block' ],
		supports: { multiple: false, reusable: false, html: false, inserter: false },
		attributes: {
			lock: { type: 'object', default: { remove: true, move: true } },
		},
		edit: function () {
			return el(
				'div',
				useBlockProps( { style: { padding: '12px', border: '1px dashed #949494', borderRadius: '4px' } } ),
				el( 'strong', null, __( 'Delivery location', 'afrihex-address-for-woocommerce' ) ),
				el( 'p', { style: { margin: '4px 0 0', fontSize: '13px' } }, __( 'Customers enter a GhanaPostGPS code, a place, or directions from a landmark. Settings: WooCommerce → AfriHex Address.', 'afrihex-address-for-woocommerce' ) )
			);
		},
		save: function () {
			return el( 'div', useBlockProps.save() );
		},
	} );
} )( window.wp );
