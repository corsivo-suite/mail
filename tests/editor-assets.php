<?php

define('ABSPATH', __DIR__ . '/');
define('CORSIVO_MAIL_DIR', dirname(__DIR__) . '/');
define('CORSIVO_MAIL_URL', 'https://example.test/wp-content/plugins/corsivo-mail/');
define('CORSIVO_MAIL_VERSION', 'test');

function add_filter() {}
function add_action() {}

require_once CORSIVO_MAIL_DIR . 'includes/blocks/editor.php';

$assertions = 0;

function assert_editor_asset($condition, $message) {
	global $assertions;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$assertions++;
}

$manifest = \CorsivoMail\Blocks\editor_build_manifest();
assert_editor_asset(is_array($manifest), 'The complete editor build is detected.');
assert_editor_asset(in_array('wp-components', $manifest['dependencies'], true), 'WordPress components remain an explicit dependency.');
assert_editor_asset(in_array('wp-block-editor', $manifest['dependencies'], true), 'The block editor remains an explicit dependency.');
assert_editor_asset(in_array('wp-api-fetch', $manifest['dependencies'], true), 'REST requests retain their API Fetch dependency.');
assert_editor_asset($manifest['version'] !== '', 'The generated asset version is preserved.');

$build_dir = CORSIVO_MAIL_DIR . 'admin-editor/build/';
$bundle = file_get_contents($build_dir . 'index.js');
$styles = file_get_contents($build_dir . 'index.css');
$source = file_get_contents(CORSIVO_MAIL_DIR . 'includes/blocks/editor.php');

assert_editor_asset(strpos($bundle, 'CorsivoMailAdminUI') !== false, 'The UI bridge is compiled into the editor bundle.');
assert_editor_asset(strpos($bundle, '@corsivo-suite/wp-admin-ui') === false, 'No private package import leaks into the runtime bundle.');
assert_editor_asset(strpos($styles, '.corsivo-admin-ui-') !== false, 'Corsivo UI styles are compiled once.');
assert_editor_asset(strpos($source, "assets/editor.js") !== false && strpos($source, "assets/email-editor.js") !== false, 'The raw fallback remains deterministic.');
assert_editor_asset(strpos($source, "'rtl', 'replace'") !== false, 'The package stylesheet declares its RTL replacement.');

echo "Corsivo Mail editor assets: {$assertions} assertions PASS\n";
