<?php
/**
 * Tests for the SES AWS Signature V4 signer.
 *
 * Verified against the official AWS `aws-sig-v4-test-suite` "get-vanilla"
 * vector (access key AKIDEXAMPLE, region us-east-1, service "service",
 * timestamp 20150830T123600Z), whose expected signature is published by AWS.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_SES extends WP_UnitTestCase {

	const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	public function test_authorization_header_matches_aws_get_vanilla_vector() {
		$auth = Lean_SMTP_SES::authorization_header(
			'AKIDEXAMPLE',
			self::SECRET,
			'us-east-1',
			'service',
			'GET',
			'/',
			'',
			[
				'host'       => 'example.amazonaws.com',
				'x-amz-date' => '20150830T123600Z',
			],
			'',
			'20150830T123600Z'
		);

		$expected = 'AWS4-HMAC-SHA256 '
			. 'Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
			. 'SignedHeaders=host;x-amz-date, '
			. 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31';

		$this->assertSame( $expected, $auth );
	}

	public function test_signing_key_is_deterministic() {
		$key = Lean_SMTP_SES::signing_key( self::SECRET, '20150830', 'us-east-1', 'service' );

		$this->assertSame( 32, strlen( $key ), 'Signing key is a 32-byte HMAC-SHA256 output.' );
		$this->assertSame(
			'938127b5336810ddb6a5d6af445fcac9e371f9ed418ed386b022aed82901be75',
			bin2hex( $key )
		);
	}

	public function test_header_order_and_whitespace_are_normalized() {
		// Passing headers out of order and with padded values must produce the
		// same signature as the canonical form.
		$auth = Lean_SMTP_SES::authorization_header(
			'AKIDEXAMPLE',
			self::SECRET,
			'us-east-1',
			'service',
			'GET',
			'/',
			'',
			[
				'x-amz-date' => '20150830T123600Z',
				'Host'       => '  example.amazonaws.com  ',
			],
			'',
			'20150830T123600Z'
		);

		$this->assertStringContainsString(
			'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			$auth
		);
	}
}
