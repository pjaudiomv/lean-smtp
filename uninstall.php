<?php
/**
 * Uninstall cleanup for Lean SMTP.
 *
 * Removes all plugin options and drops the send-log table.
 *
 * @package lean-smtp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$lean_smtp_options = [
	'lean_smtp_mailer',
	'lean_smtp_from_email',
	'lean_smtp_from_name',
	'lean_smtp_force_from_email',
	'lean_smtp_force_from_name',
	'lean_smtp_smtp_host',
	'lean_smtp_smtp_port',
	'lean_smtp_smtp_encryption',
	'lean_smtp_smtp_auth',
	'lean_smtp_smtp_username',
	'lean_smtp_smtp_password',
	'lean_smtp_ses_region',
	'lean_smtp_ses_access_key',
	'lean_smtp_ses_secret_key',
	'lean_smtp_logging_enabled',
];

foreach ( $lean_smtp_options as $lean_smtp_option ) {
	delete_option( $lean_smtp_option );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lean_smtp_log" );
