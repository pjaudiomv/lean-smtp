<?php
/**
 * Tests for the settings screen: where it lives in the admin menu, and the
 * assets it registers there.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Settings extends WP_UnitTestCase {

	/** The hook suffix of a top-level menu page. */
	const SETTINGS_HOOK = 'toplevel_page_lean-smtp';

	/** The hook suffix add_submenu_page() gives the log screen under our menu. */
	const LOG_HOOK = 'lean-smtp_page_lean-smtp-log';

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// The registries are global and outlive a test; a leaked enqueue from an
		// earlier one would make "not on this screen" pass or fail by accident.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	public function tear_down() {
		unset( $_GET['page'] );
		$GLOBALS['pagenow'] = 'index.php';
		parent::tear_down();
	}

	public function test_the_menu_is_top_level_with_a_settings_and_a_log_page() {
		Lean_SMTP_Settings::admin_menu();

		$slugs = wp_list_pluck( $GLOBALS['submenu'][ Lean_SMTP_Settings::PAGE ] ?? [], 2 );

		$this->assertContains( Lean_SMTP_Settings::PAGE, $slugs );
		$this->assertContains( Lean_SMTP_Log_Page::PAGE, $slugs );
	}

	public function test_assets_are_enqueued_only_on_our_own_screens() {
		Lean_SMTP_Settings::admin_menu();

		Lean_SMTP_Settings::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'lean-smtp-settings', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'lean-smtp-settings', 'enqueued' ) );

		Lean_SMTP_Settings::enqueue_assets( self::SETTINGS_HOOK );
		$this->assertTrue( wp_style_is( 'lean-smtp-settings', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'lean-smtp-settings', 'enqueued' ) );
	}

	public function test_the_log_screen_gets_the_stylesheet_but_not_the_settings_script() {
		// js/settings.js exists to toggle the credential sections, which the log
		// screen has none of.
		Lean_SMTP_Settings::admin_menu();

		Lean_SMTP_Settings::enqueue_assets( self::LOG_HOOK );

		$this->assertTrue( wp_style_is( 'lean-smtp-settings', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'lean-smtp-settings', 'enqueued' ) );
	}

	public function test_page_renders_without_inline_script_or_style_tags() {
		// WordPress.org review rejects inline <script>/<style> output; both live in
		// enqueued files instead.
		ob_start();
		Lean_SMTP_Settings::settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'lsmtp-transports', $html );
		$this->assertStringContainsString( 'data-mailer="mailgun"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}

	public function test_script_receives_the_mailer_option_and_labels() {
		Lean_SMTP_Settings::admin_menu();
		Lean_SMTP_Settings::enqueue_assets( self::SETTINGS_HOOK );

		$data = (string) wp_scripts()->get_data( 'lean-smtp-settings', 'data' );

		$this->assertStringContainsString( 'leanSmtpSettings', $data );
		$this->assertStringContainsString( Lean_SMTP_Mailer::OPTION_MAILER, $data );
		$this->assertStringContainsString( Lean_SMTP_Mailer::MAILER_MAILGUN, $data );
	}

	public function test_the_old_settings_url_points_at_the_new_one() {
		// Up to 0.3.1 the page lived at Settings → Lean SMTP; that URL is bookmarked.
		$GLOBALS['pagenow'] = 'options-general.php';
		$_GET['page']       = Lean_SMTP_Settings::PAGE;

		$this->assertSame( Lean_SMTP_Settings::url(), Lean_SMTP_Settings::legacy_redirect_target() );
		$this->assertStringContainsString( 'admin.php?page=lean-smtp', Lean_SMTP_Settings::url() );
	}

	public function test_other_options_pages_are_left_alone() {
		$GLOBALS['pagenow'] = 'options-general.php';
		$_GET['page']       = 'some-other-plugin';

		$this->assertSame( '', Lean_SMTP_Settings::legacy_redirect_target() );
	}

	public function test_retention_only_accepts_an_offered_size() {
		$this->assertSame( '500', Lean_SMTP_Settings::sanitize_retention( '500' ) );
		$this->assertSame( (string) Lean_SMTP_Logger::MAX_ROWS, Lean_SMTP_Settings::sanitize_retention( '250' ) );
		$this->assertSame( (string) Lean_SMTP_Logger::MAX_ROWS, Lean_SMTP_Settings::sanitize_retention( 'all of them' ) );
	}
}
