<?php
/**
 * Front-end assets and configuration shared by both checkouts.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the widget scripts and builds the browser config. Only the
 * publishable key goes to the browser; secret keys never do.
 */
class AfriHex_Widget {

	const HANDLE = 'afrihex-address-widget';

	/**
	 * Registers (does not enqueue) the shared scripts and styles.
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$v = AFRIHEX_ADDRESS_VERSION;
		wp_register_style( 'afrihex-leaflet', AFRIHEX_ADDRESS_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'afrihex-leaflet', AFRIHEX_ADDRESS_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_register_style( self::HANDLE, AFRIHEX_ADDRESS_URL . 'assets/css/widget.css', array( 'afrihex-leaflet' ), $v );
		wp_register_script( self::HANDLE, AFRIHEX_ADDRESS_URL . 'assets/js/widget.js', array( 'afrihex-leaflet' ), $v, true );
	}

	/**
	 * The browser config.
	 *
	 * @param AfriHex_Settings $settings Settings.
	 * @param array|null       $initial  A previous selection to restore.
	 * @return array
	 */
	public static function config( AfriHex_Settings $settings, $initial = null ) {
		$states = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( 'GH' ) : array();
		return array(
			'publishableKey' => (string) $settings->get( 'publishable_key' ),
			'baseURL'        => $settings->browser_api_base_url(),
			'showMap'        => (bool) $settings->get( 'show_map' ),
			'tileUrl'        => (string) $settings->get( 'tile_url' ),
			'required'       => (bool) $settings->get( 'required' ),
			'ghStates'       => is_array( $states ) ? $states : array(),
			'initial'        => $initial,
			'labels'         => self::labels(),
		);
	}

	/**
	 * Translatable strings for the widget.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'label'           => __( 'Delivery location', 'afrihex-address-for-woocommerce' ),
			'required'        => __( 'required', 'afrihex-address-for-woocommerce' ),
			'placeholder'     => __( 'GhanaPostGPS code, place, or directions', 'afrihex-address-for-woocommerce' ),
			'help'            => __( 'E.g. GA-183-8164, "Accra Mall", or "opposite Goil, Madina Zongo Junction".', 'afrihex-address-for-woocommerce' ),
			'locate'          => __( 'Use my current location', 'afrihex-address-for-woocommerce' ),
			'locating'        => __( 'Finding you…', 'afrihex-address-for-woocommerce' ),
			'currentLocation' => __( 'Current location', 'afrihex-address-for-woocommerce' ),
			'noResults'       => __( 'No matches. Try a landmark near you, or a GhanaPostGPS code.', 'afrihex-address-for-woocommerce' ),
			'locationDenied'  => __( 'Location is off. Allow it in your browser, or type your address.', 'afrihex-address-for-woocommerce' ),
			'offline'         => __( 'Suggestions are not available right now. Type your address or directions; we will check it after you order.', 'afrihex-address-for-woocommerce' ),
			'directions'      => __( 'Directions', 'afrihex-address-for-woocommerce' ),
			'dragHint'        => __( 'Not quite right? Drag the pin, or tap the map, to mark your gate.', 'afrihex-address-for-woocommerce' ),
			'pinMoved'        => __( 'Pin moved. We will deliver to the new spot.', 'afrihex-address-for-woocommerce' ),
			'mapLabel'        => __( 'Map of your delivery location', 'afrihex-address-for-woocommerce' ),
			'missing'         => __( 'Please enter your delivery location.', 'afrihex-address-for-woocommerce' ),
		);
	}
}
