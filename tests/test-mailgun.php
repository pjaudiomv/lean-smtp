<?php
/**
 * Tests for the Mailgun transport.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Mailgun extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function configure_mailgun( $region = Lean_SMTP_Mailgun::REGION_US ) {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_MAILGUN );
		update_option( Lean_SMTP_Mailgun::OPTION_DOMAIN, 'mg.example.com' );
		update_option( Lean_SMTP_Mailgun::OPTION_API_KEY, Lean_SMTP_Crypto::encrypt( 'key-abc123' ) );
		update_option( Lean_SMTP_Mailgun::OPTION_REGION, $region );
	}

	/**
	 * @return array Captured request, by reference through the filter.
	 */
	private function capture_request( &$captured, $code = 200, $body = '{"id":"<x@mg>","message":"Queued. Thank you."}' ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured, $code, $body ) {
				$captured['url']  = $url;
				$captured['args'] = $args;
				return [
					'response' => [
						'code'    => $code,
						'message' => 200 === $code ? 'OK' : 'Bad Request',
					],
					'body'     => $body,
				];
			},
			10,
			3
		);
	}

	public function test_is_configured_requires_domain_and_key() {
		$this->assertFalse( Lean_SMTP_Mailgun::is_configured() );

		update_option( Lean_SMTP_Mailgun::OPTION_DOMAIN, 'mg.example.com' );
		$this->assertFalse( Lean_SMTP_Mailgun::is_configured() );

		update_option( Lean_SMTP_Mailgun::OPTION_API_KEY, Lean_SMTP_Crypto::encrypt( 'key-abc123' ) );
		$this->assertTrue( Lean_SMTP_Mailgun::is_configured() );
	}

	public function test_region_selects_the_api_host() {
		update_option( Lean_SMTP_Mailgun::OPTION_REGION, Lean_SMTP_Mailgun::REGION_US );
		$this->assertSame( 'https://api.mailgun.net', Lean_SMTP_Mailgun::api_base() );

		update_option( Lean_SMTP_Mailgun::OPTION_REGION, Lean_SMTP_Mailgun::REGION_EU );
		$this->assertSame( 'https://api.eu.mailgun.net', Lean_SMTP_Mailgun::api_base() );

		// Anything unrecognised falls back to the US stack rather than a bad host.
		update_option( Lean_SMTP_Mailgun::OPTION_REGION, 'moon' );
		$this->assertSame( 'https://api.mailgun.net', Lean_SMTP_Mailgun::api_base() );
	}

	public function test_send_posts_the_raw_mime_to_the_messages_endpoint() {
		$this->configure_mailgun();

		$captured = [];
		$this->capture_request( $captured );

		$ok = Lean_SMTP_Mailer::send_via_api(
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
		$this->assertSame( 'https://api.mailgun.net/v3/mg.example.com/messages.mime', $captured['url'] );
		$this->assertSame(
			'Basic ' . base64_encode( 'api:key-abc123' ),
			$captured['args']['headers']['Authorization']
		);
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $captured['args']['headers']['Content-Type'] );

		$this->assertStringContainsString( 'Subject: Hello', $captured['args']['body'] );
		$this->assertStringContainsString( 'To: rcpt@example.com', $captured['args']['body'] );
		$this->assertStringContainsString( 'Content-Type: message/rfc822', $captured['args']['body'] );
	}

	public function test_bcc_is_passed_as_an_envelope_recipient() {
		// Bcc deliberately isn't in the MIME headers, so the MIME endpoint would
		// silently drop those copies unless they are listed separately.
		$this->configure_mailgun();

		$captured = [];
		$this->capture_request( $captured );

		Lean_SMTP_Mailer::send_via_api(
			null,
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => "Cc: copy@example.com\nBcc: hidden@example.com",
				'attachments' => [],
			]
		);

		$body = $captured['args']['body'];

		// One "to" form field per envelope recipient.
		$this->assertSame( 3, substr_count( $body, 'name="to"' ) );
		$this->assertStringContainsString( 'hidden@example.com', $body );
		$this->assertStringContainsString( 'copy@example.com', $body );
		// ...and Bcc still must not appear in the message headers themselves.
		$this->assertStringNotContainsString( 'Bcc: hidden@example.com', $body );
	}

	public function test_eu_region_posts_to_the_eu_host() {
		$this->configure_mailgun( Lean_SMTP_Mailgun::REGION_EU );

		$captured = [];
		$this->capture_request( $captured );

		Lean_SMTP_Mailer::send_via_api(
			null,
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => '',
				'attachments' => [],
			]
		);

		$this->assertSame( 'https://api.eu.mailgun.net/v3/mg.example.com/messages.mime', $captured['url'] );
	}

	public function test_api_error_reports_failure_once() {
		$this->configure_mailgun();

		$captured = [];
		$this->capture_request( $captured, 401, '{"message":"Forbidden"}' );

		$calls = 0;
		add_action(
			'wp_mail_failed',
			function () use ( &$calls ) {
				$calls++;
			}
		);

		$ok = Lean_SMTP_Mailer::send_via_api(
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
		$this->assertSame( 1, $calls, 'A failed send must fire wp_mail_failed once, so it logs once.' );
	}

	public function test_unconfigured_send_fails_without_an_http_request() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_MAILGUN );

		$requests = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt ) use ( &$requests ) {
				$requests++;
				return $preempt;
			}
		);

		$ok = Lean_SMTP_Mailer::send_via_api(
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
		$this->assertSame( 0, $requests );
	}
}
