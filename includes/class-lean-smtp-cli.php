<?php
/**
 * WP-CLI commands.
 *
 * Deliverability problems are usually diagnosed over SSH on the box that is
 * failing to send, so the two things the settings page offers — "is this
 * configured?" and "send a test" — are available without a browser. Loaded
 * only under WP-CLI.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_CLI {

	public static function register(): void {
		WP_CLI::add_command( 'lean-smtp', self::class );
	}

	/**
	 * Sends a test email using the currently-configured transport.
	 *
	 * ## OPTIONS
	 *
	 * [<recipient>]
	 * : Address to send to. Defaults to the site's admin email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-smtp test
	 *     wp lean-smtp test someone@example.com
	 *
	 * @param array $args Positional arguments.
	 */
	public function test( array $args ): void {
		$to = $args[0] ?? (string) get_option( 'admin_email' );

		WP_CLI::log( sprintf( 'Sending via %s to %s…', strtoupper( Lean_SMTP_Mailer::mailer() ), $to ) );

		$result = Lean_SMTP_Mailer::send_test( $to );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( Lean_SMTP_Mailer::is_offline() ) {
			WP_CLI::success( 'Test email captured in the send log. Nothing was sent — the offline mailer is selected.' );
			return;
		}

		WP_CLI::success( 'Test email sent.' );
	}

	/**
	 * Shows the mail configuration in force, including any wp-config.php overrides.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-smtp status
	 *     wp lean-smtp status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$mailer = Lean_SMTP_Mailer::mailer();

		$rows = [
			self::row( __( 'Mailer', 'lean-smtp' ), $mailer, Lean_SMTP_Mailer::OPTION_MAILER ),
			self::row( __( 'From Email', 'lean-smtp' ), Lean_SMTP_Mailer::from_email(), Lean_SMTP_Mailer::OPTION_FROM_EMAIL ),
			self::row( __( 'From Name', 'lean-smtp' ), Lean_SMTP_Mailer::from_name(), Lean_SMTP_Mailer::OPTION_FROM_NAME ),
			self::row( __( 'Reply-To', 'lean-smtp' ), Lean_SMTP_Mailer::reply_to(), Lean_SMTP_Mailer::OPTION_REPLY_TO ),
		];

		foreach ( self::mailer_rows( $mailer ) as $row ) {
			$rows[] = $row;
		}

		$rows[] = self::row( __( 'Send Log', 'lean-smtp' ), Lean_SMTP_Logger::enabled() ? 'enabled' : 'disabled', Lean_SMTP_Logger::OPTION_ENABLED );
		$rows[] = self::row( __( 'Log Headers', 'lean-smtp' ), Lean_SMTP_Logger::log_headers() ? 'yes' : 'no', Lean_SMTP_Logger::OPTION_LOG_HEADERS );
		$rows[] = self::row( __( 'Log Body', 'lean-smtp' ), Lean_SMTP_Logger::log_body() ? 'yes' : 'no', Lean_SMTP_Logger::OPTION_LOG_BODY );
		$rows[] = [
			'setting' => __( 'Configured', 'lean-smtp' ),
			'value'   => Lean_SMTP_Mailer::is_configured() ? 'yes' : 'no',
			'source'  => '',
		];

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'setting', 'value', 'source' ]
		);

		$failure = Lean_SMTP_Notices::last_failure();
		if ( null !== $failure ) {
			WP_CLI::warning(
				sprintf(
					'Last send failed (%s): %s',
					$failure['time'] ?? '',
					$failure['message'] ?? ''
				)
			);
		}

		if ( ! Lean_SMTP_Mailer::is_configured() ) {
			WP_CLI::warning( 'The selected mailer is missing required settings.' );
		}
	}

	/**
	 * The transport-specific rows for the selected mailer.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function mailer_rows( string $mailer ): array {
		switch ( $mailer ) {
			case Lean_SMTP_Mailer::MAILER_SES:
				return [
					self::row( __( 'SES Region', 'lean-smtp' ), Lean_SMTP_SES::region(), Lean_SMTP_SES::OPTION_REGION ),
					self::row( __( 'SES Access Key', 'lean-smtp' ), Lean_SMTP_SES::access_key(), Lean_SMTP_SES::OPTION_ACCESS_KEY ),
					self::secret_row( __( 'SES Secret Key', 'lean-smtp' ), Lean_SMTP_SES::OPTION_SECRET_KEY ),
				];

			case Lean_SMTP_Mailer::MAILER_MAILGUN:
				return [
					self::row( __( 'Mailgun Domain', 'lean-smtp' ), Lean_SMTP_Mailgun::domain(), Lean_SMTP_Mailgun::OPTION_DOMAIN ),
					self::row( __( 'Mailgun Region', 'lean-smtp' ), Lean_SMTP_Mailgun::region(), Lean_SMTP_Mailgun::OPTION_REGION ),
					self::secret_row( __( 'Mailgun API Key', 'lean-smtp' ), Lean_SMTP_Mailgun::OPTION_API_KEY ),
				];

			case Lean_SMTP_Mailer::MAILER_RESEND:
				return [
					self::secret_row( __( 'Resend API Key', 'lean-smtp' ), Lean_SMTP_Resend::OPTION_API_KEY ),
				];

			case Lean_SMTP_Mailer::MAILER_OFFLINE:
				// Nothing to configure — mail is recorded, never sent.
				return [];

			default:
				return [
					self::row( __( 'SMTP Host', 'lean-smtp' ), Lean_SMTP_Config::get_string( Lean_SMTP_Mailer::OPTION_SMTP_HOST ), Lean_SMTP_Mailer::OPTION_SMTP_HOST ),
					self::row( __( 'SMTP Port', 'lean-smtp' ), (string) Lean_SMTP_Config::get( Lean_SMTP_Mailer::OPTION_SMTP_PORT, 587 ), Lean_SMTP_Mailer::OPTION_SMTP_PORT ),
					self::row( __( 'Encryption', 'lean-smtp' ), Lean_SMTP_Config::get_string( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, 'tls' ), Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION ),
					self::row( __( 'Authentication', 'lean-smtp' ), Lean_SMTP_Config::get_bool( Lean_SMTP_Mailer::OPTION_SMTP_AUTH, true ) ? 'on' : 'off', Lean_SMTP_Mailer::OPTION_SMTP_AUTH ),
					self::row( __( 'Username', 'lean-smtp' ), Lean_SMTP_Config::get_string( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ), Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ),
					self::secret_row( __( 'Password', 'lean-smtp' ), Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD ),
				];
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function row( string $label, string $value, string $option ): array {
		return [
			'setting' => $label,
			'value'   => '' !== $value ? $value : '(not set)',
			'source'  => self::source( $option ),
		];
	}

	/**
	 * Secrets report only whether they are set — never the value.
	 *
	 * @return array<string, string>
	 */
	private static function secret_row( string $label, string $option ): array {
		return [
			'setting' => $label,
			'value'   => Lean_SMTP_Config::has_secret( $option ) ? '(set)' : '(not set)',
			'source'  => self::source( $option ),
		];
	}

	private static function source( string $option ): string {
		return Lean_SMTP_Config::is_constant( $option )
			? Lean_SMTP_Config::constant_name( $option )
			: 'database';
	}
}
