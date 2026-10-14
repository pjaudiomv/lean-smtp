<?php
/**
 * SMTP delivery integration test.
 *
 * Unlike the API-transport tests, this one does not mock the HTTP layer: it
 * sends a real message over SMTP to a Mailpit sink and reads it back through
 * Mailpit's HTTP API, exercising the whole SMTP path end to end.
 *
 * It runs only when MAILPIT_SMTP_HOST and MAILPIT_URL are set and Mailpit is
 * reachable — which is the case under docker-compose.test.yml and in CI, and
 * skips cleanly on a plain local phpunit run.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_SMTP_Mailpit extends WP_UnitTestCase {

	private ?string $api = null;

	public function set_up() {
		parent::set_up();

		$this->api = $this->mailpit_api();
		if ( null === $this->api ) {
			$this->markTestSkipped( 'Mailpit is not available (set MAILPIT_SMTP_HOST and MAILPIT_URL; run via docker-compose.test.yml).' );
		}

		$this->mailpit_clear();
	}

	public function tear_down() {
		if ( null !== $this->api ) {
			$this->mailpit_clear();
		}
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * The Mailpit API base, once it answers, or null when it is not configured or
	 * not yet reachable (a short poll covers container start-up).
	 */
	private function mailpit_api(): ?string {
		$api  = (string) getenv( 'MAILPIT_URL' );
		$host = (string) getenv( 'MAILPIT_SMTP_HOST' );
		if ( '' === $api || '' === $host ) {
			return null;
		}
		$api = rtrim( $api, '/' );

		for ( $i = 0; $i < 20; $i++ ) {
			$response = wp_remote_get( $api . '/api/v1/info', [ 'timeout' => 2 ] );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return $api;
			}
			usleep( 500000 );
		}

		return null;
	}

	private function mailpit_clear(): void {
		wp_remote_request( $this->api . '/api/v1/messages', [ 'method' => 'DELETE', 'timeout' => 5 ] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function mailpit_messages(): array {
		$response = wp_remote_get( $this->api . '/api/v1/messages?limit=50', [ 'timeout' => 5 ] );
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && isset( $data['messages'] ) ? (array) $data['messages'] : [];
	}

	public function test_smtp_delivers_a_message_to_mailpit() {
		update_option( Lean_SMTP_Mailer::OPTION_MAILER, Lean_SMTP_Mailer::MAILER_SMTP );
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_HOST, (string) getenv( 'MAILPIT_SMTP_HOST' ) );
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_PORT, 1025 );
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, 'none' );
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_AUTH, '0' );

		// The WordPress test suite swaps in a MockPHPMailer that captures instead
		// of sending; null the global so wp_mail() builds a real PHPMailer and
		// actually talks SMTP to Mailpit.
		$GLOBALS['phpmailer'] = null;

		$subject = 'Lean SMTP Mailpit test ' . wp_generate_password( 8, false );
		$to      = 'rcpt@example.test';

		Lean_SMTP_Mailer::init();
		$sent = wp_mail( $to, $subject, 'Delivered through SMTP to Mailpit.' );
		$this->assertTrue( $sent, 'wp_mail() should report success delivering to Mailpit over SMTP.' );

		// Mailpit stores the message on DATA completion; poll briefly for it.
		$match = null;
		for ( $i = 0; $i < 20 && null === $match; $i++ ) {
			foreach ( $this->mailpit_messages() as $message ) {
				if ( ( $message['Subject'] ?? '' ) === $subject ) {
					$match = $message;
					break;
				}
			}
			if ( null === $match ) {
				usleep( 250000 );
			}
		}

		$this->assertNotNull( $match, 'The message should have been delivered to Mailpit.' );
		$addresses = array_column( (array) ( $match['To'] ?? [] ), 'Address' );
		$this->assertContains( $to, $addresses, 'Mailpit should have recorded the recipient.' );
	}
}
