<?php
/**
 * Lean SMTP → Email Log.
 *
 * Owns the screen the send log is read on: the submenu entry, the per-page
 * screen option, the delete handlers, and the page chrome around
 * Lean_SMTP_Log_Table.
 *
 * The table class itself is required from load() rather than from the plugin
 * bootstrap — see the note at the top of class-lean-smtp-log-table.php.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_SMTP_Log_Page {

	const PAGE = 'lean-smtp-log';

	/** Nonce action for a single-row delete; the row id is appended. */
	const DELETE_NONCE = 'lean_smtp_delete_log_';

	/**
	 * The per-page screen option, and its default. Held here rather than on the
	 * table: init() names it while registering the save filter, and that runs on
	 * every admin request, long before the table class is loaded.
	 */
	const PER_PAGE_OPTION = 'lean_smtp_log_per_page';
	const PER_PAGE        = 20;

	/**
	 * Hook suffix of this screen, so assets and the load- hook attach nowhere else.
	 */
	private static string $hook_suffix = '';

	/**
	 * Built during load() — core needs the table constructed before the screen is
	 * rendered for the per-page option and column headers to take effect.
	 */
	private static ?Lean_SMTP_Log_Table $table = null;

	public static function init(): void {
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, [ static::class, 'save_per_page' ], 10, 3 );
		add_action( 'admin_post_lean_smtp_clear_log', [ static::class, 'handle_clear_log' ] );
	}

	/**
	 * Add the submenu under the plugin's top-level menu.
	 *
	 * @param string $parent Parent menu slug.
	 * @return string The hook suffix, for the caller's asset check.
	 */
	public static function register( string $parent ): string {
		$hook = (string) add_submenu_page(
			$parent,
			__( 'Email Log', 'lean-smtp' ),
			__( 'Email Log', 'lean-smtp' ),
			'manage_options',
			self::PAGE,
			[ static::class, 'render' ]
		);

		self::$hook_suffix = $hook;

		if ( '' !== $hook ) {
			add_action( "load-{$hook}", [ static::class, 'load' ] );
		}

		return $hook;
	}

	public static function url( array $args = [] ): string {
		return Lean_SMTP_Settings::url( self::PAGE, $args );
	}

	/**
	 * @param mixed  $status Value to store, or false to fall through to core.
	 * @param string $option Screen-option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_per_page( $status, string $option, $value ) {
		$value = absint( $value );

		return ( $value > 0 && $value <= 500 ) ? $value : $status;
	}

	// -------------------------------------------------------------------------
	// Screen setup
	// -------------------------------------------------------------------------

	public static function load(): void {
		require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-log-table.php';

		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Entries per page', 'lean-smtp' ),
				'default' => self::PER_PAGE,
				'option'  => self::PER_PAGE_OPTION,
			]
		);

		self::handle_actions();

		self::$table = new Lean_SMTP_Log_Table();
		self::$table->prepare_items();
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	/**
	 * The filter and paging state, carried across a delete so you land back where
	 * you were rather than on page one of an unfiltered list.
	 *
	 * @return array<string, string>
	 */
	public static function state(): array {
		$state = [];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the list's own filter and paging state to put it back on the URL.
		foreach ( [ 'status', 's', 'paged', 'order' ] as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] ) {
				$state[ $key ] = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $state;
	}

	/**
	 * The action asked for, from either end of the bulk-action form.
	 */
	private static function requested_action(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the action only selects which branch below verifies its own nonce.
		foreach ( [ 'lsmtp_action', 'action', 'action2' ] as $key ) {
			$value = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( (string) $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				return $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * Run whatever the request asked for before the table is built, so the list
	 * that renders is the list as it now stands.
	 */
	private static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		switch ( self::requested_action() ) {
			case 'delete-entry':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below, against a nonce naming this id.
				$id = isset( $_GET['log'] ) ? absint( wp_unslash( $_GET['log'] ) ) : 0;
				check_admin_referer( self::DELETE_NONCE . $id );

				self::redirect_deleted( Lean_SMTP_Logger::delete( [ $id ] ) );
				break;

			case 'delete':
				check_admin_referer( 'bulk-' . Lean_SMTP_Log_Table::PLURAL );

				$ids = isset( $_REQUEST['log'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['log'] ) ) : [];

				self::redirect_deleted( Lean_SMTP_Logger::delete( $ids ) );
				break;
		}
	}

	/**
	 * Back to the list, keeping the filter, with a count to report.
	 */
	private static function redirect_deleted( int $deleted ): void {
		$args = self::state();
		unset( $args['paged'] );

		wp_safe_redirect( self::url( [ 'lsmtp_deleted' => $deleted ] + $args ) );
		exit;
	}

	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lean-smtp' ) );
		}
		check_admin_referer( 'lean_smtp_clear_log' );

		Lean_SMTP_Logger::clear();

		wp_safe_redirect( self::url( [ 'lsmtp_cleared' => '1' ] ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, and only decides which confirmation to print.
		if ( isset( $_GET['lsmtp_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Send log cleared.', 'lean-smtp' )
				. '</p></div>';
		}

		$deleted = isset( $_GET['lsmtp_deleted'] ) ? absint( wp_unslash( $_GET['lsmtp_deleted'] ) ) : 0;

		// Applying Delete with nothing ticked deletes nothing; don't report it.
		if ( $deleted > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of log entries deleted. */
						_n( '%s log entry deleted.', '%s log entries deleted.', $deleted, 'lean-smtp' ),
						number_format_i18n( $deleted )
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Logging off is the usual reason this screen is empty, and it is fixed on
	 * the other page — so say so rather than leaving a bare "no items".
	 */
	private static function disabled_notice(): void {
		if ( Lean_SMTP_Logger::enabled() ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'The send log is off, so nothing new is being recorded.', 'lean-smtp' ); ?>
				<a href="<?php echo esc_url( Lean_SMTP_Settings::url() ); ?>"><?php esc_html_e( 'Turn it on in Settings', 'lean-smtp' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function render(): void {
		if ( null === self::$table ) {
			// Only reachable if something rendered the page without the load- hook.
			self::load();
		}
		?>
		<div class="wrap lsmtp-wrap lsmtp-log-wrap">
			<?php
			Lean_SMTP_Settings::render_header();
			self::notices();
			self::disabled_notice();

			self::$table->views();
			?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php
				$status = Lean_SMTP_Log_Table::current_status();
				if ( '' !== $status ) :
					?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
					<?php
				endif;

				self::$table->search_box( __( 'Search Log', 'lean-smtp' ), 'lean-smtp-log' );
				self::$table->display();
				?>
			</form>

			<?php if ( self::$table->has_items() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lsmtp-clear-form">
					<input type="hidden" name="action" value="lean_smtp_clear_log" />
					<?php wp_nonce_field( 'lean_smtp_clear_log' ); ?>
					<?php submit_button( __( 'Clear Log', 'lean-smtp' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
