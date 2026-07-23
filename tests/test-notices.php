<?php
/**
 * Tests for the mail-failure admin notice.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Notices extends WP_UnitTestCase {

	public function tear_down() {
		Lean_SMTP_Notices::resume();
		delete_option( Lean_SMTP_Notices::OPTION_LAST_FAILURE );
		parent::tear_down();
	}

	private function record_failure( $message = 'Connection refused', $to = 'rcpt@example.com' ) {
		$error = new WP_Error();
		$error->add(
			'wp_mail_failed',
			$message,
			[
				'to'      => [ $to ],
				'subject' => 'Subject',
			]
		);
		do_action( 'wp_mail_failed', $error );
	}

	public function test_failure_is_recorded() {
		$this->record_failure();

		$failure = Lean_SMTP_Notices::last_failure();

		$this->assertIsArray( $failure );
		$this->assertSame( 'Connection refused', $failure['message'] );
		$this->assertSame( 'rcpt@example.com', $failure['to'] );
		$this->assertNotEmpty( $failure['time'] );
	}

	public function test_a_later_success_clears_the_notice() {
		// The notice says "mail is broken now", not "mail broke once".
		$this->record_failure();
		$this->assertNotNull( Lean_SMTP_Notices::last_failure() );

		do_action( 'wp_mail_succeeded', [ 'to' => 'rcpt@example.com' ] );

		$this->assertNull( Lean_SMTP_Notices::last_failure() );
	}

	public function test_suspended_failures_are_not_recorded() {
		// A deliberate test send reports its own result inline; it shouldn't
		// also raise the background notice.
		Lean_SMTP_Notices::suspend();
		$this->record_failure();
		$this->assertNull( Lean_SMTP_Notices::last_failure() );

		Lean_SMTP_Notices::resume();
		$this->record_failure();
		$this->assertNotNull( Lean_SMTP_Notices::last_failure() );
	}

	public function test_notice_renders_only_for_administrators() {
		$this->record_failure( 'SES rejected the message' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		ob_start();
		Lean_SMTP_Notices::render();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		Lean_SMTP_Notices::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'SES rejected the message', $output );
		$this->assertStringContainsString( 'notice-error', $output );
	}
}
