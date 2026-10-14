<?php
/**
 * Shared Gmail send path.
 *
 * Both Gmail transports — the service account (`Lean_SMTP_Gmail`) and the OAuth
 * authorization-code flow (`Lean_SMTP_Gmail_OAuth`) — differ only in how they
 * obtain an access token. Everything downstream of that token is identical:
 * the same `users.messages.send` endpoint, the same base64url raw-MIME body,
 * the same error mapping, and the same deliberate decision to leave the `Bcc`
 * header in place (Gmail consumes it like a real mail client; `messages.send`
 * carries no separate envelope, so stripping it would drop those recipients).
 *
 * That shared path lives here so it cannot drift between the two transports.
 * The two Google endpoint constants it leans on (`SEND_ENDPOINT`, and the
 * sender accessor `sender()`) are declared by each using class — PHP 8.1, which
 * the plugin targets, does not allow constants in a trait.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Lean_SMTP_Gmail_Send {

	/**
	 * URL-safe base64 without padding, per the JWT / base64url spec. Gmail's
	 * raw-message field wants the same encoding.
	 */
	public static function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * POST the raw MIME to the Gmail send endpoint for the sending mailbox.
	 *
	 * The using class supplies `SEND_ENDPOINT` (a printf template taking the
	 * URL-encoded sender) and `sender()`.
	 *
	 * @param string $token    A valid Gmail access token.
	 * @param string $raw_mime The assembled message, raw MIME.
	 * @return string|WP_Error The Gmail message id (may be '') on success, or a WP_Error.
	 */
	private static function send_message( string $token, string $raw_mime ) {
		$endpoint = sprintf( self::SEND_ENDPOINT, rawurlencode( self::sender() ) );

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'raw' => self::base64url( $raw_mime ) ] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) && ! empty( $body['id'] ) ? (string) $body['id'] : '';
		}

		$message = is_array( $body ) && isset( $body['error']['message'] ) && '' !== $body['error']['message']
			? (string) $body['error']['message']
			: wp_remote_retrieve_response_message( $response );

		/* translators: 1: HTTP status code, 2: Gmail error message. */
		return new WP_Error( 'lean_smtp_gmail_http_' . $code, sprintf( __( 'Gmail rejected the message (HTTP %1$d): %2$s', 'lean-smtp' ), $code, $message ) );
	}
}
