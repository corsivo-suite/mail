<?php

namespace CorsivoMail\Template {
	const TEMPLATE_TYPE = 'corsivo_mail_layout';
	const FOOTER_TYPE = 'corsivo_mail_footer';
}

namespace CorsivoMail\Automation {
	const TYPE = 'corsivo_mail_email';
	function providers() {
		return ['password_reset' => ['label' => 'Password reset', 'merge_tags' => ['reset_url']]];
	}
	function events() {
		return ['member_created' => ['label' => 'Member created', 'merge_tags' => ['member_name']]];
	}
	function audiences() {
		return ['manual' => 'Manual'];
	}
}

namespace CorsivoMail\Accounts {
	function all() {
		return [['id' => 'default', 'label' => 'Default', 'from_email' => 'mail@example.test']];
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('CORSIVO_MAIL_DIR', dirname(__DIR__) . '/');
	define('CORSIVO_MAIL_URL', 'https://example.test/wp-content/plugins/corsivo-mail/');
	define('CORSIVO_MAIL_VERSION', 'test');

	$events = [];
	$assertions = 0;

	function add_filter() {}
	function add_action() {}
	function get_current_screen() {
		return (object) ['post_type' => 'corsivo_mail_email'];
	}
	function wp_enqueue_script($handle, $src = '', $dependencies = [], $version = false, $in_footer = false) {
		global $events;
		$events[] = ['enqueue_script', $handle, $src, $dependencies, $version, $in_footer];
	}
	function wp_register_script($handle, $src = '', $dependencies = [], $version = false, $in_footer = false) {
		global $events;
		$events[] = ['register_script', $handle, $src, $dependencies, $version, $in_footer];
	}
	function wp_enqueue_style($handle, $src = '', $dependencies = [], $version = false) {
		global $events;
		$events[] = ['enqueue_style', $handle, $src, $dependencies, $version];
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
	function get_editable_roles() {
		return ['editor' => ['name' => 'Editor']];
	}
	function admin_url($path = '') {
		return 'https://example.test/wp-admin/' . ltrim($path, '/');
	}
	function wp_create_nonce() {
		return 'nonce';
	}

	function assert_editor_enqueue($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once CORSIVO_MAIL_DIR . 'includes/blocks/editor.php';
	\CorsivoMail\Blocks\enqueue_editor_assets();

	$by_type = [];
	foreach ($events as $event) {
		$by_type[$event[0]][] = $event;
	}

	$script = $by_type['enqueue_script'][0];
	assert_editor_enqueue($script[1] === 'corsivo-mail-email-editor', 'The compiled bundle keeps the email editor handle.');
	assert_editor_enqueue(str_ends_with($script[2], 'admin-editor/build/index.js'), 'The complete build is enqueued.');
	assert_editor_enqueue(in_array('wp-block-editor', $script[3], true), 'The Gutenberg dependency is preserved.');
	assert_editor_enqueue(in_array('wp-api-fetch', $script[3], true), 'The REST dependency is preserved.');
	assert_editor_enqueue(count($by_type['enqueue_script']) === 2 && $by_type['enqueue_script'][1][1] === 'corsivo-mail-editor', 'The legacy common handle remains available as an alias.');
	assert_editor_enqueue($by_type['register_script'][0][2] === false, 'The common handle alias does not load a duplicate script.');
	assert_editor_enqueue(str_ends_with($by_type['enqueue_style'][0][2], 'admin-editor/build/index.css'), 'The package stylesheet is enqueued once.');
	assert_editor_enqueue($by_type['style_data'][0] === ['style_data', 'corsivo-mail-admin-ui', 'rtl', 'replace'], 'The RTL replacement is registered.');
	assert_editor_enqueue($by_type['localize'][0][1] === 'corsivo-mail-email-editor', 'Runtime configuration is attached before the compiled bundle.');
	assert_editor_enqueue($by_type['localize'][0][3]['postType'] === 'corsivo_mail_email', 'The existing post type contract is unchanged.');
	assert_editor_enqueue($by_type['translations'][0][2] === 'corsivo-mail', 'The consumer text domain is registered.');

	echo "Corsivo Mail editor enqueue: {$assertions} assertions PASS\n";
}
