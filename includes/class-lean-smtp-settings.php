<?php
/**
 * Admin settings page: transport selection, From identity, SMTP / SES
 * credentials, a test-email button, and the send log viewer.
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

	public static function register_settings(): void {
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_MAILER, [ static::class, 'sanitize_mailer' ] );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_FROM_EMAIL, 'sanitize_email' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_FROM_NAME, 'sanitize_text_field' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, 'absint' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME, 'absint' );

		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_SMTP_HOST, 'sanitize_text_field' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_SMTP_PORT, 'absint' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, [ static::class, 'sanitize_encryption' ] );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_SMTP_AUTH, 'absint' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_SMTP_USERNAME, 'sanitize_text_field' );
		register_setting( self::GROUP, Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD, [ static::class, 'sanitize_smtp_password' ] );

		register_setting( self::GROUP, Lean_SMTP_SES::OPTION_REGION, 'sanitize_text_field' );
		register_setting( self::GROUP, Lean_SMTP_SES::OPTION_ACCESS_KEY, 'sanitize_text_field' );
		register_setting( self::GROUP, Lean_SMTP_SES::OPTION_SECRET_KEY, [ static::class, 'sanitize_ses_secret' ] );

		register_setting( self::GROUP, Lean_SMTP_Logger::OPTION_ENABLED, 'absint' );
	}

	public static function sanitize_mailer( $value ): string {
		return Lean_SMTP_Mailer::MAILER_SES === $value ? Lean_SMTP_Mailer::MAILER_SES : Lean_SMTP_Mailer::MAILER_SMTP;
	}

	public static function sanitize_encryption( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, [ 'none', 'ssl', 'tls' ], true ) ? $value : 'tls';
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

	private static function sanitize_secret( $value, string $option ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( $option, '' );
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
	// Page
	// -------------------------------------------------------------------------

	public static function settings_page(): void {
		$mailer     = Lean_SMTP_Mailer::mailer();
		$has_pass   = '' !== (string) get_option( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD, '' );
		$has_secret = '' !== (string) get_option( Lean_SMTP_SES::OPTION_SECRET_KEY, '' );
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
						<th scope="row"><label for="lean_smtp_mailer"><?php esc_html_e( 'Mailer', 'lean-smtp' ); ?></label></th>
						<td>
							<select id="lean_smtp_mailer" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_MAILER ); ?>">
								<option value="smtp" <?php selected( $mailer, Lean_SMTP_Mailer::MAILER_SMTP ); ?>><?php esc_html_e( 'SMTP', 'lean-smtp' ); ?></option>
								<option value="ses" <?php selected( $mailer, Lean_SMTP_Mailer::MAILER_SES ); ?>><?php esc_html_e( 'Amazon SES (API)', 'lean-smtp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How mail is sent. To use SES over SMTP instead of the API, choose SMTP and set the host to email-smtp.{region}.amazonaws.com.', 'lean-smtp' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'From', 'lean-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lean_smtp_from_email"><?php esc_html_e( 'From Email', 'lean-smtp' ); ?></label></th>
						<td>
							<input type="email" id="lean_smtp_from_email" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FROM_EMAIL ); ?>"
								value="<?php echo esc_attr( get_option( Lean_SMTP_Mailer::OPTION_FROM_EMAIL, '' ) ); ?>"
								class="regular-text" placeholder="<?php echo esc_attr( Lean_SMTP_Mailer::default_from_email() ); ?>" />
							<p class="description"><?php esc_html_e( 'The address messages are sent from. For SES this must be a verified identity in your account.', 'lean-smtp' ); ?></p>
							<label style="display:block;margin-top:6px;">
								<input type="hidden" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL ); ?>" value="1" <?php checked( '1', (string) get_option( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, '0' ) ); ?> />
								<?php esc_html_e( 'Force From Email (override the address other plugins set)', 'lean-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lean_smtp_from_name"><?php esc_html_e( 'From Name', 'lean-smtp' ); ?></label></th>
						<td>
							<input type="text" id="lean_smtp_from_name" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FROM_NAME ); ?>"
								value="<?php echo esc_attr( get_option( Lean_SMTP_Mailer::OPTION_FROM_NAME, '' ) ); ?>"
								class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<label style="display:block;margin-top:6px;">
								<input type="hidden" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME ); ?>" value="1" <?php checked( '1', (string) get_option( Lean_SMTP_Mailer::OPTION_FORCE_FROM_NAME, '0' ) ); ?> />
								<?php esc_html_e( 'Force From Name (override the name other plugins set)', 'lean-smtp' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<div class="lsmtp-section" data-mailer="smtp">
					<h2><?php esc_html_e( 'SMTP', 'lean-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="lean_smtp_smtp_host"><?php esc_html_e( 'SMTP Host', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="text" id="lean_smtp_smtp_host" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_HOST ); ?>"
									value="<?php echo esc_attr( get_option( Lean_SMTP_Mailer::OPTION_SMTP_HOST, '' ) ); ?>"
									class="regular-text" placeholder="smtp.example.com" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lean_smtp_smtp_port"><?php esc_html_e( 'Port', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="number" id="lean_smtp_smtp_port" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_PORT ); ?>"
									value="<?php echo esc_attr( (string) get_option( Lean_SMTP_Mailer::OPTION_SMTP_PORT, 587 ) ); ?>"
									class="small-text" min="1" max="65535" />
								<p class="description"><?php esc_html_e( '587 for TLS (STARTTLS), 465 for SSL, 25 for none.', 'lean-smtp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lean_smtp_smtp_encryption"><?php esc_html_e( 'Encryption', 'lean-smtp' ); ?></label></th>
							<td>
								<?php $enc = (string) get_option( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, 'tls' ); ?>
								<select id="lean_smtp_smtp_encryption" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION ); ?>">
									<option value="tls" <?php selected( $enc, 'tls' ); ?>><?php esc_html_e( 'TLS (STARTTLS)', 'lean-smtp' ); ?></option>
									<option value="ssl" <?php selected( $enc, 'ssl' ); ?>><?php esc_html_e( 'SSL', 'lean-smtp' ); ?></option>
									<option value="none" <?php selected( $enc, 'none' ); ?>><?php esc_html_e( 'None', 'lean-smtp' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Authentication', 'lean-smtp' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_AUTH ); ?>" value="0" />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_AUTH ); ?>" value="1" <?php checked( '1', (string) get_option( Lean_SMTP_Mailer::OPTION_SMTP_AUTH, '1' ) ); ?> />
									<?php esc_html_e( 'Use a username and password', 'lean-smtp' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lean_smtp_smtp_username"><?php esc_html_e( 'Username', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="text" id="lean_smtp_smtp_username" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ); ?>"
									value="<?php echo esc_attr( get_option( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME, '' ) ); ?>"
									class="regular-text" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lean_smtp_smtp_password"><?php esc_html_e( 'Password', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="password" id="lean_smtp_smtp_password" name="<?php echo esc_attr( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD ); ?>"
									value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo $has_pass ? esc_attr__( '••••••••  (leave blank to keep)', 'lean-smtp' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the current password.', 'lean-smtp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="lsmtp-section" data-mailer="ses">
					<h2><?php esc_html_e( 'Amazon SES', 'lean-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="lean_smtp_ses_region"><?php esc_html_e( 'Region', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="text" id="lean_smtp_ses_region" name="<?php echo esc_attr( Lean_SMTP_SES::OPTION_REGION ); ?>"
									value="<?php echo esc_attr( get_option( Lean_SMTP_SES::OPTION_REGION, '' ) ); ?>"
									class="regular-text" placeholder="us-east-1" />
								<p class="description"><?php esc_html_e( 'The AWS region your SES identity lives in, e.g. us-east-1.', 'lean-smtp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lean_smtp_ses_access_key"><?php esc_html_e( 'Access Key ID', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="text" id="lean_smtp_ses_access_key" name="<?php echo esc_attr( Lean_SMTP_SES::OPTION_ACCESS_KEY ); ?>"
									value="<?php echo esc_attr( get_option( Lean_SMTP_SES::OPTION_ACCESS_KEY, '' ) ); ?>"
									class="regular-text" autocomplete="off" placeholder="AKIA…" />
								<p class="description"><?php esc_html_e( 'An IAM access key whose only permission is ses:SendRawEmail / ses:SendEmail.', 'lean-smtp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lean_smtp_ses_secret_key"><?php esc_html_e( 'Secret Access Key', 'lean-smtp' ); ?></label></th>
							<td>
								<input type="password" id="lean_smtp_ses_secret_key" name="<?php echo esc_attr( Lean_SMTP_SES::OPTION_SECRET_KEY ); ?>"
									value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo $has_secret ? esc_attr__( '••••••••  (leave blank to keep)', 'lean-smtp' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the current key.', 'lean-smtp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<h2><?php esc_html_e( 'Logging', 'lean-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send Log', 'lean-smtp' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Lean_SMTP_Logger::OPTION_ENABLED ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Lean_SMTP_Logger::OPTION_ENABLED ); ?>" value="1" <?php checked( '1', (string) get_option( Lean_SMTP_Logger::OPTION_ENABLED, '0' ) ); ?> />
								<?php
								/* translators: %d: number of rows retained. */
								printf( esc_html__( 'Record the last %d send attempts (recipient, subject, result).', 'lean-smtp' ), (int) Lean_SMTP_Logger::MAX_ROWS );
								?>
							</label>
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
				var select = document.getElementById( 'lean_smtp_mailer' );
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
