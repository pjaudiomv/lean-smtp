<?php
/**
 * Tests for the Gmail OAuth2 (Workspace-Internal) transport.
 *
 * The pure pieces — the consent URL builder and the id_token email reader — are
 * checked without a network. The refresh-token exchange and the send path are
 * exercised through the real wp_mail() pipeline with the HTTP layer mocked, the
 * same way the service-account transport is tested.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Gmail_OAuth extends WP_UnitTestCase {

	const CLIENT_ID     = '1234567890-abc.apps.googleusercontent.com';
	const CLIENT_SECRET = 'GOCSPX-secret';
	const REFRESH_TOKEN = '1//refresh-token';
	const SENDER        = 'postmaster@example.com';

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Base64url-encode (no padding), to build a fake id_token payload.
	 */
	private static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * A minimal JWT carrying the given claims as its payload. The signature is a
	 * placeholder — the transport never verifies it, it only reads the payload.
	 *
	 * @param array<string, mixed> $claims
	 */
	private static function id_token( array $claims ): string {
		return self::b64url( '{"alg":"RS256"}' ) . '.' . self::b64url( (string) wp_json_encode( $claims ) ) . '.sig';
	}

	/**
	 * Select the OAuth mailer and store a complete, connected configuration.
	 */
	private function configure(): void {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_GMAIL_OAUTH );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_ID, self::CLIENT_ID );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_SECRET, self::CLIENT_SECRET );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_REFRESH_TOKEN, self::REFRESH_TOKEN );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_SENDER, self::SENDER );
	}

	private function cache_key(): string {
		return Lean_SMTP_Gmail_OAuth::TOKEN_TRANSIENT . '_' . md5( self::CLIENT_ID . '|' . self::SENDER . '|' . self::REFRESH_TOKEN );
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

	private function wp_mail_send( array $atts ) {
		Lean_SMTP_Mailer::init();
		return wp_mail(
			$atts['to'] ?? '',
			(string) ( $atts['subject'] ?? '' ),
			$atts['message'] ?? '',
			$atts['headers'] ?? '',
			$atts['attachments'] ?? []
		);
	}

	/**
	 * Mock the token endpoint (refresh / code exchange) and the send endpoint.
	 */
	private function mock( array $token, array $send, ?int &$token_hits = null, ?int &$send_hits = null ): void {
		$token_hits = 0;
		$send_hits  = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $token, $send, &$token_hits, &$send_hits ) {
				if ( Lean_SMTP_Gmail_OAuth::TOKEN_URI === $url ) {
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

	// -------------------------------------------------------------------------
	// Pure: authorize_url
	// -------------------------------------------------------------------------

	public function test_authorize_url_carries_the_offline_consent_parameters() {
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_ID, self::CLIENT_ID );

		$url = Lean_SMTP_Gmail_OAuth::authorize_url( 'https://site.example/wp-admin/admin-post.php?action=cb', 'state-123' );

		$this->assertStringStartsWith( Lean_SMTP_Gmail_OAuth::AUTH_URI . '?', $url );
		$this->assertStringContainsString( 'response_type=code', $url );
		$this->assertStringContainsString( 'access_type=offline', $url );
		$this->assertStringContainsString( 'prompt=consent', $url );
		$this->assertStringContainsString( 'state=state-123', $url );
		$this->assertStringContainsString( 'client_id=' . rawurlencode( self::CLIENT_ID ), $url );
		// Scope is space-delimited and RFC3986-encoded (spaces as %20, not +).
		$this->assertStringContainsString( 'scope=openid%20email%20', $url );
		$this->assertStringNotContainsString( 'scope=openid+email', $url );
		// The redirect URI is encoded whole, not merged into the outer query.
		$this->assertStringContainsString( 'redirect_uri=' . rawurlencode( 'https://site.example/wp-admin/admin-post.php?action=cb' ), $url );
	}

	// -------------------------------------------------------------------------
	// Pure: email_from_id_token
	// -------------------------------------------------------------------------

	public function test_email_from_id_token_reads_the_email_claim() {
		$token = self::id_token(
			[
				'email'          => self::SENDER,
				'email_verified' => true,
			]
		);
		$this->assertSame( self::SENDER, Lean_SMTP_Gmail_OAuth::email_from_id_token( $token ) );
	}

	public function test_email_from_id_token_returns_empty_on_garbage() {
		$this->assertSame( '', Lean_SMTP_Gmail_OAuth::email_from_id_token( 'not-a-jwt' ) );
		$this->assertSame( '', Lean_SMTP_Gmail_OAuth::email_from_id_token( self::b64url( 'x' ) . '.' . self::b64url( '{"sub":"no-email"}' ) . '.sig' ) );
	}

	// -------------------------------------------------------------------------
	// Configuration gate
	// -------------------------------------------------------------------------

	public function test_is_configured_requires_client_and_connection() {
		$this->assertFalse( Lean_SMTP_Gmail_OAuth::is_configured() );

		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_ID, self::CLIENT_ID );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_SECRET, self::CLIENT_SECRET );
		// Client credentials alone — not yet connected.
		$this->assertFalse( Lean_SMTP_Gmail_OAuth::is_configured() );
		$this->assertFalse( Lean_SMTP_Gmail_OAuth::is_connected() );

		update_option( Lean_SMTP_Gmail_OAuth::OPTION_REFRESH_TOKEN, self::REFRESH_TOKEN );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_SENDER, self::SENDER );
		$this->assertTrue( Lean_SMTP_Gmail_OAuth::is_connected() );
		$this->assertTrue( Lean_SMTP_Gmail_OAuth::is_configured() );
	}

	// -------------------------------------------------------------------------
	// Send path: refresh-token grant
	// -------------------------------------------------------------------------

	public function test_a_send_refreshes_an_access_token_and_caches_it() {
		$this->configure();
		$this->mock(
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
		$this->assertSame( 'tok', get_transient( $this->cache_key() ) );

		$ttl = (int) get_option( '_transient_timeout_' . $this->cache_key() ) - time();
		$this->assertLessThanOrEqual( 3600 - Lean_SMTP_Gmail_OAuth::TOKEN_MARGIN, $ttl );
		$this->assertGreaterThan( 3600 - Lean_SMTP_Gmail_OAuth::TOKEN_MARGIN - 30, $ttl );
	}

	public function test_a_cached_token_is_reused_without_refreshing() {
		$this->configure();
		set_transient( $this->cache_key(), 'cached-tok', HOUR_IN_SECONDS );
		$this->mock(
			[
				'code' => 200,
				'body' => '{"access_token":"fresh","expires_in":3600}',
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			],
			$token_hits
		);

		$this->assertTrue( $this->wp_mail_send( $this->send_atts() ) );
		$this->assertSame( 0, $token_hits, 'A valid cached token must not trigger a refresh.' );
	}

	public function test_an_invalid_grant_clears_the_stored_token_and_never_sends() {
		$this->configure();
		$this->mock(
			[
				'code'    => 400,
				'message' => 'Bad Request',
				'body'    => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}',
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

		$this->assertFalse( $this->wp_mail_send( $this->send_atts() ) );
		$this->assertSame( 0, $send_hits, 'A dead grant must never reach the Gmail send endpoint.' );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 'wp_mail_failed', $error->get_error_code() );

		// The refresh token is dropped so the UI falls back to "Connect".
		$this->assertSame( '', (string) get_option( Lean_SMTP_Gmail_OAuth::OPTION_REFRESH_TOKEN ) );
		$this->assertFalse( Lean_SMTP_Gmail_OAuth::is_connected() );
	}

	public function test_a_rejected_send_reports_the_gmail_error() {
		$this->configure();
		$this->mock(
			[
				'code' => 200,
				'body' => '{"access_token":"tok","expires_in":3600}',
			],
			[
				'code'    => 403,
				'message' => 'Forbidden',
				'body'    => '{"error":{"message":"Delegation denied"}}',
			]
		);

		$error = null;
		add_action(
			'wp_mail_failed',
			function ( $wp_error ) use ( &$error ) {
				$error = $wp_error;
			}
		);

		$this->assertFalse( $this->wp_mail_send( $this->send_atts() ) );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertStringContainsString( 'Delegation denied', $error->get_error_message() );
	}

	// -------------------------------------------------------------------------
	// Consent completion
	// -------------------------------------------------------------------------

	public function test_complete_authorization_stores_the_refresh_token_and_sender() {
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_ID, self::CLIENT_ID );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_SECRET, self::CLIENT_SECRET );

		$id_token = self::id_token( [ 'email' => self::SENDER ] );
		$this->mock(
			[
				'code' => 200,
				'body' => (string) wp_json_encode(
					[
						'access_token'  => 'tok',
						'refresh_token' => self::REFRESH_TOKEN,
						'id_token'      => $id_token,
						'expires_in'    => 3600,
					]
				),
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			]
		);

		$result = Lean_SMTP_Gmail_OAuth::complete_authorization( 'auth-code', 'https://site.example/cb' );

		$this->assertTrue( $result );
		$this->assertSame( self::REFRESH_TOKEN, Lean_SMTP_Gmail_OAuth::refresh_token() );
		$this->assertSame( self::SENDER, Lean_SMTP_Gmail_OAuth::sender() );
		$this->assertTrue( Lean_SMTP_Gmail_OAuth::is_connected() );
	}

	public function test_complete_authorization_without_an_email_claim_is_an_error() {
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_ID, self::CLIENT_ID );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_SECRET, self::CLIENT_SECRET );

		$this->mock(
			[
				'code' => 200,
				'body' => (string) wp_json_encode(
					[
						'access_token'  => 'tok',
						'refresh_token' => self::REFRESH_TOKEN,
						'id_token'      => self::id_token( [ 'sub' => 'no-email' ] ),
					]
				),
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			]
		);

		$result = Lean_SMTP_Gmail_OAuth::complete_authorization( 'auth-code', 'https://site.example/cb' );

		$this->assertWPError( $result );
		$this->assertSame( 'lean_smtp_gmail_oauth_no_email', $result->get_error_code() );
		$this->assertSame( '', (string) get_option( Lean_SMTP_Gmail_OAuth::OPTION_REFRESH_TOKEN ) );
	}

	public function test_complete_authorization_reports_a_google_error() {
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_ID, self::CLIENT_ID );
		update_option( Lean_SMTP_Gmail_OAuth::OPTION_CLIENT_SECRET, self::CLIENT_SECRET );

		$this->mock(
			[
				'code'    => 400,
				'message' => 'Bad Request',
				'body'    => '{"error":"invalid_grant","error_description":"Malformed auth code."}',
			],
			[
				'code' => 200,
				'body' => '{"id":"m1"}',
			]
		);

		$result = Lean_SMTP_Gmail_OAuth::complete_authorization( 'bad-code', 'https://site.example/cb' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Malformed auth code.', $result->get_error_message() );
	}
}
