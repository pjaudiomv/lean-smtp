<?php
/**
 * Plugin Name: Lean SMTP
 * Plugin URI: https://github.com/pjaudiomv/lean-smtp
 * Description: Routes wp_mail() through an SMTP server or the Amazon SES, Mailgun, or Resend API, with From identity control, wp-config.php overrides, failure alerts, WP-CLI commands, a test-email button, and optional send logging.
 * Version: 0.2.0
 * Author: pjaudiomv
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEAN_SMTP_VERSION', '0.2.0' );
define( 'LEAN_SMTP_FILE', __FILE__ );
define( 'LEAN_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEAN_SMTP_URL', plugin_dir_url( __FILE__ ) );

require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-crypto.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-config.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-logger.php';
require_once LEAN_SMTP_DIR . 'includes/interface-lean-smtp-api-transport.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-ses.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-mailgun.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-resend.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-mailer.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-notices.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-settings.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-cli.php';
}

class Lean_SMTP {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Lean_SMTP_Mailer::init();
		Lean_SMTP_Notices::init();
		Lean_SMTP_Settings::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Lean_SMTP_CLI::register();
		}
	}

	// -------------------------------------------------------------------------
	// Activation / Deactivation
	// -------------------------------------------------------------------------

	public static function activate(): void {
		Lean_SMTP_Logger::create_table();
	}

	public static function deactivate(): void {
		// Nothing to unschedule; the plugin only hooks into wp_mail at runtime.
	}
}

register_activation_hook( __FILE__, [ 'Lean_SMTP', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Lean_SMTP', 'deactivate' ] );
Lean_SMTP::get_instance();
