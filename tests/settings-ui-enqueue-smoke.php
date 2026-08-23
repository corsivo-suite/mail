<?php

namespace CorsivoMail\Accounts {
	function all() {
		return [[
			'id' => 'account-1',
			'label' => 'Primary',
			'smtp_pass' => 'stored-smtp',
			'imap_pass' => 'stored-imap',
			'is_default' => true,
		]];
	}

	function defaults() {
		return [
			'id' => '',
			'provider' => 'custom',
			'smtp_pass' => '',
			'imap_pass' => '',
		];
	}

	function smtp_presets() {
		return [
			'custom' => ['label' => 'Personalizzato'],
			'gmail' => ['label' => 'Gmail'],
		];
	}
}

namespace CorsivoMail\Options {
	function get() {
		return true;
	}
}

namespace CorsivoMail\Groups {
	function all() {
		return [[
			'id' => 'group-1',
			'name' => 'Editors',
			'rule' => 'role',
			'value' => 'editor',
		]];
	}

	function rules() {
		return ['all_users' => 'Tutti', 'role' => 'Ruolo'];
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('CORSIVO_MAIL_DIR', dirname(__DIR__) . '/');
	define('CORSIVO_MAIL_URL', 'https://example.test/wp-content/plugins/corsivo-mail/');
	define('CORSIVO_MAIL_VERSION', 'test');

	$events = [];
	$filters = [];
	$assertions = 0;

	function add_action() {}
	function add_filter($hook, $callback, $priority = 10) {
		global $filters;
		$filters[$hook][$priority][] = $callback;
	}
	function apply_filters($hook, $value, ...$arguments) {
		global $filters;
		if (empty($filters[$hook])) {
			return $value;
		}
		ksort($filters[$hook]);
		foreach ($filters[$hook] as $callbacks) {
			foreach ($callbacks as $callback) {
				$value = $callback($value, ...$arguments);
			}
		}
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
	function wp_style_add_data($handle, $key, $value) {
		global $events;
		$events[] = ['style_data', $handle, $key, $value];
	}
	function wp_localize_script($handle, $name, $value) {
		global $events;
		$events[] = ['localize', $handle, $name, $value];
	}
	function wp_set_script_translations($handle, $domain, $path) {
		global $events;
		$events[] = ['translations', $handle, $domain, $path];
	}
	function wp_create_nonce($action = '') {
		return $action . '-nonce';
	}
	function rest_url($path = '') {
		return 'https://example.test/wp-json/' . ltrim($path, '/');
	}

	function assert_settings_enqueue($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once CORSIVO_MAIL_DIR . 'includes/settings/page.php';
	require_once CORSIVO_MAIL_DIR . 'includes/newsletter/groups-page.php';

	$_GET = ['page' => 'corsivo-mail', 'tab' => 'accounts'];
	\CorsivoMail\Settings\enqueue_settings_ui('toplevel_page_corsivo-mail');
	$account_events = $events;
	$account_scripts = array_values(array_filter($account_events, fn($event) => $event[0] === 'script'));
	$account_styles = array_values(array_filter($account_events, fn($event) => $event[0] === 'style'));
	$account_localize = array_values(array_filter($account_events, fn($event) => $event[0] === 'localize'))[0];

	assert_settings_enqueue(count($account_scripts) === 2, 'Accounts enqueue the fallback and compiled scripts.');
	assert_settings_enqueue(str_ends_with($account_scripts[0][2], 'assets/settings.js'), 'The raw fallback loads first.');
	assert_settings_enqueue(str_ends_with($account_scripts[1][2], 'settings-app/build/index.js'), 'The compiled settings bundle loads.');
	assert_settings_enqueue(in_array('corsivo-mail-settings-fallback', $account_scripts[1][3], true), 'The compiled bundle waits for fallback configuration.');
	assert_settings_enqueue(in_array('wp-components', $account_scripts[1][3], true), 'The Core component dependency is explicit.');
	assert_settings_enqueue(count($account_styles) === 1, 'The compiled settings stylesheet is loaded once.');
	assert_settings_enqueue(str_ends_with($account_styles[0][2], 'settings-app/build/index.css'), 'The compiled stylesheet is selected.');
	assert_settings_enqueue($account_localize[1] === 'corsivo-mail-settings-fallback', 'Configuration targets the stable fallback handle.');
	assert_settings_enqueue($account_localize[2] === 'CorsivoMailSettingsUI', 'The localized bridge name remains stable.');
	$account_config = $account_localize[3]['accounts'];
	assert_settings_enqueue($account_config['accounts'][0]['smtp_pass'] === '', 'Stored SMTP passwords are not exposed.');
	assert_settings_enqueue($account_config['accounts'][0]['imap_pass'] === '', 'Stored IMAP passwords are not exposed.');
	assert_settings_enqueue($account_config['nonce'] === 'corsivo_mail_settings-nonce', 'The traditional settings nonce is localized.');
	assert_settings_enqueue($account_config['restNonce'] === 'wp_rest-nonce', 'The REST nonce is localized for IMAP tests.');
	assert_settings_enqueue(str_ends_with($account_config['imapTestUrl'], '/corsivo/v1/mail/imap-test'), 'The existing IMAP test route is preserved.');
	assert_settings_enqueue(isset($account_config['presets']['gmail']), 'Provider presets are localized.');
	assert_settings_enqueue(in_array(['style_data', 'corsivo-mail-settings-ui', 'rtl', 'replace'], $account_events, true), 'The RTL replacement is registered.');

	$events = [];
	$_GET['tab'] = 'groups';
	\CorsivoMail\Settings\enqueue_settings_ui('toplevel_page_corsivo-mail');
	$group_localize = array_values(array_filter($events, fn($event) => $event[0] === 'localize'))[0];
	$group_config = $group_localize[3]['groups'];
	assert_settings_enqueue($group_config['groups'][0] === [
		'id' => 'group-1',
		'name' => 'Editors',
		'rule' => 'role',
		'value' => 'editor',
	], 'Groups keep the persisted public shape.');
	assert_settings_enqueue($group_config['nonce'] === 'corsivo_mail_groups-nonce', 'The traditional Groups nonce is localized.');
	assert_settings_enqueue($group_config['rules']['role'] === 'Ruolo', 'The server rule registry is localized.');
	assert_settings_enqueue(count(array_filter($events, fn($event) => $event[0] === 'style')) === 1, 'Groups also load the shared stylesheet once.');

	echo "Corsivo Mail settings enqueue: {$assertions} assertions PASS\n";
}
