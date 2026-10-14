<?php
/**
 * Tests for the admin-post handlers' capability and nonce guards.
 *
 * The test-email and clear-log handlers both wp_die() when the current user
 * lacks manage_options or the request carries no valid nonce. The WordPress
 * test suite turns wp_die() into a WPDieException, so each guard is asserted by
 * the exception it raises.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Admin extends WP_UnitTestCase {

	public function tear_down() {
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	private function act_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	public function test_handle_test_denies_a_user_without_manage_options() {
		$this->act_as( 'subscriber' );

		$this->expectException( WPDieException::class );
		Lean_SMTP_Settings::handle_test();
	}

	public function test_handle_test_requires_a_valid_nonce() {
		// An administrator passes the capability check, so the nonce guard is what
		// stops a request that arrives without one.
		$this->act_as( 'administrator' );

		$this->expectException( WPDieException::class );
		Lean_SMTP_Settings::handle_test();
	}

	public function test_clear_log_denies_a_user_without_manage_options() {
		$this->act_as( 'subscriber' );

		$this->expectException( WPDieException::class );
		Lean_SMTP_Log_Page::handle_clear_log();
	}

	public function test_clear_log_requires_a_valid_nonce() {
		$this->act_as( 'administrator' );

		$this->expectException( WPDieException::class );
		Lean_SMTP_Log_Page::handle_clear_log();
	}
}
