<?php
/**
 * Tests for the send log: the opt-in message columns and the schema upgrade
 * that gets them onto a site that already had the table.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Logger extends WP_UnitTestCase {

	/**
	 * These tests alter the table definition, and DDL commits implicitly — the
	 * suite's per-test transaction can't roll back anything that happened before
	 * one. So the options are reset at the *start* of each test (where a later
	 * implicit commit will persist the reset), and the schema is put back at the
	 * end.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Lean_SMTP_Logger::OPTION_LOG_HEADERS );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_BODY );
		delete_option( Lean_SMTP_Logger::OPTION_RETENTION );
		delete_option( Lean_SMTP_Logger::OPTION_MAX_AGE );
		update_option( Lean_SMTP_Logger::OPTION_ENABLED, '1' );
		Lean_SMTP_Logger::create_table();
		Lean_SMTP_Logger::clear();
	}

	public function tear_down() {
		delete_option( Lean_SMTP_Logger::OPTION_ENABLED );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_HEADERS );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_BODY );
		delete_option( Lean_SMTP_Logger::OPTION_RETENTION );
		delete_option( Lean_SMTP_Logger::OPTION_MAX_AGE );
		Lean_SMTP_Logger::create_table();
		Lean_SMTP_Logger::clear();
		parent::tear_down();
	}

	/**
	 * Push an existing row's sent_at back by $days, to stand in for an entry that
	 * has aged past the cap. In the same local frame the logger writes sent_at in.
	 */
	private function backdate( string $to_email, int $days ): void {
		global $wpdb;
		$when = current_datetime()->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test helper on the custom log table.
		$wpdb->update( Lean_SMTP_Logger::table(), [ 'sent_at' => $when ], [ 'to_email' => $to_email ] );
	}

	/**
	 * @return string[] The log table's column names.
	 */
	private function columns(): array {
		global $wpdb;
		$table = Lean_SMTP_Logger::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
	}

	private function drop_column( string $column ): void {
		global $wpdb;
		$table = Lean_SMTP_Logger::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
	}

	private function last_row() {
		$rows = Lean_SMTP_Logger::recent( 1 );
		return $rows[0] ?? null;
	}

	private function sample(): array {
		return [
			'to'          => 'rcpt@example.com',
			'subject'     => 'Order #1234',
			'message'     => 'Reset your password: https://example.org/reset?key=secret',
			'headers'     => [ 'Content-Type: text/html; charset=UTF-8', 'Cc: copy@example.com' ],
			'attachments' => [ '/tmp/invoice-1234.pdf' ],
		];
	}

	private function log_sample(): void {
		Lean_SMTP_Logger::log(
			'smtp',
			'rcpt@example.com',
			'Order #1234',
			Lean_SMTP_Logger::STATUS_SENT,
			'',
			$this->sample()
		);
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	public function test_table_has_the_content_columns() {
		$columns = $this->columns();

		$this->assertContains( 'headers', $columns );
		$this->assertContains( 'body', $columns );
		$this->assertContains( 'attachments', $columns );
	}

	public function test_upgrade_adds_a_missing_column_to_an_existing_table() {
		// The shape of a site updating from a version that predates these
		// columns: the table exists, the activation hook never fires again.
		$this->drop_column( 'body' );
		update_option( Lean_SMTP_Logger::OPTION_DB_VERSION, '1' );
		$this->assertNotContains( 'body', $this->columns() );

		Lean_SMTP_Logger::maybe_upgrade();

		$this->assertContains( 'body', $this->columns() );
		$this->assertSame( Lean_SMTP_Logger::DB_VERSION, get_option( Lean_SMTP_Logger::OPTION_DB_VERSION ) );
	}

	public function test_upgrade_does_nothing_once_the_version_matches() {
		// Proves the check is what gates the work — otherwise every request
		// would pay for a dbDelta.
		update_option( Lean_SMTP_Logger::OPTION_DB_VERSION, Lean_SMTP_Logger::DB_VERSION );
		$this->drop_column( 'body' );

		Lean_SMTP_Logger::maybe_upgrade();

		$this->assertNotContains( 'body', $this->columns() );
	}

	// -------------------------------------------------------------------------
	// Content columns
	// -------------------------------------------------------------------------

	public function test_nothing_is_recorded_without_opting_in() {
		$this->log_sample();
		$row = $this->last_row();

		$this->assertSame( 'Order #1234', $row->subject );
		$this->assertSame( Lean_SMTP_Logger::STATUS_SENT, $row->status );
		// The send is recorded; what was in it is not.
		$this->assertNull( $row->headers );
		$this->assertNull( $row->body );
		$this->assertNull( $row->attachments );
	}

	public function test_headers_option_records_headers_and_attachment_names_only() {
		update_option( Lean_SMTP_Logger::OPTION_LOG_HEADERS, '1' );

		$this->log_sample();
		$row = $this->last_row();

		$this->assertStringContainsString( 'Cc: copy@example.com', $row->headers );
		$this->assertSame( 'invoice-1234.pdf', $row->attachments );
		// The body is governed by its own setting.
		$this->assertNull( $row->body );
	}

	public function test_body_option_records_the_body_only() {
		update_option( Lean_SMTP_Logger::OPTION_LOG_BODY, '1' );

		$this->log_sample();
		$row = $this->last_row();

		$this->assertStringContainsString( 'https://example.org/reset?key=secret', $row->body );
		$this->assertNull( $row->headers );
		$this->assertNull( $row->attachments );
	}

	public function test_a_long_body_is_truncated() {
		update_option( Lean_SMTP_Logger::OPTION_LOG_BODY, '1' );

		Lean_SMTP_Logger::log(
			'smtp',
			'rcpt@example.com',
			'Big',
			Lean_SMTP_Logger::STATUS_SENT,
			'',
			[ 'message' => str_repeat( 'a', Lean_SMTP_Logger::MAX_BODY_BYTES + 5000 ) ]
		);

		$body = $this->last_row()->body;

		$this->assertLessThan( Lean_SMTP_Logger::MAX_BODY_BYTES + 100, strlen( $body ) );
		$this->assertStringContainsString( 'truncated', $body );
	}

	public function test_string_headers_are_stored_verbatim() {
		update_option( Lean_SMTP_Logger::OPTION_LOG_HEADERS, '1' );

		Lean_SMTP_Logger::log(
			'ses',
			[ 'a@example.com', 'b@example.com' ],
			'Subject',
			Lean_SMTP_Logger::STATUS_SENT,
			'',
			[ 'headers' => "Reply-To: help@example.org\r\nBcc: hidden@example.com" ]
		);

		$row = $this->last_row();

		$this->assertSame( "Reply-To: help@example.org\nBcc: hidden@example.com", $row->headers );
		$this->assertSame( 'a@example.com, b@example.com', $row->to_email );
	}

	public function test_a_failure_records_its_error() {
		Lean_SMTP_Logger::log(
			'mailgun',
			'rcpt@example.com',
			'Subject',
			Lean_SMTP_Logger::STATUS_FAILED,
			'Mailgun returned HTTP 401'
		);

		$row = $this->last_row();

		$this->assertSame( Lean_SMTP_Logger::STATUS_FAILED, $row->status );
		$this->assertSame( 'Mailgun returned HTTP 401', $row->error );
	}

	public function test_nothing_is_recorded_when_logging_is_off() {
		update_option( Lean_SMTP_Logger::OPTION_ENABLED, '0' );

		$this->log_sample();

		$this->assertSame( [], Lean_SMTP_Logger::recent( 5 ) );
	}

	// -------------------------------------------------------------------------
	// Querying — what the Email Log screen reads through
	// -------------------------------------------------------------------------

	/**
	 * Three rows: two sent (one to alice), one failed.
	 */
	private function log_a_mixed_handful(): void {
		Lean_SMTP_Logger::log( 'smtp', 'alice@example.com', 'Welcome aboard', Lean_SMTP_Logger::STATUS_SENT );
		Lean_SMTP_Logger::log( 'smtp', 'bob@example.com', 'Your receipt', Lean_SMTP_Logger::STATUS_SENT );
		Lean_SMTP_Logger::log( 'ses', 'carol@example.com', 'Password reset', Lean_SMTP_Logger::STATUS_FAILED, 'HTTP 401' );
	}

	public function test_query_filters_by_status() {
		$this->log_a_mixed_handful();

		$failed = Lean_SMTP_Logger::query( [ 'status' => Lean_SMTP_Logger::STATUS_FAILED ] );

		$this->assertCount( 1, $failed );
		$this->assertSame( 'carol@example.com', $failed[0]->to_email );
		$this->assertSame( 2, Lean_SMTP_Logger::count( [ 'status' => Lean_SMTP_Logger::STATUS_SENT ] ) );
		$this->assertSame( 3, Lean_SMTP_Logger::count() );
	}

	public function test_query_searches_recipient_and_subject() {
		$this->log_a_mixed_handful();

		$this->assertCount( 1, Lean_SMTP_Logger::query( [ 'search' => 'alice@' ] ) );
		$this->assertCount( 1, Lean_SMTP_Logger::query( [ 'search' => 'receipt' ] ) );
		$this->assertSame( 0, Lean_SMTP_Logger::count( [ 'search' => 'nobody' ] ) );
	}

	public function test_search_wildcards_are_escaped_rather_than_matched() {
		// A bare % in the search box would otherwise match everything.
		$this->log_a_mixed_handful();

		$this->assertSame( 0, Lean_SMTP_Logger::count( [ 'search' => '%' ] ) );
	}

	public function test_query_pages_newest_first() {
		$this->log_a_mixed_handful();

		$first = Lean_SMTP_Logger::query( [ 'per_page' => 2 ] );
		$next  = Lean_SMTP_Logger::query(
			[
				'per_page' => 2,
				'offset'   => 2,
			]
		);

		$this->assertCount( 2, $first );
		$this->assertCount( 1, $next );
		$this->assertSame( 'carol@example.com', $first[0]->to_email );
		$this->assertSame( 'alice@example.com', $next[0]->to_email );
	}

	public function test_counts_by_status_totals_every_bucket() {
		$this->log_a_mixed_handful();

		$counts = Lean_SMTP_Logger::counts_by_status();

		$this->assertSame( 3, $counts['all'] );
		$this->assertSame( 2, $counts[ Lean_SMTP_Logger::STATUS_SENT ] );
		$this->assertSame( 1, $counts[ Lean_SMTP_Logger::STATUS_FAILED ] );
	}

	public function test_delete_removes_only_the_given_rows() {
		$this->log_a_mixed_handful();
		$rows = Lean_SMTP_Logger::recent();

		$deleted = Lean_SMTP_Logger::delete( [ (int) $rows[0]->id, (int) $rows[2]->id ] );

		$this->assertSame( 2, $deleted );
		$remaining = Lean_SMTP_Logger::recent();
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'bob@example.com', $remaining[0]->to_email );
	}

	public function test_delete_of_nothing_is_a_no_op() {
		$this->log_a_mixed_handful();

		$this->assertSame( 0, Lean_SMTP_Logger::delete( [] ) );
		$this->assertSame( 0, Lean_SMTP_Logger::delete( [ 'not-an-id' ] ) );
		$this->assertSame( 3, Lean_SMTP_Logger::count() );
	}

	// -------------------------------------------------------------------------
	// Retention
	// -------------------------------------------------------------------------

	public function test_retention_defaults_and_rejects_a_value_outside_the_offered_set() {
		$this->assertSame( Lean_SMTP_Logger::MAX_ROWS, Lean_SMTP_Logger::retention() );

		update_option( Lean_SMTP_Logger::OPTION_RETENTION, '1000' );
		$this->assertSame( 1000, Lean_SMTP_Logger::retention() );

		// Anything else — a hand-edited option, a typo in a wp-config.php constant.
		update_option( Lean_SMTP_Logger::OPTION_RETENTION, '999999' );
		$this->assertSame( Lean_SMTP_Logger::MAX_ROWS, Lean_SMTP_Logger::retention() );
	}

	public function test_the_table_is_trimmed_to_the_retention_in_force() {
		$overshoot = Lean_SMTP_Logger::MAX_ROWS + 5;

		for ( $i = 0; $i < $overshoot; $i++ ) {
			Lean_SMTP_Logger::log( 'smtp', "rcpt{$i}@example.com", "Message {$i}", Lean_SMTP_Logger::STATUS_SENT );
		}

		$this->assertSame( Lean_SMTP_Logger::MAX_ROWS, Lean_SMTP_Logger::count() );
		// The newest survive; the oldest are the ones dropped.
		$this->assertSame( 'rcpt' . ( $overshoot - 1 ) . '@example.com', Lean_SMTP_Logger::recent( 1 )[0]->to_email );
	}

	// -------------------------------------------------------------------------
	// Age cap
	// -------------------------------------------------------------------------

	public function test_max_age_defaults_off_and_rejects_a_value_outside_the_offered_set() {
		$this->assertSame( 0, Lean_SMTP_Logger::max_age() );

		update_option( Lean_SMTP_Logger::OPTION_MAX_AGE, '30' );
		$this->assertSame( 30, Lean_SMTP_Logger::max_age() );

		// A day count that isn't one of the offered choices reads as off.
		update_option( Lean_SMTP_Logger::OPTION_MAX_AGE, '45' );
		$this->assertSame( 0, Lean_SMTP_Logger::max_age() );
	}

	public function test_purge_expired_drops_rows_past_the_age_cap_and_keeps_recent_ones() {
		Lean_SMTP_Logger::log( 'smtp', 'old@example.com', 'Old', Lean_SMTP_Logger::STATUS_SENT );
		Lean_SMTP_Logger::log( 'smtp', 'fresh@example.com', 'Fresh', Lean_SMTP_Logger::STATUS_SENT );
		$this->backdate( 'old@example.com', 40 );

		update_option( Lean_SMTP_Logger::OPTION_MAX_AGE, '30' );
		Lean_SMTP_Logger::purge_expired();

		$remaining = wp_list_pluck( Lean_SMTP_Logger::recent( 5 ), 'to_email' );
		$this->assertContains( 'fresh@example.com', $remaining );
		$this->assertNotContains( 'old@example.com', $remaining );
	}

	public function test_purge_expired_does_nothing_when_the_cap_is_off() {
		Lean_SMTP_Logger::log( 'smtp', 'old@example.com', 'Old', Lean_SMTP_Logger::STATUS_SENT );
		$this->backdate( 'old@example.com', 400 );

		update_option( Lean_SMTP_Logger::OPTION_MAX_AGE, '0' );
		Lean_SMTP_Logger::purge_expired();

		$this->assertSame( 1, Lean_SMTP_Logger::count() );
	}
}
