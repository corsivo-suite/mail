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

	function add_action() {}

	$assertions = 0;

	function assert_inbox_asset($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once CORSIVO_MAIL_DIR . 'includes/imap/page.php';

	$manifest = \CorsivoMail\Imap\inbox_build_manifest();
	assert_inbox_asset(is_array($manifest), 'The complete inbox build is detected.');
	assert_inbox_asset(in_array('wp-components', $manifest['dependencies'], true), 'WordPress components remain explicit.');
	assert_inbox_asset(in_array('wp-api-fetch', $manifest['dependencies'], true), 'API Fetch remains explicit.');
	assert_inbox_asset($manifest['version'] !== '', 'The generated version is preserved.');

	$bundle = file_get_contents(CORSIVO_MAIL_DIR . 'inbox-app/build/index.js');
	$styles = file_get_contents(CORSIVO_MAIL_DIR . 'inbox-app/build/index.css');
	$page = file_get_contents(CORSIVO_MAIL_DIR . 'includes/imap/page.php');

	assert_inbox_asset(strpos($bundle, 'CorsivoMailInboxUI') !== false, 'The UI bridge is compiled into the inbox bundle.');
	assert_inbox_asset(strpos($bundle, '@corsivo-suite/wp-admin-ui') === false, 'The private package import does not leak at runtime.');
	assert_inbox_asset(strpos($styles, '.corsivo-admin-ui-confirm-dialog') !== false, 'ConfirmDialog styles are bundled.');
	assert_inbox_asset(strpos($styles, '.corsivo-mail-inbox__layout') !== false, 'Scoped inbox styles are bundled.');
	assert_inbox_asset(strpos($page, "CORSIVO_MAIL_URL . 'assets/inbox.js'") !== false, 'The raw JavaScript fallback remains available.');
	assert_inbox_asset(strpos($page, "CORSIVO_MAIL_URL . 'assets/inbox.css'") !== false, 'The raw CSS fallback remains available.');
	assert_inbox_asset(strpos($page, "'rtl', 'replace'") !== false, 'The generated stylesheet declares its RTL replacement.');

	echo "Corsivo Mail inbox assets: {$assertions} assertions PASS\n";
}
