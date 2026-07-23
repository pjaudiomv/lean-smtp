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

class Lean_SMTP_SES implements Lean_SMTP_Api_Transport {

	use Lean_SMTP_Mime;

	const OPTION_REGION     = 'lean_smtp_ses_region';
	const OPTION_ACCESS_KEY = 'lean_smtp_ses_access_key';
	const OPTION_SECRET_KEY = 'lean_smtp_ses_secret_key';

	const ALGORITHM = 'AWS4-HMAC-SHA256';
	const SERVICE   = 'ses';

	public static function label(): string {
		return __( 'Amazon SES', 'lean-smtp' );
	}

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
		return Lean_SMTP_Config::get_string( self::OPTION_REGION );
	}

	public static function access_key(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_ACCESS_KEY );
	}

	public static function secret_key(): string {
		return Lean_SMTP_Config::get_secret( self::OPTION_SECRET_KEY );
	}

	public static function is_configured(): bool {
		return '' !== self::region() && '' !== self::access_key() && '' !== self::secret_key();
	}

	// -------------------------------------------------------------------------
	// Send
	// -------------------------------------------------------------------------

	/**
	 * SES takes the message as raw MIME, and normally derives recipients from
	 * its headers — so a plain message needs no translation, just its bytes.
	 *
	 * A Bcc is the exception: its header can't stay (it would disclose the
	 * hidden recipients to everyone), but dropping it would also drop those
	 * recipients, since headers are all SES has to go on. So *only* when a Bcc
	 * is present do we strip the header and hand SES an explicit Destination
	 * instead — leaving every other send on the unchanged, header-derived path.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Assembled, `preSend()` already called.
	 * @return true|WP_Error
	 */
	public static function send( PHPMailer\PHPMailer\PHPMailer $phpmailer ) {
		$bcc = self::addresses( $phpmailer->getBccAddresses() );
		if ( empty( $bcc ) ) {
			return self::send_raw_email( $phpmailer->getSentMIMEMessage() );
		}

		$destination = array_filter(
			[
				'ToAddresses'  => self::addresses( $phpmailer->getToAddresses() ),
				'CcAddresses'  => self::addresses( $phpmailer->getCcAddresses() ),
				'BccAddresses' => $bcc,
			]
		);

		return self::send_raw_email( self::strip_bcc_header( $phpmailer->getSentMIMEMessage() ), $destination );
	}

	/**
	 * Flatten PHPMailer's [ address, name ] recipient entries to bare addresses
	 * for a SES Destination. The friendly name still rides along in the MIME
	 * headers; the envelope only needs the address.
	 *
	 * @param array<int, array> $entries
	 * @return string[]
	 */
	private static function addresses( array $entries ): array {
		$addresses = [];
		foreach ( $entries as $entry ) {
			if ( isset( $entry[0] ) && '' !== $entry[0] ) {
				$addresses[] = (string) $entry[0];
			}
		}
		return $addresses;
	}

	/**
	 * Send a raw (already MIME-encoded) message through SES.
	 *
	 * @param string   $raw_mime    The assembled message.
	 * @param array    $destination Optional SES Destination (To/Cc/BccAddresses).
	 *                              When given, SES uses it for the envelope
	 *                              instead of reading the message headers.
	 * @return true|WP_Error True on success, WP_Error describing the failure.
	 */
	public static function send_raw_email( string $raw_mime, array $destination = [] ) {
		$region = self::region();
		$access = self::access_key();
		$secret = self::secret_key();

		if ( '' === $region || '' === $access || '' === $secret ) {
			return new WP_Error( 'lean_smtp_ses_unconfigured', __( 'Amazon SES is not fully configured (region, access key, and secret key are required).', 'lean-smtp' ) );
		}

		$request = [
			'Content' => [
				'Raw' => [ 'Data' => base64_encode( $raw_mime ) ],
			],
		];
		if ( ! empty( $destination ) ) {
			$request['Destination'] = $destination;
		}

		$host     = 'email.' . $region . '.amazonaws.com';
		$uri      = '/v2/email/outbound-emails';
		$amz_date = gmdate( 'Ymd\THis\Z' );
		$payload  = wp_json_encode( $request );

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
