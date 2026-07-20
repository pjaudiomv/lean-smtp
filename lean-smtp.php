<?php
/**
 * Plugin Name: Lean SMTP
 * Plugin URI: https://github.com/pjaudiomv/lean-smtp
 * Description: Routes wp_mail() through an SMTP server or the Amazon SES API, with From identity control, a test-email button, and optional send logging.
 * Version: 0.1.0
 * Author: pjaudiomv
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEAN_SMTP_VERSION', '0.1.0' );
define( 'LEAN_SMTP_FILE', __FILE__ );
define( 'LEAN_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEAN_SMTP_URL', plugin_dir_url( __FILE__ ) );

require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-crypto.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-logger.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-ses.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-mailer.php';
require_once LEAN_SMTP_DIR . 'includes/class-lean-smtp-settings.php';

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
		Lean_SMTP_Settings::init();
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
