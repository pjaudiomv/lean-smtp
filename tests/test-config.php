<?php
/**
 * Tests for wp-config.php constant overrides.
 *
 * LEAN_SMTP_SMTP_USERNAME and LEAN_SMTP_SMTP_PASSWORD are defined in
 * tests/bootstrap.php, standing in for a site that pins its credentials.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Config extends WP_UnitTestCase {

	public function test_constant_name_is_the_option_uppercased() {
		$this->assertSame( 'LEAN_SMTP_SES_REGION', Lean_SMTP_Config::constant_name( 'lean_smtp_ses_region' ) );
	}

	public function test_undefined_setting_falls_through_to_the_option() {
		$this->assertFalse( Lean_SMTP_Config::is_constant( Lean_SMTP_SES::OPTION_REGION ) );

		update_option( Lean_SMTP_SES::OPTION_REGION, 'eu-west-1' );
		$this->assertSame( 'eu-west-1', Lean_SMTP_Config::get_string( Lean_SMTP_SES::OPTION_REGION ) );
	}

	public function test_default_is_used_when_neither_constant_nor_option_exists() {
		$this->assertSame( 'tls', Lean_SMTP_Config::get_string( Lean_SMTP_Mailer::OPTION_SMTP_ENCRYPTION, 'tls' ) );
	}

	public function test_constant_wins_over_a_stored_option() {
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME, 'database-user' );

		$this->assertTrue( Lean_SMTP_Config::is_constant( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ) );
		$this->assertSame( 'pinned-user', Lean_SMTP_Config::get_string( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME ) );
	}

	public function test_secret_constant_is_read_as_plaintext() {
		// A constant holds the secret in the clear, so it must not be run
		// through the at-rest decryptor.
		$this->assertSame( 'pinned-pass', Lean_SMTP_Config::get_secret( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD ) );
		$this->assertTrue( Lean_SMTP_Config::has_secret( Lean_SMTP_Mailer::OPTION_SMTP_PASSWORD ) );
	}

	public function test_stored_secret_is_decrypted() {
		update_option( Lean_SMTP_SES::OPTION_SECRET_KEY, Lean_SMTP_Crypto::encrypt( 'shhh' ) );

		$this->assertSame( 'shhh', Lean_SMTP_Config::get_secret( Lean_SMTP_SES::OPTION_SECRET_KEY ) );
		$this->assertTrue( Lean_SMTP_Config::has_secret( Lean_SMTP_SES::OPTION_SECRET_KEY ) );
		$this->assertFalse( Lean_SMTP_Config::has_secret( Lean_SMTP_Resend::OPTION_API_KEY ) );
	}

	public function test_booleans_accept_both_option_and_constant_spellings() {
		update_option( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, '1' );
		$this->assertTrue( Lean_SMTP_Config::get_bool( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL ) );

		update_option( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL, '0' );
		$this->assertFalse( Lean_SMTP_Config::get_bool( Lean_SMTP_Mailer::OPTION_FORCE_FROM_MAIL ) );

		delete_option( Lean_SMTP_Mailer::OPTION_SMTP_AUTH );
		$this->assertTrue( Lean_SMTP_Config::get_bool( Lean_SMTP_Mailer::OPTION_SMTP_AUTH, true ) );
	}

	public function test_is_network_mode_is_false_without_a_network_activation() {
		// On a single site, and on multisite where the plugin is not network
		// active (the test harness loads it by require, not by activation), reads
		// and writes stay per-site.
		$this->assertFalse( Lean_SMTP_Config::is_network_mode() );
	}

	public function test_store_and_stored_round_trip_through_site_options() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site storage path; the network path is covered separately.' );
		}

		Lean_SMTP_Config::store( Lean_SMTP_SES::OPTION_REGION, 'ap-south-1' );

		$this->assertSame( 'ap-south-1', get_option( Lean_SMTP_SES::OPTION_REGION ) );
		$this->assertSame( 'ap-south-1', Lean_SMTP_Config::stored( Lean_SMTP_SES::OPTION_REGION ) );

		Lean_SMTP_Config::erase( Lean_SMTP_SES::OPTION_REGION );
		$this->assertFalse( get_option( Lean_SMTP_SES::OPTION_REGION, false ) );
	}

	public function test_network_mode_reads_and_writes_the_network_store() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires the multisite test harness (WP_TESTS_MULTISITE=1).' );
		}

		// Stand in for a network activation: the resolver reads the same option
		// is_plugin_active_for_network() does.
		update_site_option( 'active_sitewide_plugins', [ plugin_basename( LEAN_SMTP_FILE ) => time() ] );
		$this->assertTrue( Lean_SMTP_Config::is_network_mode() );

		Lean_SMTP_Config::store( Lean_SMTP_Mailer::OPTION_FROM_NAME, 'Network Co' );

		// Written to the network store, not this site's options table.
		$this->assertSame( 'Network Co', get_site_option( Lean_SMTP_Mailer::OPTION_FROM_NAME ) );
		$this->assertFalse( get_option( Lean_SMTP_Mailer::OPTION_FROM_NAME, false ) );

		// And read back through the resolver that every setting read goes through.
		$this->assertSame( 'Network Co', Lean_SMTP_Config::get_string( Lean_SMTP_Mailer::OPTION_FROM_NAME ) );

		Lean_SMTP_Config::erase( Lean_SMTP_Mailer::OPTION_FROM_NAME );
		$this->assertFalse( get_site_option( Lean_SMTP_Mailer::OPTION_FROM_NAME, false ) );
	}

	public function test_secret_round_trips_through_the_network_store() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires the multisite test harness (WP_TESTS_MULTISITE=1).' );
		}

		update_site_option( 'active_sitewide_plugins', [ plugin_basename( LEAN_SMTP_FILE ) => time() ] );

		Lean_SMTP_Config::store( Lean_SMTP_SES::OPTION_SECRET_KEY, Lean_SMTP_Crypto::encrypt( 'net-secret' ) );

		$this->assertTrue( Lean_SMTP_Config::has_secret( Lean_SMTP_SES::OPTION_SECRET_KEY ) );
		$this->assertSame( 'net-secret', Lean_SMTP_Config::get_secret( Lean_SMTP_SES::OPTION_SECRET_KEY ) );
	}

	public function test_plugin_infrastructure_constants_are_not_treated_as_settings() {
		// LEAN_SMTP_VERSION is always defined, but there is no such setting —
		// it must never shadow an option.
		$this->assertFalse( Lean_SMTP_Config::is_constant( 'lean_smtp_version' ) );
	}

	public function test_constant_backed_setting_ignores_form_submissions() {
		// Core's options.php writes every registered option on save, so a
		// disabled field would otherwise wipe the value stored underneath the
		// constant. The sanitize guard has to hand the stored value straight
		// back instead.
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME, 'database-user' );
		Lean_SMTP_Settings::register_settings();

		// Core's sanitize_option() passes the option name alongside the value.
		$sanitized = apply_filters(
			'sanitize_option_' . Lean_SMTP_Mailer::OPTION_SMTP_USERNAME,
			'submitted-user',
			Lean_SMTP_Mailer::OPTION_SMTP_USERNAME,
			'submitted-user'
		);

		$this->assertSame( 'database-user', $sanitized );
	}

	public function test_every_setting_has_a_sanitizer_and_a_constant_guard() {
		// Both are registered per setting, so a new setting can only be missed by
		// omission — this is what catches that.
		Lean_SMTP_Settings::register_settings();

		$options    = (array) ( $GLOBALS['new_allowed_options'][ Lean_SMTP_Settings::GROUP ] ?? [] );
		$registered = get_registered_settings();

		$this->assertNotEmpty( $options );

		foreach ( $options as $option ) {
			$this->assertTrue(
				is_callable( $registered[ $option ]['sanitize_callback'] ?? null ),
				"No sanitize_callback registered for {$option}."
			);
			$this->assertSame(
				20,
				has_filter( 'sanitize_option_' . $option, [ 'Lean_SMTP_Settings', 'guard_constant' ] ),
				"No wp-config.php constant guard on {$option}."
			);
		}
	}

	public function test_unpinned_setting_is_sanitized_on_save() {
		// The registered callback dispatches on the option name, so a stale map
		// would silently stop sanitizing a setting.
		Lean_SMTP_Settings::register_settings();

		$sanitized = apply_filters(
			'sanitize_option_' . Lean_SMTP_Mailer::OPTION_MAILER,
			'not-a-transport',
			Lean_SMTP_Mailer::OPTION_MAILER,
			'not-a-transport'
		);

		$this->assertSame( Lean_SMTP_Mailer::MAILER_SMTP, $sanitized );
	}

	public function test_smtp_transport_uses_pinned_credentials() {
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_HOST, 'smtp.example.com' );
		update_option( Lean_SMTP_Mailer::OPTION_SMTP_USERNAME, 'database-user' );

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		$phpmailer = new PHPMailer\PHPMailer\PHPMailer();

		Lean_SMTP_Mailer::configure_smtp( $phpmailer );

		$this->assertSame( 'smtp.example.com', $phpmailer->Host );
		$this->assertSame( 'pinned-user', $phpmailer->Username );
		$this->assertSame( 'pinned-pass', $phpmailer->Password );
	}
}
