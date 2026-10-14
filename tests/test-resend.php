<?php
/**
 * Tests for the Resend transport.
 *
 * Resend has no raw-MIME endpoint, so this is the transport that re-expresses
 * the assembled message as JSON — the mapping is what needs pinning down.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Resend extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Send through the real wp_mail() pipeline, so the pre_wp_mail hook wiring is
	 * exercised and not just send_via_api() in isolation. init() is idempotent:
	 * WordPress dedupes the identical static callbacks it adds.
	 */
	private function wp_mail_send( array $atts ) {
		Lean_SMTP_Mailer::init();
		return wp_mail(
			$atts['to'] ?? '',
			(string) ( $atts['subject'] ?? '' ),
			$atts['message'] ?? '',
			$atts['headers'] ?? '',
			$atts['attachments'] ?? [],
			$atts['embeds'] ?? []
		);
	}

	private function configure_resend() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_RESEND );
		update_option( Lean_SMTP_Resend::OPTION_API_KEY, Lean_SMTP_Crypto::encrypt( 're_test_key' ) );
	}

	private function capture_request( &$captured, $code = 200, $body = '{"id":"abc-123"}' ) {
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

	/**
	 * @return array The decoded JSON body Resend was sent.
	 */
	private function send( array $atts ) {
		$captured = [];
		$this->capture_request( $captured );

		$ok = $this->wp_mail_send( $atts );
		$this->assertTrue( $ok );

		$this->assertSame( 'https://api.resend.com/emails', $captured['url'] );
		$this->assertSame( 'Bearer re_test_key', $captured['args']['headers']['Authorization'] );

		return json_decode( $captured['args']['body'], true );
	}

	public function test_is_configured_requires_an_api_key() {
		$this->assertFalse( Lean_SMTP_Resend::is_configured() );

		update_option( Lean_SMTP_Resend::OPTION_API_KEY, Lean_SMTP_Crypto::encrypt( 're_test_key' ) );
		$this->assertTrue( Lean_SMTP_Resend::is_configured() );
	}

	public function test_plain_text_message_maps_to_the_text_field() {
		$this->configure_resend();
		update_option( Lean_SMTP_Mailer::OPTION_FROM_EMAIL, 'sender@example.com' );
		update_option( Lean_SMTP_Mailer::OPTION_FROM_NAME, 'Example Site' );

		$payload = $this->send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body text',
				'headers'     => '',
				'attachments' => [],
			]
		);

		$this->assertSame( '"Example Site" <sender@example.com>', $payload['from'] );
		$this->assertSame( [ 'rcpt@example.com' ], $payload['to'] );
		$this->assertSame( 'Hello', $payload['subject'] );
		$this->assertSame( 'Body text', $payload['text'] );
		$this->assertArrayNotHasKey( 'html', $payload );
	}

	public function test_html_message_maps_to_the_html_field() {
		$this->configure_resend();

		$payload = $this->send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => '<p>Rich body</p>',
				'headers'     => 'Content-Type: text/html',
				'attachments' => [],
			]
		);

		$this->assertSame( '<p>Rich body</p>', $payload['html'] );
	}

	public function test_html_with_a_plain_text_alternative_sends_both_bodies() {
		// When another plugin sets AltBody, PHPMailer's preSend() rewrites
		// ContentType to multipart/alternative. The HTML Body must still map to
		// Resend's `html` field (not be shipped as plain text) and the AltBody to
		// `text`.
		$this->configure_resend();

		add_action(
			'phpmailer_init',
			function ( $phpmailer ) {
				$phpmailer->AltBody = 'Plain alternative';
			}
		);

		$payload = $this->send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => '<p>Rich body</p>',
				'headers'     => 'Content-Type: text/html',
				'attachments' => [],
			]
		);

		$this->assertSame( '<p>Rich body</p>', $payload['html'] );
		$this->assertSame( 'Plain alternative', $payload['text'] );
	}

	public function test_inline_embeds_become_attachments_with_a_content_id() {
		// The sixth wp_mail() argument (embeds, WordPress 6.9+). Resend carries an
		// inline image as an attachment tagged with its content_id.
		$this->configure_resend();

		$image = wp_tempnam( 'lean-smtp-embed' );
		file_put_contents( $image, 'PNGDATA' );

		$payload = $this->send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => '<p>See <img src="cid:logo"></p>',
				'headers'     => 'Content-Type: text/html',
				'attachments' => [],
				'embeds'      => [ 'logo' => $image ],
			]
		);

		unlink( $image );

		$this->assertCount( 1, $payload['attachments'] );
		$this->assertSame( 'logo', $payload['attachments'][0]['content_id'] );
		$this->assertSame( 'PNGDATA', base64_decode( $payload['attachments'][0]['content'] ) );
	}

	public function test_recipients_and_headers_are_carried_over() {
		$this->configure_resend();

		$payload = $this->send(
			[
				'to'          => [ 'one@example.com', 'Two Person <two@example.com>' ],
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => "Cc: copy@example.com\nBcc: hidden@example.com\nReply-To: reply@example.com\nX-Custom: yes",
				'attachments' => [],
			]
		);

		$this->assertSame( [ 'one@example.com', '"Two Person" <two@example.com>' ], $payload['to'] );
		$this->assertSame( [ 'copy@example.com' ], $payload['cc'] );
		$this->assertSame( [ 'hidden@example.com' ], $payload['bcc'] );
		$this->assertSame( [ 'reply@example.com' ], $payload['reply_to'] );
		$this->assertSame( 'yes', $payload['headers']['X-Custom'] );
	}

	public function test_absent_fields_are_omitted_rather_than_sent_empty() {
		$this->configure_resend();

		$payload = $this->send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => '',
				'attachments' => [],
			]
		);

		$this->assertArrayNotHasKey( 'cc', $payload );
		$this->assertArrayNotHasKey( 'bcc', $payload );
		$this->assertArrayNotHasKey( 'attachments', $payload );
	}

	public function test_attachments_are_base64_encoded() {
		$this->configure_resend();

		$file = wp_tempnam( 'lean-smtp-attachment' );
		file_put_contents( $file, 'attachment contents' );

		$payload = $this->send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => '',
				'attachments' => [ $file ],
			]
		);

		unlink( $file );

		$this->assertCount( 1, $payload['attachments'] );
		$this->assertSame( 'attachment contents', base64_decode( $payload['attachments'][0]['content'] ) );
		$this->assertSame( basename( $file ), $payload['attachments'][0]['filename'] );
	}

	public function test_api_error_reports_failure() {
		$this->configure_resend();

		$captured = [];
		$this->capture_request( $captured, 422, '{"message":"The from address is not verified."}' );

		$error = null;
		add_action(
			'wp_mail_failed',
			function ( $wp_error ) use ( &$error ) {
				$error = $wp_error;
			}
		);

		$ok = $this->wp_mail_send(
			[
				'to'          => 'rcpt@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => '',
				'attachments' => [],
			]
		);

		$this->assertFalse( $ok );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertStringContainsString( 'The from address is not verified.', $error->get_error_message() );
	}
}
