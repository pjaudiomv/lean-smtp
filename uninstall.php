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
	'lean_smtp_gmail_service_account',
	'lean_smtp_gmail_sender',
	'lean_smtp_gmail_oauth_client_id',
	'lean_smtp_gmail_oauth_client_secret',
	'lean_smtp_gmail_oauth_refresh_token',
	'lean_smtp_gmail_oauth_sender',
	'lean_smtp_logging_enabled',
	'lean_smtp_log_headers',
	'lean_smtp_log_body',
	'lean_smtp_log_retention',
	'lean_smtp_log_max_age',
	'lean_smtp_db_version',
	'lean_smtp_last_failure',
];

foreach ( $lean_smtp_options as $lean_smtp_option ) {
	delete_option( $lean_smtp_option );
}

// The Email Log screen's per-page preference is user meta, not an option.
delete_metadata( 'user', 0, 'lean_smtp_log_per_page', '', true );

global $wpdb;

// Both Gmail transports cache short-lived access tokens in transients keyed by a
// hash of the credentials, so they are removed by prefix rather than by name.
// The service-account and OAuth prefixes are distinct; one LIKE pattern matches
// both because 'lean_smtp_gmail_' prefixes each of them.
$lean_smtp_transient_like = $wpdb->esc_like( '_transient_lean_smtp_gmail_' ) . '%';
$lean_smtp_timeout_like   = $wpdb->esc_like( '_transient_timeout_lean_smtp_gmail_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $lean_smtp_transient_like, $lean_smtp_timeout_like ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lean_smtp_log" );
