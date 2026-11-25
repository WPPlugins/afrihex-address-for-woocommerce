<?php
/**
 * Server-side AfriHex API calls, made with the shop's secret key.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * A thin wrapper over wp_remote_request for the two calls checkout needs.
 */
class AfriHex_API {

	const DEFAULT_BASE_URL = 'https://api.afrihex.com';

	/**
	 * API base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Secret (afx_live_ / afx_test_) key. Never leaves the server.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $base_url API base URL.
	 * @param string $secret   Secret key.
	 */
	public function __construct( $base_url, $secret ) {
		$this->base = untrailingslashit( $base_url ? $base_url : self::DEFAULT_BASE_URL );
		$this->key  = (string) $secret;
	}

	/**
	 * Whether a secret key is configured.
	 *
	 * @return bool
	 */
	public function has_key() {
		return '' !== $this->key;
	}

	/**
	 * POST /v2/address/validate.
	 *
	 * @param array $body Request body (address, gps_code, point).
	 * @return array|WP_Error The response `data`.
	 */
	public function validate( array $body ) {
		return $this->post( '/v2/address/validate', $body, 8 );
	}

	/**
	 * POST /v2/address/resolve: landmark directions to a search area and rider hints.
	 *
	 * @param string     $text Customer directions.
	 * @param array|null $near Point to rank landmarks by.
	 * @return array|WP_Error The response `data`.
	 */
	public function resolve( $text, $near = null ) {
		$body = array( 'text' => $text );
		if ( is_array( $near ) ) {
			$body['near'] = $near;
		}
		return $this->post( '/v2/address/resolve', $body, 4 );
	}

	/**
	 * Sends a JSON POST and unwraps the `data` envelope.
	 *
	 * @param string $path    API path.
	 * @param array  $body    JSON body.
	 * @param int    $timeout Seconds. Checkout is waiting, so keep it short.
	 * @return array|WP_Error
	 */
	private function post( $path, array $body, $timeout ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'afrihex_no_key', __( 'No AfriHex secret key is set.', 'afrihex-address-for-woocommerce' ) );
		}
		$res = wp_remote_post(
			$this->base . $path,
			array(
				'timeout'    => $timeout,
				'headers'    => array(
					'X-API-Key'    => $this->key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'user-agent' => 'afrihex-woocommerce/' . ( defined( 'AFRIHEX_ADDRESS_VERSION' ) ? AFRIHEX_ADDRESS_VERSION : 'dev' ),
				'body'       => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$json   = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $status < 200 || $status >= 300 ) {
			$code = is_array( $json ) && isset( $json['error']['code'] ) ? (string) $json['error']['code'] : 'HTTP_' . $status;
			$msg  = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : 'HTTP ' . $status;
			return new WP_Error( 'afrihex_' . strtolower( $code ), $msg, array( 'status' => $status ) );
		}
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'afrihex_bad_response', __( 'AfriHex returned an unreadable response.', 'afrihex-address-for-woocommerce' ) );
		}
		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : $json;
	}
}
