<?php
/**
 * Tests for at-rest secret encryption.
 *
 * @package simple-smtp
 */

class Test_Simple_SMTP_Crypto extends WP_UnitTestCase {

	public function test_round_trip() {
		$secret    = 'super-secret-password-123!';
		$encrypted = Simple_SMTP_Crypto::encrypt( $secret );

		$this->assertNotSame( $secret, $encrypted, 'Ciphertext should not equal plaintext.' );
		$this->assertTrue( Simple_SMTP_Crypto::is_encrypted( $encrypted ) );
		$this->assertSame( $secret, Simple_SMTP_Crypto::decrypt( $encrypted ) );
	}

	public function test_empty_stays_empty() {
		$this->assertSame( '', Simple_SMTP_Crypto::encrypt( '' ) );
		$this->assertSame( '', Simple_SMTP_Crypto::decrypt( '' ) );
	}

	public function test_plaintext_passthrough_on_decrypt() {
		// A legacy / unencrypted value (no prefix) must decrypt to itself so
		// upgrades never lose an existing password.
		$this->assertSame( 'legacy-plain', Simple_SMTP_Crypto::decrypt( 'legacy-plain' ) );
	}

	public function test_iv_is_randomized() {
		$secret = 'the-same-input';
		$a      = Simple_SMTP_Crypto::encrypt( $secret );
		$b      = Simple_SMTP_Crypto::encrypt( $secret );

		if ( Simple_SMTP_Crypto::available() ) {
			$this->assertNotSame( $a, $b, 'Each encryption should use a fresh IV.' );
		}
		$this->assertSame( $secret, Simple_SMTP_Crypto::decrypt( $a ) );
		$this->assertSame( $secret, Simple_SMTP_Crypto::decrypt( $b ) );
	}
}
