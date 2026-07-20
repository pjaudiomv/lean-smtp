<?php
/**
 * The core: reroutes wp_mail() to the configured transport.
 *
 * Two paths:
 *   - SMTP  — configured on the PHPMailer instance in `phpmailer_init`, letting
 *             WordPress's own wp_mail() do the sending. (Point this at
 *             email-smtp.{region}.amazonaws.com to use SES over SMTP.)
 *   - SES   — the Amazon SES v2 API. wp_mail() is short-circuited via
 *             `pre_wp_mail`; PHPMailer assembles the MIME message and the raw
 *             bytes are handed to the SES API.
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

	const OPTION_SMTP_HOST       = 'lean_smtp_smtp_host';
	const OPTION_SMTP_PORT       = 'lean_smtp_smtp_port';
	const OPTION_SMTP_ENCRYPTION = 'lean_smtp_smtp_encryption';
	const OPTION_SMTP_AUTH       = 'lean_smtp_smtp_auth';
	const OPTION_SMTP_USERNAME   = 'lean_smtp_smtp_username';
	const OPTION_SMTP_PASSWORD   = 'lean_smtp_smtp_password';

	const MAILER_SMTP = 'smtp';
	const MAILER_SES  = 'ses';

	public static function init(): void {
		add_filter( 'wp_mail_from', [ static::class, 'filter_from_email' ] );
		add_filter( 'wp_mail_from_name', [ static::class, 'filter_from_name' ] );

		if ( self::MAILER_SES === self::mailer() ) {
			add_filter( 'pre_wp_mail', [ static::class, 'send_via_ses' ], 10, 2 );
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
		return self::MAILER_SES === get_option( self::OPTION_MAILER ) ? self::MAILER_SES : self::MAILER_SMTP;
	}

	public static function from_email(): string {
		return trim( (string) get_option( self::OPTION_FROM_EMAIL, '' ) );
	}

	public static function from_name(): string {
		return trim( (string) get_option( self::OPTION_FROM_NAME, '' ) );
	}

	private static function force_from_email(): bool {
		return '1' === (string) get_option( self::OPTION_FORCE_FROM_MAIL, '0' );
	}

	private static function force_from_name(): bool {
		return '1' === (string) get_option( self::OPTION_FORCE_FROM_NAME, '0' );
	}

	private static function smtp_password(): string {
		return Lean_SMTP_Crypto::decrypt( (string) get_option( self::OPTION_SMTP_PASSWORD, '' ) );
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

	// -------------------------------------------------------------------------
	// SMTP path
	// -------------------------------------------------------------------------

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public static function configure_smtp( $phpmailer ): void {
		$host = trim( (string) get_option( self::OPTION_SMTP_HOST, '' ) );
		if ( '' === $host ) {
			return; // Not configured — leave WordPress's default transport alone.
		}

		$phpmailer->isSMTP();
		$phpmailer->Host    = $host;
		$phpmailer->Port    = (int) get_option( self::OPTION_SMTP_PORT, 587 );
		$phpmailer->Timeout = 15;

		$encryption = (string) get_option( self::OPTION_SMTP_ENCRYPTION, 'tls' );
		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $encryption; // 'ssl' or 'tls' (STARTTLS).
		}

		if ( '1' === (string) get_option( self::OPTION_SMTP_AUTH, '1' ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = (string) get_option( self::OPTION_SMTP_USERNAME, '' );
			$phpmailer->Password = self::smtp_password();
		} else {
			$phpmailer->SMTPAuth = false;
		}
	}

	// -------------------------------------------------------------------------
	// SES path (pre_wp_mail short-circuit)
	// -------------------------------------------------------------------------

	/**
	 * Assemble the message with PHPMailer and deliver it through the SES API.
	 * Mirrors WordPress core's wp_mail() header/recipient handling so callers
	 * behave identically, then swaps the final send for a raw SES API call.
	 *
	 * @param null|bool $short_circuit Prior filter value.
	 * @param array     $atts          to, subject, message, headers, attachments.
	 * @return bool Whether the message was accepted by SES.
	 */
	public static function send_via_ses( $short_circuit, array $atts ): bool {
		$atts = apply_filters( 'wp_mail', $atts );

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
		$from_email = apply_filters( 'wp_mail_from', $from_email );
		$from_name  = apply_filters( 'wp_mail_from_name', '' !== $from_name ? $from_name : 'WordPress' );

		try {
			$phpmailer->setFrom( $from_email, $from_name, false );
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return self::fail_ses( $to, $subject, $e->getMessage() );
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
		$content_type = apply_filters( 'wp_mail_content_type', $content_type );
		$phpmailer->ContentType = $content_type;
		if ( 'text/html' === $content_type ) {
			$phpmailer->isHTML( true );
		}

		if ( '' === $charset ) {
			$charset = get_bloginfo( 'charset' );
		}
		$phpmailer->CharSet = apply_filters( 'wp_mail_charset', $charset );

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
		do_action_ref_array( 'phpmailer_init', [ &$phpmailer ] );

		// --- Assemble MIME and hand off to SES ------------------------------
		try {
			if ( ! $phpmailer->preSend() ) {
				return self::fail_ses( $to, $subject, $phpmailer->ErrorInfo );
			}
			$raw = $phpmailer->getSentMIMEMessage();
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return self::fail_ses( $to, $subject, $e->getMessage() );
		}

		$result = Lean_SMTP_SES::send_raw_email( $raw );

		if ( is_wp_error( $result ) ) {
			return self::fail_ses( $to, $subject, $result->get_error_message() );
		}

		// Log through the same wp_mail_succeeded hook the SMTP path uses, so a
		// send is recorded exactly once. Core doesn't fire this when pre_wp_mail
		// short-circuits, so we fire it ourselves (also notifies other plugins).
		do_action(
			'wp_mail_succeeded',
			[
				'to'          => $to,
				'subject'     => $subject,
				'message'     => $message,
				'headers'     => $headers,
				'attachments' => $attachments,
			]
		);
		return true;
	}

	/**
	 * Fire the standard wp_mail_failed hook — which the logger and other plugins
	 * listen on — and report failure to wp_mail(). Logging happens via that hook,
	 * not here, so a failed send is never recorded twice.
	 *
	 * @param string[] $to
	 */
	private static function fail_ses( array $to, string $subject, string $error ): bool {
		$mail_error = new WP_Error();
		$mail_error->add(
			'wp_mail_failed',
			$error,
			[
				'to'      => $to,
				'subject' => $subject,
			]
		);
		do_action( 'wp_mail_failed', $mail_error );

		return false;
	}

	// -------------------------------------------------------------------------
	// Logging for the SMTP (native wp_mail) path
	// -------------------------------------------------------------------------

	/**
	 * @param array $mail_data to, subject, message, headers, attachments.
	 */
	public static function log_success( array $mail_data ): void {
		Lean_SMTP_Logger::log(
			self::mailer(),
			$mail_data['to'] ?? '',
			(string) ( $mail_data['subject'] ?? '' ),
			true
		);
	}

	public static function log_failure( WP_Error $error ): void {
		$data = $error->get_error_data();
		Lean_SMTP_Logger::log(
			self::mailer(),
			is_array( $data ) ? ( $data['to'] ?? '' ) : '',
			is_array( $data ) ? (string) ( $data['subject'] ?? '' ) : '',
			false,
			$error->get_error_message()
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

		$ok = wp_mail( $to, $subject, $body );

		remove_action( 'wp_mail_failed', $capture );

		if ( $ok ) {
			return true;
		}

		return $captured instanceof WP_Error
			? $captured
			: new WP_Error( 'lean_smtp_test_failed', __( 'The test email could not be sent. Check your settings and your host\'s outbound mail.', 'lean-smtp' ) );
	}
}
