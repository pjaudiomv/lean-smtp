<?php
/**
 * Amazon SES v2 API transport.
 *
 * Sends a fully-assembled MIME message via the SES `outbound-emails` endpoint,
 * authenticated with a hand-rolled AWS Signature Version 4 signer — no AWS SDK
 * dependency, which keeps the plugin to a single small package.
 *
 * The signing primitives (`signing_key`, `authorization_header`) are pure and
 * deterministic given an explicit timestamp, so they are verified in the unit
 * suite against AWS's own documented SigV4 example vectors.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_SES {

	const OPTION_REGION     = 'lean_smtp_ses_region';
	const OPTION_ACCESS_KEY = 'lean_smtp_ses_access_key';
	const OPTION_SECRET_KEY = 'lean_smtp_ses_secret_key';

	const ALGORITHM = 'AWS4-HMAC-SHA256';
	const SERVICE   = 'ses';

	// -------------------------------------------------------------------------
	// Signing (pure)
	// -------------------------------------------------------------------------

	/**
	 * Derive the AWS SigV4 signing key (raw bytes) for a given day/region/service.
	 */
	public static function signing_key( string $secret, string $date_stamp, string $region, string $service ): string {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Build the full `Authorization` header for a request.
	 *
	 * @param array<string, string> $headers Request headers to sign; keys are
	 *                                        lowercased and sorted internally.
	 *                                        Must include `host` and `x-amz-date`.
	 */
	public static function authorization_header(
		string $access_key,
		string $secret,
		string $region,
		string $service,
		string $method,
		string $canonical_uri,
		string $canonical_query,
		array $headers,
		string $payload,
		string $amz_date
	): string {
		$date_stamp = substr( $amz_date, 0, 8 );

		$normalized = [];
		foreach ( $headers as $name => $value ) {
			$normalized[ strtolower( $name ) ] = trim( preg_replace( '/\s+/', ' ', $value ) );
		}
		ksort( $normalized );

		$canonical_headers = '';
		foreach ( $normalized as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode( ';', array_keys( $normalized ) );

		$canonical_request = implode(
			"\n",
			[
				$method,
				$canonical_uri,
				$canonical_query,
				$canonical_headers,
				$signed_headers,
				hash( 'sha256', $payload ),
			]
		);

		$scope          = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = implode(
			"\n",
			[
				self::ALGORITHM,
				$amz_date,
				$scope,
				hash( 'sha256', $canonical_request ),
			]
		);

		$signature = hash_hmac( 'sha256', $string_to_sign, self::signing_key( $secret, $date_stamp, $region, $service ), false );

		return self::ALGORITHM
			. ' Credential=' . $access_key . '/' . $scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;
	}

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	public static function region(): string {
		return trim( (string) get_option( self::OPTION_REGION, '' ) );
	}

	public static function access_key(): string {
		return trim( (string) get_option( self::OPTION_ACCESS_KEY, '' ) );
	}

	public static function secret_key(): string {
		return Lean_SMTP_Crypto::decrypt( (string) get_option( self::OPTION_SECRET_KEY, '' ) );
	}

	public static function is_configured(): bool {
		return '' !== self::region() && '' !== self::access_key() && '' !== self::secret_key();
	}

	// -------------------------------------------------------------------------
	// Send
	// -------------------------------------------------------------------------

	/**
	 * Send a raw (already MIME-encoded) message through SES.
	 *
	 * @return true|WP_Error True on success, WP_Error describing the failure.
	 */
	public static function send_raw_email( string $raw_mime ) {
		$region = self::region();
		$access = self::access_key();
		$secret = self::secret_key();

		if ( '' === $region || '' === $access || '' === $secret ) {
			return new WP_Error( 'lean_smtp_ses_unconfigured', __( 'Amazon SES is not fully configured (region, access key, and secret key are required).', 'lean-smtp' ) );
		}

		$host     = 'email.' . $region . '.amazonaws.com';
		$uri      = '/v2/email/outbound-emails';
		$amz_date = gmdate( 'Ymd\THis\Z' );
		$payload  = wp_json_encode(
			[
				'Content' => [
					'Raw' => [ 'Data' => base64_encode( $raw_mime ) ],
				],
			]
		);

		// Sign only the required headers. Content-Type is sent but left unsigned:
		// HTTP transports may re-case or append a charset to it, which would
		// break a signature that covered it. SigV4 only requires host + date.
		$sign_headers = [
			'host'       => $host,
			'x-amz-date' => $amz_date,
		];

		$authorization = self::authorization_header(
			$access,
			$secret,
			$region,
			self::SERVICE,
			'POST',
			$uri,
			'',
			$sign_headers,
			$payload,
			$amz_date
		);

		$response = wp_remote_post(
			'https://' . $host . $uri,
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => $authorization,
					'X-Amz-Date'    => $amz_date,
					'Content-Type'  => 'application/json',
				],
				'body'    => $payload,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : wp_remote_retrieve_response_message( $response );

		/* translators: 1: HTTP status code, 2: SES error message. */
		return new WP_Error( 'lean_smtp_ses_http_' . $code, sprintf( __( 'SES rejected the message (HTTP %1$d): %2$s', 'lean-smtp' ), $code, $message ) );
	}
}
