<?php
/**
 * The field on the block-based Checkout.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Adds an inner block to the Checkout's shipping-address step, sends the
 * selection through the Store API as extension data, and checks it before payment.
 */
class AfriHex_Checkout_Blocks {

	const NAMESPACE_KEY = 'afrihex-address';

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
	 * Record stored during this request, for the order note.
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
	 * Hooks. The Store API and blocks integration exist from WooCommerce 8.x.
	 */
	public function register() {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'blocks_loaded' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'update_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'processed' ) );
	}

	/**
	 * Registers the Store API schema and the script integration.
	 */
	public function blocks_loaded() {
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => CheckoutSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE_KEY,
					'schema_callback' => array( $this, 'schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}
		require_once AFRIHEX_ADDRESS_DIR . 'includes/class-afrihex-blocks-integration.php';
		$settings = $this->settings;
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			static function ( $registry ) use ( $settings ) {
				$registry->register( new AfriHex_Blocks_Integration( $settings ) );
			}
		);
	}

	/**
	 * Store API schema for the extension data.
	 *
	 * @return array
	 */
	public function schema() {
		return array(
			'location' => array(
				'description' => __( 'The delivery location the customer chose, as JSON.', 'afrihex-address-for-woocommerce' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
				'arg_options' => array(
					// Parsed and sanitized field by field in AfriHex_Location::from_customer().
					'validate_callback' => static function ( $value ) {
						return null === $value || ( is_string( $value ) && strlen( $value ) <= 5000 );
					},
				),
			),
		);
	}

	/**
	 * Whether the field is on this checkout (shipping address step shown, key set).
	 *
	 * @return bool
	 */
	private function active() {
		return $this->settings->checkout_enabled() && function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping();
	}

	/**
	 * Checks and stores the location. Throws only for a missing location or one
	 * the shop chose to reject; an unreachable API never blocks the order.
	 *
	 * @param WC_Order        $order   Draft order.
	 * @param WP_REST_Request $request Checkout request.
	 * @throws RouteException When the customer must fix the location.
	 */
	public function update_order( $order, $request ) {
		if ( ! $this->active() ) {
			return;
		}
		$ext      = $request->get_param( 'extensions' );
		$raw      = is_array( $ext ) && isset( $ext[ self::NAMESPACE_KEY ]['location'] ) ? $ext[ self::NAMESPACE_KEY ]['location'] : '';
		$customer = AfriHex_Location::from_customer( is_string( $raw ) ? $raw : '' );

		$reason = $this->verifier->blocking_reason( $customer );
		if ( '' !== $reason ) {
			throw new RouteException( 'afrihex_location_required', esc_html( $reason ), 400 );
		}

		$rec    = $this->verifier->verify( $customer, AfriHex_Verifier::order_address_text( $order ) );
		$reason = $this->verifier->blocking_reason( $customer, $rec );
		if ( '' !== $reason ) {
			throw new RouteException( 'afrihex_location_not_found', esc_html( $reason ), 400 );
		}
		$this->verifier->store( $order, $rec );
		$this->pending = $rec;
	}

	/**
	 * After payment is processed: note and retry scheduling.
	 *
	 * @param WC_Order $order Order.
	 */
	public function processed( $order ) {
		if ( null === $this->pending || ! $order ) {
			return;
		}
		$order->add_order_note( AfriHex_Verifier::note( $this->pending ) );
		$this->verifier->after_save( $order, $this->pending );
	}
}
