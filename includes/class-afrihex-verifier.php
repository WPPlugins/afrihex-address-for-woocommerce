<?php
/**
 * Checks a delivery location with AfriHex and stores the result on the order.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the classic checkout, the block checkout, the admin retry button and the retry cron.
 */
class AfriHex_Verifier {

	const META        = '_afrihex_location';
	const META_STATUS = '_afrihex_status';
	const META_CODE   = '_afrihex_gps_code';
	const META_TRIES  = '_afrihex_retry_count';
	const RETRY_HOOK  = 'afrihex_address_retry';

	/** Automatic retries for an order saved while AfriHex was unreachable: 5 min, 30 min, 3 h. */
	const RETRY_DELAYS = array( 300, 1800, 10800 );

	/**
	 * API client.
	 *
	 * @var AfriHex_API
	 */
	private $api;

	/**
	 * Settings.
	 *
	 * @var AfriHex_Settings
	 */
	private $settings;

	/**
	 * Results already fetched in this request, keyed by input, so the classic
	 * checkout's validate-then-save doesn't call the API twice.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Constructor.
	 *
	 * @param AfriHex_API      $api      Client.
	 * @param AfriHex_Settings $settings Settings.
	 */
	public function __construct( AfriHex_API $api, AfriHex_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
		add_action( self::RETRY_HOOK, array( $this, 'cron_retry' ) );
	}

	/**
	 * Settings, for the checkout classes.
	 *
	 * @return AfriHex_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Checks what the customer gave. Never throws: on any failure the record is "unverified".
	 *
	 * @param array  $customer      From AfriHex_Location::from_customer().
	 * @param string $fallback_text The order's own address lines.
	 * @return array Stored record.
	 */
	public function verify( array $customer, $fallback_text = '' ) {
		$mode = $this->settings->mode();
		$body = AfriHex_Location::validate_body( $customer, $fallback_text );
		if ( empty( $body ) ) {
			return AfriHex_Location::unverified( $customer, '', $mode );
		}
		$memo_key = md5( wp_json_encode( $body ) );
		if ( isset( $this->memo[ $memo_key ] ) ) {
			return $this->memo[ $memo_key ];
		}

		if ( empty( $customer ) ) {
			// No widget data (JS failed or field skipped): check the typed address lines.
			$customer = AfriHex_Location::from_customer( array( 'input' => $body['address'] ) );
		}

		$data = $this->api->validate( $body );
		if ( is_wp_error( $data ) ) {
			$rec = AfriHex_Location::unverified( $customer, $data->get_error_code() . ': ' . $data->get_error_message(), $mode );
			// Don't memoise failures: a later call in the same request may succeed.
			return $rec;
		}

		// Directions like "behind Total, Madina, 2nd gate, blue gate" carry entrance hints.
		$resolve = null;
		$text    = isset( $body['address'] ) ? $body['address'] : '';
		if ( '' !== $text && AfriHex_Location::has_directions( $text ) ) {
			$r = $this->api->resolve( $text, isset( $body['point'] ) ? $body['point'] : null );
			if ( ! is_wp_error( $r ) ) {
				$resolve = $r;
			}
		}

		$rec                     = AfriHex_Location::verified( $customer, $data, $resolve, $mode );
		$this->memo[ $memo_key ] = $rec;
		return $rec;
	}

	/**
	 * Why checkout should stop, or '' to let it through. Never stops for an
	 * unreachable API: only for a missing location or a confirmed bad one.
	 *
	 * @param array $customer Customer selection.
	 * @param array $rec      Result of verify(), or null when not checked yet.
	 * @return string Message for the customer.
	 */
	public function blocking_reason( array $customer, $rec = null ) {
		if ( empty( $customer ) && $this->settings->get( 'required' ) ) {
			return __( 'Please enter your delivery location: a GhanaPostGPS code, a place, or directions from a landmark.', 'afrihex-address-for-woocommerce' );
		}
		if ( is_array( $rec ) && $this->settings->get( 'block_on_fix' ) && AfriHex_Location::STATUS_VERIFIED === $rec['status'] && 'fix' === $rec['verdict'] ) {
			$msg = __( 'We could not find that delivery location. Please check the GhanaPostGPS code, or describe it from a nearby landmark.', 'afrihex-address-for-woocommerce' );
			foreach ( $rec['issues'] as $issue ) {
				if ( 'error' === $issue['severity'] && '' !== $issue['message'] ) {
					$msg .= ' ' . $issue['message'];
					break;
				}
			}
			return $msg;
		}
		return '';
	}

	/**
	 * The order's own address lines as one string, used as the text to check
	 * when the customer didn't use the field.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_address_text( $order ) {
		$type  = $order->has_shipping_address() ? 'shipping' : 'billing';
		$parts = array();
		foreach ( array( 'address_1', 'address_2', 'city' ) as $f ) {
			$v = $order->{"get_{$type}_{$f}"}();
			if ( '' !== (string) $v ) {
				$parts[] = $v;
			}
		}
		$state   = $order->{"get_{$type}_state"}();
		$country = $order->{"get_{$type}_country"}();
		if ( $state && $country && function_exists( 'WC' ) ) {
			$states  = WC()->countries->get_states( $country );
			$parts[] = isset( $states[ $state ] ) ? $states[ $state ] : $state;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Saves a record on the order (does not call $order->save()).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $rec   Record.
	 */
	public function store( $order, array $rec ) {
		$order->update_meta_data( self::META, $rec );
		$order->update_meta_data( self::META_STATUS, $rec['status'] . ( '' !== $rec['verdict'] ? ':' . $rec['verdict'] : '' ) );
		$order->update_meta_data( self::META_CODE, $rec['gps_code'] );
	}

	/**
	 * After the order exists: note the outcome and queue a retry if the check didn't run.
	 *
	 * @param WC_Order $order Saved order.
	 * @param array    $rec   Record.
	 */
	public function after_save( $order, array $rec ) {
		if ( AfriHex_Location::STATUS_UNVERIFIED === $rec['status'] ) {
			$this->schedule_retry( $order->get_id(), 0 );
		}
	}

	/**
	 * Re-checks an order from what the customer gave (or a corrected code from the shop).
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $new_code  Optional GhanaPostGPS code entered by the shop.
	 * @return array New record.
	 */
	public function recheck( $order, $new_code = '' ) {
		$old      = $order->get_meta( self::META );
		$customer = is_array( $old ) && ! empty( $old['customer'] ) ? $old['customer'] : array();
		$customer = AfriHex_Location::from_customer( $customer );
		$code     = AfriHex_Location::gps_code( $new_code );
		if ( '' !== $code ) {
			// The shop corrected the code (e.g. after calling the customer): trust it over the old pin.
			$customer = AfriHex_Location::from_customer(
				array(
					'input'      => $code,
					'gps_code'   => $code,
					'source'     => 'ghanapostgps',
					'directions' => isset( $customer['directions'] ) ? $customer['directions'] : '',
				)
			);
		}
		$this->memo = array();
		$rec        = $this->verify( $customer, self::order_address_text( $order ) );
		$this->store( $order, $rec );
		$order->save();
		return $rec;
	}

	/**
	 * Cron: retry an unverified order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function cron_retry( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}
		$old = $order->get_meta( self::META );
		if ( ! is_array( $old ) || AfriHex_Location::STATUS_UNVERIFIED !== $old['status'] ) {
			return; // Checked already (maybe by hand).
		}
		$tries = (int) $order->get_meta( self::META_TRIES ) + 1;
		$order->update_meta_data( self::META_TRIES, $tries );
		$rec = $this->recheck( $order );
		if ( AfriHex_Location::STATUS_UNVERIFIED === $rec['status'] ) {
			$this->schedule_retry( $order->get_id(), $tries );
		} else {
			$order->add_order_note( self::note( $rec ) );
		}
	}

	/**
	 * Queues the next automatic retry, if any are left.
	 *
	 * @param int $order_id Order ID.
	 * @param int $tries    Retries done so far.
	 */
	private function schedule_retry( $order_id, $tries ) {
		if ( ! isset( self::RETRY_DELAYS[ $tries ] ) ) {
			return;
		}
		$args = array( (int) $order_id );
		if ( ! wp_next_scheduled( self::RETRY_HOOK, $args ) ) {
			wp_schedule_single_event( time() + self::RETRY_DELAYS[ $tries ], self::RETRY_HOOK, $args );
		}
	}

	/**
	 * A one-line order note for the outcome.
	 *
	 * @param array $rec Record.
	 * @return string
	 */
	public static function note( array $rec ) {
		if ( AfriHex_Location::STATUS_VERIFIED === $rec['status'] ) {
			/* translators: 1: verdict (accept/confirm/fix), 2: GhanaPostGPS code or "-" */
			return sprintf( __( 'AfriHex checked the delivery location: %1$s (%2$s).', 'afrihex-address-for-woocommerce' ), AfriHex_Order_Display::verdict_label( $rec ), '' !== $rec['gps_code'] ? $rec['gps_code'] : '-' );
		}
		if ( AfriHex_Location::STATUS_SKIPPED === $rec['status'] ) {
			return __( 'No delivery location was given to check.', 'afrihex-address-for-woocommerce' );
		}
		/* translators: %s: error from the API */
		return sprintf( __( 'AfriHex could not check the delivery location yet (%s). Saved what the customer entered; it will be retried.', 'afrihex-address-for-woocommerce' ), $rec['error'] );
	}
}
