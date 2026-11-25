<?php
/**
 * Settings: WooCommerce → AfriHex Address.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and renders the plugin settings. Secret keys are kept in the
 * database (or wp-config.php constants) and are never printed back into any page.
 */
class AfriHex_Settings {

	const OPTION        = 'afrihex_address_settings';
	const PAGE          = 'afrihex-address';
	const DEFAULT_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

	/**
	 * Defaults for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'publishable_key' => '',
			'secret_key'      => '',
			'test_secret_key' => '',
			'test_mode'       => false,
			'required'        => true,
			'block_on_fix'    => false,
			'show_map'        => true,
			'tile_url'        => self::DEFAULT_TILES,
			'api_base_url'    => AfriHex_API::DEFAULT_BASE_URL,
		);
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array
	 */
	public function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether calls run in test mode (afx_test_ key).
	 *
	 * @return bool
	 */
	public function test_mode() {
		return (bool) $this->get( 'test_mode' );
	}

	/**
	 * The secret key for the current mode. wp-config.php constants win over the database.
	 *
	 * @return string
	 */
	public function secret_key() {
		if ( $this->test_mode() ) {
			return defined( 'AFRIHEX_TEST_SECRET_KEY' ) ? (string) AFRIHEX_TEST_SECRET_KEY : (string) $this->get( 'test_secret_key' );
		}
		return defined( 'AFRIHEX_SECRET_KEY' ) ? (string) AFRIHEX_SECRET_KEY : (string) $this->get( 'secret_key' );
	}

	/**
	 * API base URL. Overridable in wp-config.php for local development.
	 *
	 * @return string
	 */
	public function api_base_url() {
		if ( defined( 'AFRIHEX_API_BASE_URL' ) ) {
			return (string) AFRIHEX_API_BASE_URL;
		}
		$url = (string) $this->get( 'api_base_url' );
		return '' !== $url ? $url : AfriHex_API::DEFAULT_BASE_URL;
	}

	/**
	 * Browser-side API base URL. Same as the server's unless overridden, which
	 * the local Docker setup needs (the server reaches the mock by service name).
	 *
	 * @return string
	 */
	public function browser_api_base_url() {
		return defined( 'AFRIHEX_BROWSER_API_BASE_URL' ) ? (string) AFRIHEX_BROWSER_API_BASE_URL : $this->api_base_url();
	}

	/**
	 * 'test' or 'live', recorded on each order.
	 *
	 * @return string
	 */
	public function mode() {
		return $this->test_mode() ? 'test' : 'live';
	}

	/**
	 * Whether the checkout field can run: it needs a publishable key in the browser.
	 *
	 * @return bool
	 */
	public function checkout_enabled() {
		return 0 === strpos( (string) $this->get( 'publishable_key' ), 'afx_pub_' );
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_afrihex_test_connection', array( $this, 'ajax_test_connection' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AFRIHEX_ADDRESS_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
		add_action( 'admin_init', array( $this, 'dismiss_setup_notice' ) );
	}

	/**
	 * Adds the page under WooCommerce.
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AfriHex Address', 'afrihex-address-for-woocommerce' ),
			__( 'AfriHex Address', 'afrihex-address-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'afrihex-address-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * User meta key set when a user dismisses the setup reminder.
	 */
	const DISMISS_META = 'afrihex_address_setup_notice_dismissed';

	/**
	 * Reminds the shop to add a key: on the Plugins screen only, until a key
	 * is saved or the user dismisses it (remembered per user).
	 */
	public function setup_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( $this->checkout_enabled() || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$dismiss_url = wp_nonce_url( add_query_arg( 'afrihex_dismiss_setup', '1' ), 'afrihex_dismiss_setup' );
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html__( 'AfriHex Address is not showing at checkout yet: add your publishable key.', 'afrihex-address-for-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Open settings', 'afrihex-address-for-woocommerce' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'afrihex-address-for-woocommerce' )
		);
	}

	/**
	 * Remembers that the current user dismissed the setup reminder.
	 */
	public function dismiss_setup_notice() {
		if ( ! isset( $_GET['afrihex_dismiss_setup'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'afrihex_dismiss_setup' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( remove_query_arg( array( 'afrihex_dismiss_setup', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Registers the option with the Settings API (which handles the nonce).
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Cleans submitted settings. A blank secret field keeps the saved key, so
	 * the key never has to be printed into the form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$old = $this->all();
		$in  = is_array( $input ) ? $input : array();
		$out = $old;

		$out['publishable_key'] = isset( $in['publishable_key'] ) ? sanitize_text_field( $in['publishable_key'] ) : '';
		if ( '' !== $out['publishable_key'] && 0 !== strpos( $out['publishable_key'], 'afx_pub_' ) ) {
			add_settings_error( self::OPTION, 'pub', __( 'The publishable key should start with afx_pub_. Secret keys must never go in the publishable key field.', 'afrihex-address-for-woocommerce' ) );
			$out['publishable_key'] = $old['publishable_key'];
		}

		foreach ( array(
			'secret_key'      => 'afx_live_',
			'test_secret_key' => 'afx_test_',
		) as $field => $prefix ) {
			if ( ! empty( $in[ $field . '_clear' ] ) ) {
				$out[ $field ] = '';
				continue;
			}
			$v = isset( $in[ $field ] ) ? trim( sanitize_text_field( $in[ $field ] ) ) : '';
			if ( '' === $v ) {
				continue; // Keep the saved key.
			}
			if ( 0 === strpos( $v, 'afx_pub_' ) || ( 'afx_test_' === $prefix ) !== ( 0 === strpos( $v, 'afx_test_' ) ) ) {
				/* translators: %s: expected key prefix, e.g. afx_live_ */
				add_settings_error( self::OPTION, $field, sprintf( __( 'That key is the wrong kind for this field: it should start with %s.', 'afrihex-address-for-woocommerce' ), $prefix ) );
				continue;
			}
			$out[ $field ] = $v;
		}

		foreach ( array( 'test_mode', 'required', 'block_on_fix', 'show_map' ) as $flag ) {
			$out[ $flag ] = ! empty( $in[ $flag ] );
		}

		$tiles           = isset( $in['tile_url'] ) ? esc_url_raw( trim( $in['tile_url'] ), array( 'https', 'http' ) ) : '';
		$out['tile_url'] = ( '' !== $tiles && false !== strpos( $tiles, '{z}' ) ) ? str_replace( array( '%7B', '%7D' ), array( '{', '}' ), $tiles ) : self::DEFAULT_TILES;

		$base                = isset( $in['api_base_url'] ) ? esc_url_raw( trim( $in['api_base_url'] ), array( 'https', 'http' ) ) : '';
		$out['api_base_url'] = '' !== $base ? untrailingslashit( $base ) : AfriHex_API::DEFAULT_BASE_URL;

		return $out;
	}

	/**
	 * Shows the last four characters of a stored key, never the key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function masked( $key ) {
		if ( '' === $key ) {
			return '';
		}
		$prefix = preg_match( '/^afx_[a-z]+_/', $key, $m ) ? $m[0] : '';
		return $prefix . '…' . substr( $key, -4 );
	}

	/**
	 * Renders the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s      = $this->all();
		$name   = self::OPTION;
		$origin = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AfriHex Address', 'afrihex-address-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Customers enter a GhanaPostGPS code, a place, or directions by landmark at checkout. Each order is checked with AfriHex and saved with its GPS code, coordinates and rider directions.', 'afrihex-address-for-woocommerce' ); ?></p>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( self::PAGE ); ?>
				<h2><?php esc_html_e( 'API keys', 'afrihex-address-for-woocommerce' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: link to the AfriHex dashboard */
						esc_html__( 'Get both keys from your %s.', 'afrihex-address-for-woocommerce' ),
						'<a href="https://maps.afrihex.com/dashboard" target="_blank" rel="noopener noreferrer">' . esc_html__( 'AfriHex dashboard', 'afrihex-address-for-woocommerce' ) . '</a>'
					);
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="afx-pub"><?php esc_html_e( 'Publishable key', 'afrihex-address-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="afx-pub" name="<?php echo esc_attr( $name ); ?>[publishable_key]" value="<?php echo esc_attr( $s['publishable_key'] ); ?>" placeholder="afx_pub_…" spellcheck="false">
							<p class="description">
								<?php
								printf(
									/* translators: %s: this shop's web origin, e.g. https://shop.example.com */
									esc_html__( 'Used in the checkout page for suggestions. In the dashboard, add %s to this key\'s websites or lookups will be refused.', 'afrihex-address-for-woocommerce' ),
									'<code>' . esc_html( $origin ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<?php
					$this->secret_row( 'secret_key', __( 'Secret key (live)', 'afrihex-address-for-woocommerce' ), 'afx_live_…', defined( 'AFRIHEX_SECRET_KEY' ) ? 'AFRIHEX_SECRET_KEY' : '', $s['secret_key'] );
					$this->secret_row( 'test_secret_key', __( 'Secret key (test)', 'afrihex-address-for-woocommerce' ), 'afx_test_…', defined( 'AFRIHEX_TEST_SECRET_KEY' ) ? 'AFRIHEX_TEST_SECRET_KEY' : '', $s['test_secret_key'] );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test mode', 'afrihex-address-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[test_mode]" value="1" <?php checked( $s['test_mode'] ); ?>> <?php esc_html_e( 'Check orders with the test key', 'afrihex-address-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Test calls use their own daily allowance and don\'t count against your plan. Orders are marked "test".', 'afrihex-address-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'afrihex-address-for-woocommerce' ); ?></th>
						<td>
							<button type="button" class="button" id="afx-test-connection" data-nonce="<?php echo esc_attr( wp_create_nonce( 'afrihex_test_connection' ) ); ?>"><?php esc_html_e( 'Test secret key', 'afrihex-address-for-woocommerce' ); ?></button>
							<span id="afx-test-result" aria-live="polite" style="margin-left:8px"></span>
							<p class="description"><?php esc_html_e( 'Checks a sample address with the key for the current mode. Save first.', 'afrihex-address-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Checkout', 'afrihex-address-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery location', 'afrihex-address-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $s['required'] ); ?>> <?php esc_html_e( 'Customers must enter a delivery location', 'afrihex-address-for-woocommerce' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_on_fix]" value="1" <?php checked( $s['block_on_fix'] ); ?>> <?php esc_html_e( 'Ask the customer to correct an address AfriHex cannot locate', 'afrihex-address-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Checkout is never blocked because AfriHex is unreachable: the order is saved as "unverified" and checked again later.', 'afrihex-address-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Map', 'afrihex-address-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_map]" value="1" <?php checked( $s['show_map'] ); ?>> <?php esc_html_e( 'Show a small map so customers can drag the pin to their gate', 'afrihex-address-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="afx-tiles"><?php esc_html_e( 'Map tiles URL', 'afrihex-address-for-woocommerce' ); ?></label></th>
						<td>
							<input type="url" class="large-text code" id="afx-tiles" name="<?php echo esc_attr( $name ); ?>[tile_url]" value="<?php echo esc_attr( $s['tile_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'A raster tile template with {z}/{x}/{y}. The default OpenStreetMap server is for light use only; busy shops should use their own tile provider.', 'afrihex-address-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Advanced', 'afrihex-address-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="afx-base"><?php esc_html_e( 'API base URL', 'afrihex-address-for-woocommerce' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="afx-base" name="<?php echo esc_attr( $name ); ?>[api_base_url]" value="<?php echo esc_attr( $s['api_base_url'] ); ?>" <?php disabled( defined( 'AFRIHEX_API_BASE_URL' ) ); ?>>
							<p class="description"><?php esc_html_e( 'Leave as https://api.afrihex.com unless AfriHex support tells you otherwise.', 'afrihex-address-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * A secret key row: empty input plus a masked hint of the saved key.
	 *
	 * @param string $field    Setting name.
	 * @param string $label    Row label.
	 * @param string $hint     Placeholder.
	 * @param string $constant Name of the wp-config constant that overrides it, or ''.
	 * @param string $saved    Saved key.
	 */
	private function secret_row( $field, $label, $hint, $constant, $saved ) {
		$name = self::OPTION . '[' . $field . ']';
		?>
		<tr>
			<th scope="row"><label for="afx-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $constant ) : ?>
					<p>
						<?php
						/* translators: %s: PHP constant name */
						printf( esc_html__( 'Set in wp-config.php (%s).', 'afrihex-address-for-woocommerce' ), '<code>' . esc_html( $constant ) . '</code>' );
						?>
					</p>
				<?php else : ?>
					<input type="password" class="regular-text code" id="afx-<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" placeholder="<?php echo esc_attr( '' !== $saved ? self::masked( $saved ) : $hint ); ?>" autocomplete="new-password" spellcheck="false">
					<?php if ( '' !== $saved ) : ?>
						<label style="margin-left:8px"><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $field . '_clear]' ); ?>" value="1"> <?php esc_html_e( 'Remove', 'afrihex-address-for-woocommerce' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Stays on your server; it is never sent to the browser. Leave blank to keep the saved key.', 'afrihex-address-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * AJAX: checks the current mode's secret key with a sample address.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'afrihex_test_connection' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'afrihex-address-for-woocommerce' ) ), 403 );
		}
		$api = new AfriHex_API( $this->api_base_url(), $this->secret_key() );
		if ( ! $api->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'No secret key saved for the current mode.', 'afrihex-address-for-woocommerce' ) ) );
		}
		$res = $api->validate( array( 'gps_code' => 'GA-183-8164' ) );
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message from the API */
			wp_send_json_error( array( 'message' => sprintf( __( 'Failed: %s', 'afrihex-address-for-woocommerce' ), $res->get_error_message() ) ) );
		}
		wp_send_json_success(
			array(
				/* translators: %s: live or test */
				'message' => sprintf( __( 'Connected (%s mode).', 'afrihex-address-for-woocommerce' ), $this->mode() ),
			)
		);
	}
}
