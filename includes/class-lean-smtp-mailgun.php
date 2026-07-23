<?php
/**
 * Mailgun API transport.
 *
 * Mailgun's `/messages.mime` endpoint accepts a complete RFC 822 message, so
 * this transport reuses the MIME that Lean_SMTP_Mailer already assembled and
 * adds nothing but authentication — attachments, alternative parts, custom
 * headers and encoding are whatever PHPMailer produced.
 *
 * The endpoint does need the envelope recipients as separate form fields:
 * the MIME headers alone don't carry Bcc, so they are passed explicitly.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Mailgun implements Lean_SMTP_Api_Transport {

	use Lean_SMTP_Mime;

	const OPTION_DOMAIN  = 'lean_smtp_mailgun_domain';
	const OPTION_API_KEY = 'lean_smtp_mailgun_api_key';
	const OPTION_REGION  = 'lean_smtp_mailgun_region';

	const REGION_US = 'us';
	const REGION_EU = 'eu';

	public static function label(): string {
		return __( 'Mailgun', 'lean-smtp' );
	}

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	public static function domain(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_DOMAIN );
	}

	public static function api_key(): string {
		return Lean_SMTP_Config::get_secret( self::OPTION_API_KEY );
	}

	public static function region(): string {
		return self::REGION_EU === Lean_SMTP_Config::get_string( self::OPTION_REGION, self::REGION_US )
			? self::REGION_EU
			: self::REGION_US;
	}

	/**
	 * Mailgun runs separate US and EU stacks; a key issued in one is not valid
	 * against the other, so the region picks the host rather than a parameter.
	 */
	public static function api_base(): string {
		return self::REGION_EU === self::region() ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
	}

	public static function is_configured(): bool {
		return '' !== self::domain() && '' !== self::api_key();
	}

	// -------------------------------------------------------------------------
	// Send
	// -------------------------------------------------------------------------

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Assembled, `preSend()` already called.
	 * @return true|WP_Error
	 */
	public static function send( PHPMailer\PHPMailer\PHPMailer $phpmailer ) {
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'lean_smtp_mailgun_unconfigured',
				__( 'Mailgun is not fully configured (sending domain and API key are required).', 'lean-smtp' )
			);
		}

		$recipients = self::envelope_recipients( $phpmailer );
		if ( empty( $recipients ) ) {
			return new WP_Error( 'lean_smtp_mailgun_no_recipients', __( 'The message has no recipients.', 'lean-smtp' ) );
		}

		$boundary = 'lsmtp' . bin2hex( random_bytes( 12 ) );
		$body     = self::multipart_body( $boundary, $recipients, self::strip_bcc_header( $phpmailer->getSentMIMEMessage() ) );

		$response = wp_remote_post(
			self::api_base() . '/v3/' . rawurlencode( self::domain() ) . '/messages.mime',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( 'api:' . self::api_key() ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $decoded ) && ! empty( $decoded['message'] )
			? (string) $decoded['message']
			: wp_remote_retrieve_response_message( $response );

		/* translators: 1: HTTP status code, 2: Mailgun error message. */
		return new WP_Error( 'lean_smtp_mailgun_http_' . $code, sprintf( __( 'Mailgun rejected the message (HTTP %1$d): %2$s', 'lean-smtp' ), $code, $message ) );
	}

	/**
	 * Every address the message should actually be delivered to. Bcc is only
	 * ever an envelope recipient — it deliberately isn't in the MIME headers —
	 * so it has to be listed here or those copies are silently dropped.
	 *
	 * @return string[]
	 */
	private static function envelope_recipients( PHPMailer\PHPMailer\PHPMailer $phpmailer ): array {
		$addresses = [];
		foreach ( array_merge( $phpmailer->getToAddresses(), $phpmailer->getCcAddresses(), $phpmailer->getBccAddresses() ) as $entry ) {
			if ( isset( $entry[0] ) && '' !== $entry[0] ) {
				$addresses[] = (string) $entry[0];
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Hand-build the multipart/form-data payload — wp_remote_post() only
	 * url-encodes an array body, and the MIME message has to travel as a file
	 * part with its bytes untouched.
	 *
	 * @param string[] $recipients
	 */
	private static function multipart_body( string $boundary, array $recipients, string $raw_mime ): string {
		$body = '';

		foreach ( $recipients as $recipient ) {
			$body .= '--' . $boundary . "\r\n"
				. "Content-Disposition: form-data; name=\"to\"\r\n\r\n"
				. $recipient . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n"
			. "Content-Disposition: form-data; name=\"message\"; filename=\"message.mime\"\r\n"
			. "Content-Type: message/rfc822\r\n\r\n"
			. $raw_mime . "\r\n";

		return $body . '--' . $boundary . "--\r\n";
	}
}
