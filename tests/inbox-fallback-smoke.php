<?php

namespace CorsivoMail\Options {
	function get() {
		return true;
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('CORSIVO_MAIL_DIR', __DIR__ . '/missing-build/');
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
	function wp_style_add_data() {
		fwrite(STDERR, "FAIL: The logical fallback stylesheet must not request a missing RTL file.\n");
		exit(1);
	}
	function wp_localize_script() {}
	function wp_set_script_translations() {}
	function admin_url($path = '') {
		return 'https://example.test/wp-admin/' . ltrim($path, '/');
	}
	function wp_create_nonce() {
		return 'nonce';
	}

	function assert_inbox_fallback($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once dirname(__DIR__) . '/includes/imap/page.php';
	assert_inbox_fallback(\CorsivoMail\Imap\inbox_build_manifest() === null, 'An incomplete build is rejected.');
	\CorsivoMail\Imap\enqueue('corsivo-mail_page_corsivo-mail-inbox');

	assert_inbox_fallback(str_ends_with($events[0][2], 'assets/inbox.js'), 'The raw inbox script is loaded.');
	assert_inbox_fallback($events[0][3] === \CorsivoMail\Imap\INBOX_SCRIPT_DEPENDENCIES, 'The fallback preserves all dependencies.');
	assert_inbox_fallback(str_ends_with($events[1][2], 'assets/inbox.css'), 'The scoped fallback stylesheet is loaded.');
	assert_inbox_fallback($events[1][3] === ['wp-components'], 'The fallback stylesheet keeps its Core dependency.');

	echo "Corsivo Mail inbox fallback: {$assertions} assertions PASS\n";
}
