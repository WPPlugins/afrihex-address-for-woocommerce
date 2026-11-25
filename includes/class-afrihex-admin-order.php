<?php
/**
 * Admin order screen: the location box, the re-check button and an orders-list column.
 *
 * @package AfriHex_Address
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Works with both order storage modes (posts and HPOS).
 */
class AfriHex_Admin_Order {

	const NONCE = 'afrihex_recheck';

	/**
	 * Verifier.
	 *
	 * @var AfriHex_Verifier
	 */
	private $verifier;

	/**
	 * Constructor.
	 *
	 * @param AfriHex_Verifier $verifier Verifier.
	 */
	public function __construct( AfriHex_Verifier $verifier ) {
		$this->verifier = $verifier;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_afrihex_recheck', array( $this, 'ajax_recheck' ) );
		// Orders list column: legacy posts screen and HPOS screen.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'column_value_legacy' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'column_value' ), 10, 2 );
	}

	/**
	 * The order edit screen ID for the active storage.
	 *
	 * @return string
	 */
	private static function screen() {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return wc_get_page_screen_id( 'shop-order' );
		}
		return 'shop_order';
	}

	/**
	 * Adds the box beside the order.
	 */
	public function meta_box() {
		add_meta_box( 'afrihex-location', __( 'Delivery location (AfriHex)', 'afrihex-address-for-woocommerce' ), array( $this, 'render_box' ), self::screen(), 'side', 'high' );
	}

	/**
	 * Script for the re-check button and the settings page.
	 */
	public function enqueue() {
		$screen = get_current_screen();
		$ours   = $screen && ( self::screen() === $screen->id || 'woocommerce_page_' . AfriHex_Settings::PAGE === $screen->id );
		if ( ! $ours ) {
			return;
		}
		wp_enqueue_script( 'afrihex-address-admin', AFRIHEX_ADDRESS_URL . 'assets/js/admin.js', array(), AFRIHEX_ADDRESS_VERSION, true );
		wp_localize_script(
			'afrihex-address-admin',
			'afrihexAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'checking' => __( 'Checking…', 'afrihex-address-for-woocommerce' ),
					'failed'   => __( 'Request failed.', 'afrihex-address-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Renders the box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (legacy) or order (HPOS).
	 */
	public function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$rec = AfriHex_Order_Display::record( $order );
		if ( ! $rec ) {
			echo '<p>' . esc_html__( 'No delivery location was recorded for this order.', 'afrihex-address-for-woocommerce' ) . '</p>';
			$this->recheck_form( $order, null );
			return;
		}

		$badge = self::badge( $rec );
		echo '<p><span class="afx-badge" style="' . esc_attr( $badge[1] ) . '">' . esc_html( $badge[0] ) . '</span></p>';
		echo '<table class="widefat striped" style="border:0"><tbody>';
		foreach ( AfriHex_Order_Display::rows( $rec, true ) as $r ) {
			echo '<tr><th scope="row" style="width:38%;padding:6px 8px">' . esc_html( $r[0] ) . '</th><td style="padding:6px 8px;word-break:break-word">' . esc_html( $r[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$map = AfriHex_Location::map_url( $rec );
		if ( $map ) {
			echo '<p><a href="' . esc_url( $map ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Google Maps', 'afrihex-address-for-woocommerce' ) . '</a></p>';
		}
		if ( ! empty( $rec['issues'] ) ) {
			echo '<ul style="margin:8px 0 0 16px;list-style:disc">';
			foreach ( $rec['issues'] as $issue ) {
				echo '<li>' . esc_html( $issue['message'] ) . '</li>';
			}
			echo '</ul>';
		}
		if ( AfriHex_Location::STATUS_UNVERIFIED === $rec['status'] && '' !== $rec['error'] ) {
			/* translators: %s: error message */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last error: %s', 'afrihex-address-for-woocommerce' ), $rec['error'] ) ) . '</p>';
		}
		/* translators: %s: date and time */
		echo '<p class="description">' . esc_html( sprintf( __( 'Checked %s', 'afrihex-address-for-woocommerce' ), wc_format_datetime( new WC_DateTime( $rec['checked_at'] ) ) . ' ' . wc_format_datetime( new WC_DateTime( $rec['checked_at'] ), wc_time_format() ) ) ) . '</p>';
		$this->recheck_form( $order, $rec );
	}

	/**
	 * The re-check controls. Not a <form>: the box sits inside the order form.
	 *
	 * @param WC_Order   $order Order.
	 * @param array|null $rec   Record.
	 */
	private function recheck_form( $order, $rec ) {
		?>
		<div class="afx-recheck" style="margin-top:12px;border-top:1px solid #dcdcde;padding-top:10px">
			<label for="afx-recheck-code" style="display:block;margin-bottom:4px"><?php esc_html_e( 'Corrected GhanaPostGPS code (optional)', 'afrihex-address-for-woocommerce' ); ?></label>
			<input type="text" id="afx-recheck-code" class="widefat code" placeholder="GA-183-8164" spellcheck="false">
			<p style="margin:8px 0 0">
				<button type="button" class="button" id="afx-recheck" data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE . '_' . $order->get_id() ) ); ?>">
					<?php echo $rec && AfriHex_Location::STATUS_UNVERIFIED === $rec['status'] ? esc_html__( 'Retry check', 'afrihex-address-for-woocommerce' ) : esc_html__( 'Check again', 'afrihex-address-for-woocommerce' ); ?>
				</button>
				<span id="afx-recheck-result" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Badge text and inline style for an outcome.
	 *
	 * @param array $rec Record.
	 * @return string[] [ text, css ].
	 */
	public static function badge( array $rec ) {
		$base = 'display:inline-block;padding:2px 8px;border-radius:3px;font-weight:600;';
		$text = AfriHex_Order_Display::verdict_label( $rec ) . ( 'test' === $rec['mode'] ? ' · ' . __( 'test', 'afrihex-address-for-woocommerce' ) : '' );
		if ( AfriHex_Location::STATUS_VERIFIED === $rec['status'] && 'accept' === $rec['verdict'] ) {
			return array( $text, $base . 'background:#d1f0d9;color:#0a5c22' );
		}
		if ( AfriHex_Location::STATUS_VERIFIED === $rec['status'] && 'confirm' === $rec['verdict'] ) {
			return array( $text, $base . 'background:#fcf0c8;color:#6b4a00' );
		}
		if ( AfriHex_Location::STATUS_VERIFIED === $rec['status'] ) {
			return array( $text, $base . 'background:#f8d7da;color:#842029' );
		}
		return array( $text, $base . 'background:#e5e5e5;color:#3c434a' );
	}

	/**
	 * AJAX: re-check one order, optionally with a corrected code.
	 */
	public function ajax_recheck() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_ajax_referer( self::NONCE . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'afrihex-address-for-woocommerce' ) ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'afrihex-address-for-woocommerce' ) ), 404 );
		}
		$code = isset( $_POST['gps_code'] ) ? sanitize_text_field( wp_unslash( $_POST['gps_code'] ) ) : '';
		if ( '' !== $code && '' === AfriHex_Location::gps_code( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a GhanaPostGPS code (e.g. GA-183-8164).', 'afrihex-address-for-woocommerce' ) ) );
		}
		$rec  = $this->verifier->recheck( $order, $code );
		$note = AfriHex_Verifier::note( $rec );
		/* translators: 1: user display name, 2: outcome note */
		$order->add_order_note( sprintf( __( '%1$s re-checked the delivery location. %2$s', 'afrihex-address-for-woocommerce' ), wp_get_current_user()->display_name, $note ) );
		if ( AfriHex_Location::STATUS_UNVERIFIED === $rec['status'] ) {
			wp_send_json_error( array( 'message' => $note ) );
		}
		wp_send_json_success( array( 'message' => AfriHex_Order_Display::verdict_label( $rec ) ) );
	}

	/**
	 * Adds the column after the shipping address.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'shipping_address' === $key ) {
				$out['afrihex_location'] = __( 'Location check', 'afrihex-address-for-woocommerce' );
			}
		}
		if ( ! isset( $out['afrihex_location'] ) ) {
			$out['afrihex_location'] = __( 'Location check', 'afrihex-address-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * Column value (legacy posts screen).
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order ID.
	 */
	public function column_value_legacy( $column, $post_id ) {
		$this->column_value( $column, wc_get_order( $post_id ) );
	}

	/**
	 * Column value.
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public function column_value( $column, $order ) {
		if ( 'afrihex_location' !== $column || ! $order ) {
			return;
		}
		$rec = AfriHex_Order_Display::record( $order );
		if ( ! $rec ) {
			echo '&ndash;';
			return;
		}
		$badge = self::badge( $rec );
		echo '<span style="' . esc_attr( $badge[1] ) . '">' . esc_html( $badge[0] ) . '</span>';
		if ( '' !== $rec['gps_code'] ) {
			echo '<br><code>' . esc_html( $rec['gps_code'] ) . '</code>';
		}
	}
}
