<?php
/**
 * Surfaces mail failures in the admin.
 *
 * The failure mode that actually costs sites money is the silent one:
 * wp_mail() returns false, nobody is listening, and password resets and order
 * receipts quietly stop arriving. The send log catches this only if it is
 * enabled and someone thinks to look, so the last failure is also recorded on
 * its own and shown as an admin notice.
 *
 * It is stored in an autoloaded option rather than a transient so that the
 * check on every successful send costs no query. A later success clears it —
 * the notice reflects "mail is broken now", not "mail broke once".
 *
 * No attempt is made to email the alert: the mailer is the thing that's broken.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Notices {

	const OPTION_LAST_FAILURE = 'lean_smtp_last_failure';

	/**
	 * Set while a deliberate test send is running, which reports its own result.
	 */
	private static bool $suspended = false;

	public static function init(): void {
		add_action( 'wp_mail_failed', [ static::class, 'record' ] );
		add_action( 'wp_mail_succeeded', [ static::class, 'clear' ] );
		add_action( 'admin_notices', [ static::class, 'render' ] );
		add_action( 'admin_post_lean_smtp_dismiss_failure', [ static::class, 'handle_dismiss' ] );
	}

	public static function suspend(): void {
		self::$suspended = true;
	}

	public static function resume(): void {
		self::$suspended = false;
	}

	// -------------------------------------------------------------------------
	// Recording
	// -------------------------------------------------------------------------

	public static function record( WP_Error $error ): void {
		if ( self::$suspended ) {
			return;
		}

		$data = $error->get_error_data();
		$to   = is_array( $data ) ? ( $data['to'] ?? '' ) : '';

		update_option(
			self::OPTION_LAST_FAILURE,
			[
				'time'    => current_time( 'mysql' ),
				'mailer'  => Lean_SMTP_Mailer::mailer(),
				'to'      => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
				'message' => $error->get_error_message(),
			]
		);
	}

	/**
	 * Mail is working again — drop the notice. Guarded so a healthy site pays
	 * nothing beyond reading an already-autoloaded option.
	 */
	public static function clear(): void {
		if ( false !== get_option( self::OPTION_LAST_FAILURE, false ) ) {
			delete_option( self::OPTION_LAST_FAILURE );
		}
	}

	/**
	 * @return array{time: string, mailer: string, to: string, message: string}|null
	 */
	public static function last_failure(): ?array {
		$failure = get_option( self::OPTION_LAST_FAILURE, false );
		return is_array( $failure ) ? $failure : null;
	}

	// -------------------------------------------------------------------------
	// Notice
	// -------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failure = self::last_failure();
		if ( null === $failure ) {
			return;
		}

		$dismiss = wp_nonce_url(
			admin_url( 'admin-post.php?action=lean_smtp_dismiss_failure' ),
			'lean_smtp_dismiss_failure'
		);
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Lean SMTP: the last email failed to send.', 'lean-smtp' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: mailer name, 2: date and time, 3: recipient address. */
					esc_html__( 'Mailer: %1$s — %2$s — to %3$s', 'lean-smtp' ),
					esc_html( strtoupper( (string) ( $failure['mailer'] ?? '' ) ) ),
					esc_html( (string) ( $failure['time'] ?? '' ) ),
					esc_html( '' !== (string) ( $failure['to'] ?? '' ) ? (string) $failure['to'] : __( '(unknown recipient)', 'lean-smtp' ) )
				);
				?>
			</p>
			<?php if ( '' !== (string) ( $failure['message'] ?? '' ) ) : ?>
				<p><code><?php echo esc_html( (string) $failure['message'] ); ?></code></p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( Lean_SMTP_Settings::url() ); ?>">
					<?php esc_html_e( 'Check your mail settings', 'lean-smtp' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'lean-smtp' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lean-smtp' ) );
		}
		check_admin_referer( 'lean_smtp_dismiss_failure' );

		delete_option( self::OPTION_LAST_FAILURE );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
