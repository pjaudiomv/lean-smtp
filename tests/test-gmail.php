<?php
/**
 * Tests for the Gmail service-account JWT signer and configuration gate.
 *
 * The signer is pure and needs no network: a throwaway RSA keypair is generated
 * in-test, jwt_assertion() builds and signs the assertion, and the signature is
 * checked back with openssl_verify() against the matching public key.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Gmail extends WP_UnitTestCase {

	const CLIENT_EMAIL = 'svc@project.iam.gserviceaccount.com';
	const SENDER       = 'postmaster@example.com';
	const NOW          = 1700000000;

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

	/**
	 * Decode a base64url segment (no padding) back to raw bytes.
	 */
	private static function b64url_decode( string $value ): string {
		$pad = strlen( $value ) % 4;
		if ( 0 !== $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		return (string) base64_decode( strtr( $value, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own JWT segment in a test.
	}

	/**
	 * A fresh RSA keypair: [ private PEM, public PEM ].
	 *
	 * @return array{0:string,1:string}
	 */
	private static function keypair(): array {
		$resource = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		openssl_pkey_export( $resource, $private_key );
		$details = openssl_pkey_get_details( $resource );
		return [ $private_key, $details['key'] ];
	}

	public function test_base64url_is_url_safe_and_unpadded() {
		// A payload whose standard base64 contains +, / and = padding.
		$encoded = Lean_SMTP_Gmail::base64url( "\xfb\xff\xbf>>>" );

		$this->assertStringNotContainsString( '+', $encoded );
		$this->assertStringNotContainsString( '/', $encoded );
		$this->assertStringNotContainsString( '=', $encoded );
	}

	public function test_jwt_assertion_has_three_segments_and_correct_claims() {
		list( $private_key ) = self::keypair();

		$assertion = Lean_SMTP_Gmail::jwt_assertion( self::CLIENT_EMAIL, self::SENDER, $private_key, self::NOW );
		$this->assertIsString( $assertion );

		$parts = explode( '.', $assertion );
		$this->assertCount( 3, $parts );

		$header = json_decode( self::b64url_decode( $parts[0] ), true );
		$this->assertSame( 'RS256', $header['alg'] );
		$this->assertSame( 'JWT', $header['typ'] );

		$claims = json_decode( self::b64url_decode( $parts[1] ), true );
		$this->assertSame( self::CLIENT_EMAIL, $claims['iss'] );
		$this->assertSame( self::SENDER, $claims['sub'] );
		$this->assertSame( 'https://www.googleapis.com/auth/gmail.send', $claims['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
		$this->assertSame( self::NOW, $claims['iat'] );
		$this->assertSame( self::NOW + 3600, $claims['exp'] );
	}

	public function test_jwt_assertion_signature_verifies_with_public_key() {
		list( $private_key, $public_key ) = self::keypair();

		$assertion = Lean_SMTP_Gmail::jwt_assertion( self::CLIENT_EMAIL, self::SENDER, $private_key, self::NOW );
		$parts     = explode( '.', $assertion );

		$signing_input = $parts[0] . '.' . $parts[1];
		$signature     = self::b64url_decode( $parts[2] );

		$this->assertSame( 1, openssl_verify( $signing_input, $signature, $public_key, OPENSSL_ALGO_SHA256 ) );
	}

	public function test_jwt_assertion_errors_on_invalid_key() {
		$result = Lean_SMTP_Gmail::jwt_assertion( self::CLIENT_EMAIL, self::SENDER, 'not-a-key', self::NOW );

		$this->assertWPError( $result );
		$this->assertSame( 'lean_smtp_gmail_sign_failed', $result->get_error_code() );
	}

	public function test_is_configured_requires_sender_and_parseable_key() {
		list( $private_key ) = self::keypair();
		$valid_json          = (string) wp_json_encode(
			[
				'client_email' => self::CLIENT_EMAIL,
				'private_key'  => $private_key,
			]
		);

		// Nothing set.
		$this->assertFalse( Lean_SMTP_Gmail::is_configured() );

		// Key but no sender.
		update_option( Lean_SMTP_Gmail::OPTION_SERVICE_ACCOUNT, $valid_json );
		$this->assertFalse( Lean_SMTP_Gmail::is_configured() );

		// Sender but the key does not parse.
		update_option( Lean_SMTP_Gmail::OPTION_SERVICE_ACCOUNT, 'garbage' );
		update_option( Lean_SMTP_Gmail::OPTION_SENDER, self::SENDER );
		$this->assertFalse( Lean_SMTP_Gmail::is_configured() );

		// JSON missing the private key.
		update_option( Lean_SMTP_Gmail::OPTION_SERVICE_ACCOUNT, (string) wp_json_encode( [ 'client_email' => self::CLIENT_EMAIL ] ) );
		$this->assertFalse( Lean_SMTP_Gmail::is_configured() );

		// Both present and valid.
		update_option( Lean_SMTP_Gmail::OPTION_SERVICE_ACCOUNT, $valid_json );
		$this->assertTrue( Lean_SMTP_Gmail::is_configured() );

		$credentials = Lean_SMTP_Gmail::credentials();
		$this->assertIsArray( $credentials );
		// The escaped "\n" in the stored JSON round-trips back to a real PEM.
		$this->assertStringContainsString( "-----BEGIN PRIVATE KEY-----\n", $credentials['private_key'] );
	}

	/**
	 * Store a service-account key JSON for the shared client_email + sender.
	 */
	private function store_key( string $private_key ): void {
		update_option(
			Lean_SMTP_Gmail::OPTION_SERVICE_ACCOUNT,
			(string) wp_json_encode(
				[
					'client_email' => self::CLIENT_EMAIL,
					'private_key'  => $private_key,
				]
			)
		);
	}

	/**
	 * Select Gmail, store a fresh key for the shared account/sender, and return
	 * the private key (so a test can rebuild the token cache key from it).
	 */
	private function configure_gmail(): string {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_GMAIL );
		update_option( Lean_SMTP_Gmail::OPTION_SENDER, self::SENDER );
		list( $key ) = self::keypair();
		$this->store_key( $key );
		return $key;
	}

	private function cache_key( string $private_key ): string {
		return Lean_SMTP_Gmail::TOKEN_TRANSIENT . '_' . md5( self::CLIENT_EMAIL . '|' . self::SENDER . '|' . $private_key );
	}

	private function send_atts(): array {
		return [
			'to'          => 'rcpt@example.com',
			'subject'     => 'Hello',
			'message'     => 'Body',
			'headers'     => '',
			'attachments' => [],
		];
	}

	/**
	 * Mock the token endpoint and the send endpoint. Token responses come from
	 * $token (code + body); the send endpoint answers per $send. The optional
	 * counters record how often each endpoint was hit.
	 */
	private function mock_gmail( array $token, array $send, ?int &$token_hits = null, ?int &$send_hits = null ): void {
		$token_hits = 0;
		$send_hits  = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $token, $send, &$token_hits, &$send_hits ) {
				if ( Lean_SMTP_Gmail::TOKEN_URI === $url ) {
					++$token_hits;
					return [
						'response' => [
							'code'    => $token['code'],
							'message' => $token['message'] ?? '',
						],
						'body'     => $token['body'],
					];
				}
				++$send_hits;
				return [
					'response' => [
						'code'    => $send['code'],
						'message' => $send['message'] ?? '',
					],
					'body'     => $send['body'],
				];
			},
			10,
			3
		);
	}

	public function test_token_is_cached_with_the_server_lifetime_minus_the_safety_margin() {
		$key = $this->configure_gmail();
		$this->mock_gmail(
			[
				'code' => 200,
				'body' => '{"access_token":"tok","expires_in":3600}',
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			]
		);

		$this->assertTrue( $this->wp_mail_send( $this->send_atts() ) );

		$cache_key = $this->cache_key( $key );
		$this->assertSame( 'tok', get_transient( $cache_key ) );

		// The token is kept for the server-stated lifetime less the safety margin,
		// so it is never handed out in its final seconds.
		$ttl = (int) get_option( '_transient_timeout_' . $cache_key ) - time();
		$this->assertGreaterThan( 3600 - Lean_SMTP_Gmail::TOKEN_MARGIN - 30, $ttl );
		$this->assertLessThanOrEqual( 3600 - Lean_SMTP_Gmail::TOKEN_MARGIN, $ttl );
	}

	public function test_an_expired_cached_token_triggers_a_fresh_exchange() {
		$key = $this->configure_gmail();
		$this->mock_gmail(
			[
				'code' => 200,
				'body' => '{"access_token":"tok","expires_in":3600}',
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			],
			$token_hits
		);

		$this->assertTrue( $this->wp_mail_send( $this->send_atts() ) );
		$this->assertSame( 1, $token_hits );

		// Simulate the cached token having expired: the next send must mint a new
		// one rather than reuse a token that is no longer valid.
		delete_transient( $this->cache_key( $key ) );

		$this->assertTrue( $this->wp_mail_send( $this->send_atts() ) );
		$this->assertSame( 2, $token_hits );
	}

	public function test_a_rejected_token_request_fails_without_posting_to_gmail() {
		$this->configure_gmail();
		$this->mock_gmail(
			[
				'code'    => 400,
				'message' => 'Bad Request',
				'body'    => '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}',
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			],
			$token_hits,
			$send_hits
		);

		$error = null;
		add_action(
			'wp_mail_failed',
			function ( $wp_error ) use ( &$error ) {
				$error = $wp_error;
			}
		);

		$ok = $this->wp_mail_send( $this->send_atts() );

		$this->assertFalse( $ok );
		$this->assertSame( 0, $send_hits, 'A failed token exchange must never reach the Gmail send endpoint.' );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertStringContainsString( 'Invalid JWT Signature.', $error->get_error_message() );
	}

	public function test_a_rejected_send_reports_the_gmail_error() {
		$this->configure_gmail();
		$this->mock_gmail(
			[
				'code' => 200,
				'body' => '{"access_token":"tok","expires_in":3600}',
			],
			[
				'code'    => 403,
				'message' => 'Forbidden',
				'body'    => '{"error":{"message":"Delegation denied for postmaster@example.com"}}',
			]
		);

		$error = null;
		add_action(
			'wp_mail_failed',
			function ( $wp_error ) use ( &$error ) {
				$error = $wp_error;
			}
		);

		$ok = $this->wp_mail_send( $this->send_atts() );

		$this->assertFalse( $ok );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertStringContainsString( 'Delegation denied', $error->get_error_message() );
	}

	public function test_rotating_the_private_key_forces_a_fresh_token_exchange() {
		// The token cache must be keyed to the private key, not just the account
		// and sender — otherwise rotating the key keeps using the token minted for
		// the old one, and a post-rotation test would "succeed" without ever
		// proving the replacement key authenticates.
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_GMAIL );
		update_option( Lean_SMTP_Gmail::OPTION_SENDER, self::SENDER );

		$token_exchanges = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$token_exchanges ) {
				if ( Lean_SMTP_Gmail::TOKEN_URI === $url ) {
					++$token_exchanges;
					return [
						'response' => [
							'code'    => 200,
							'message' => 'OK',
						],
						'body'     => '{"access_token":"tok","expires_in":3600}',
					];
				}
				// The Gmail send endpoint.
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{"id":"m1"}',
				];
			},
			10,
			3
		);

		$atts = [
			'to'          => 'rcpt@example.com',
			'subject'     => 'Hello',
			'message'     => 'Body',
			'headers'     => '',
			'attachments' => [],
		];

		list( $key_a ) = self::keypair();
		$this->store_key( $key_a );
		$this->assertTrue( $this->wp_mail_send( $atts ) );
		$this->assertTrue( $this->wp_mail_send( $atts ) );
		$this->assertSame( 1, $token_exchanges, 'A second send with the same key reuses the cached token.' );

		// Rotate to a different key for the same account and sender.
		list( $key_b ) = self::keypair();
		$this->store_key( $key_b );
		$this->assertTrue( $this->wp_mail_send( $atts ) );
		$this->assertSame( 2, $token_exchanges, 'A rotated key must not reuse the token cached for the old key.' );
	}
}
