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
}
