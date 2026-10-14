<?php
/**
 * Gmail / Google Workspace API transport (service-account auth).
 *
 * Sends a fully-assembled MIME message through the Gmail API
 * `users.messages.send` endpoint, authenticated as a Google Cloud *service
 * account* with domain-wide delegation — no OAuth consent screen, no refresh
 * token, no bundled Google SDK. Authentication is a short-lived JWT the plugin
 * signs with the service account's private key (RS256) and exchanges for an
 * access token, mirroring the hand-rolled signing the SES transport does for
 * AWS Signature V4.
 *
 * Workspace only: the impersonated sender must be a real mailbox in a Google
 * Workspace domain whose Super Admin has authorized the service account's
 * client ID for the gmail.send scope in the Admin Console. It does nothing for
 * consumer @gmail.com accounts. The sibling Lean_SMTP_Gmail_OAuth transport is
 * the per-mailbox alternative for admins who can't grant domain-wide
 * delegation; it is likewise Workspace-only. Consumer @gmail.com — which would
 * need an externally-verified OAuth app — remains a deliberate non-goal.
 *
 * The send path (base64url + messages.send, Bcc left in place) is shared with
 * that OAuth transport through the Lean_SMTP_Gmail_Send trait.
 *
 * The signer (`jwt_assertion`) is pure and deterministic given an explicit
 * timestamp, so it is verified in the unit suite without a network call.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Gmail implements Lean_SMTP_Api_Transport {

	use Lean_SMTP_Gmail_Send;

	const OPTION_SERVICE_ACCOUNT = 'lean_smtp_gmail_service_account';
	const OPTION_SENDER          = 'lean_smtp_gmail_sender';

	const SCOPE         = 'https://www.googleapis.com/auth/gmail.send';
	const TOKEN_URI     = 'https://oauth2.googleapis.com/token';
	const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/%s/messages/send';

	// Access tokens live an hour; cache a touch under that so a token is never
	// used in the final seconds of its life.
	const TOKEN_TRANSIENT = 'lean_smtp_gmail_token';
	const TOKEN_MARGIN    = 60;

	public static function label(): string {
		return __( 'Gmail / Google Workspace', 'lean-smtp' );
	}

	// -------------------------------------------------------------------------
	// Signing (pure)
	// -------------------------------------------------------------------------

	/**
	 * Build and RS256-sign the JWT bearer assertion that is exchanged for an
	 * access token. Pure and deterministic given $now, so the unit suite can
	 * verify its structure and signature against a throwaway keypair.
	 *
	 * @param string $client_email The service-account address (JWT `iss`).
	 * @param string $sender       The Workspace mailbox to impersonate (`sub`).
	 * @param string $private_key  The service-account private key, PEM.
	 * @param int    $now          Current Unix time (`iat`; `exp` is +3600).
	 * @return string|WP_Error The signed assertion, or an error if signing failed.
	 */
	public static function jwt_assertion( string $client_email, string $sender, string $private_key, int $now ) {
		$header = [
			'alg' => 'RS256',
			'typ' => 'JWT',
		];
		$claims = [
			'iss'   => $client_email,
			'sub'   => $sender,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URI,
			'iat'   => $now,
			'exp'   => $now + 3600,
		];

		// Parse the key up front: openssl_sign() raises a PHP warning when handed
		// an un-coercible key, whereas openssl_pkey_get_private() just returns
		// false, letting us fail cleanly with a WP_Error.
		$key = openssl_pkey_get_private( $private_key );
		if ( false === $key ) {
			return new WP_Error( 'lean_smtp_gmail_sign_failed', __( 'Could not read the service-account private key — check that the key JSON was pasted in full.', 'lean-smtp' ) );
		}

		$signing_input = self::base64url( (string) wp_json_encode( $header ) )
			. '.' . self::base64url( (string) wp_json_encode( $claims ) );

		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'lean_smtp_gmail_sign_failed', __( 'Could not sign the Google authentication token with the service-account private key.', 'lean-smtp' ) );
		}

		return $signing_input . '.' . self::base64url( $signature );
	}

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	public static function sender(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_SENDER );
	}

	/**
	 * The stored service-account key, decoded. Returns the credentials array
	 * (carrying at least client_email + private_key) or null when the option is
	 * unset or does not parse to a usable key.
	 *
	 * The key is pasted as the JSON file Google hands out, so json_decode turns
	 * the escaped "\n" inside private_key back into the real newlines a PEM — and
	 * openssl_sign() — needs.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function credentials(): ?array {
		$json = Lean_SMTP_Config::get_secret( self::OPTION_SERVICE_ACCOUNT );
		if ( '' === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
			return null;
		}

		return $decoded;
	}

	public static function is_configured(): bool {
		return '' !== self::sender() && null !== self::credentials();
	}

	// -------------------------------------------------------------------------
	// Send
	// -------------------------------------------------------------------------

	/**
	 * Gmail takes the message as base64url raw MIME and, like any real mail
	 * client, handles Bcc itself: it delivers to the Bcc recipients and drops
	 * the header before anyone else sees it. So — unlike SES/Mailgun — there is
	 * nothing to strip here; messages.send carries no separate envelope, so
	 * stripping the header would simply lose those recipients.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Assembled, `preSend()` already called.
	 * @return string|WP_Error The Gmail message id (may be '') on success, or a WP_Error.
	 */
	public static function send( PHPMailer\PHPMailer\PHPMailer $phpmailer ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return self::send_message( $token, $phpmailer->getSentMIMEMessage() );
	}

	/**
	 * A valid access token for the impersonated sender, from the short-lived
	 * cache or freshly minted. Tokens are specific to the credentials + sender,
	 * so the cache is keyed to the service-account email, the sender *and* a
	 * fingerprint of the private key — rotate the key and the old token is never
	 * reused, so a fresh exchange proves the replacement actually authenticates.
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$credentials = self::credentials();
		$sender      = self::sender();

		if ( null === $credentials || '' === $sender ) {
			return new WP_Error( 'lean_smtp_gmail_unconfigured', __( 'Gmail is not configured (a service-account key and a sender address are required).', 'lean-smtp' ) );
		}

		$cache_key = self::TOKEN_TRANSIENT . '_' . md5( $credentials['client_email'] . '|' . $sender . '|' . (string) $credentials['private_key'] );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$now       = time();
		$assertion = self::jwt_assertion( (string) $credentials['client_email'], $sender, (string) $credentials['private_key'], $now );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$response = wp_remote_post(
			self::TOKEN_URI,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error_description'] )
				? (string) $body['error_description']
				: wp_remote_retrieve_response_message( $response );
			/* translators: 1: HTTP status code, 2: Google error message. */
			return new WP_Error( 'lean_smtp_gmail_token_http_' . $code, sprintf( __( 'Google refused the authentication request (HTTP %1$d): %2$s', 'lean-smtp' ), $code, $message ) );
		}

		$token      = (string) $body['access_token'];
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( $cache_key, $token, max( 1, $expires_in - self::TOKEN_MARGIN ) );

		return $token;
	}
}
