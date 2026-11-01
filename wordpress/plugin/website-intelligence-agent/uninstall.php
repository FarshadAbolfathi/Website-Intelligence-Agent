<?php
/**
 * Runs when the plugin is deleted from WordPress. Data is removed only if the admin explicitly opted in.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== (string) get_option( 'wia_delete_on_uninstall', '0' ) ) {
	return;
}

global $wpdb;
foreach ( array( 'postings', 'chunks', 'documents', 'faqs', 'messages', 'conversations' ) as $wia_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'wia_' . $wia_table ) . '`' ); // phpcs:ignore WordPress.DB
}
foreach ( array( 'wia_db_version', 'wia_settings', 'wia_scan', 'wia_delete_on_uninstall', 'wia_last_sweep' ) as $wia_option ) {
	delete_option( $wia_option );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_wia_%', '_transient_timeout_wia_%' ) ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'wia_scan_batch' );
wp_clear_scheduled_hook( 'wia_daily' );
