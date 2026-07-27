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
	// Reply-To
	// -------------------------------------------------------------------------

	private function phpmailer() {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		return new PHPMailer\PHPMailer\PHPMailer( true );
	}

	/**
	 * PHPMailer hands back [ address, name ] pairs; the addresses alone are what
	 * these tests care about.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 * @return string[]
	 */
	private function reply_to_addresses( $phpmailer ): array {
		return array_column( $phpmailer->getReplyToAddresses(), 0 );
	}

	public function test_reply_to_fills_in_when_the_message_sets_none() {
		update_option( Lean_SMTP_Mailer::OPTION_REPLY_TO, 'help@example.org' );

		$phpmailer = $this->phpmailer();
		Lean_SMTP_Mailer::apply_reply_to( $phpmailer );

		$this->assertSame( [ 'help@example.org' ], $this->reply_to_addresses( $phpmailer ) );
	}

	public function test_reply_to_leaves_one_the_message_chose_alone() {
		// A Reply-To in the message's own headers was picked for that message;
		// this setting is only a site-wide default.
		update_option( Lean_SMTP_Mailer::OPTION_REPLY_TO, 'help@example.org' );

		$phpmailer = $this->phpmailer();
		$phpmailer->addReplyTo( 'sales@example.org' );
		Lean_SMTP_Mailer::apply_reply_to( $phpmailer );

		$this->assertSame( [ 'sales@example.org' ], $this->reply_to_addresses( $phpmailer ) );
	}

	public function test_no_reply_to_is_added_when_the_setting_is_empty() {
		$phpmailer = $this->phpmailer();
		Lean_SMTP_Mailer::apply_reply_to( $phpmailer );

		$this->assertSame( [], $this->reply_to_addresses( $phpmailer ) );
	}

	public function test_reply_to_reaches_an_api_send() {
		// One phpmailer_init handler has to serve both paths; the API path
		// re-fires that hook, so this proves the shared handler is enough.
		$this->configure_ses();
		update_option( Lean_SMTP_Mailer::OPTION_REPLY_TO, 'help@example.org' );
		add_action( 'phpmailer_init', [ 'Lean_SMTP_Mailer', 'apply_reply_to' ] );

		$captured = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured ) {
				$captured['args'] = $args;
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{"MessageId":"x"}',
				];
			},
			10,
			3
		);

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

		$body = json_decode( $captured['args']['body'], true );
		$raw  = base64_decode( $body['Content']['Raw']['Data'] );

		$this->assertStringContainsString( 'Reply-To: help@example.org', $raw );
	}

	// -------------------------------------------------------------------------
	// Offline path
	// -------------------------------------------------------------------------

	public function test_offline_records_the_message_and_contacts_nothing() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_OFFLINE );
		update_option( Lean_SMTP_Logger::OPTION_ENABLED, '1' );
		update_option( Lean_SMTP_Logger::OPTION_LOG_BODY, '1' );
		Lean_SMTP_Logger::clear();

		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'Offline mode must not make an HTTP request.' );
			}
		);

		// init() is what decides the path, so exercise it: a real wp_mail() call
		// has to come back true without anything leaving the site.
		Lean_SMTP_Mailer::init();
		$sent = wp_mail( 'rcpt@example.com', 'Order #1234', 'Your order shipped.' );

		$this->assertTrue( $sent );

		$rows = Lean_SMTP_Logger::recent( 5 );
		$this->assertCount( 1, $rows );
		$this->assertSame( Lean_SMTP_Logger::STATUS_OFFLINE, $rows[0]->status );
		$this->assertSame( Lean_SMTP_Mailer::MAILER_OFFLINE, $rows[0]->mailer );
		$this->assertSame( 'Order #1234', $rows[0]->subject );
		$this->assertSame( 'Your order shipped.', $rows[0]->body );

		Lean_SMTP_Logger::clear();
		delete_option( Lean_SMTP_Logger::OPTION_ENABLED );
		delete_option( Lean_SMTP_Logger::OPTION_LOG_BODY );
	}

	public function test_offline_is_always_configured_and_never_a_transport() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_OFFLINE );

		$this->assertTrue( Lean_SMTP_Mailer::is_offline() );
		$this->assertTrue( Lean_SMTP_Mailer::is_configured() );
		$this->assertNull( Lean_SMTP_Mailer::transport() );
	}

	public function test_an_unknown_mailer_falls_back_to_smtp() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, 'sendgrid' );

		$this->assertSame( Lean_SMTP_Mailer::MAILER_SMTP, Lean_SMTP_Mailer::mailer() );
		$this->assertFalse( Lean_SMTP_Mailer::is_offline() );
	}

	// -------------------------------------------------------------------------
	// SES send path
	// -------------------------------------------------------------------------

	private function configure_ses() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_SES );
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
		$this->assertSame( 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', $captured['url'] );
		$this->assertStringContainsString( 'AWS4-HMAC-SHA256', $captured['args']['headers']['Authorization'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertArrayHasKey( 'Content', $body );
		$raw = base64_decode( $body['Content']['Raw']['Data'] );
		$this->assertStringContainsString( 'Subject: Hello', $raw );
		$this->assertStringContainsString( 'To: rcpt@example.com', $raw );
	}

	public function test_ses_send_without_bcc_omits_destination() {
		// A plain message needs no explicit envelope; SES derives recipients
		// from the headers, so no Bcc-stripping is needed either.
		$this->configure_ses();

		$captured = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured ) {
				$captured['args'] = $args;
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{"MessageId":"x"}',
				];
			},
			10,
			3
		);

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

		$body = json_decode( $captured['args']['body'], true );
		$this->assertArrayNotHasKey( 'Destination', $body );
	}

	public function test_ses_bcc_goes_in_destination_and_not_the_delivered_headers() {
		// The privacy fix: Bcc recipients must be delivered to (via an explicit
		// SES Destination) but must not appear in the MIME SES sends on, or
		// every recipient would see them.
		$this->configure_ses();

		$captured = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured ) {
				$captured['args'] = $args;
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{"MessageId":"x"}',
				];
			},
			10,
			3
		);

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

		$body = json_decode( $captured['args']['body'], true );

		// Delivered to: the Bcc address rides in the envelope...
		$this->assertContains( 'hidden@example.com', $body['Destination']['BccAddresses'] );
		$this->assertContains( 'rcpt@example.com', $body['Destination']['ToAddresses'] );
		$this->assertContains( 'copy@example.com', $body['Destination']['CcAddresses'] );

		// ...but the Bcc header is gone from the message itself.
		$raw = base64_decode( $body['Content']['Raw']['Data'] );
		$this->assertStringNotContainsStringIgnoringCase( 'Bcc: hidden@example.com', $raw );
		// Cc is a visible header and must survive.
		$this->assertStringContainsString( 'Cc: copy@example.com', $raw );
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
	}

	// -------------------------------------------------------------------------
	// Regressions
	// -------------------------------------------------------------------------

	public function test_secret_encryption_is_idempotent() {
		// WordPress runs a setting's sanitize callback twice on the first save
		// of a new option (update_option, then add_option). The second pass
		// receives our own ciphertext and must not re-encrypt it — otherwise the
		// stored secret decrypts to a still-encrypted string and every SES
		// signature fails with HTTP 403.
		$enc1 = Lean_SMTP_Settings::sanitize_ses_secret( 'my-aws-secret/key+value' );
		$enc2 = Lean_SMTP_Settings::sanitize_ses_secret( $enc1 );

		$this->assertSame( $enc1, $enc2, 'Re-sanitizing ciphertext must return it unchanged.' );
		$this->assertSame( 'my-aws-secret/key+value', Lean_SMTP_Crypto::decrypt( $enc2 ) );
	}

	public function test_credentials_are_stored_verbatim() {
		// A password is an opaque token: sanitize_text_field() would eat the angle
		// brackets and the %-sequence below, storing something that looks saved but
		// no longer authenticates.
		$password = 'p@ss<w>rd&"100%"#\'*+/=?^`{|}~ end';

		$stored = Lean_SMTP_Settings::sanitize_smtp_password( $password );

		$this->assertSame( $password, Lean_SMTP_Crypto::decrypt( $stored ) );
	}

	public function test_control_characters_are_stripped_from_credentials() {
		// Nothing else is removed, but a newline or NUL can never be part of a
		// credential and could break out of the SMTP dialogue downstream.
		$stored = Lean_SMTP_Settings::sanitize_resend_key( "re_key\r\nAUTH inject\x00" );

		$this->assertSame( 're_keyAUTH inject', Lean_SMTP_Crypto::decrypt( $stored ) );
	}

	public function test_ses_success_fires_succeeded_hook_exactly_once() {
		$this->configure_ses();
		add_filter(
			'pre_http_request',
			function () {
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{"MessageId":"x"}',
				];
			}
		);

		$calls = 0;
		add_action(
			'wp_mail_succeeded',
			function () use ( &$calls ) {
				$calls++;
			}
		);

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

		$this->assertSame( 1, $calls, 'A successful SES send must notify exactly once, so it logs once.' );
	}

	public function test_ses_failure_fires_failed_hook_exactly_once() {
		$this->configure_ses();
		add_filter(
			'pre_http_request',
			function () {
				return [
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'body'     => '{"message":"nope"}',
				];
			}
		);

		$calls = 0;
		add_action(
			'wp_mail_failed',
			function () use ( &$calls ) {
				$calls++;
			}
		);

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

		$this->assertSame( 1, $calls, 'A failed SES send must fire wp_mail_failed once, so it logs once.' );
	}
}
