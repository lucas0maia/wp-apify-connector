<?php
/**
 * Uninstall cleanup for WP Apify Connector.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_apify_connector_options' );

global $wpdb;
$like_transient = $wpdb->esc_like( '_transient_wp_apify_ds_' ) . '%';
$like_timeout   = $wpdb->esc_like( '_transient_timeout_wp_apify_ds_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_transient, $like_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
