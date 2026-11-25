<?php
/**
 * The delivery location: what the customer picked at checkout, and what we
 * store on the order after checking it.
 *
 * Kept free of WooCommerce so it can be unit tested on its own.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses the browser's selection and turns API answers into the stored record.
 */
class AfriHex_Location {

	/** Checked with AfriHex and a verdict came back. */
	const STATUS_VERIFIED = 'verified';

	/** Not checked yet (API down, no key). What the customer typed is kept for a retry. */
	const STATUS_UNVERIFIED = 'unverified';

	/** The customer gave nothing to check. */
	const STATUS_SKIPPED = 'skipped';

	const SOURCES = array( 'ghanapostgps', 'search', 'landmark', 'current_location', 'pin', 'typed' );

	// Generous box around Ghana. A point outside it is a bug or tampering, not a delivery address.
	const LAT_MIN = 4.0;
	const LAT_MAX = 11.5;
	const LNG_MIN = -3.6;
	const LNG_MAX = 1.6;

	/**
	 * Sanitizes what the checkout widget sent. Accepts a JSON string or an array;
	 * anything unexpected is dropped rather than trusted.
	 *
	 * @param mixed $raw JSON string (already unslashed) or decoded array from the browser.
	 * @return array Customer selection with known keys only; empty when nothing usable was sent.
	 */
	public static function from_customer( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array(
			'input'      => self::text( $raw, 'input', 500 ),
			'label'      => self::text( $raw, 'label', 300 ),
			'directions' => self::text( $raw, 'directions', 300 ),
			'street'     => self::text( $raw, 'street', 120 ),
			'area'       => self::text( $raw, 'area', 120 ),
			'district'   => self::text( $raw, 'district', 120 ),
			'region'     => self::text( $raw, 'region', 120 ),
			'gps_code'   => self::gps_code( isset( $raw['gps_code'] ) ? $raw['gps_code'] : '' ),
			'source'     => ( isset( $raw['source'] ) && in_array( $raw['source'], self::SOURCES, true ) ) ? $raw['source'] : 'typed',
			'adjusted'   => ! empty( $raw['adjusted'] ),
			'lat'        => null,
			'lng'        => null,
		);

		if ( isset( $raw['lat'], $raw['lng'] ) && is_numeric( $raw['lat'] ) && is_numeric( $raw['lng'] ) ) {
			$lat = (float) $raw['lat'];
			$lng = (float) $raw['lng'];
			if ( $lat >= self::LAT_MIN && $lat <= self::LAT_MAX && $lng >= self::LNG_MIN && $lng <= self::LNG_MAX ) {
				$out['lat'] = round( $lat, 7 );
				$out['lng'] = round( $lng, 7 );
			}
		}

		// A code typed but never looked up (lookups failing) is still worth keeping.
		if ( '' === $out['gps_code'] && '' !== $out['input'] ) {
			$out['gps_code'] = self::gps_code( $out['input'] );
		}

		if ( '' === $out['input'] && '' === $out['gps_code'] && null === $out['lat'] ) {
			return array();
		}
		return $out;
	}

	/**
	 * Formats a GhanaPostGPS code as GA-183-8164, or returns '' when it isn't one.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	public static function gps_code( $code ) {
		if ( ! is_string( $code ) ) {
			return '';
		}
		$c = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $code ) );
		if ( preg_match( '/^([A-Z]{2})(\d{3,4})(\d{4})$/', $c, $m ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		return '';
	}

	/**
	 * Whether the text reads like Ghanaian directions ("opposite Goil, Madina").
	 *
	 * @param string $text Customer text.
	 * @return bool
	 */
	public static function has_directions( $text ) {
		return (bool) preg_match( '/\b(opposite|opp\.?|adjacent|adj\.?|behind|beside|near|next to|close to|in front of|infront of|after|before|off|around|gate)\b/i', (string) $text );
	}

	/**
	 * Body for POST /v2/address/validate. Sends everything the customer gave
	 * so the API can cross-check a code against a pin against the text.
	 *
	 * @param array  $customer       From from_customer().
	 * @param string $fallback_text  The shop's own address fields, used when the widget sent no text.
	 * @return array Empty when there is nothing to check.
	 */
	public static function validate_body( array $customer, $fallback_text = '' ) {
		$body = array();
		$text = '';
		if ( ! empty( $customer ) ) {
			// For a search or landmark pick the customer's own words carry the
			// directions; the label is just the place name.
			$text = '' !== $customer['input'] ? $customer['input'] : $customer['label'];
			if ( self::gps_code( $text ) !== '' ) {
				$text = ''; // The code alone; sent below as gps_code.
			}
			if ( '' !== $customer['gps_code'] ) {
				$body['gps_code'] = $customer['gps_code'];
			}
			if ( null !== $customer['lat'] ) {
				$body['point'] = array(
					'lat' => $customer['lat'],
					'lng' => $customer['lng'],
				);
			}
		}
		if ( '' === $text ) {
			$text = trim( (string) $fallback_text );
		}
		if ( '' !== $text ) {
			$body['address'] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
		}
		return $body;
	}

	/**
	 * The record stored on the order after a successful check.
	 *
	 * @param array      $customer From from_customer().
	 * @param array      $data     The `data` object of the validate response.
	 * @param array|null $resolve  The `data` of POST /v2/address/resolve, for entrance hints; null if not called.
	 * @param string     $mode     'live' or 'test'.
	 * @return array
	 */
	public static function verified( array $customer, array $data, $resolve, $mode ) {
		$verdict = isset( $data['verdict'] ) && is_array( $data['verdict'] ) ? $data['verdict'] : array();
		$geo     = isset( $data['geocode'] ) && is_array( $data['geocode'] ) ? $data['geocode'] : array();
		$lm      = isset( $data['landmark'] ) && is_array( $data['landmark'] ) ? $data['landmark'] : array();

		$rec                = self::base( $customer, $mode );
		$rec['status']      = self::STATUS_VERIFIED;
		$rec['verdict']     = self::pick( $verdict, 'possible_next_action', array( 'accept', 'confirm', 'fix' ) );
		$rec['granularity'] = self::pick( $geo, 'granularity', array( 'region', 'locality', 'street', 'landmark', 'premise' ) );
		$rec['complete']    = ! empty( $verdict['address_complete'] );
		$rec['formatted']   = self::text( isset( $data['address'] ) && is_array( $data['address'] ) ? $data['address'] : array(), 'formatted', 300 );
		$rec['confidence']  = self::confidence( $data );

		// The customer's own pin wins when they placed it themselves: it's where the gate is.
		$own_pin = null !== $customer['lat'] && ( $customer['adjusted'] || 'current_location' === $customer['source'] );
		if ( ! $own_pin && isset( $geo['lat'], $geo['lng'] ) && is_numeric( $geo['lat'] ) && is_numeric( $geo['lng'] ) ) {
			$rec['lat'] = round( (float) $geo['lat'], 7 );
			$rec['lng'] = round( (float) $geo['lng'], 7 );
		}
		$code = self::gps_code( isset( $geo['gps_code'] ) ? $geo['gps_code'] : '' );
		if ( '' !== $code ) {
			$rec['gps_code'] = $code;
		}
		$rec['hex']       = self::text( $geo, 'hex', 40 );
		$rec['plus_code'] = self::text( $geo, 'plus_code', 20 );

		if ( ! empty( $lm['name'] ) ) {
			$rec['landmark'] = array(
				'name'       => self::text( $lm, 'name', 200 ),
				'relation'   => self::text( $lm, 'relation', 40 ),
				'distance_m' => isset( $lm['distance_m'] ) && is_numeric( $lm['distance_m'] ) ? (int) $lm['distance_m'] : null,
			);
		}

		$rec['issues'] = array();
		if ( isset( $data['issues'] ) && is_array( $data['issues'] ) ) {
			foreach ( array_slice( $data['issues'], 0, 10 ) as $issue ) {
				if ( ! is_array( $issue ) ) {
					continue;
				}
				$rec['issues'][] = array(
					'code'     => self::text( $issue, 'code', 60 ),
					'severity' => self::pick( $issue, 'severity', array( 'error', 'warning', 'info' ) ),
					'message'  => self::text( $issue, 'message', 300 ),
				);
			}
		}

		$rec['entrance'] = is_array( $resolve ) ? self::entrance( $resolve ) : '';
		return $rec;
	}

	/**
	 * The record stored when the check couldn't run. Keeps what the customer
	 * gave so the shop can retry or deliver from it anyway.
	 *
	 * @param array  $customer From from_customer().
	 * @param string $error    Why, for the shop (not shown to the customer).
	 * @param string $mode     'live' or 'test'.
	 * @return array
	 */
	public static function unverified( array $customer, $error, $mode ) {
		$rec           = self::base( $customer, $mode );
		$rec['status'] = empty( $customer ) ? self::STATUS_SKIPPED : self::STATUS_UNVERIFIED;
		$rec['error']  = substr( (string) $error, 0, 300 );
		return $rec;
	}

	/**
	 * Rider-facing entrance details from the resolve hints: "2nd gate · left side · blue gate".
	 *
	 * @param array $resolve The `data` of POST /v2/address/resolve.
	 * @return string
	 */
	public static function entrance( array $resolve ) {
		if ( empty( $resolve['hints'] ) || ! is_array( $resolve['hints'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $resolve['hints'] as $hint ) {
			if ( is_array( $hint ) && isset( $hint['type'] ) && in_array( $hint['type'], array( 'gate', 'side', 'turn', 'colour', 'floor', 'house_number', 'description' ), true ) ) {
				$t = self::text( $hint, 'text', 80 );
				if ( '' === $t ) {
					$t = self::text( $hint, 'value', 80 );
				}
				if ( '' !== $t ) {
					$parts[] = $t;
				}
			}
		}
		return implode( ' · ', array_unique( $parts ) );
	}

	/**
	 * A Google Maps link for the rider.
	 *
	 * @param array $rec Stored record.
	 * @return string Empty without coordinates.
	 */
	public static function map_url( array $rec ) {
		if ( ! isset( $rec['lat'], $rec['lng'] ) || null === $rec['lat'] ) {
			return '';
		}
		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $rec['lat'] . ',' . $rec['lng'] );
	}

	/**
	 * The fields shared by both outcomes.
	 *
	 * @param array  $customer Customer selection.
	 * @param string $mode     'live' or 'test'.
	 * @return array
	 */
	private static function base( array $customer, $mode ) {
		$c = array_merge(
			array(
				'input'      => '',
				'label'      => '',
				'directions' => '',
				'source'     => '',
				'gps_code'   => '',
				'lat'        => null,
				'lng'        => null,
				'adjusted'   => false,
			),
			$customer
		);
		return array(
			'status'      => self::STATUS_UNVERIFIED,
			'verdict'     => '',
			'confidence'  => null,
			'granularity' => '',
			'complete'    => false,
			'gps_code'    => $c['gps_code'],
			'lat'         => $c['lat'],
			'lng'         => $c['lng'],
			'formatted'   => '',
			'hex'         => '',
			'plus_code'   => '',
			'landmark'    => null,
			'entrance'    => '',
			'directions'  => $c['directions'],
			'issues'      => array(),
			'error'       => '',
			'customer'    => array(
				'input'    => $c['input'],
				'label'    => $c['label'],
				'source'   => $c['source'],
				'gps_code' => $c['gps_code'],
				'lat'      => $c['lat'],
				'lng'      => $c['lng'],
				'adjusted' => (bool) $c['adjusted'],
			),
			'mode'        => 'test' === $mode ? 'test' : 'live',
			'checked_at'  => gmdate( 'c' ),
		);
	}

	/**
	 * A 0–100 confidence score if the API sends one. The validate response
	 * doesn't today; this picks it up when it does (`confidence.score` or a bare number).
	 *
	 * @param array $data Validate `data`.
	 * @return int|null
	 */
	private static function confidence( array $data ) {
		$c = isset( $data['confidence'] ) ? $data['confidence'] : null;
		if ( is_array( $c ) && isset( $c['score'] ) ) {
			$c = $c['score'];
		}
		if ( ! is_numeric( $c ) ) {
			return null;
		}
		$c = (float) $c;
		if ( $c > 0 && $c <= 1 ) {
			$c *= 100; // A 0–1 scale.
		}
		return (int) max( 0, min( 100, round( $c ) ) );
	}

	/**
	 * A sanitized single-line string from an array.
	 *
	 * @param array  $a   Source.
	 * @param string $key Key.
	 * @param int    $max Maximum length in characters.
	 * @return string
	 */
	private static function text( array $a, $key, $max ) {
		if ( ! isset( $a[ $key ] ) || ! is_scalar( $a[ $key ] ) ) {
			return '';
		}
		$s = sanitize_text_field( (string) $a[ $key ] );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * One of the allowed values, or ''.
	 *
	 * @param array  $a       Source.
	 * @param string $key     Key.
	 * @param array  $allowed Allowed values.
	 * @return string
	 */
	private static function pick( array $a, $key, array $allowed ) {
		return isset( $a[ $key ] ) && in_array( $a[ $key ], $allowed, true ) ? $a[ $key ] : '';
	}
}
