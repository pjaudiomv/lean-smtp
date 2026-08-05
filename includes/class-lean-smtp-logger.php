<?php
/**
 * Send log: a small custom table recording each wp_mail() attempt routed
 * through the plugin (mailer used, recipients, subject, success/failure and
 * any error). Written only when logging is enabled in settings.
 *
 * The message itself — headers, attachment filenames and body — is recorded
 * only when separately opted in. A stored body contains password-reset links,
 * order details and anything else the site mails, so it is off by default and
 * governed by its own setting rather than riding along with the log.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Logger {

	const OPTION_ENABLED     = 'lean_smtp_logging_enabled';
	const OPTION_LOG_HEADERS = 'lean_smtp_log_headers';
	const OPTION_LOG_BODY    = 'lean_smtp_log_body';
	const OPTION_RETENTION   = 'lean_smtp_log_retention';

	/** Bumped whenever the table definition changes; see maybe_upgrade(). */
	const DB_VERSION        = '2';
	const OPTION_DB_VERSION = 'lean_smtp_db_version';

	/** Rows kept when no retention has been chosen. */
	const MAX_ROWS = 100;

	/**
	 * The retention sizes offered. A free-text row count would invite someone to
	 * type a number that turns the log into an unbounded table, which is the one
	 * thing trim() exists to prevent.
	 */
	const RETENTION_CHOICES = [ 100, 500, 1000, 5000 ];

	/** A stored body is truncated past this, so one runaway email can't bloat the table. */
	const MAX_BODY_BYTES = 65535;

	const STATUS_SENT    = 'sent';
	const STATUS_FAILED  = 'failed';
	const STATUS_OFFLINE = 'offline';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lean_smtp_log';
	}

	public static function enabled(): bool {
		return Lean_SMTP_Config::get_bool( self::OPTION_ENABLED );
	}

	/** Whether to store the message headers and attachment filenames. */
	public static function log_headers(): bool {
		return Lean_SMTP_Config::get_bool( self::OPTION_LOG_HEADERS );
	}

	/** Whether to store the message body. */
	public static function log_body(): bool {
		return Lean_SMTP_Config::get_bool( self::OPTION_LOG_BODY );
	}

	/**
	 * How many rows the table is trimmed to. Anything outside the offered set —
	 * an unset option, or a wp-config.php constant with a typo in it — falls back
	 * to the default rather than being honoured.
	 */
	public static function retention(): int {
		$rows = (int) Lean_SMTP_Config::get( self::OPTION_RETENTION, self::MAX_ROWS );

		return in_array( $rows, self::RETENTION_CHOICES, true ) ? $rows : self::MAX_ROWS;
	}

	/**
	 * Create or update the log table. Called on activation and from
	 * maybe_upgrade(); dbDelta adds any missing column, so it is safe to call
	 * repeatedly.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// The content columns are nullable rather than NOT NULL DEFAULT '':
		// MySQL won't accept a default on a TEXT column, and null reads as
		// "not recorded", which is exactly what an opted-out send is.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sent_at datetime NOT NULL,
			mailer varchar(20) NOT NULL DEFAULT '',
			to_email text NOT NULL,
			subject text NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			error text NULL,
			headers mediumtext NULL,
			body mediumtext NULL,
			attachments text NULL,
			PRIMARY KEY  (id),
			KEY sent_at (sent_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Bring an existing install's table up to date.
	 *
	 * The activation hook does not fire when a plugin is *updated*, so a new
	 * column would otherwise never reach a site that already has the table —
	 * and every insert naming it would fail. The check is a single read of an
	 * autoloaded option, so it can run on any request; the dbDelta behind it
	 * runs once per schema bump.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
			return;
		}
		self::create_table();
	}

	/**
	 * Record one send. No-op unless logging is enabled.
	 *
	 * @param string          $mailer    Mailer slug the message went through.
	 * @param string|string[] $to        Recipient(s).
	 * @param string          $subject   Message subject.
	 * @param string          $status    One of the STATUS_* constants.
	 * @param string          $error     Error detail on failure.
	 * @param array           $mail_data The wp_mail() arguments (message, headers,
	 *                                   attachments), for the optional content columns.
	 */
	public static function log( string $mailer, $to, string $subject, string $status, string $error = '', array $mail_data = [] ): void {
		if ( ! self::enabled() ) {
			return;
		}

		global $wpdb;

		$to_email = is_array( $to ) ? implode( ', ', $to ) : (string) $to;

		$row = [
			'sent_at'  => current_time( 'mysql' ),
			'mailer'   => $mailer,
			'to_email' => $to_email,
			'subject'  => $subject,
			'status'   => $status,
			'error'    => self::STATUS_FAILED === $status ? $error : null,
		];

		$row += self::content_columns( $mail_data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table, no core API.
		$wpdb->insert(
			self::table(),
			$row,
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		self::trim();
	}

	/**
	 * The optional message columns, honouring the two content settings. Anything
	 * not opted into stays null — the row records that the send happened, not
	 * what was in it.
	 *
	 * @param array $mail_data The wp_mail() arguments.
	 * @return array{headers: ?string, body: ?string, attachments: ?string}
	 */
	private static function content_columns( array $mail_data ): array {
		$headers = self::log_headers();

		return [
			'headers'     => $headers ? self::flatten_headers( $mail_data['headers'] ?? '' ) : null,
			'body'        => self::log_body() ? self::truncate( (string) ( $mail_data['message'] ?? '' ) ) : null,
			'attachments' => $headers ? self::flatten_attachments( $mail_data['attachments'] ?? [] ) : null,
		];
	}

	/**
	 * Headers reach wp_mail() as either a newline-delimited string or an array
	 * of lines; store the readable form of both.
	 *
	 * @param string|string[] $headers
	 */
	private static function flatten_headers( $headers ): string {
		if ( is_array( $headers ) ) {
			$lines = [];
			foreach ( $headers as $name => $value ) {
				$lines[] = is_string( $name ) ? $name . ': ' . $value : (string) $value;
			}
			$headers = implode( "\n", $lines );
		}
		return trim( str_replace( "\r\n", "\n", (string) $headers ) );
	}

	/**
	 * Filenames only. The files themselves are on disk and may be enormous;
	 * what a log reader needs to know is which ones went along.
	 *
	 * @param string|string[] $attachments
	 */
	private static function flatten_attachments( $attachments ): string {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$names = [];
		foreach ( $attachments as $name => $path ) {
			// wp_mail() lets the array key supply a display filename.
			$names[] = is_string( $name ) && '' !== $name ? $name : basename( (string) $path );
		}

		return implode( ', ', array_filter( $names ) );
	}

	private static function truncate( string $body ): string {
		if ( strlen( $body ) <= self::MAX_BODY_BYTES ) {
			return $body;
		}
		$body = function_exists( 'mb_strcut' )
			? mb_strcut( $body, 0, self::MAX_BODY_BYTES, 'UTF-8' )
			: substr( $body, 0, self::MAX_BODY_BYTES );

		return $body . "\n\n" . '[…truncated]';
	}

	/**
	 * Keep the table bounded — drop everything older than the newest retained rows.
	 */
	private static function trim(): void {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a fixed suffix, never user input.
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::retention() ) );
		if ( null !== $cutoff ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a fixed suffix, never user input.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a fixed suffix, never user input.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/**
	 * A filtered page of rows, newest first — what the Email Log screen lists.
	 *
	 * Ordering is by id rather than sent_at: it is the same order (rows are only
	 * ever appended) and it is the primary key, so there is nothing to sort.
	 *
	 * @param array $args status, search, order, per_page, offset.
	 * @return array<int, object>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'status'   => '',
				'search'   => '',
				'order'    => 'DESC',
				'per_page' => 20,
				'offset'   => 0,
			]
		);

		list( $where, $params ) = self::where( $args );

		$table = self::table();
		$order = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$params[] = max( 1, (int) $args['per_page'] );
		$params[] = max( 0, (int) $args['offset'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a fixed suffix; $where and $order are built here from placeholders and a two-way choice, never from user input.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id {$order} LIMIT %d OFFSET %d", $params ) );
	}

	/**
	 * How many rows match the same filter — the pagination total.
	 *
	 * @param array $args status, search.
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'status' => '',
				'search' => '',
			]
		);

		list( $where, $params ) = self::where( $args );

		$table = self::table();
		$sql   = "SELECT COUNT(*) FROM {$table} {$where}";

		// An unfiltered count has no placeholders, and prepare() with none is an error.
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from placeholders only.
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- see above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Row counts keyed by status, plus an 'all' total — the log screen's filter
	 * links, in one query rather than one per status.
	 *
	 * @return array<string, int>
	 */
	public static function counts_by_status(): array {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a fixed suffix, never user input.
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		$counts = [ 'all' => 0 ];
		foreach ( $rows as $row ) {
			$total                            = (int) $row->total;
			$counts[ (string) $row->status ]   = $total;
			$counts['all']                    += $total;
		}

		return $counts;
	}

	/**
	 * Delete the given rows.
	 *
	 * @param array<int, int|string> $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is a fixed suffix on $wpdb->prefix; $placeholders is a generated list of %d, and every id is bound through prepare().
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * The WHERE fragment shared by query() and count(), with the values to bind.
	 *
	 * @param array $args status, search.
	 * @return array{0: string, 1: array} SQL beginning with WHERE (or an empty string), and its parameters.
	 */
	private static function where( array $args ): array {
		global $wpdb;

		$clauses = [];
		$params  = [];

		$status = (string) ( $args['status'] ?? '' );
		if ( '' !== $status ) {
			$clauses[] = 'status = %s';
			$params[]  = $status;
		}

		$search = (string) ( $args['search'] ?? '' );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '( to_email LIKE %s OR subject LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
		}

		return [ empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses ), $params ];
	}

	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a fixed suffix, never user input.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
