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
		update_option( Lean_SMTP_Logger::OPTION_ENABLED, '1' );
		Lean_SMTP_Logger::create_table();
		Lean_SMTP_Logger::clear();
	}

	public function tear_down() {
		delete_option( Lean_SMTP_Logger::OPTION_ENABLED );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_HEADERS );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_BODY );
		Lean_SMTP_Logger::create_table();
		Lean_SMTP_Logger::clear();
		parent::tear_down();
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
}
