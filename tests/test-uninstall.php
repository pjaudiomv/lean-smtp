<?php
/**
 * Test for uninstall.php: it must remove every plugin option, the per-user
 * screen preference, the cached Gmail tokens and the send-log table.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_removes_options_metadata_transients_and_the_log_table() {
		global $wpdb;

		// The test harness rewrites CREATE/DROP TABLE to their TEMPORARY form so
		// per-test tables auto-clean. That would turn uninstall.php's DROP into a
		// no-op against the real log table, so lift those rewrites for this test
		// and operate on the real table (recreated at the end for the rest of the
		// suite).
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		// Seed something for each kind of thing uninstall.php should remove.
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_SES );
		update_option( Lean_SMTP_Resend::OPTION_API_KEY, 'secret' );
		update_option( Lean_SMTP_Logger::OPTION_DB_VERSION, Lean_SMTP_Logger::DB_VERSION );
		set_transient( 'lean_smtp_gmail_token_abc123', 'tok', HOUR_IN_SECONDS );
		update_user_meta( 1, Lean_SMTP_Log_Page::PER_PAGE_OPTION, 50 );
		Lean_SMTP_Logger::create_table();

		$table = $wpdb->prefix . 'lean_smtp_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verifying the custom table exists in a test.
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lean-smtp/lean-smtp.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( Lean_SMTP_Mailer::OPTION_MAILER, false ) );
		$this->assertFalse( get_option( Lean_SMTP_Resend::OPTION_API_KEY, false ) );
		$this->assertFalse( get_option( Lean_SMTP_Logger::OPTION_DB_VERSION, false ) );
		$this->assertSame( '', (string) get_user_meta( 1, Lean_SMTP_Log_Page::PER_PAGE_OPTION, true ) );

		// Checked at the DB level rather than via get_transient(), whose in-memory
		// cache would mask the row the raw DELETE removed.
		$transient_like = $wpdb->esc_like( '_transient_lean_smtp_gmail_token_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verifying transient rows are gone in a test.
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) );
		$this->assertSame( 0, $remaining );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verifying the custom table was dropped in a test.
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		// Dropping the table implicitly committed the surrounding test transaction,
		// so the rollback won't restore it — recreate it for the rest of the suite.
		Lean_SMTP_Logger::create_table();
	}
}
