<?php
/**
 * Tests for Lean SMTP → Email Log: the list table's filtering, paging and
 * rendering, and the screen's delete handling.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Log_Page extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-log-table.php';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'lean-smtp_page_lean-smtp-log' );

		update_option( Lean_SMTP_Logger::OPTION_ENABLED, '1' );
		Lean_SMTP_Logger::create_table();
		Lean_SMTP_Logger::clear();
	}

	public function tear_down() {
		$_GET    = [];
		$_REQUEST = [];
		delete_option( Lean_SMTP_Logger::OPTION_ENABLED );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_BODY );
		Lean_SMTP_Logger::clear();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function log_a_mixed_handful(): void {
		Lean_SMTP_Logger::log( 'smtp', 'alice@example.com', 'Welcome aboard', Lean_SMTP_Logger::STATUS_SENT );
		Lean_SMTP_Logger::log( 'smtp', 'bob@example.com', 'Your receipt', Lean_SMTP_Logger::STATUS_SENT );
		Lean_SMTP_Logger::log( 'ses', 'carol@example.com', 'Password reset', Lean_SMTP_Logger::STATUS_FAILED, 'HTTP 401' );
	}

	private function prepared_table(): Lean_SMTP_Log_Table {
		$table = new Lean_SMTP_Log_Table();
		$table->prepare_items();

		return $table;
	}

	// -------------------------------------------------------------------------
	// The list
	// -------------------------------------------------------------------------

	public function test_the_list_shows_every_send_newest_first() {
		$this->log_a_mixed_handful();

		$table = $this->prepared_table();

		$this->assertCount( 3, $table->items );
		$this->assertSame( 'carol@example.com', $table->items[0]->to_email );
	}

	public function test_the_status_filter_narrows_the_list() {
		$this->log_a_mixed_handful();
		$_GET['status'] = Lean_SMTP_Logger::STATUS_FAILED;

		$table = $this->prepared_table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'carol@example.com', $table->items[0]->to_email );
	}

	public function test_an_unknown_status_is_ignored_rather_than_queried() {
		$this->log_a_mixed_handful();
		$_GET['status'] = 'bogus';

		$this->assertSame( '', Lean_SMTP_Log_Table::current_status() );
		$this->assertCount( 3, $this->prepared_table()->items );
	}

	public function test_search_narrows_the_list() {
		$this->log_a_mixed_handful();
		$_GET['s'] = 'receipt';

		$table = $this->prepared_table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'bob@example.com', $table->items[0]->to_email );
	}

	public function test_paging_reports_the_full_total() {
		$this->log_a_mixed_handful();

		update_user_meta( get_current_user_id(), Lean_SMTP_Log_Page::PER_PAGE_OPTION, 2 );

		// get_pagenum() reads $_REQUEST, which PHP does not build from $_GET in CLI.
		$_GET['paged']     = 2;
		$_REQUEST['paged'] = 2;

		$table = $this->prepared_table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'alice@example.com', $table->items[0]->to_email );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	public function test_rows_render_with_a_status_badge_and_a_delete_action() {
		$this->log_a_mixed_handful();
		$table = $this->prepared_table();

		ob_start();
		$table->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'lsmtp-badge failed', $html );
		$this->assertStringContainsString( 'lsmtp-badge sent', $html );
		$this->assertStringContainsString( 'lsmtp_action=delete-entry', $html );
		// The bulk-action nonce the delete handler verifies against.
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_recorded_message_content_renders_in_the_detail_row() {
		update_option( Lean_SMTP_Logger::OPTION_LOG_BODY, '1' );
		Lean_SMTP_Logger::log(
			'smtp',
			'rcpt@example.com',
			'Reset',
			Lean_SMTP_Logger::STATUS_SENT,
			'',
			[ 'message' => 'Reset link: https://example.org/reset?key=secret' ]
		);

		ob_start();
		$this->prepared_table()->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'lsmtp-log-detail', $html );
		$this->assertStringContainsString( 'example.org/reset?key=secret', $html );
	}

	public function test_a_log_without_recorded_content_has_no_detail_row() {
		// The default: the send is recorded, what was in it is not.
		$this->log_a_mixed_handful();

		ob_start();
		$this->prepared_table()->display();
		$html = (string) ob_get_clean();

		// The failed row still shows its error; the two successes have nothing.
		$this->assertSame( 1, substr_count( $html, 'lsmtp-log-detail' ) );
	}

	public function test_the_page_renders_without_inline_script_or_style_tags() {
		$this->log_a_mixed_handful();

		ob_start();
		Lean_SMTP_Log_Page::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'lsmtp-log-wrap', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
	}

	public function test_the_page_says_so_when_logging_is_off() {
		update_option( Lean_SMTP_Logger::OPTION_ENABLED, '0' );

		ob_start();
		Lean_SMTP_Log_Page::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'The send log is off', $html );
		$this->assertStringContainsString( Lean_SMTP_Settings::url(), $html );
	}

	// -------------------------------------------------------------------------
	// State carried across a delete
	// -------------------------------------------------------------------------

	public function test_the_delete_link_carries_the_current_filter() {
		$this->log_a_mixed_handful();
		$_REQUEST['status'] = Lean_SMTP_Logger::STATUS_FAILED;
		$_GET['status']     = Lean_SMTP_Logger::STATUS_FAILED;

		ob_start();
		$this->prepared_table()->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'status=failed', $html );
	}
}
