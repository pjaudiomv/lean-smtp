<?php
/**
 * Gmail / Google Workspace API transport (OAuth2 authorization-code auth).
 *
 * A second Gmail transport alongside the service-account one. It sends through
 * the same `users.messages.send` endpoint (via the shared Lean_SMTP_Gmail_Send
 * trait), but authenticates with the OAuth2 authorization-code flow against a
 * customer-owned Google Cloud app: the admin authorizes one mailbox in the
 * browser, Google returns a refresh token, and the plugin trades that refresh
 * token for short-lived access tokens on each send.
 *
 * Workspace-Internal only by design. `gmail.send` is a Google *restricted*
 * scope; the only publishing status that needs no app verification *and* issues
 * non-expiring refresh tokens is an **Internal** consent screen on a Workspace
 * org. A consumer (External) app in Testing gets 7-day refresh tokens and
 * otherwise demands Google's verification/security-assessment process, so
 * consumer @gmail.com remains a non-goal — see the service-account transport's
 * header and CLAUDE.md.
 *
 * Why this exists next to the service-account transport: that one needs a Super
 * Admin to grant domain-wide delegation (impersonation across the whole
 * domain); this one needs only a per-mailbox browser consent by the account's
 * owner. Same Gmail API, same send path; only the token source and who approves
 * it differ.
 *
 * Like the service-account transport, it leaves the `Bcc` header in place:
 * Gmail consumes it itself and `messages.send` has no separate envelope.
 *
 * The URL builder (`authorize_url`) and the id_token reader
 * (`email_from_id_token`) are pure, so the consent round-trip is unit-tested
 * without a browser or a network call.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Gmail_OAuth implements Lean_SMTP_Api_Transport {

	use Lean_SMTP_Gmail_Send;

	const OPTION_CLIENT_ID     = 'lean_smtp_gmail_oauth_client_id';
	const OPTION_CLIENT_SECRET = 'lean_smtp_gmail_oauth_client_secret';
	const OPTION_REFRESH_TOKEN = 'lean_smtp_gmail_oauth_refresh_token';
	const OPTION_SENDER        = 'lean_smtp_gmail_oauth_sender';

	// openid + email are requested alongside gmail.send so the connected mailbox
	// comes back in the id_token — the admin never types the sending address.
	const SCOPE         = 'openid email https://www.googleapis.com/auth/gmail.send';
	const AUTH_URI      = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URI     = 'https://oauth2.googleapis.com/token';
	const REVOKE_URI    = 'https://oauth2.googleapis.com/revoke';
	const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/%s/messages/send';

	// admin-post action Google is pointed back at; also the registered redirect URI.
	const CALLBACK_ACTION = 'lean_smtp_gmail_oauth_callback';

	// Access tokens live an hour; cache a touch under that so a token is never
	// used in the final seconds of its life.
	const TOKEN_TRANSIENT = 'lean_smtp_gmail_oauth_token';
	const TOKEN_MARGIN    = 60;

	public static function label(): string {
		return __( 'Gmail / Google Workspace (OAuth)', 'lean-smtp' );
	}

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	public static function client_id(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_CLIENT_ID );
	}

	public static function client_secret(): string {
		return Lean_SMTP_Config::get_secret( self::OPTION_CLIENT_SECRET );
	}

	public static function refresh_token(): string {
		return Lean_SMTP_Config::get_secret( self::OPTION_REFRESH_TOKEN );
	}

	/** The connected mailbox, captured from the id_token on authorization. */
	public static function sender(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_SENDER );
	}

	/** Whether an account has been authorized (a refresh token is held). */
	public static function is_connected(): bool {
		return '' !== self::refresh_token() && '' !== self::sender();
	}

	public static function is_configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret() && self::is_connected();
	}

	/**
	 * The redirect URI Google sends the browser back to after consent. Must be
	 * registered verbatim in the Google Cloud Console; the settings page prints
	 * it read-only for exactly that reason.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	// -------------------------------------------------------------------------
	// Consent round-trip
	// -------------------------------------------------------------------------

	/**
	 * The Google consent URL to send the admin's browser to. Pure and
	 * deterministic given its inputs, so it is unit-tested without a network.
	 *
	 * `access_type=offline` + `prompt=consent` is what makes Google return a
	 * refresh token (and re-issue one on every reconsent).
	 *
	 * @param string $redirect_uri The registered redirect URI.
	 * @param string $state        An opaque anti-CSRF token echoed back to us.
	 */
	public static function authorize_url( string $redirect_uri, string $state ): string {
		$query = http_build_query(
			[
				'client_id'     => self::client_id(),
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return self::AUTH_URI . '?' . $query;
	}

	/**
	 * Read the `email` claim out of a Google id_token (a JWT). The token came
	 * straight from Google's TLS token endpoint, so the signature is not
	 * re-verified here — only the payload is decoded. Pure; unit-tested.
	 *
	 * @return string The mailbox address, or '' if the token has no usable email.
	 */
	public static function email_from_id_token( string $id_token ): string {
		$parts = explode( '.', $id_token );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$payload = $parts[1];
		$pad     = strlen( $payload ) % 4;
		if ( 0 !== $pad ) {
			$payload .= str_repeat( '=', 4 - $pad );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a JWT payload segment returned by Google, not obfuscation.
		$json = base64_decode( strtr( $payload, '-_', '+/' ), true );
		if ( false === $json ) {
			return '';
		}

		$claims = json_decode( $json, true );
		if ( ! is_array( $claims ) || empty( $claims['email'] ) ) {
			return '';
		}

		return sanitize_email( (string) $claims['email'] );
	}

	/**
	 * Exchange the authorization code Google returned for a refresh token, and
	 * store it (encrypted) together with the connected mailbox read from the
	 * id_token. Called from the admin-post callback handler after the state
	 * nonce has been verified.
	 *
	 * @param string $code         The one-time authorization code.
	 * @param string $redirect_uri The same redirect URI used in the auth request.
	 * @return true|WP_Error
	 */
	public static function complete_authorization( string $code, string $redirect_uri ) {
		$response = wp_remote_post(
			self::TOKEN_URI,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'redirect_uri'  => $redirect_uri,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $http < 200 || $http >= 300 || ! is_array( $body ) || empty( $body['refresh_token'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error_description'] )
				? (string) $body['error_description']
				: wp_remote_retrieve_response_message( $response );
			if ( is_array( $body ) && empty( $body['refresh_token'] ) && $http >= 200 && $http < 300 ) {
				// A 200 with no refresh token means Google reused a prior grant
				// without re-issuing one; prompt=consent should prevent this.
				$message = __( 'Google did not return a refresh token. Remove the app from your Google Account\'s third-party access and try connecting again.', 'lean-smtp' );
			}
			/* translators: 1: HTTP status code, 2: Google error message. */
			return new WP_Error( 'lean_smtp_gmail_oauth_exchange_http_' . $http, sprintf( __( 'Google refused the authorization (HTTP %1$d): %2$s', 'lean-smtp' ), $http, $message ) );
		}

		$email = isset( $body['id_token'] ) ? self::email_from_id_token( (string) $body['id_token'] ) : '';
		if ( '' === $email ) {
			return new WP_Error( 'lean_smtp_gmail_oauth_no_email', __( 'Google did not return the connected account address. Make sure the OAuth consent screen requests the "email" scope.', 'lean-smtp' ) );
		}

		self::clear_token_cache();
		// The connection is configuration — on a network-activated multisite it is
		// shared by every site — so it is written through Lean_SMTP_Config, which
		// the reads already go through. The access-token cache below stays per-site.
		Lean_SMTP_Config::store( self::OPTION_REFRESH_TOKEN, Lean_SMTP_Crypto::encrypt( (string) $body['refresh_token'] ) );
		Lean_SMTP_Config::store( self::OPTION_SENDER, $email );

		return true;
	}

	/**
	 * Revoke the stored grant at Google (best effort) and forget it locally, so
	 * the settings page falls back to "Connect".
	 */
	public static function revoke(): void {
		$refresh = self::refresh_token();
		if ( '' !== $refresh ) {
			wp_remote_post(
				self::REVOKE_URI,
				[
					'timeout' => 15,
					'body'    => [ 'token' => $refresh ],
				]
			);
		}

		self::clear_token_cache();
		Lean_SMTP_Config::erase( self::OPTION_REFRESH_TOKEN );
		Lean_SMTP_Config::erase( self::OPTION_SENDER );
	}

	// -------------------------------------------------------------------------
	// Send
	// -------------------------------------------------------------------------

	/**
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
	 * A valid access token for the connected mailbox, from the short-lived cache
	 * or freshly refreshed. Keyed to the client id, the sender *and* the refresh
	 * token, so reconnecting a different account never reuses an old token.
	 *
	 * A refresh that fails with `invalid_grant` means the authorization was
	 * revoked or expired and can never be refreshed again, so the stored token
	 * is dropped: the next page load shows "Connect" rather than failing forever.
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$refresh = self::refresh_token();

		if ( '' === self::client_id() || '' === self::client_secret() || '' === $refresh ) {
			return new WP_Error( 'lean_smtp_gmail_oauth_unconfigured', __( 'Gmail (OAuth) is not connected — authorize a Google account on the settings page.', 'lean-smtp' ) );
		}

		$cache_key = self::token_cache_key();
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_URI,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type'    => 'refresh_token',
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'refresh_token' => $refresh,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $http < 200 || $http >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			if ( is_array( $body ) && isset( $body['error'] ) && 'invalid_grant' === $body['error'] ) {
				self::clear_token_cache();
				Lean_SMTP_Config::erase( self::OPTION_REFRESH_TOKEN );
				return new WP_Error( 'lean_smtp_gmail_oauth_reconnect', __( 'The Google authorization is no longer valid — reconnect the account on the settings page.', 'lean-smtp' ) );
			}
			$message = is_array( $body ) && ! empty( $body['error_description'] )
				? (string) $body['error_description']
				: wp_remote_retrieve_response_message( $response );
			/* translators: 1: HTTP status code, 2: Google error message. */
			return new WP_Error( 'lean_smtp_gmail_oauth_token_http_' . $http, sprintf( __( 'Google refused the token refresh (HTTP %1$d): %2$s', 'lean-smtp' ), $http, $message ) );
		}

		$token      = (string) $body['access_token'];
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( $cache_key, $token, max( 1, $expires_in - self::TOKEN_MARGIN ) );

		return $token;
	}

	private static function token_cache_key(): string {
		return self::TOKEN_TRANSIENT . '_' . md5( self::client_id() . '|' . self::sender() . '|' . self::refresh_token() );
	}

	private static function clear_token_cache(): void {
		delete_transient( self::token_cache_key() );
	}
}
