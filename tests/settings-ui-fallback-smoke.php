<?php

namespace CorsivoMail\Accounts {
	function all() {
		return [[
			'id' => 'account-1',
			'label' => 'Primary',
			'provider' => 'custom',
			'from_name' => 'Sender',
			'from_email' => 'sender@example.test',
			'smtp_host' => 'smtp.example.test',
			'smtp_port' => 587,
			'smtp_secure' => 'tls',
			'smtp_auth' => true,
			'smtp_user' => 'sender@example.test',
			'smtp_pass' => 'stored-smtp',
			'imap_host' => 'imap.example.test',
			'imap_port' => 993,
			'imap_secure' => 'ssl',
			'imap_user' => '',
			'imap_pass' => 'stored-imap',
			'is_default' => true,
		]];
	}

	function defaults() {
		$account = all()[0];
		$account['id'] = '';
		$account['label'] = '';
		$account['smtp_pass'] = '';
		$account['imap_pass'] = '';
		return $account;
	}

	function smtp_presets() {
		return ['custom' => ['label' => 'Personalizzato']];
	}
}

namespace CorsivoMail\Options {
	function get() {
		return true;
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('CORSIVO_MAIL_DIR', __DIR__ . '/missing-settings-build/');
	define('CORSIVO_MAIL_URL', 'https://example.test/wp-content/plugins/corsivo-mail/');
	define('CORSIVO_MAIL_VERSION', 'test');

	$events = [];
	$assertions = 0;

	function add_action() {}
	function apply_filters($hook, $value) {
		return $value;
	}
	function sanitize_key($value) {
		return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
	}
	function wp_enqueue_script($handle, $source = '', $dependencies = [], $version = false, $footer = false) {
		global $events;
		$events[] = ['script', $handle, $source, $dependencies, $version, $footer];
	}
	function wp_enqueue_style($handle, $source = '', $dependencies = [], $version = false) {
		global $events;
		$events[] = ['style', $handle, $source, $dependencies, $version];
	}
	function wp_style_add_data() {
		fwrite(STDERR, "FAIL: The logical fallback stylesheet must not request a missing RTL file.\n");
		exit(1);
	}
	function wp_localize_script() {}
	function wp_set_script_translations() {}
	function wp_create_nonce() {
		return 'nonce';
	}
	function rest_url($path = '') {
		return 'https://example.test/wp-json/' . ltrim($path, '/');
	}
	function esc_attr($value) {
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}
	function esc_html($value) {
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}
	function selected($current, $value) {
		if ((string) $current === (string) $value) {
			echo 'selected';
		}
	}
	function checked($value) {
		if ($value) {
			echo 'checked';
		}
	}
	function wp_nonce_field($action) {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($action) . '">';
	}
	function submit_button($label) {
		echo '<button type="submit">' . esc_html($label) . '</button>';
	}

	function assert_settings_fallback($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once dirname(__DIR__) . '/includes/settings/page.php';
	assert_settings_fallback(\CorsivoMail\Settings\settings_ui_build_manifest() === null, 'An incomplete settings build is rejected.');

	$_GET = ['page' => 'corsivo-mail', 'tab' => 'accounts'];
	\CorsivoMail\Settings\enqueue_settings_ui('toplevel_page_corsivo-mail');
	assert_settings_fallback(count($events) === 2, 'Only the raw fallback script and stylesheet are enqueued.');
	assert_settings_fallback(str_ends_with($events[0][2], 'assets/settings.js'), 'The raw settings script is loaded.');
	assert_settings_fallback($events[0][3] === [], 'The fallback script has no unavailable build dependency.');
	assert_settings_fallback(str_ends_with($events[1][2], 'assets/settings.css'), 'The scoped fallback stylesheet is loaded.');
	assert_settings_fallback($events[1][3] === ['wp-components'], 'The fallback stylesheet keeps its Core dependency.');

	ob_start();
	\CorsivoMail\Settings\render_accounts_tab();
	$markup = ob_get_clean();
	assert_settings_fallback(strpos($markup, 'id="corsivo-mail-accounts-app" hidden') !== false, 'The enhancement root starts hidden.');
	assert_settings_fallback(strpos($markup, 'id="corsivo-mail-accounts-fallback"') !== false, 'The server-rendered fallback remains visible.');
	assert_settings_fallback(strpos($markup, 'name="accounts[0][smtp_pass]" value=""') !== false, 'The fallback never echoes a stored SMTP password.');
	assert_settings_fallback(strpos($markup, 'name="accounts[0][imap_pass]" value=""') !== false, 'The fallback never echoes a stored IMAP password.');

	echo "Corsivo Mail settings fallback: {$assertions} assertions PASS\n";
}
