<?php
/**
 * The core: reroutes wp_mail() to the configured transport.
 *
 * Two paths:
 *   - SMTP  — configured on the PHPMailer instance in `phpmailer_init`, letting
 *             WordPress's own wp_mail() do the sending. (Point this at
 *             email-smtp.{region}.amazonaws.com to use SES over SMTP.)
 *   - API   — Amazon SES, Mailgun or Resend. wp_mail() is short-circuited via
 *             `pre_wp_mail`; this class assembles the message with PHPMailer
 *             and hands it to a Lean_SMTP_Api_Transport, which adds only auth
 *             and body shape. Every API provider shares that one assembly, so
 *             core's wp_mail() semantics are reproduced in exactly one place.
 *
 * Plus one non-transport: `offline`, which short-circuits wp_mail() and records
 * the message without sending it — for staging sites that should behave exactly
 * like production without mailing real customers.
 *
 * The From identity (email + name) is applied through the standard
 * `wp_mail_from` / `wp_mail_from_name` filters so it governs both paths and
 * cooperates with other plugins. "Force" makes it win over an address another
 * plugin set; otherwise it only fills in where WordPress would use its default.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Mailer {

	const OPTION_MAILER          = 'lean_smtp_mailer';
	const OPTION_FROM_EMAIL      = 'lean_smtp_from_email';
	const OPTION_FROM_NAME       = 'lean_smtp_from_name';
	const OPTION_FORCE_FROM_MAIL = 'lean_smtp_force_from_email';
	const OPTION_FORCE_FROM_NAME = 'lean_smtp_force_from_name';
	const OPTION_REPLY_TO        = 'lean_smtp_reply_to';

	const OPTION_SMTP_HOST       = 'lean_smtp_smtp_host';
	const OPTION_SMTP_PORT       = 'lean_smtp_smtp_port';
	const OPTION_SMTP_ENCRYPTION = 'lean_smtp_smtp_encryption';
	const OPTION_SMTP_AUTH       = 'lean_smtp_smtp_auth';
	const OPTION_SMTP_USERNAME   = 'lean_smtp_smtp_username';
	const OPTION_SMTP_PASSWORD   = 'lean_smtp_smtp_password';

	const MAILER_SMTP    = 'smtp';
	const MAILER_SES     = 'ses';
	const MAILER_MAILGUN = 'mailgun';
	const MAILER_RESEND  = 'resend';
	const MAILER_OFFLINE = 'offline';

	/**
	 * The API transports, keyed by their mailer slug. SMTP is deliberately
	 * absent: it isn't a transport class, it's PHPMailer configuration. So is
	 * offline, which sends nothing at all.
	 *
	 * @return array<string, string> Slug => class implementing Lean_SMTP_Api_Transport.
	 */
	public static function transports(): array {
		return [
			self::MAILER_SES     => 'Lean_SMTP_SES',
			self::MAILER_MAILGUN => 'Lean_SMTP_Mailgun',
			self::MAILER_RESEND  => 'Lean_SMTP_Resend',
		];
	}

	/**
	 * Every selectable mailer slug — the transports plus the two that aren't
	 * transport classes.
	 *
	 * @return string[]
	 */
	public static function mailers(): array {
		return array_merge(
			[ self::MAILER_SMTP ],
			array_keys( self::transports() ),
			[ self::MAILER_OFFLINE ]
		);
	}

	public static function is_valid_mailer( string $slug ): bool {
		return in_array( $slug, self::mailers(), true );
	}

	/**
	 * The transport class for the selected mailer, or null when sending over
	 * SMTP or not sending at all.
	 */
	public static function transport(): ?string {
		return self::transports()[ self::mailer() ] ?? null;
	}

	public static function init(): void {
		add_filter( 'wp_mail_from', [ static::class, 'filter_from_email' ] );
		add_filter( 'wp_mail_from_name', [ static::class, 'filter_from_name' ] );

		// Both send paths raise phpmailer_init — core does it for SMTP, and
		// send_via_api() re-fires it — so one handler covers Reply-To for both.
		add_action( 'phpmailer_init', [ static::class, 'apply_reply_to' ] );

		if ( self::is_offline() ) {
			// Nothing leaves the site; the record is written inline (below)
			// rather than off wp_mail_succeeded, so it can carry its own status.
			add_filter( 'pre_wp_mail', [ static::class, 'send_offline' ], 10, 2 );
			return;
		}

		if ( null !== self::transport() ) {
			add_filter( 'pre_wp_mail', [ static::class, 'send_via_api' ], 10, 2 );
		} else {
			add_action( 'phpmailer_init', [ static::class, 'configure_smtp' ] );
		}

		if ( Lean_SMTP_Logger::enabled() ) {
			add_action( 'wp_mail_succeeded', [ static::class, 'log_success' ] );
			add_action( 'wp_mail_failed', [ static::class, 'log_failure' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Configuration accessors
	// -------------------------------------------------------------------------

	public static function mailer(): string {
		$mailer = Lean_SMTP_Config::get_string( self::OPTION_MAILER, self::MAILER_SMTP );
		return self::is_valid_mailer( $mailer ) ? $mailer : self::MAILER_SMTP;
	}

	/** Log-only mode: wp_mail() is answered without anything being sent. */
	public static function is_offline(): bool {
		return self::MAILER_OFFLINE === self::mailer();
	}

	public static function from_email(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_FROM_EMAIL );
	}

	public static function from_name(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_FROM_NAME );
	}

	public static function reply_to(): string {
		return Lean_SMTP_Config::get_string( self::OPTION_REPLY_TO );
	}

	private static function force_from_email(): bool {
		return Lean_SMTP_Config::get_bool( self::OPTION_FORCE_FROM_MAIL );
	}

	private static function force_from_name(): bool {
		return Lean_SMTP_Config::get_bool( self::OPTION_FORCE_FROM_NAME );
	}

	private static function smtp_password(): string {
		return Lean_SMTP_Config::get_secret( self::OPTION_SMTP_PASSWORD );
	}

	/**
	 * Whether the selected mailer has everything it needs to send. SMTP only
	 * really requires a host; the API transports each answer for themselves;
	 * offline has nothing to configure.
	 */
	public static function is_configured(): bool {
		if ( self::is_offline() ) {
			return true;
		}
		$transport = self::transport();
		if ( null === $transport ) {
			return '' !== Lean_SMTP_Config::get_string( self::OPTION_SMTP_HOST );
		}
		return (bool) call_user_func( [ $transport, 'is_configured' ] );
	}

	/**
	 * WordPress's default From address, `wordpress@{site-domain}` — reproduced
	 * from core so "don't force" can tell an untouched default from a real one.
	 */
	public static function default_from_email(): string {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( is_string( $sitename ) && 'www.' === substr( $sitename, 0, 4 ) ) {
			$sitename = substr( $sitename, 4 );
		}
		return 'wordpress@' . $sitename;
	}

	public static function is_default_from_email( string $email ): bool {
		return strtolower( $email ) === strtolower( self::default_from_email() );
	}

	// -------------------------------------------------------------------------
	// From identity filters (both transports)
	// -------------------------------------------------------------------------

	public static function filter_from_email( string $from_email ): string {
		$configured = self::from_email();
		if ( '' === $configured ) {
			return $from_email;
		}
		if ( self::force_from_email() || self::is_default_from_email( $from_email ) ) {
			return $configured;
		}
		return $from_email;
	}

	public static function filter_from_name( string $from_name ): string {
		$configured = self::from_name();
		if ( '' === $configured ) {
			return $from_name;
		}
		// WordPress's default From name is "WordPress"; treat that as unset.
		if ( self::force_from_name() || '' === $from_name || 'WordPress' === $from_name ) {
			return $configured;
		}
		return $from_name;
	}

	/**
	 * Apply the configured Reply-To, if the message hasn't set one of its own.
	 *
	 * There is no core filter for Reply-To the way there is for From, but every
	 * path ends at a PHPMailer that has just been populated and not yet sent —
	 * so this one handler serves SMTP and the API transports alike. A Reply-To
	 * a caller passed in its headers always wins: it was chosen for that
	 * message, whereas this setting is only a site-wide default.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public static function apply_reply_to( $phpmailer ): void {
		$reply_to = self::reply_to();
		if ( '' === $reply_to || ! empty( $phpmailer->getReplyToAddresses() ) ) {
			return;
		}

		try {
			$phpmailer->addReplyTo( $reply_to, self::from_name() );
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return; // An unusable address is not worth failing the send over.
		}
	}

	// -------------------------------------------------------------------------
	// Offline path (pre_wp_mail short-circuit, nothing sent)
	// -------------------------------------------------------------------------

	/**
	 * Record the message and report success without sending it.
	 *
	 * wp_mail() is answered `true` and `wp_mail_succeeded` fires, so the site
	 * behaves exactly as it would in production — a staging WooCommerce still
	 * marks its emails as sent — while no mail server is ever contacted.
	 *
	 * @param null|bool $short_circuit Prior filter value.
	 * @param array     $atts          to, subject, message, headers, attachments.
	 */
	public static function send_offline( $short_circuit, array $atts ): bool {
		$atts = apply_filters( 'wp_mail', $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-applying a WordPress core mail hook.

		$to      = $atts['to'] ?? '';
		$subject = (string) ( $atts['subject'] ?? '' );

		if ( ! is_array( $to ) ) {
			$to = array_map( 'trim', explode( ',', (string) $to ) );
		}

		Lean_SMTP_Logger::log(
			self::MAILER_OFFLINE,
			$to,
			$subject,
			Lean_SMTP_Logger::STATUS_OFFLINE,
			'',
			$atts
		);

		$mail_data = [
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $atts['message'] ?? '',
			'headers'     => $atts['headers'] ?? '',
			'attachments' => $atts['attachments'] ?? [],
		];
		do_action( 'wp_mail_succeeded', $mail_data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-firing a WordPress core mail hook.

		return true;
	}

	// -------------------------------------------------------------------------
	// SMTP path
	// -------------------------------------------------------------------------

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public static function configure_smtp( $phpmailer ): void {
		$host = Lean_SMTP_Config::get_string( self::OPTION_SMTP_HOST );
		if ( '' === $host ) {
			return; // Not configured — leave WordPress's default transport alone.
		}

		$phpmailer->isSMTP();
		$phpmailer->Host    = $host;
		$phpmailer->Port    = (int) Lean_SMTP_Config::get( self::OPTION_SMTP_PORT, 587 );
		$phpmailer->Timeout = 15;

		$encryption = Lean_SMTP_Config::get_string( self::OPTION_SMTP_ENCRYPTION, 'tls' );
		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $encryption; // 'ssl' or 'tls' (STARTTLS).
		}

		if ( Lean_SMTP_Config::get_bool( self::OPTION_SMTP_AUTH, true ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = Lean_SMTP_Config::get_string( self::OPTION_SMTP_USERNAME );
			$phpmailer->Password = self::smtp_password();
		} else {
			$phpmailer->SMTPAuth = false;
		}
	}

	// -------------------------------------------------------------------------
	// API path (pre_wp_mail short-circuit)
	// -------------------------------------------------------------------------

	/**
	 * Assemble the message with PHPMailer and hand it to the selected API
	 * transport. Mirrors WordPress core's wp_mail() header/recipient handling
	 * so callers behave identically, then swaps the final send for an API call.
	 *
	 * @param null|bool $short_circuit Prior filter value.
	 * @param array     $atts          to, subject, message, headers, attachments.
	 * @return bool Whether the message was accepted by the provider.
	 */
	public static function send_via_api( $short_circuit, array $atts ): bool {
		$atts = apply_filters( 'wp_mail', $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-applying a WordPress core mail hook.

		$to          = $atts['to'] ?? '';
		$subject     = (string) ( $atts['subject'] ?? '' );
		$message     = $atts['message'] ?? '';
		$headers     = $atts['headers'] ?? '';
		$attachments = $atts['attachments'] ?? [];

		if ( ! is_array( $to ) ) {
			$to = explode( ',', $to );
		}
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}

		// Snapshot the arguments as they arrived, for the wp_mail_succeeded /
		// wp_mail_failed payload — header parsing below rewrites $headers.
		$mail_data = [
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => $attachments,
		];

		// --- Parse headers into structured pieces (faithful to core). --------
		$cc          = [];
		$bcc         = [];
		$reply_to    = [];
		$from_email  = '';
		$from_name   = '';
		$content_type = '';
		$charset      = '';
		$boundary     = '';
		$parsed_headers = [];

		if ( ! empty( $headers ) ) {
			if ( ! is_array( $headers ) ) {
				$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
			}
			foreach ( $headers as $header ) {
				if ( strpos( $header, ':' ) === false ) {
					if ( false !== stripos( $header, 'boundary=' ) ) {
						$parts    = preg_split( '/boundary=/i', trim( $header ) );
						$boundary = trim( str_replace( [ "'", '"' ], '', $parts[1] ) );
					}
					continue;
				}
				list( $name, $content ) = explode( ':', trim( $header ), 2 );
				$name    = trim( $name );
				$content = trim( $content );

				switch ( strtolower( $name ) ) {
					case 'from':
						$bracket_pos = strpos( $content, '<' );
						if ( false !== $bracket_pos ) {
							if ( $bracket_pos > 0 ) {
								$from_name = trim( str_replace( '"', '', substr( $content, 0, $bracket_pos - 1 ) ) );
							}
							$from_email = trim( str_replace( [ '<', '>' ], '', substr( $content, $bracket_pos + 1 ) ) );
						} elseif ( '' !== trim( $content ) ) {
							$from_email = trim( $content );
						}
						break;
					case 'content-type':
						if ( strpos( $content, ';' ) !== false ) {
							list( $type, $charset_content ) = explode( ';', $content );
							$content_type                   = trim( $type );
							if ( false !== stripos( $charset_content, 'charset=' ) ) {
								$charset = trim( str_replace( [ 'charset=', '"' ], '', $charset_content ) );
							} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
								$boundary = trim( str_replace( [ 'BOUNDARY=', 'boundary=', '"' ], '', $charset_content ) );
								$charset  = '';
							}
						} elseif ( '' !== trim( $content ) ) {
							$content_type = trim( $content );
						}
						break;
					case 'cc':
						$cc = array_merge( $cc, explode( ',', $content ) );
						break;
					case 'bcc':
						$bcc = array_merge( $bcc, explode( ',', $content ) );
						break;
					case 'reply-to':
						$reply_to = array_merge( $reply_to, explode( ',', $content ) );
						break;
					default:
						$parsed_headers[ trim( $name ) ] = trim( $content );
						break;
				}
			}
		}

		global $phpmailer;
		if ( ! ( $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );
			$phpmailer::$validator = static function ( $email ) {
				return (bool) is_email( $email );
			};
		}

		$phpmailer->clearAllRecipients();
		$phpmailer->clearAttachments();
		$phpmailer->clearCustomHeaders();
		$phpmailer->clearReplyTos();
		$phpmailer->Body    = '';
		$phpmailer->AltBody = '';

		// --- From ------------------------------------------------------------
		if ( '' === $from_email ) {
			$from_email = self::default_from_email();
		}
		$from_email = apply_filters( 'wp_mail_from', $from_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-applying a WordPress core mail hook.
		$from_name  = apply_filters( 'wp_mail_from_name', '' !== $from_name ? $from_name : 'WordPress' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-applying a WordPress core mail hook.

		try {
			$phpmailer->setFrom( $from_email, $from_name, false );
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return self::fail_api( $mail_data, $e->getMessage() );
		}

		$phpmailer->Subject = $subject;
		$phpmailer->Body    = $message;

		// --- Recipients ------------------------------------------------------
		foreach (
			[
				'to'       => $to,
				'cc'       => $cc,
				'bcc'      => $bcc,
				'reply_to' => $reply_to,
			] as $type => $addresses
		) {
			foreach ( (array) $addresses as $address ) {
				$recipient_name = '';
				if ( preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
					if ( count( $matches ) === 3 ) {
						$recipient_name = trim( $matches[1] );
						$address        = trim( $matches[2] );
					}
				}
				try {
					switch ( $type ) {
						case 'cc':
							$phpmailer->addCc( $address, $recipient_name );
							break;
						case 'bcc':
							$phpmailer->addBcc( $address, $recipient_name );
							break;
						case 'reply_to':
							$phpmailer->addReplyTo( $address, $recipient_name );
							break;
						default:
							$phpmailer->addAddress( $address, $recipient_name );
							break;
					}
				} catch ( PHPMailer\PHPMailer\Exception $e ) {
					continue;
				}
			}
		}

		// --- Content type & charset -----------------------------------------
		if ( '' === $content_type ) {
			$content_type = 'text/plain';
		}
		$content_type = apply_filters( 'wp_mail_content_type', $content_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-applying a WordPress core mail hook.
		$phpmailer->ContentType = $content_type;
		if ( 'text/html' === $content_type ) {
			$phpmailer->isHTML( true );
		}

		if ( '' === $charset ) {
			$charset = get_bloginfo( 'charset' );
		}
		$phpmailer->CharSet = apply_filters( 'wp_mail_charset', $charset ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-applying a WordPress core mail hook.

		if ( '' !== $boundary ) {
			$phpmailer->addCustomHeader( 'Content-Type', sprintf( "%s;\n\t boundary=\"%s\"", $content_type, $boundary ) );
		}

		foreach ( $parsed_headers as $name => $content ) {
			$phpmailer->addCustomHeader( $name, $content );
		}

		foreach ( $attachments as $filename => $attachment ) {
			$filename = is_string( $filename ) ? $filename : '';
			try {
				$phpmailer->addAttachment( $attachment, $filename );
			} catch ( PHPMailer\PHPMailer\Exception $e ) {
				continue;
			}
		}

		// Let other plugins tweak the message, exactly as core does.
		do_action_ref_array( 'phpmailer_init', [ &$phpmailer ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-firing a WordPress core mail hook.

		// --- Assemble and hand off to the transport --------------------------
		$transport = self::transport();
		if ( null === $transport ) {
			return self::fail_api( $mail_data, __( 'No API transport is selected.', 'lean-smtp' ) );
		}

		// preSend() validates the message and builds the MIME body. Transports
		// that want raw MIME read it back with getSentMIMEMessage(); the rest
		// read the structured properties it has just finalised.
		try {
			if ( ! $phpmailer->preSend() ) {
				return self::fail_api( $mail_data, $phpmailer->ErrorInfo );
			}
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return self::fail_api( $mail_data, $e->getMessage() );
		}

		$result = call_user_func( [ $transport, 'send' ], $phpmailer );

		if ( is_wp_error( $result ) ) {
			return self::fail_api( $mail_data, $result->get_error_message() );
		}

		// Log through the same wp_mail_succeeded hook the SMTP path uses, so a
		// send is recorded exactly once. Core doesn't fire this when pre_wp_mail
		// short-circuits, so we fire it ourselves (also notifies other plugins).
		do_action( 'wp_mail_succeeded', $mail_data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-firing a WordPress core mail hook.
		return true;
	}

	/**
	 * Fire the standard wp_mail_failed hook — which the logger and other plugins
	 * listen on — and report failure to wp_mail(). Logging happens via that hook,
	 * not here, so a failed send is never recorded twice.
	 *
	 * The error carries the whole message, as core's wp_mail() does on a
	 * PHPMailer exception, so a listener sees the same payload either way.
	 *
	 * @param array $mail_data to, subject, message, headers, attachments.
	 */
	private static function fail_api( array $mail_data, string $error ): bool {
		$mail_error = new WP_Error();
		$mail_error->add( 'wp_mail_failed', $error, $mail_data );
		do_action( 'wp_mail_failed', $mail_error ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-firing a WordPress core mail hook.

		return false;
	}

	// -------------------------------------------------------------------------
	// Logging for the SMTP (native wp_mail) path
	// -------------------------------------------------------------------------

	/**
	 * The hook payload is core's own — to, subject, message, headers and
	 * attachments — so the logger can record the message itself when the site
	 * has opted into that, without either send path having to assemble it.
	 *
	 * @param array $mail_data to, subject, message, headers, attachments.
	 */
	public static function log_success( array $mail_data ): void {
		Lean_SMTP_Logger::log(
			self::mailer(),
			$mail_data['to'] ?? '',
			(string) ( $mail_data['subject'] ?? '' ),
			Lean_SMTP_Logger::STATUS_SENT,
			'',
			$mail_data
		);
	}

	public static function log_failure( WP_Error $error ): void {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		Lean_SMTP_Logger::log(
			self::mailer(),
			$data['to'] ?? '',
			(string) ( $data['subject'] ?? '' ),
			Lean_SMTP_Logger::STATUS_FAILED,
			$error->get_error_message(),
			$data
		);
	}

	// -------------------------------------------------------------------------
	// Test email (used by the settings page)
	// -------------------------------------------------------------------------

	/**
	 * Send a test message to $to using the currently-configured transport.
	 *
	 * @return true|WP_Error
	 */
	public static function send_test( string $to ) {
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'lean_smtp_bad_recipient', __( 'Please enter a valid recipient email address.', 'lean-smtp' ) );
		}

		$captured = null;
		$capture  = static function ( WP_Error $error ) use ( &$captured ) {
			$captured = $error;
		};
		add_action( 'wp_mail_failed', $capture );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Lean SMTP test email from %s', 'lean-smtp' ), get_bloginfo( 'name' ) );
		$body    = __( 'This is a test email sent by the Lean SMTP plugin. If you received it, your mail settings are working.', 'lean-smtp' );

		// A deliberate test reports its own result inline; it shouldn't also
		// raise the background "mail is failing" notice.
		Lean_SMTP_Notices::suspend();
		$ok = wp_mail( $to, $subject, $body );
		Lean_SMTP_Notices::resume();

		remove_action( 'wp_mail_failed', $capture );

		if ( $ok ) {
			return true;
		}

		return $captured instanceof WP_Error
			? $captured
			: new WP_Error( 'lean_smtp_test_failed', __( 'The test email could not be sent. Check your settings and your host\'s outbound mail.', 'lean-smtp' ) );
	}
}
