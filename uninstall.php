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

/**
 * Remove everything the plugin keeps in the current site's own tables: its
 * options, the per-site Gmail token-cache transients, and the send-log table.
 *
 * Factored out so a multisite network can run it once per site. On multisite
 * the settings themselves may instead live in network options (see below); the
 * options deleted here are harmless no-ops in that case.
 *
 * @param string[] $options Option names to delete.
 */
function lean_smtp_uninstall_site( array $options ): void {
	global $wpdb;

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Both Gmail transports cache short-lived access tokens in transients keyed by
	// a hash of the credentials, so they are removed by prefix rather than by name.
	// The service-account and OAuth prefixes are distinct; one LIKE pattern matches
	// both because 'lean_smtp_gmail_' prefixes each of them.
	$transient_like = $wpdb->esc_like( '_transient_lean_smtp_gmail_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_lean_smtp_gmail_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $transient_like, $timeout_like ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lean_smtp_log" );
}

if ( is_multisite() ) {
	// The log table and token transients are per-site, so clean every site; the
	// configuration may be network options (network-activated install), so clear
	// those too.
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $lean_smtp_blog_id ) {
		switch_to_blog( (int) $lean_smtp_blog_id );
		lean_smtp_uninstall_site( $lean_smtp_options );
		restore_current_blog();
	}

	foreach ( $lean_smtp_options as $lean_smtp_option ) {
		delete_site_option( $lean_smtp_option );
	}
} else {
	lean_smtp_uninstall_site( $lean_smtp_options );
}

// The Email Log screen's per-page preference is user meta (global, one row per
// user regardless of site), so it is removed once.
delete_metadata( 'user', 0, 'lean_smtp_log_per_page', '', true );
