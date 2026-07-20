<?php
/**
 * Uninstall cleanup for Simple SMTP.
 *
 * Removes all plugin options and drops the send-log table.
 *
 * @package simple-smtp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$simple_smtp_options = [
	'simple_smtp_mailer',
	'simple_smtp_from_email',
	'simple_smtp_from_name',
	'simple_smtp_force_from_email',
	'simple_smtp_force_from_name',
	'simple_smtp_smtp_host',
	'simple_smtp_smtp_port',
	'simple_smtp_smtp_encryption',
	'simple_smtp_smtp_auth',
	'simple_smtp_smtp_username',
	'simple_smtp_smtp_password',
	'simple_smtp_ses_region',
	'simple_smtp_ses_access_key',
	'simple_smtp_ses_secret_key',
	'simple_smtp_logging_enabled',
];

foreach ( $simple_smtp_options as $simple_smtp_option ) {
	delete_option( $simple_smtp_option );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}simple_smtp_log" );
