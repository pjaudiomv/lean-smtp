<?php
/**
 * Contract for the HTTP-API transports (SES, Mailgun, Resend).
 *
 * Lean_SMTP_Mailer::send_via_api() owns all of the message construction — it
 * short-circuits wp_mail() via `pre_wp_mail`, mirrors core's header/recipient
 * handling, and hands over a PHPMailer instance on which `preSend()` has
 * already run. That instance is both forms a provider might want:
 *
 *   - `getSentMIMEMessage()` for the providers that accept raw MIME
 *     (SES `outbound-emails`, Mailgun `/messages.mime`);
 *   - the structured `Subject` / `Body` / `getToAddresses()` accessors for the
 *     providers that want JSON (Resend).
 *
 * So an implementation is only ever auth plus body shape — no provider
 * re-derives recipients or content type, and there is exactly one place where
 * core's wp_mail() semantics are reproduced.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Lean_SMTP_Api_Transport {

	/**
	 * Human-readable name for the settings page and error messages.
	 */
	public static function label(): string;

	/**
	 * Whether every credential this transport needs is present.
	 */
	public static function is_configured(): bool;

	/**
	 * Deliver an assembled message.
	 *
	 * On success return the provider's message id — the identifier the API hands
	 * back for the accepted message (SES `MessageId`, the `id` the others return)
	 * — or '' when the provider gives none. The mailer treats any non-WP_Error
	 * return as success and records the id in the send log.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Fully populated, `preSend()` already called.
	 * @return string|WP_Error The provider's message id (may be '') when accepted.
	 */
	public static function send( PHPMailer\PHPMailer\PHPMailer $phpmailer );
}
