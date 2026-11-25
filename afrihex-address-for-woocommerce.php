<?php
/**
 * Plugin Name:          AfriHex Address for WooCommerce
 * Description:          Customers enter a GhanaPostGPS code, a place or landmark directions at checkout. The address is checked with AfriHex and saved on the order with its GPS code, coordinates and rider directions.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.6
 * WC tested up to:      10.3
 * Author:               AfriHex
 * Author URI:           https://afrihex.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          afrihex-address-for-woocommerce
 * Domain Path:          /languages
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

define( 'AFRIHEX_ADDRESS_VERSION', '0.1.0' );
define( 'AFRIHEX_ADDRESS_FILE', __FILE__ );
define( 'AFRIHEX_ADDRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFRIHEX_ADDRESS_URL', plugin_dir_url( __FILE__ ) );

require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-location.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-api.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-settings.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-verifier.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-widget.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-checkout-classic.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-checkout-blocks.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-order-display.php';
require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-admin-order.php';

// Declare support for High-Performance Order Storage and the block checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AFRIHEX_ADDRESS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AFRIHEX_ADDRESS_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'AfriHex Address needs WooCommerce to be installed and active.', 'afrihex-address-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}

		$settings = new AfriHex_Settings();
		$api      = new AfriHex_API( $settings->api_base_url(), $settings->secret_key() );
		$verifier = new AfriHex_Verifier( $api, $settings );

		$settings->register();
		( new AfriHex_Checkout_Classic( $settings, $verifier ) )->register();
		( new AfriHex_Checkout_Blocks( $settings, $verifier ) )->register();
		( new AfriHex_Order_Display() )->register();
		( new AfriHex_Admin_Order( $verifier ) )->register();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_unschedule_hook( AfriHex_Verifier::RETRY_HOOK );
	}
);
