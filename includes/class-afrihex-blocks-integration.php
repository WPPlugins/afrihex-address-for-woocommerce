<?php
/**
 * Script integration for the block Checkout.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Loads the checkout block script and passes it the browser config.
 */
class AfriHex_Blocks_Integration implements IntegrationInterface {

	/**
	 * Settings.
	 *
	 * @var AfriHex_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param AfriHex_Settings $settings Settings.
	 */
	public function __construct( AfriHex_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Integration name; the config is read in JS with getSetting( 'afrihex-address_data' ).
	 *
	 * @return string
	 */
	public function get_name() {
		return 'afrihex-address';
	}

	/**
	 * Registers the scripts.
	 */
	public function initialize() {
		AfriHex_Widget::register_assets();
		wp_register_script(
			'afrihex-address-blocks',
			AFRIHEX_ADDRESS_URL . 'assets/js/checkout-blocks.js',
			array( AfriHex_Widget::HANDLE, 'wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-data' ),
			AFRIHEX_ADDRESS_VERSION,
			true
		);
		wp_register_script(
			'afrihex-address-blocks-editor',
			AFRIHEX_ADDRESS_URL . 'assets/js/checkout-blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ),
			AFRIHEX_ADDRESS_VERSION,
			true
		);
		wp_set_script_translations( 'afrihex-address-blocks-editor', 'afrihex-address-for-woocommerce', AFRIHEX_ADDRESS_DIR . 'languages' );
		if ( $this->settings->checkout_enabled() && ! is_admin() ) {
			wp_enqueue_style( AfriHex_Widget::HANDLE );
		}
	}

	/**
	 * Front-end scripts. Nothing loads until a publishable key is set.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return $this->settings->checkout_enabled() ? array( 'afrihex-address-blocks' ) : array();
	}

	/**
	 * Editor scripts.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'afrihex-address-blocks-editor' );
	}

	/**
	 * Data for the script. Only the publishable key; never a secret key.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return AfriHex_Widget::config( $this->settings );
	}
}
