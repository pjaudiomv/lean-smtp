<?php
/**
 * Admin settings page: transport selection, From identity, SMTP / SES
 * credentials, a test-email button, and the send log viewer.
 *
 * @package simple-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Simple_SMTP_Settings {

	const PAGE  = 'simple-smtp';
	const GROUP = 'simple-smtp-group';

	public static function init(): void {
		add_action( 'admin_menu', [ static::class, 'admin_menu' ] );
		add_action( 'admin_init', [ static::class, 'register_settings' ] );
		add_action( 'admin_post_simple_smtp_test', [ static::class, 'handle_test' ] );
		add_action( 'admin_post_simple_smtp_clear_log', [ static::class, 'handle_clear_log' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( SIMPLE_SMTP_FILE ), [ static::class, 'settings_link' ] );
	}

	public static function settings_link( array $links ): array {
		$url     = admin_url( 'options-general.php?page=' . self::PAGE );
		$links[] = "<a href='{$url}'>" . esc_html__( 'Settings', 'simple-smtp' ) . '</a>';
		return $links;
	}

	public static function admin_menu(): void {
		add_options_page(
			__( 'Simple SMTP Settings', 'simple-smtp' ),
			__( 'Simple SMTP', 'simple-smtp' ),
			'manage_options',
			self::PAGE,
			[ static::class, 'settings_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_MAILER, [ static::class, 'sanitize_mailer' ] );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_FROM_EMAIL, 'sanitize_email' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_FROM_NAME, 'sanitize_text_field' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, 'absint' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_FORCE_FROM_NAME, 'absint' );

		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_SMTP_HOST, 'sanitize_text_field' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_SMTP_PORT, 'absint' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, [ static::class, 'sanitize_encryption' ] );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_SMTP_AUTH, 'absint' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_SMTP_USERNAME, 'sanitize_text_field' );
		register_setting( self::GROUP, Simple_SMTP_Mailer::OPTION_SMTP_PASSWORD, [ static::class, 'sanitize_smtp_password' ] );

		register_setting( self::GROUP, Simple_SMTP_SES::OPTION_REGION, 'sanitize_text_field' );
		register_setting( self::GROUP, Simple_SMTP_SES::OPTION_ACCESS_KEY, 'sanitize_text_field' );
		register_setting( self::GROUP, Simple_SMTP_SES::OPTION_SECRET_KEY, [ static::class, 'sanitize_ses_secret' ] );

		register_setting( self::GROUP, Simple_SMTP_Logger::OPTION_ENABLED, 'absint' );
	}

	public static function sanitize_mailer( $value ): string {
		return Simple_SMTP_Mailer::MAILER_SES === $value ? Simple_SMTP_Mailer::MAILER_SES : Simple_SMTP_Mailer::MAILER_SMTP;
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
		return self::sanitize_secret( $value, Simple_SMTP_Mailer::OPTION_SMTP_PASSWORD );
	}

	public static function sanitize_ses_secret( $value ): string {
		return self::sanitize_secret( $value, Simple_SMTP_SES::OPTION_SECRET_KEY );
	}

	private static function sanitize_secret( $value, string $option ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( $option, '' );
		}
		return Simple_SMTP_Crypto::encrypt( sanitize_text_field( $value ) );
	}

	// -------------------------------------------------------------------------
	// Test email
	// -------------------------------------------------------------------------

	public static function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-smtp' ) );
		}
		check_admin_referer( 'simple_smtp_test' );

		$to     = isset( $_POST['simple_smtp_test_to'] ) ? sanitize_email( wp_unslash( (string) $_POST['simple_smtp_test_to'] ) ) : '';
		$result = Simple_SMTP_Mailer::send_test( $to );

		$args = is_wp_error( $result )
			? [
				'ssmtp_test' => 'fail',
				'ssmtp_msg'  => rawurlencode( $result->get_error_message() ),
			]
			: [ 'ssmtp_test' => 'ok' ];

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-smtp' ) );
		}
		check_admin_referer( 'simple_smtp_clear_log' );

		Simple_SMTP_Logger::clear();

		wp_safe_redirect( add_query_arg( 'ssmtp_cleared', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public static function settings_page(): void {
		$mailer     = Simple_SMTP_Mailer::mailer();
		$has_pass   = '' !== (string) get_option( Simple_SMTP_Mailer::OPTION_SMTP_PASSWORD, '' );
		$has_secret = '' !== (string) get_option( Simple_SMTP_SES::OPTION_SECRET_KEY, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple SMTP Settings', 'simple-smtp' ); ?></h1>

			<?php settings_errors(); ?>
			<?php self::test_notice(); ?>

			<?php if ( isset( $_GET['ssmtp_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Send log cleared.', 'simple-smtp' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="simple_smtp_mailer"><?php esc_html_e( 'Mailer', 'simple-smtp' ); ?></label></th>
						<td>
							<select id="simple_smtp_mailer" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_MAILER ); ?>">
								<option value="smtp" <?php selected( $mailer, Simple_SMTP_Mailer::MAILER_SMTP ); ?>><?php esc_html_e( 'SMTP', 'simple-smtp' ); ?></option>
								<option value="ses" <?php selected( $mailer, Simple_SMTP_Mailer::MAILER_SES ); ?>><?php esc_html_e( 'Amazon SES (API)', 'simple-smtp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How mail is sent. To use SES over SMTP instead of the API, choose SMTP and set the host to email-smtp.{region}.amazonaws.com.', 'simple-smtp' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'From', 'simple-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="simple_smtp_from_email"><?php esc_html_e( 'From Email', 'simple-smtp' ); ?></label></th>
						<td>
							<input type="email" id="simple_smtp_from_email" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_FROM_EMAIL ); ?>"
								value="<?php echo esc_attr( get_option( Simple_SMTP_Mailer::OPTION_FROM_EMAIL, '' ) ); ?>"
								class="regular-text" placeholder="<?php echo esc_attr( Simple_SMTP_Mailer::default_from_email() ); ?>" />
							<p class="description"><?php esc_html_e( 'The address messages are sent from. For SES this must be a verified identity in your account.', 'simple-smtp' ); ?></p>
							<label style="display:block;margin-top:6px;">
								<input type="hidden" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_FORCE_FROM_MAIL ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_FORCE_FROM_MAIL ); ?>" value="1" <?php checked( '1', (string) get_option( Simple_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, '0' ) ); ?> />
								<?php esc_html_e( 'Force From Email (override the address other plugins set)', 'simple-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="simple_smtp_from_name"><?php esc_html_e( 'From Name', 'simple-smtp' ); ?></label></th>
						<td>
							<input type="text" id="simple_smtp_from_name" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_FROM_NAME ); ?>"
								value="<?php echo esc_attr( get_option( Simple_SMTP_Mailer::OPTION_FROM_NAME, '' ) ); ?>"
								class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<label style="display:block;margin-top:6px;">
								<input type="hidden" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_FORCE_FROM_NAME ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_FORCE_FROM_NAME ); ?>" value="1" <?php checked( '1', (string) get_option( Simple_SMTP_Mailer::OPTION_FORCE_FROM_NAME, '0' ) ); ?> />
								<?php esc_html_e( 'Force From Name (override the name other plugins set)', 'simple-smtp' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<div class="ssmtp-section" data-mailer="smtp">
					<h2><?php esc_html_e( 'SMTP', 'simple-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="simple_smtp_smtp_host"><?php esc_html_e( 'SMTP Host', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="text" id="simple_smtp_smtp_host" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_HOST ); ?>"
									value="<?php echo esc_attr( get_option( Simple_SMTP_Mailer::OPTION_SMTP_HOST, '' ) ); ?>"
									class="regular-text" placeholder="smtp.example.com" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="simple_smtp_smtp_port"><?php esc_html_e( 'Port', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="number" id="simple_smtp_smtp_port" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_PORT ); ?>"
									value="<?php echo esc_attr( (string) get_option( Simple_SMTP_Mailer::OPTION_SMTP_PORT, 587 ) ); ?>"
									class="small-text" min="1" max="65535" />
								<p class="description"><?php esc_html_e( '587 for TLS (STARTTLS), 465 for SSL, 25 for none.', 'simple-smtp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="simple_smtp_smtp_encryption"><?php esc_html_e( 'Encryption', 'simple-smtp' ); ?></label></th>
							<td>
								<?php $enc = (string) get_option( Simple_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, 'tls' ); ?>
								<select id="simple_smtp_smtp_encryption" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_ENCRYPTION ); ?>">
									<option value="tls" <?php selected( $enc, 'tls' ); ?>><?php esc_html_e( 'TLS (STARTTLS)', 'simple-smtp' ); ?></option>
									<option value="ssl" <?php selected( $enc, 'ssl' ); ?>><?php esc_html_e( 'SSL', 'simple-smtp' ); ?></option>
									<option value="none" <?php selected( $enc, 'none' ); ?>><?php esc_html_e( 'None', 'simple-smtp' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Authentication', 'simple-smtp' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_AUTH ); ?>" value="0" />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_AUTH ); ?>" value="1" <?php checked( '1', (string) get_option( Simple_SMTP_Mailer::OPTION_SMTP_AUTH, '1' ) ); ?> />
									<?php esc_html_e( 'Use a username and password', 'simple-smtp' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="simple_smtp_smtp_username"><?php esc_html_e( 'Username', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="text" id="simple_smtp_smtp_username" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_USERNAME ); ?>"
									value="<?php echo esc_attr( get_option( Simple_SMTP_Mailer::OPTION_SMTP_USERNAME, '' ) ); ?>"
									class="regular-text" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="simple_smtp_smtp_password"><?php esc_html_e( 'Password', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="password" id="simple_smtp_smtp_password" name="<?php echo esc_attr( Simple_SMTP_Mailer::OPTION_SMTP_PASSWORD ); ?>"
									value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo $has_pass ? esc_attr__( '••••••••  (leave blank to keep)', 'simple-smtp' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the current password.', 'simple-smtp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ssmtp-section" data-mailer="ses">
					<h2><?php esc_html_e( 'Amazon SES', 'simple-smtp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="simple_smtp_ses_region"><?php esc_html_e( 'Region', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="text" id="simple_smtp_ses_region" name="<?php echo esc_attr( Simple_SMTP_SES::OPTION_REGION ); ?>"
									value="<?php echo esc_attr( get_option( Simple_SMTP_SES::OPTION_REGION, '' ) ); ?>"
									class="regular-text" placeholder="us-east-1" />
								<p class="description"><?php esc_html_e( 'The AWS region your SES identity lives in, e.g. us-east-1.', 'simple-smtp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="simple_smtp_ses_access_key"><?php esc_html_e( 'Access Key ID', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="text" id="simple_smtp_ses_access_key" name="<?php echo esc_attr( Simple_SMTP_SES::OPTION_ACCESS_KEY ); ?>"
									value="<?php echo esc_attr( get_option( Simple_SMTP_SES::OPTION_ACCESS_KEY, '' ) ); ?>"
									class="regular-text" autocomplete="off" placeholder="AKIA…" />
								<p class="description"><?php esc_html_e( 'An IAM access key whose only permission is ses:SendRawEmail / ses:SendEmail.', 'simple-smtp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="simple_smtp_ses_secret_key"><?php esc_html_e( 'Secret Access Key', 'simple-smtp' ); ?></label></th>
							<td>
								<input type="password" id="simple_smtp_ses_secret_key" name="<?php echo esc_attr( Simple_SMTP_SES::OPTION_SECRET_KEY ); ?>"
									value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo $has_secret ? esc_attr__( '••••••••  (leave blank to keep)', 'simple-smtp' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the current key.', 'simple-smtp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<h2><?php esc_html_e( 'Logging', 'simple-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send Log', 'simple-smtp' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Simple_SMTP_Logger::OPTION_ENABLED ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Simple_SMTP_Logger::OPTION_ENABLED ); ?>" value="1" <?php checked( '1', (string) get_option( Simple_SMTP_Logger::OPTION_ENABLED, '0' ) ); ?> />
								<?php
								/* translators: %d: number of rows retained. */
								printf( esc_html__( 'Record the last %d send attempts (recipient, subject, result).', 'simple-smtp' ), (int) Simple_SMTP_Logger::MAX_ROWS );
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
				var select = document.getElementById( 'simple_smtp_mailer' );
				if ( ! select ) { return; }
				function sync() {
					document.querySelectorAll( '.ssmtp-section' ).forEach( function ( el ) {
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
		if ( ! isset( $_GET['ssmtp_test'] ) ) {
			return;
		}
		if ( 'ok' === $_GET['ssmtp_test'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent successfully.', 'simple-smtp' ) . '</p></div>';
		} else {
			$msg = isset( $_GET['ssmtp_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ssmtp_msg'] ) ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Test email failed.', 'simple-smtp' )
				. ( '' !== $msg ? ' ' . esc_html( $msg ) : '' )
				. '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private static function render_test_form(): void {
		?>
		<h2><?php esc_html_e( 'Send a Test Email', 'simple-smtp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Save your settings first, then send a test with the current configuration.', 'simple-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="simple_smtp_test" />
			<?php wp_nonce_field( 'simple_smtp_test' ); ?>
			<input type="email" name="simple_smtp_test_to" class="regular-text" required
				value="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
			<?php submit_button( __( 'Send Test Email', 'simple-smtp' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_log(): void {
		if ( ! Simple_SMTP_Logger::enabled() ) {
			return;
		}

		$rows = Simple_SMTP_Logger::recent( 25 );
		?>
		<h2><?php esc_html_e( 'Recent Sends', 'simple-smtp' ); ?></h2>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No mail has been logged yet.', 'simple-smtp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'simple-smtp' ); ?></th>
						<th><?php esc_html_e( 'Mailer', 'simple-smtp' ); ?></th>
						<th><?php esc_html_e( 'To', 'simple-smtp' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'simple-smtp' ); ?></th>
						<th><?php esc_html_e( 'Result', 'simple-smtp' ); ?></th>
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
								<span style="color:green;">&#10004; <?php esc_html_e( 'Sent', 'simple-smtp' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;" title="<?php echo esc_attr( (string) $row->error ); ?>">&#10008; <?php esc_html_e( 'Failed', 'simple-smtp' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<input type="hidden" name="action" value="simple_smtp_clear_log" />
				<?php wp_nonce_field( 'simple_smtp_clear_log' ); ?>
				<?php submit_button( __( 'Clear Log', 'simple-smtp' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
