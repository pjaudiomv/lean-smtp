<?php
/**
 * Resend API transport.
 *
 * Resend has no raw-MIME endpoint, so this is the one provider that reads the
 * structured side of the assembled message — subject, body, recipients,
 * headers, attachments — and re-expresses it as JSON. Nothing is re-derived
 * from the original wp_mail() arguments; it all comes off the PHPMailer that
 * Lean_SMTP_Mailer built, so content type, charset and `phpmailer_init`
 * modifications are already accounted for.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Resend implements Lean_SMTP_Api_Transport {

	const OPTION_API_KEY = 'lean_smtp_resend_api_key';

	const ENDPOINT = 'https://api.resend.com/emails';

	public static function label(): string {
		return __( 'Resend', 'lean-smtp' );
	}

	public static function api_key(): string {
		return Lean_SMTP_Config::get_secret( self::OPTION_API_KEY );
	}

	public static function is_configured(): bool {
		return '' !== self::api_key();
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
			return new WP_Error( 'lean_smtp_resend_unconfigured', __( 'Resend is not configured (an API key is required).', 'lean-smtp' ) );
		}

		$payload = self::payload( $phpmailer );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . self::api_key(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
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

		/* translators: 1: HTTP status code, 2: Resend error message. */
		return new WP_Error( 'lean_smtp_resend_http_' . $code, sprintf( __( 'Resend rejected the message (HTTP %1$d): %2$s', 'lean-smtp' ), $code, $message ) );
	}

	/**
	 * Translate the assembled message into Resend's JSON shape.
	 *
	 * @return array|WP_Error
	 */
	private static function payload( PHPMailer\PHPMailer\PHPMailer $phpmailer ) {
		$to = self::addresses( $phpmailer->getToAddresses() );
		if ( empty( $to ) ) {
			return new WP_Error( 'lean_smtp_resend_no_recipients', __( 'The message has no recipients.', 'lean-smtp' ) );
		}

		$payload = [
			'from'    => self::format_address( $phpmailer->From, $phpmailer->FromName ),
			'to'      => $to,
			'subject' => (string) $phpmailer->Subject,
		];

		// PHPMailer keeps the HTML in Body and the plain-text alternative in
		// AltBody; for a text/plain message Body *is* the text.
		if ( 'text/html' === $phpmailer->ContentType ) {
			$payload['html'] = (string) $phpmailer->Body;
			if ( '' !== trim( (string) $phpmailer->AltBody ) ) {
				$payload['text'] = (string) $phpmailer->AltBody;
			}
		} else {
			$payload['text'] = (string) $phpmailer->Body;
		}

		foreach (
			[
				'cc'       => $phpmailer->getCcAddresses(),
				'bcc'      => $phpmailer->getBccAddresses(),
				'reply_to' => $phpmailer->getReplyToAddresses(),
			] as $field => $entries
		) {
			$addresses = self::addresses( $entries );
			if ( ! empty( $addresses ) ) {
				$payload[ $field ] = $addresses;
			}
		}

		$headers = [];
		foreach ( $phpmailer->getCustomHeaders() as $header ) {
			if ( isset( $header[0], $header[1] ) && '' !== $header[1] ) {
				$headers[ (string) $header[0] ] = (string) $header[1];
			}
		}
		if ( ! empty( $headers ) ) {
			$payload['headers'] = $headers;
		}

		$attachments = self::attachments( $phpmailer );
		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
		}

		return $payload;
	}

	/**
	 * PHPMailer address entries are [ address, name ] pairs; both getReplyTo
	 * and the recipient getters use the same shape (Reply-To is keyed by
	 * address, so values are taken rather than keys).
	 *
	 * @param array<int|string, array> $entries
	 * @return string[]
	 */
	private static function addresses( array $entries ): array {
		$formatted = [];
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry[0] ) || '' === $entry[0] ) {
				continue;
			}
			$formatted[] = self::format_address( (string) $entry[0], isset( $entry[1] ) ? (string) $entry[1] : '' );
		}
		return $formatted;
	}

	private static function format_address( string $address, string $name = '' ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return $address;
		}
		// Quote the display name and escape anything that would break the pair.
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $name ) . '" <' . $address . '>';
	}

	/**
	 * Attachments as base64. PHPMailer entries are
	 * [ path|data, filename, name, encoding, type, isString, disposition, cid ].
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function attachments( PHPMailer\PHPMailer\PHPMailer $phpmailer ): array {
		$attachments = [];

		foreach ( $phpmailer->getAttachments() as $entry ) {
			$is_string = ! empty( $entry[5] );
			$content   = '';

			if ( $is_string ) {
				$content = (string) $entry[0];
			} else {
				$path = (string) $entry[0];
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local attachment PHPMailer already resolved; WP_Filesystem is not initialised during mail.
				$read = is_readable( $path ) ? file_get_contents( $path ) : false;
				if ( false === $read ) {
					continue; // Unreadable attachment — send the message without it rather than failing outright.
				}
				$content = $read;
			}

			$attachment = [
				'filename' => '' !== (string) $entry[2] ? (string) $entry[2] : (string) $entry[1],
				'content'  => base64_encode( $content ),
			];

			// Inline images are referenced from the HTML by cid: — keep the link.
			if ( ! empty( $entry[7] ) && 'inline' === ( $entry[6] ?? '' ) ) {
				$attachment['content_id'] = (string) $entry[7];
			}

			$attachments[] = $attachment;
		}

		return $attachments;
	}
}
