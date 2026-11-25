<?php
/**
 * Shows the delivery location in emails, on the customer's order page and in the REST API.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only views of the stored record.
 */
class AfriHex_Order_Display {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'woocommerce_email_order_meta', array( $this, 'email' ), 20, 3 );
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'customer_view' ) );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'rest' ), 10, 2 );
	}

	/**
	 * The stored record for an order, or null.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function record( $order ) {
		if ( ! $order instanceof WC_Abstract_Order ) {
			return null;
		}
		$rec = $order->get_meta( AfriHex_Verifier::META );
		return is_array( $rec ) && isset( $rec['status'] ) ? $rec : null;
	}

	/**
	 * Human label for the outcome.
	 *
	 * @param array $rec Record.
	 * @return string
	 */
	public static function verdict_label( array $rec ) {
		if ( AfriHex_Location::STATUS_UNVERIFIED === $rec['status'] ) {
			return __( 'Not checked yet', 'afrihex-address-for-woocommerce' );
		}
		if ( AfriHex_Location::STATUS_SKIPPED === $rec['status'] ) {
			return __( 'No location given', 'afrihex-address-for-woocommerce' );
		}
		switch ( $rec['verdict'] ) {
			case 'accept':
				return __( 'Verified', 'afrihex-address-for-woocommerce' );
			case 'confirm':
				return __( 'Found, confirm with customer', 'afrihex-address-for-woocommerce' );
			case 'fix':
				return __( 'Could not locate', 'afrihex-address-for-woocommerce' );
		}
		return __( 'Checked', 'afrihex-address-for-woocommerce' );
	}

	/**
	 * Label/value rows shared by every view. Values are plain text.
	 *
	 * @param array $rec        Record.
	 * @param bool  $for_staff  Include the check outcome (not shown to customers).
	 * @return array[] Each [ label, value ].
	 */
	public static function rows( array $rec, $for_staff ) {
		$rows = array();
		if ( '' !== $rec['gps_code'] ) {
			$rows[] = array( __( 'GhanaPostGPS', 'afrihex-address-for-woocommerce' ), $rec['gps_code'] );
		}
		$place = '' !== $rec['formatted'] ? $rec['formatted'] : ( isset( $rec['customer']['label'] ) ? $rec['customer']['label'] : '' );
		if ( '' !== $place ) {
			$rows[] = array( __( 'Place', 'afrihex-address-for-woocommerce' ), $place );
		}
		$typed = isset( $rec['customer']['input'] ) ? $rec['customer']['input'] : '';
		if ( '' !== $typed && $typed !== $place && $typed !== $rec['gps_code'] ) {
			$rows[] = array( __( 'Customer wrote', 'afrihex-address-for-woocommerce' ), $typed );
		}
		if ( ! empty( $rec['landmark']['name'] ) ) {
			$lm     = $rec['landmark'];
			$text   = trim( ( '' !== $lm['relation'] ? ucfirst( $lm['relation'] ) . ' ' : '' ) . $lm['name'] );
			$text  .= null !== $lm['distance_m'] ? ' (' . sprintf( /* translators: %d: metres */ __( '%d m', 'afrihex-address-for-woocommerce' ), $lm['distance_m'] ) . ')' : '';
			$rows[] = array( __( 'Landmark', 'afrihex-address-for-woocommerce' ), $text );
		}
		if ( '' !== $rec['entrance'] ) {
			$rows[] = array( __( 'Entrance', 'afrihex-address-for-woocommerce' ), $rec['entrance'] );
		}
		if ( '' !== $rec['directions'] ) {
			$rows[] = array( __( 'Directions', 'afrihex-address-for-woocommerce' ), $rec['directions'] );
		}
		if ( null !== $rec['lat'] ) {
			$rows[] = array( __( 'Coordinates', 'afrihex-address-for-woocommerce' ), $rec['lat'] . ', ' . $rec['lng'] . ( ! empty( $rec['customer']['adjusted'] ) ? ' ' . __( '(pin placed by customer)', 'afrihex-address-for-woocommerce' ) : '' ) );
		}
		if ( $for_staff ) {
			$check = self::verdict_label( $rec );
			if ( null !== $rec['confidence'] ) {
				/* translators: %d: confidence 0-100 */
				$check .= ' · ' . sprintf( __( 'confidence %d/100', 'afrihex-address-for-woocommerce' ), $rec['confidence'] );
			}
			if ( '' !== $rec['granularity'] ) {
				/* translators: %s: e.g. premise, landmark, street */
				$check .= ' · ' . sprintf( __( 'located to %s', 'afrihex-address-for-woocommerce' ), $rec['granularity'] );
			}
			if ( 'test' === $rec['mode'] ) {
				$check .= ' · ' . __( 'TEST MODE', 'afrihex-address-for-woocommerce' );
			}
			$rows[] = array( __( 'AfriHex check', 'afrihex-address-for-woocommerce' ), $check );
		}
		return $rows;
	}

	/**
	 * Order emails (customer and admin), HTML and plain text.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether this copy goes to the shop.
	 * @param bool     $plain_text    Plain-text email.
	 */
	public function email( $order, $sent_to_admin, $plain_text ) {
		$rec = self::record( $order );
		if ( ! $rec || AfriHex_Location::STATUS_SKIPPED === $rec['status'] ) {
			return;
		}
		$rows = self::rows( $rec, (bool) $sent_to_admin );
		$map  = AfriHex_Location::map_url( $rec );
		if ( $plain_text ) {
			echo "\n" . esc_html( wc_strtoupper( __( 'Delivery location', 'afrihex-address-for-woocommerce' ) ) ) . "\n\n";
			foreach ( $rows as $r ) {
				echo esc_html( $r[0] . ': ' . $r[1] ) . "\n";
			}
			if ( $map ) {
				echo esc_html__( 'Map', 'afrihex-address-for-woocommerce' ) . ': ' . esc_url_raw( $map ) . "\n";
			}
			return;
		}
		echo '<h2>' . esc_html__( 'Delivery location', 'afrihex-address-for-woocommerce' ) . '</h2>';
		echo '<table class="td" cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:24px" border="1">';
		foreach ( $rows as $r ) {
			echo '<tr><th class="td" scope="row" style="text-align:left">' . esc_html( $r[0] ) . '</th><td class="td">' . esc_html( $r[1] ) . '</td></tr>';
		}
		if ( $map ) {
			echo '<tr><th class="td" scope="row" style="text-align:left">' . esc_html__( 'Map', 'afrihex-address-for-woocommerce' ) . '</th><td class="td"><a href="' . esc_url( $map ) . '">' . esc_html__( 'Open in Google Maps', 'afrihex-address-for-woocommerce' ) . '</a></td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Thank-you page and My Account → Orders.
	 *
	 * @param WC_Order $order Order.
	 */
	public function customer_view( $order ) {
		$rec = self::record( $order );
		if ( ! $rec || AfriHex_Location::STATUS_SKIPPED === $rec['status'] ) {
			return;
		}
		echo '<section class="woocommerce-afrihex-location"><h2 class="woocommerce-column__title">' . esc_html__( 'Delivery location', 'afrihex-address-for-woocommerce' ) . '</h2><table class="woocommerce-table shop_table"><tbody>';
		foreach ( self::rows( $rec, false ) as $r ) {
			echo '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . esc_html( $r[1] ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Adds `afrihex_location` to REST API v3 order responses, so delivery
	 * partners reading orders over the API get the location without parsing meta.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WC_Order         $order    Order.
	 * @return WP_REST_Response
	 */
	public function rest( $response, $order ) {
		$rec = self::record( $order );
		if ( ! $rec ) {
			return $response;
		}
		$data = array(
			'status'      => $rec['status'],
			'verdict'     => '' !== $rec['verdict'] ? $rec['verdict'] : null,
			'confidence'  => $rec['confidence'],
			'gps_code'    => '' !== $rec['gps_code'] ? $rec['gps_code'] : null,
			'lat'         => $rec['lat'],
			'lng'         => $rec['lng'],
			'granularity' => '' !== $rec['granularity'] ? $rec['granularity'] : null,
			'formatted'   => '' !== $rec['formatted'] ? $rec['formatted'] : null,
			'landmark'    => $rec['landmark'],
			'entrance'    => '' !== $rec['entrance'] ? $rec['entrance'] : null,
			'directions'  => '' !== $rec['directions'] ? $rec['directions'] : null,
			'hex'         => '' !== $rec['hex'] ? $rec['hex'] : null,
			'issues'      => $rec['issues'],
			'customer'    => $rec['customer'],
			'map_url'     => AfriHex_Location::map_url( $rec ),
			'mode'        => $rec['mode'],
			'checked_at'  => $rec['checked_at'],
		);
		/**
		 * Filters the delivery location added to REST API order responses.
		 *
		 * @param array    $data  Location data.
		 * @param WC_Order $order Order.
		 */
		$response->data['afrihex_location'] = apply_filters( 'afrihex_address_rest_order_location', $data, $order );
		return $response;
	}
}
