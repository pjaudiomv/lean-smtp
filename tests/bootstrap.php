<?php
/**
 * PHPUnit bootstrap file for Lean SMTP plugin tests.
 *
 * Uses wp-phpunit/wp-phpunit (installed via Composer) as the test library.
 * WordPress core must be downloaded to WP_CORE_DIR (default: /tmp/wordpress).
 */

// Point wp-phpunit at our config file.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$lean_smtp_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( "{$lean_smtp_tests_dir}/includes/functions.php" ) ) {
	echo 'Could not find wp-phpunit. Run: composer install' . PHP_EOL;
	exit( 1 );
}

require_once "{$lean_smtp_tests_dir}/includes/functions.php";

// Stand in for wp-config.php constants so the override path in
// Lean_SMTP_Config is exercised under test. The SMTP username and password are
// pinned because nothing else in the suite depends on their stored values.
define( 'LEAN_SMTP_SMTP_USERNAME', 'pinned-user' );
define( 'LEAN_SMTP_SMTP_PASSWORD', 'pinned-pass' );

/**
 * Load the plugin being tested.
 */
function lean_smtp_tests_manually_load_plugin() {
	require dirname( __DIR__ ) . '/lean-smtp.php';
}
tests_add_filter( 'muplugins_loaded', 'lean_smtp_tests_manually_load_plugin' );

require "{$lean_smtp_tests_dir}/includes/bootstrap.php";
