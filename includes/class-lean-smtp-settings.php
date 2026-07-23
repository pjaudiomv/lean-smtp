<?php
/**
 * Admin settings page: transport selection, From identity, SMTP / SES /
 * Mailgun / Resend credentials, a test-email button, and the send log viewer.
 *
 * Any setting can instead be pinned in wp-config.php (see Lean_SMTP_Config).
 * When it is, the field renders read-only and its sanitize callback leaves the
 * stored option alone — so what the page shows is always what is in force, and
 * removing the constant restores whatever was saved before.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Settings {

	const PAGE  = 'lean-smtp';
	const GROUP = 'lean-smtp-group';

	public static function init(): void {
		add_action( 'admin_menu', [ static::class, 'admin_menu' ] );
		add_action( 'admin_init', [ static::class, 'register_settings' ] );
		add_action( 'admin_post_lean_smtp_test', [ static::class, 'handle_test' ] );
		add_action( 'admin_post_lean_smtp_clear_log', [ static::class, 'handle_clear_log' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( LEAN_SMTP_FILE ), [ static::class, 'settings_link' ] );
	}

	public static function settings_link( array $links ): array {
		$url     = admin_url( 'options-general.php?page=' . self::PAGE );
		$links[] = "<a href='{$url}'>" . esc_html__( 'Settings', 'lean-smtp' ) . '</a>';
		return $links;
	}

	public static function admin_menu(): void {
		add_options_page(
			__( 'Lean SMTP Settings', 'lean-smtp' ),
			__( 'Lean SMTP', 'lean-smtp' ),
			'manage_options',
			self::PAGE,
			[ static::class, 'settings_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Registration & sanitizing
	// -------------------------------------------------------------------------

	public static function register_settings(): void {
		self::register( Lean_SMTP_Mailer::OPTION_MAILER, [ static::class, 'sanitize_mailer' ] );
		self::register( Lean_SMTP_Mailer::OPTION_FROM_EMAIL, 'sanitize_email' );
		self::register( Lean_SMTP_Mailer::OPTION_FROM_NAME, 'sanitize_text_field' );
		self::register( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, 'absint' );
		self::register( Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME, 'absint' );

		self::register( Lean_SMTP_Mailer::OPTION_SMTP_HOST, 'sanitize_text_field' );
		self::register( Lean_SMTP_Mailer::OPTION_SMTP_PORT, 'absint' );
		self::register( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, [ static::class, 'sanitize_encryption' ] );
		self::register( Lean_SMTP_Mailer::OPTION_SMTP_AUTH, 'absint' );
		self::register( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME, 'sanitize_text_field' );
		self::register( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD, [ static::class, 'sanitize_smtp_password' ] );

		self::register( Lean_SMTP_SES::OPTION_REGION, 'sanitize_text_field' );
		self::register( Lean_SMTP_SES::OPTION_ACCESS_KEY, 'sanitize_text_field' );
		self::register( Lean_SMTP_SES::OPTION_SECRET_KEY, [ static::class, 'sanitize_ses_secret' ] );

		self::register( Lean_SMTP_Mailgun::OPTION_DOMAIN, 'sanitize_text_field' );
		self::register( Lean_SMTP_Mailgun::OPTION_REGION, [ static::class, 'sanitize_mailgun_region' ] );
		self::register( Lean_SMTP_Mailgun::OPTION_API_KEY, [ static::class, 'sanitize_mailgun_key' ] );

		self::register( Lean_SMTP_Resend::OPTION_API_KEY, [ static::class, 'sanitize_resend_key' ] );

		self::register( Lean_SMTP_Logger::OPTION_ENABLED, 'absint' );
	}

	private static function register( string $option, callable $callback ): void {
		register_setting( self::GROUP, $option, [ 'sanitize_callback' => self::guard( $option, $callback ) ] );
	}

	/**
	 * Wrap a sanitize callback so a constant-backed setting ignores submissions.
	 *
	 * Necessary as well as tidy: core's options.php calls update_option() with
	 * null for any registered option missing from the POST, so a disabled field
	 * would otherwise wipe the value saved underneath the constant.
	 */
	private static function guard( string $option, callable $callback ): callable {
		return static function ( $value ) use ( $option, $callback ) {
			if ( Lean_SMTP_Config::is_constant( $option ) ) {
				return get_option( $option, '' );
			}
			return call_user_func( $callback, $value );
		};
	}

	public static function sanitize_mailer( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return isset( Lean_SMTP_Mailer::transports()[ $value ] ) ? $value : Lean_SMTP_Mailer::MAILER_SMTP;
	}

	public static function sanitize_encryption( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, [ 'none', 'ssl', 'tls' ], true ) ? $value : 'tls';
	}

	public static function sanitize_mailgun_region( $value ): string {
		return Lean_SMTP_Mailgun::REGION_EU === $value ? Lean_SMTP_Mailgun::REGION_EU : Lean_SMTP_Mailgun::REGION_US;
	}

	/**
	 * Encrypt a submitted SMTP password. A blank submission keeps the stored
	 * value (so re-saving the form doesn't wipe a password the field never
	 * echoes back).
	 */
	public static function sanitize_smtp_password( $value ): string {
		return self::sanitize_secret( $value, Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD );
	}

	public static function sanitize_ses_secret( $value ): string {
		return self::sanitize_secret( $value, Lean_SMTP_SES::OPTION_SECRET_KEY );
	}

	public static function sanitize_mailgun_key( $value ): string {
		return self::sanitize_secret( $value, Lean_SMTP_Mailgun::OPTION_API_KEY );
	}

	public static function sanitize_resend_key( $value ): string {
		return self::sanitize_secret( $value, Lean_SMTP_Resend::OPTION_API_KEY );
	}

	private static function sanitize_secret( $value, string $option ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( $option, '' );
		}
		// WordPress runs the sanitize callback twice on the first save of a new
		// option (update_option, then add_option). The second pass receives our
		// own ciphertext — don't encrypt it again, or the stored value decrypts
		// to a still-encrypted string and every signature/auth fails.
		if ( Lean_SMTP_Crypto::is_encrypted( $value ) ) {
			return $value;
		}
		return Lean_SMTP_Crypto::encrypt( sanitize_text_field( $value ) );
	}

	// -------------------------------------------------------------------------
	// Test email
	// -------------------------------------------------------------------------

	public static function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lean-smtp' ) );
		}
		check_admin_referer( 'lean_smtp_test' );

		$to     = isset( $_POST['lean_smtp_test_to'] ) ? sanitize_email( wp_unslash( (string) $_POST['lean_smtp_test_to'] ) ) : '';
		$result = Lean_SMTP_Mailer::send_test( $to );

		$args = is_wp_error( $result )
			? [
				'lsmtp_test' => 'fail',
				'lsmtp_msg'  => rawurlencode( $result->get_error_message() ),
			]
			: [ 'lsmtp_test' => 'ok' ];

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lean-smtp' ) );
		}
		check_admin_referer( 'lean_smtp_clear_log' );

		Lean_SMTP_Logger::clear();

		wp_safe_redirect( add_query_arg( 'lsmtp_cleared', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Field helpers
	// -------------------------------------------------------------------------

	/**
	 * Explain a locked field, naming the constant so it can be found and changed.
	 */
	private static function lock_note( string $option ): void {
		if ( ! Lean_SMTP_Config::is_constant( $option ) ) {
			return;
		}
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: PHP constant name. */
				esc_html__( 'Set in wp-config.php as %s — change it there.', 'lean-smtp' ),
				'<code>' . esc_html( Lean_SMTP_Config::constant_name( $option ) ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * @param array $args type, class, placeholder, description, value.
	 */
	private static function text_field( string $option, array $args = [] ): void {
		$args = wp_parse_args(
			$args,
			[
				'type'        => 'text',
				'class'       => 'regular-text',
				'placeholder' => '',
				'description' => '',
			]
		);

		$value = array_key_exists( 'value', $args ) ? (string) $args['value'] : (string) Lean_SMTP_Config::get( $option, '' );
		?>
		<input type="<?php echo esc_attr( $args['type'] ); ?>" id="<?php echo esc_attr( $option ); ?>"
			name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $value ); ?>"
			class="<?php echo esc_attr( $args['class'] ); ?>" placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
			autocomplete="off" <?php disabled( Lean_SMTP_Config::is_constant( $option ) ); ?> />
		<?php if ( '' !== $args['description'] ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
		self::lock_note( $option );
	}

	/**
	 * A secret never round-trips to the browser: the field renders empty and a
	 * blank submission keeps whatever is stored.
	 */
	private static function secret_field( string $option, string $description = '' ): void {
		$placeholder = Lean_SMTP_Config::has_secret( $option )
			? __( '••••••••  (leave blank to keep)', 'lean-smtp' )
			: '';
		?>
		<input type="password" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>"
			value="" class="regular-text" autocomplete="new-password"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			<?php disabled( Lean_SMTP_Config::is_constant( $option ) ); ?> />
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
		self::lock_note( $option );
	}

	private static function checkbox_field( string $option, string $label, bool $default = false ): void {
		$locked = Lean_SMTP_Config::is_constant( $option );
		?>
		<?php if ( ! $locked ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0" />
		<?php endif; ?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $option ); ?>" value="1"
				<?php checked( Lean_SMTP_Config::get_bool( $option, $default ) ); ?>
				<?php disabled( $locked ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
		self::lock_note( $option );
	}

	/**
	 * @param array<string, string> $choices Value => label.
	 */
	private static function select_field( string $option, array $choices, string $default, string $description = '' ): void {
		$current = Lean_SMTP_Config::get_string( $option, $default );
		?>
		<select id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>"
			<?php disabled( Lean_SMTP_Config::is_constant( $option ) ); ?>>
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
		self::lock_note( $option );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public static function settings_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lean SMTP Settings', 'lean-smtp' ); ?></h1>

			<?php settings_errors(); ?>
			<?php self::test_notice(); ?>

			<?php if ( isset( $_GET['lsmtp_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Send log cleared.', 'lean-smtp' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_MAILER ); ?>"><?php esc_html_e( 'Mailer', 'lean-smtp' ); ?></label></th>
						<td>
							<?php
							self::select_field(
								Lean_SMTP_Mailer::OPTION_MAILER,
								[
									Lean_SMTP_Mailer::MAILER_SMTP    => __( 'SMTP', 'lean-smtp' ),
									Lean_SMTP_Mailer::MAILER_SES     => __( 'Amazon SES (API)', 'lean-smtp' ),
									Lean_SMTP_Mailer::MAILER_MAILGUN => __( 'Mailgun (API)', 'lean-smtp' ),
									Lean_SMTP_Mailer::MAILER_RESEND  => __( 'Resend (API)', 'lean-smtp' ),
								],
								Lean_SMTP_Mailer::MAILER_SMTP,
								__( 'How mail is sent. Every provider here also offers plain SMTP — the API transports exist for hosts that block outbound mail ports.', 'lean-smtp' )
							);
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'From', 'lean-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FROM_EMAIL ); ?>"><?php esc_html_e( 'From Email', 'lean-smtp' ); ?></label></th>
						<td>
							<?php
							self::text_field(
								Lean_SMTP_Mailer::OPTION_FROM_EMAIL,
								[
									'type'        => 'email',
									'placeholder' => Lean_SMTP_Mailer::default_from_email(),
									'description' => __( 'The address messages are sent from. Your provider must have this address or its domain verified.', 'lean-smtp' ),
								]
							);
							echo '<div style="margin-top:6px;">';
							self::checkbox_field( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, __( 'Force From Email (override the address other plugins set)', 'lean-smtp' ) );
							echo '</div>';
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FROM_NAME ); ?>"><?php esc_html_e( 'From Name', 'lean-smtp' ); ?></label></th>
						<td>
							<?php
							self::text_field(
								Lean_SMTP_Mailer::OPTION_FROM_NAME,
								[ 'placeholder' => get_bloginfo( 'name' ) ]
							);
							echo '<div style="margin-top:6px;">';
							self::checkbox_field( Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME, __( 'Force From Name (override the name other plugins set)', 'lean-smtp' ) );
							echo '</div>';
							?>
						</td>
					</tr>
				</table>

				<div class="lsmtp-section" data-mailer="smtp">
					<h2><?php esc_html_e( 'SMTP', 'lean-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_HOST ); ?>"><?php esc_html_e( 'SMTP Host', 'lean-smtp' ); ?></label></th>
							<td><?php self::text_field( Lean_SMTP_Mailer::OPTION_SMTP_HOST, [ 'placeholder' => 'smtp.example.com' ] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_PORT ); ?>"><?php esc_html_e( 'Port', 'lean-smtp' ); ?></label></th>
							<td>
								<?php
								self::text_field(
									Lean_SMTP_Mailer::OPTION_SMTP_PORT,
									[
										'type'        => 'number',
										'class'       => 'small-text',
										'value'       => (string) Lean_SMTP_Config::get( Lean_SMTP_Mailer::OPTION_SMTP_PORT, 587 ),
										'description' => __( '587 for TLS (STARTTLS), 465 for SSL, 25 for none.', 'lean-smtp' ),
									]
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION ); ?>"><?php esc_html_e( 'Encryption', 'lean-smtp' ); ?></label></th>
							<td>
								<?php
								self::select_field(
									Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION,
									[
										'tls'  => __( 'TLS (STARTTLS)', 'lean-smtp' ),
										'ssl'  => __( 'SSL', 'lean-smtp' ),
										'none' => __( 'None', 'lean-smtp' ),
									],
									'tls'
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Authentication', 'lean-smtp' ); ?></th>
							<td><?php self::checkbox_field( Lean_SMTP_Mailer::OPTION_SMTP_AUTH, __( 'Use a username and password', 'lean-smtp' ), true ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ); ?>"><?php esc_html_e( 'Username', 'lean-smtp' ); ?></label></th>
							<td><?php self::text_field( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD ); ?>"><?php esc_html_e( 'Password', 'lean-smtp' ); ?></label></th>
							<td><?php self::secret_field( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD, __( 'Stored encrypted. Leave blank to keep the current password.', 'lean-smtp' ) ); ?></td>
						</tr>
					</table>
				</div>

				<div class="lsmtp-section" data-mailer="ses">
					<h2><?php esc_html_e( 'Amazon SES', 'lean-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_SES::OPTION_REGION ); ?>"><?php esc_html_e( 'Region', 'lean-smtp' ); ?></label></th>
							<td>
								<?php
								self::text_field(
									Lean_SMTP_SES::OPTION_REGION,
									[
										'placeholder' => 'us-east-1',
										'description' => __( 'The AWS region your SES identity lives in, e.g. us-east-1.', 'lean-smtp' ),
									]
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_SES::OPTION_ACCESS_KEY ); ?>"><?php esc_html_e( 'Access Key ID', 'lean-smtp' ); ?></label></th>
							<td>
								<?php
								self::text_field(
									Lean_SMTP_SES::OPTION_ACCESS_KEY,
									[
										'placeholder' => 'AKIA…',
										'description' => __( 'An IAM access key whose only permission is ses:SendRawEmail / ses:SendEmail.', 'lean-smtp' ),
									]
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_SES::OPTION_SECRET_KEY ); ?>"><?php esc_html_e( 'Secret Access Key', 'lean-smtp' ); ?></label></th>
							<td><?php self::secret_field( Lean_SMTP_SES::OPTION_SECRET_KEY, __( 'Stored encrypted. Leave blank to keep the current key.', 'lean-smtp' ) ); ?></td>
						</tr>
					</table>
				</div>

				<div class="lsmtp-section" data-mailer="mailgun">
					<h2><?php esc_html_e( 'Mailgun', 'lean-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailgun::OPTION_DOMAIN ); ?>"><?php esc_html_e( 'Sending Domain', 'lean-smtp' ); ?></label></th>
							<td>
								<?php
								self::text_field(
									Lean_SMTP_Mailgun::OPTION_DOMAIN,
									[
										'placeholder' => 'mg.example.com',
										'description' => __( 'The verified domain in your Mailgun account, exactly as it appears there.', 'lean-smtp' ),
									]
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailgun::OPTION_REGION ); ?>"><?php esc_html_e( 'Region', 'lean-smtp' ); ?></label></th>
							<td>
								<?php
								self::select_field(
									Lean_SMTP_Mailgun::OPTION_REGION,
									[
										Lean_SMTP_Mailgun::REGION_US => __( 'US', 'lean-smtp' ),
										Lean_SMTP_Mailgun::REGION_EU => __( 'EU', 'lean-smtp' ),
									],
									Lean_SMTP_Mailgun::REGION_US,
									__( 'Mailgun runs separate US and EU stacks; a key from one is not valid against the other.', 'lean-smtp' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Mailgun::OPTION_API_KEY ); ?>"><?php esc_html_e( 'API Key', 'lean-smtp' ); ?></label></th>
							<td><?php self::secret_field( Lean_SMTP_Mailgun::OPTION_API_KEY, __( 'A Mailgun sending API key. Stored encrypted; leave blank to keep the current key.', 'lean-smtp' ) ); ?></td>
						</tr>
					</table>
				</div>

				<div class="lsmtp-section" data-mailer="resend">
					<h2><?php esc_html_e( 'Resend', 'lean-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( Lean_SMTP_Resend::OPTION_API_KEY ); ?>"><?php esc_html_e( 'API Key', 'lean-smtp' ); ?></label></th>
							<td><?php self::secret_field( Lean_SMTP_Resend::OPTION_API_KEY, __( 'A Resend API key with send permission (re_…). Stored encrypted; leave blank to keep the current key.', 'lean-smtp' ) ); ?></td>
						</tr>
					</table>
				</div>

				<h2><?php esc_html_e( 'Logging', 'lean-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send Log', 'lean-smtp' ); ?></th>
						<td>
							<?php
							self::checkbox_field(
								Lean_SMTP_Logger::OPTION_ENABLED,
								sprintf(
									/* translators: %d: number of rows retained. */
									__( 'Record the last %d send attempts (recipient, subject, result).', 'lean-smtp' ),
									(int) Lean_SMTP_Logger::MAX_ROWS
								)
							);
							?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_test_form(); ?>
			<?php self::render_log(); ?>
		</div>

		<script>
			( function () {
				var select = document.getElementById( <?php echo wp_json_encode( Lean_SMTP_Mailer::OPTION_MAILER ); ?> );
				if ( ! select ) { return; }
				function sync() {
					document.querySelectorAll( '.lsmtp-section' ).forEach( function ( el ) {
						el.style.display = ( el.getAttribute( 'data-mailer' ) === select.value ) ? '' : 'none';
					} );
				}
				select.addEventListener( 'change', sync );
				sync();
			}() );
		</script>
		<?php
	}

	private static function test_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['lsmtp_test'] ) ) {
			return;
		}
		if ( 'ok' === $_GET['lsmtp_test'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent successfully.', 'lean-smtp' ) . '</p></div>';
		} else {
			$msg = isset( $_GET['lsmtp_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lsmtp_msg'] ) ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Test email failed.', 'lean-smtp' )
				. ( '' !== $msg ? ' ' . esc_html( $msg ) : '' )
				. '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private static function render_test_form(): void {
		?>
		<h2><?php esc_html_e( 'Send a Test Email', 'lean-smtp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Save your settings first, then send a test with the current configuration.', 'lean-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="lean_smtp_test" />
			<?php wp_nonce_field( 'lean_smtp_test' ); ?>
			<input type="email" name="lean_smtp_test_to" class="regular-text" required
				value="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
			<?php submit_button( __( 'Send Test Email', 'lean-smtp' ), 'secondary', 'submit', false ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'On the command line: wp lean-smtp test, or wp lean-smtp status to see the configuration in force.', 'lean-smtp' ); ?></p>
		<?php
	}

	private static function render_log(): void {
		if ( ! Lean_SMTP_Logger::enabled() ) {
			return;
		}

		$rows = Lean_SMTP_Logger::recent( 25 );
		?>
		<h2><?php esc_html_e( 'Recent Sends', 'lean-smtp' ); ?></h2>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No mail has been logged yet.', 'lean-smtp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'lean-smtp' ); ?></th>
						<th><?php esc_html_e( 'Mailer', 'lean-smtp' ); ?></th>
						<th><?php esc_html_e( 'To', 'lean-smtp' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'lean-smtp' ); ?></th>
						<th><?php esc_html_e( 'Result', 'lean-smtp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row->sent_at ); ?></td>
						<td><?php echo esc_html( strtoupper( (string) $row->mailer ) ); ?></td>
						<td><?php echo esc_html( (string) $row->to_email ); ?></td>
						<td><?php echo esc_html( (string) $row->subject ); ?></td>
						<td>
							<?php if ( 'sent' === $row->status ) : ?>
								<span style="color:green;">&#10004; <?php esc_html_e( 'Sent', 'lean-smtp' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;" title="<?php echo esc_attr( (string) $row->error ); ?>">&#10008; <?php esc_html_e( 'Failed', 'lean-smtp' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<input type="hidden" name="action" value="lean_smtp_clear_log" />
				<?php wp_nonce_field( 'lean_smtp_clear_log' ); ?>
				<?php submit_button( __( 'Clear Log', 'lean-smtp' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
