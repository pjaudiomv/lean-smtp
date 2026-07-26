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
	'lean_smtp_reply_to',
	'lean_smtp_smtp_host',
	'lean_smtp_smtp_port',
	'lean_smtp_smtp_encryption',
	'lean_smtp_smtp_auth',
	'lean_smtp_smtp_username',
	'lean_smtp_smtp_password',
	'lean_smtp_ses_region',
	'lean_smtp_ses_access_key',
	'lean_smtp_ses_secret_key',
	'lean_smtp_mailgun_domain',
	'lean_smtp_mailgun_region',
	'lean_smtp_mailgun_api_key',
	'lean_smtp_resend_api_key',
	'lean_smtp_logging_enabled',
	'lean_smtp_log_headers',
	'lean_smtp_log_body',
	'lean_smtp_db_version',
	'lean_smtp_last_failure',
];

foreach ( $lean_smtp_options as $lean_smtp_option ) {
	delete_option( $lean_smtp_option );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lean_smtp_log" );
