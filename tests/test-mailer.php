<?php
/**
 * Tests for From-identity resolution and the SES send path.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Mailer extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// From identity
	// -------------------------------------------------------------------------

	public function test_default_from_email_shape() {
		// The test suite runs on example.org.
		$this->assertSame( 'wordpress@example.org', Lean_SMTP_Mailer::default_from_email() );
		$this->assertTrue( Lean_SMTP_Mailer::is_default_from_email( 'wordpress@example.org' ) );
		$this->assertTrue( Lean_SMTP_Mailer::is_default_from_email( 'WordPress@Example.org' ) );
		$this->assertFalse( Lean_SMTP_Mailer::is_default_from_email( 'someone@example.org' ) );
	}

	public function test_from_email_unchanged_when_not_configured() {
		$this->assertSame( 'a@b.com', Lean_SMTP_Mailer::filter_from_email( 'a@b.com' ) );
	}

	public function test_from_email_fills_in_over_wordpress_default() {
		update_option( Lean_SMTP_Mailer::OPTION_FROM_EMAIL, 'me@site.com' );

		// Force off: replace only the untouched WordPress default...
		$this->assertSame( 'me@site.com', Lean_SMTP_Mailer::filter_from_email( 'wordpress@example.org' ) );
		// ...but leave an address another plugin deliberately set.
		$this->assertSame( 'other@plugin.com', Lean_SMTP_Mailer::filter_from_email( 'other@plugin.com' ) );
	}

	public function test_force_from_email_overrides_everything() {
		update_option( Lean_SMTP_Mailer::OPTION_FROM_EMAIL, 'me@site.com' );
		update_option( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, '1' );

		$this->assertSame( 'me@site.com', Lean_SMTP_Mailer::filter_from_email( 'other@plugin.com' ) );
	}

	public function test_from_name_treats_wordpress_default_as_unset() {
		update_option( Lean_SMTP_Mailer::OPTION_FROM_NAME, 'My Site' );

		$this->assertSame( 'My Site', Lean_SMTP_Mailer::filter_from_name( 'WordPress' ) );
		$this->assertSame( 'My Site', Lean_SMTP_Mailer::filter_from_name( '' ) );
		// A real name set by another plugin is respected unless forced.
		$this->assertSame( 'Someone Else', Lean_SMTP_Mailer::filter_from_name( 'Someone Else' ) );

		update_option( Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME, '1' );
		$this->assertSame( 'My Site', Lean_SMTP_Mailer::filter_from_name( 'Someone Else' ) );
	}

	// -------------------------------------------------------------------------
	// SES send path
	// -------------------------------------------------------------------------

	private function configure_ses() {
		update_option( Lean_SMTP_SES::OPTION_REGION, 'us-east-1' );
		update_option( Lean_SMTP_SES::OPTION_ACCESS_KEY, 'AKIDEXAMPLE' );
		update_option( Lean_SMTP_SES::OPTION_SECRET_KEY, 'test-secret' );
	}

	public function test_ses_send_posts_signed_raw_message() {
		$this->configure_ses();

		$captured = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured ) {
				$captured['url']  = $url;
				$captured['args'] = $args;
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{"MessageId":"0100-abc"}',
				];
			},
			10,
			3
		);

		$ok = Lean_SMTP_Mailer::send_via_ses(
			null,
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body text',
				'headers'     => '',
				'attachments' => [],
			]
		);

		$this->assertTrue( $ok );
		$this->assertSame( 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', $captured['url'] );
		$this->assertStringContainsString( 'AWS4-HMAC-SHA256', $captured['args']['headers']['Authorization'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertArrayHasKey( 'Content', $body );
		$raw = base64_decode( $body['Content']['Raw']['Data'] );
		$this->assertStringContainsString( 'Subject: Hello', $raw );
		$this->assertStringContainsString( 'To: rcpt@example.com', $raw );
	}

	public function test_ses_send_reports_failure_on_api_error() {
		$this->configure_ses();

		add_filter(
			'pre_http_request',
			function () {
				return [
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'body'     => '{"message":"Email address is not verified."}',
				];
			}
		);

		$ok = Lean_SMTP_Mailer::send_via_ses(
			null,
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => '',
				'attachments' => [],
			]
		);

		$this->assertFalse( $ok );
	}
}
