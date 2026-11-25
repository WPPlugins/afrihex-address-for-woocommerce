<?php
/**
 * Uninstall: removes settings and scheduled retries.
 *
 * The delivery locations saved on orders are kept, because shops and riders
 * still need them for past orders. To remove them too, add
 * define( 'AFRIHEX_REMOVE_ALL_DATA', true ); to wp-config.php before deleting the plugin.
 *
 * @package AfriHex_Address
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'afrihex_address_settings' );
wp_unschedule_hook( 'afrihex_address_retry' );
delete_metadata( 'user', 0, 'afrihex_address_setup_notice_dismissed', '', true );

if ( defined( 'AFRIHEX_REMOVE_ALL_DATA' ) && AFRIHEX_REMOVE_ALL_DATA ) {
	global $wpdb;
	$afrihex_keys = array( '_afrihex_location', '_afrihex_status', '_afrihex_gps_code', '_afrihex_retry_count' );
	$afrihex_in   = implode( ',', array_fill( 0, count( $afrihex_keys ), '%s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off cleanup; placeholders built above.
	$afrihex_tables = array( $wpdb->postmeta );
	$afrihex_hpos   = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $afrihex_hpos ) ) === $afrihex_hpos ) {
		$afrihex_tables[] = $afrihex_hpos;
	}
	foreach ( $afrihex_tables as $afrihex_table ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE meta_key IN ($afrihex_in)", array_merge( array( $afrihex_table ), $afrihex_keys ) ) );
	}
	// phpcs:enable
}
