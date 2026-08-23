<?php

namespace CorsivoMail\Accounts {
	function all() {
		return [];
	}

	function defaults() {
		return [];
	}

	function smtp_presets() {
		return [];
	}
}

namespace CorsivoMail\Options {
	function get() {
		return true;
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('CORSIVO_MAIL_DIR', dirname(__DIR__) . '/');
	define('CORSIVO_MAIL_URL', 'https://example.test/wp-content/plugins/corsivo-mail/');
	define('CORSIVO_MAIL_VERSION', 'test');

	function add_action() {}
	function apply_filters($hook, $value) {
		return $value;
	}
	function wp_create_nonce() {
		return 'nonce';
	}
	function rest_url($path = '') {
		return 'https://example.test/wp-json/' . ltrim($path, '/');
	}

	$assertions = 0;

	function assert_settings_asset($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once CORSIVO_MAIL_DIR . 'includes/settings/page.php';

	$manifest = \CorsivoMail\Settings\settings_ui_build_manifest();
	assert_settings_asset(is_array($manifest), 'The complete settings build is detected.');
	assert_settings_asset(in_array('wp-components', $manifest['dependencies'], true), 'WordPress components remain explicit.');
	assert_settings_asset(in_array('wp-element', $manifest['dependencies'], true), 'WordPress element remains explicit.');
	assert_settings_asset($manifest['version'] !== '', 'The generated version is preserved.');

	$bundle = file_get_contents(CORSIVO_MAIL_DIR . 'settings-app/build/index.js');
	$styles = file_get_contents(CORSIVO_MAIL_DIR . 'settings-app/build/index.css');
	$rtl_styles = file_get_contents(CORSIVO_MAIL_DIR . 'settings-app/build/index-rtl.css');
	$fallback = file_get_contents(CORSIVO_MAIL_DIR . 'assets/settings.js');
	$page = file_get_contents(CORSIVO_MAIL_DIR . 'includes/settings/page.php');
	$groups_page = file_get_contents(CORSIVO_MAIL_DIR . 'includes/newsletter/groups-page.php');

	assert_settings_asset(strpos($bundle, 'CorsivoMailSettingsUI') !== false, 'The localized settings bridge is compiled.');
	assert_settings_asset(strpos($bundle, '@corsivo-suite/wp-admin-ui') === false, 'The private package import does not leak at runtime.');
	assert_settings_asset(strpos($styles, '.corsivo-admin-ui-confirm-dialog') !== false, 'ConfirmDialog styles are bundled.');
	assert_settings_asset(strpos($styles, '.corsivo-mail-settings-app') !== false, 'Scoped settings styles are bundled.');
	assert_settings_asset(strpos($rtl_styles, '.corsivo-mail-settings-app') !== false, 'The RTL stylesheet contains the settings surface.');
	assert_settings_asset(strpos($fallback, 'window.confirm') === false, 'The raw fallback does not use window.confirm.');
	assert_settings_asset(strpos($fallback, "createElement( 'dialog' )") !== false, 'The raw fallback uses a native dialog.');
	assert_settings_asset(strpos($page, "CORSIVO_MAIL_URL . 'assets/settings.js'") !== false, 'The raw JavaScript fallback remains available.');
	assert_settings_asset(strpos($page, "CORSIVO_MAIL_URL . 'assets/settings.css'") !== false, 'The raw CSS fallback remains available.');
	assert_settings_asset(strpos($page, "'rtl', 'replace'") !== false, 'The generated stylesheet declares its RTL replacement.');
	assert_settings_asset(strpos($page, 'id="corsivo-mail-accounts-fallback"') !== false, 'The Accounts fallback remains server rendered.');
	assert_settings_asset(strpos($groups_page, 'id="corsivo-mail-groups-fallback"') !== false, 'The Groups fallback remains server rendered.');

	echo "Corsivo Mail settings assets: {$assertions} assertions PASS\n";
}
