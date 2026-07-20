<?php
/**
 * Send log: a small custom table recording each wp_mail() attempt routed
 * through the plugin (mailer used, recipients, subject, success/failure and
 * any error). Written only when logging is enabled in settings.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Logger {

	const OPTION_ENABLED = 'lean_smtp_logging_enabled';

	/** How many rows the settings viewer shows and the table is trimmed to. */
	const MAX_ROWS = 100;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lean_smtp_log';
	}

	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Create the log table. Called on activation; safe to call repeatedly.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sent_at datetime NOT NULL,
			mailer varchar(20) NOT NULL DEFAULT '',
			to_email text NOT NULL,
			subject text NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			error text NULL,
			PRIMARY KEY  (id),
			KEY sent_at (sent_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record one send. No-op unless logging is enabled.
	 *
	 * @param string          $mailer  'smtp' or 'ses'.
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Message subject.
	 * @param bool            $ok      Whether the send succeeded.
	 * @param string          $error   Error detail on failure.
	 */
	public static function log( string $mailer, $to, string $subject, bool $ok, string $error = '' ): void {
		if ( ! self::enabled() ) {
			return;
		}

		global $wpdb;

		$to_email = is_array( $to ) ? implode( ', ', $to ) : (string) $to;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table, no core API.
		$wpdb->insert(
			self::table(),
			[
				'sent_at'  => current_time( 'mysql' ),
				'mailer'   => $mailer,
				'to_email' => $to_email,
				'subject'  => $subject,
				'status'   => $ok ? 'sent' : 'failed',
				'error'    => $ok ? null : $error,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		self::trim();
	}

	/**
	 * Keep the table bounded — drop everything older than the newest MAX_ROWS.
	 */
	private static function trim(): void {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( null !== $cutoff ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cutoff ) );
		}
	}

	/**
	 * Most recent rows, newest first.
	 *
	 * @return array<int, object>
	 */
	public static function recent( int $limit = self::MAX_ROWS ): array {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
