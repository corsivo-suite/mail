<?php

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

	$events = [];
	$assertions = 0;

	function add_action() {}
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
	function admin_url($path = '') {
		return 'https://example.test/wp-admin/' . ltrim($path, '/');
	}
	function wp_create_nonce() {
		return 'nonce';
	}

	function assert_inbox_enqueue($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once CORSIVO_MAIL_DIR . 'includes/imap/page.php';
	\CorsivoMail\Imap\enqueue('corsivo-mail_page_corsivo-mail-inbox');

	$by_type = [];
	foreach ($events as $event) {
		$by_type[$event[0]][] = $event;
	}

	$script = $by_type['script'][0];
	assert_inbox_enqueue($script[1] === 'corsivo-mail-inbox', 'The inbox handle remains stable.');
	assert_inbox_enqueue(str_ends_with($script[2], 'inbox-app/build/index.js'), 'The compiled inbox is enqueued.');
	assert_inbox_enqueue(in_array('wp-api-fetch', $script[3], true), 'The REST dependency is preserved.');
	assert_inbox_enqueue(in_array('wp-components', $script[3], true), 'The WordPress component dependency is preserved.');
	assert_inbox_enqueue(count($by_type['style']) === 1, 'The inbox stylesheet is enqueued once.');
	assert_inbox_enqueue(str_ends_with($by_type['style'][0][2], 'inbox-app/build/index.css'), 'The compiled stylesheet is used.');
	assert_inbox_enqueue($by_type['style_data'][0] === ['style_data', 'corsivo-mail-inbox-ui', 'rtl', 'replace'], 'The RTL replacement is registered.');
	assert_inbox_enqueue($by_type['localize'][0][1] === 'corsivo-mail-inbox', 'The attachment configuration targets the stable handle.');
	assert_inbox_enqueue(strpos($by_type['localize'][0][3]['attachmentBase'], 'corsivo_mail_attachment') !== false, 'The attachment URL contract is preserved.');
	assert_inbox_enqueue($by_type['translations'][0][2] === 'corsivo-mail', 'The consumer text domain is registered.');

	echo "Corsivo Mail inbox enqueue: {$assertions} assertions PASS\n";
}
