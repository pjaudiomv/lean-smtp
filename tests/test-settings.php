<?php
/**
 * Tests for the settings screen's registered assets.
 *
 * @package lean-smtp
 */

class Test_Lean_SMTP_Settings extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_assets_are_enqueued_only_on_our_own_screen() {
		Lean_SMTP_Settings::admin_menu();
		$hook = get_plugin_page_hookname( Lean_SMTP_Settings::PAGE, 'options-general.php' );

		Lean_SMTP_Settings::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'lean-smtp-settings', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'lean-smtp-settings', 'enqueued' ) );

		Lean_SMTP_Settings::enqueue_assets( $hook );
		$this->assertTrue( wp_style_is( 'lean-smtp-settings', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'lean-smtp-settings', 'enqueued' ) );
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
		Lean_SMTP_Settings::enqueue_assets( get_plugin_page_hookname( Lean_SMTP_Settings::PAGE, 'options-general.php' ) );

		$data = (string) wp_scripts()->get_data( 'lean-smtp-settings', 'data' );

		$this->assertStringContainsString( 'leanSmtpSettings', $data );
		$this->assertStringContainsString( Lean_SMTP_Mailer::OPTION_MAILER, $data );
		$this->assertStringContainsString( Lean_SMTP_Mailer::MAILER_MAILGUN, $data );
	}
}
