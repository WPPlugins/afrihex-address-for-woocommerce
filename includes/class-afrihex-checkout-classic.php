<?php
/**
 * The field on the classic ([woocommerce_checkout] shortcode) checkout.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the field after the billing form, checks it on submit and saves it on the order.
 *
 * Nonces: these hooks run inside WC_Checkout::process_checkout(), which has
 * already verified the woocommerce-process-checkout-nonce before calling them.
 */
class AfriHex_Checkout_Classic {

	const FIELD = 'afrihex_location';

	/**
	 * Settings.
	 *
	 * @var AfriHex_Settings
	 */
	private $settings;

	/**
	 * Verifier.
	 *
	 * @var AfriHex_Verifier
	 */
	private $verifier;

	/**
	 * Result from the validation step, reused when the order is created.
	 *
	 * @var array|null
	 */
	private $pending = null;

	/**
	 * Constructor.
	 *
	 * @param AfriHex_Settings $settings Settings.
	 * @param AfriHex_Verifier $verifier Verifier.
	 */
	public function __construct( AfriHex_Settings $settings, AfriHex_Verifier $verifier ) {
		$this->settings = $settings;
		$this->verifier = $verifier;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'processed' ), 10, 3 );
	}

	/**
	 * Whether the field belongs on this checkout.
	 *
	 * @return bool
	 */
	private function active() {
		return $this->settings->checkout_enabled() && function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping();
	}

	/**
	 * Loads the widget on the classic checkout page only.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! $this->active() ) {
			return;
		}
		// The block checkout loads its own script; has_block() tells the two apart.
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return;
		}
		AfriHex_Widget::register_assets();
		wp_enqueue_style( AfriHex_Widget::HANDLE );
		wp_enqueue_script( 'afrihex-address-classic', AFRIHEX_ADDRESS_URL . 'assets/js/checkout-classic.js', array( AfriHex_Widget::HANDLE, 'jquery' ), AFRIHEX_ADDRESS_VERSION, true );
		wp_add_inline_script( 'afrihex-address-classic', 'window.afrihexAddress = ' . wp_json_encode( AfriHex_Widget::config( $this->settings ) ) . ';', 'before' );
	}

	/**
	 * Prints the field container and the hidden input the script fills.
	 */
	public function render() {
		if ( ! $this->active() ) {
			return;
		}
		echo '<div id="afrihex-location-field" class="form-row form-row-wide afrihex-location-field"></div>';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" value="">';
		echo '<noscript><p>' . esc_html__( 'Enter your delivery address in the address fields above.', 'afrihex-address-for-woocommerce' ) . '</p></noscript>';
	}

	/**
	 * The customer's selection from the posted form.
	 *
	 * @return array
	 */
	private function posted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by WC_Checkout; the JSON is sanitized field by field in from_customer().
		$raw = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';
		return AfriHex_Location::from_customer( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * Stops checkout only for a missing location or (if the shop chose to) one
	 * AfriHex is sure it can't locate. An unreachable API never stops it.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors to add to.
	 */
	public function validate( $data, $errors ) {
		if ( ! $this->active() ) {
			return;
		}
		$customer = $this->posted();
		$reason   = $this->verifier->blocking_reason( $customer );
		if ( '' === $reason && $this->settings->get( 'block_on_fix' ) ) {
			$this->pending = $this->verifier->verify( $customer, $this->posted_address_text( $data ) );
			$reason        = $this->verifier->blocking_reason( $customer, $this->pending );
		}
		if ( '' !== $reason ) {
			$errors->add( 'afrihex_location', $reason, array( 'id' => 'afrihex-location-field' ) );
		}
	}

	/**
	 * Checks (if not done yet) and stores the location on the new order.
	 *
	 * @param WC_Order $order Order being created.
	 */
	public function save( $order ) {
		if ( ! $this->active() ) {
			return;
		}
		$rec = $this->pending;
		if ( null === $rec ) {
			$rec = $this->verifier->verify( $this->posted(), AfriHex_Verifier::order_address_text( $order ) );
		}
		$this->verifier->store( $order, $rec );
		$this->pending = $rec;
	}

	/**
	 * After the order is saved: note and retry scheduling.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 */
	public function processed( $order_id, $posted, $order ) {
		if ( null === $this->pending || ! $order ) {
			return;
		}
		$order->add_order_note( AfriHex_Verifier::note( $this->pending ) );
		$this->verifier->after_save( $order, $this->pending );
	}

	/**
	 * The address lines from posted checkout data.
	 *
	 * @param array $data Posted data.
	 * @return string
	 */
	private function posted_address_text( $data ) {
		$type  = ! empty( $data['ship_to_different_address'] ) ? 'shipping' : 'billing';
		$parts = array();
		foreach ( array( 'address_1', 'address_2', 'city' ) as $f ) {
			if ( ! empty( $data[ $type . '_' . $f ] ) ) {
				$parts[] = $data[ $type . '_' . $f ];
			}
		}
		return implode( ', ', $parts );
	}
}
