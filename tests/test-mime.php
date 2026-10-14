<?php
/**
 * Tests for the shared MIME helper (Bcc stripping).
 *
 * The stripper is a protected trait method shared by the raw-MIME transports
 * (SES, Mailgun); it is exercised here through a ReflectionMethod against the
 * SES class, which uses the trait.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Mime extends WP_UnitTestCase {

	private function strip( string $raw ): string {
		$method = new ReflectionMethod( 'Lean_SMTP_SES', 'strip_bcc_header' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $raw );
	}

	public function test_crlf_message_has_its_bcc_header_removed() {
		$raw = "To: a@example.com\r\nBcc: hidden@example.com\r\nSubject: Hi\r\n\r\nBody mentioning Bcc: not-a-header\r\n";

		$out = $this->strip( $raw );

		$this->assertStringNotContainsString( 'Bcc: hidden@example.com', $out );
		$this->assertStringContainsString( 'To: a@example.com', $out );
		// A body line that merely looks like a header must survive untouched.
		$this->assertStringContainsString( 'Body mentioning Bcc: not-a-header', $out );
	}

	public function test_lf_message_has_its_bcc_header_removed() {
		// PHPMailer emits LF endings for the sendmail/qmail transports; the
		// stripper must still find the header block rather than leak the Bcc.
		$raw = "To: a@example.com\nBcc: hidden@example.com\nSubject: Hi\n\nBody line\n";

		$out = $this->strip( $raw );

		$this->assertStringNotContainsString( 'Bcc: hidden@example.com', $out );
		$this->assertStringContainsString( 'To: a@example.com', $out );
		// The body (after the blank line) is returned byte-for-byte.
		$this->assertStringContainsString( "\n\nBody line\n", $out );
	}

	public function test_folded_bcc_header_is_removed_in_full() {
		$raw = "To: a@example.com\r\nBcc: one@example.com,\r\n\ttwo@example.com\r\nSubject: Hi\r\n\r\nBody\r\n";

		$out = $this->strip( $raw );

		$this->assertStringNotContainsString( 'one@example.com', $out );
		$this->assertStringNotContainsString( 'two@example.com', $out );
		$this->assertStringContainsString( 'Subject: Hi', $out );
		$this->assertStringContainsString( 'To: a@example.com', $out );
	}

	public function test_message_without_a_header_block_is_returned_unchanged() {
		$raw = 'no header separator here';

		$this->assertSame( $raw, $this->strip( $raw ) );
	}
}
