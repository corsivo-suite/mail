<?php

namespace CorsivoMail\Imap;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_menu', __NAMESPACE__ . '\\register_page', 20);
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue');

const INBOX_SCRIPT_DEPENDENCIES = [
	'wp-element',
	'wp-components',
	'wp-api-fetch',
	'wp-i18n',
];

function register_page() {
	if (!Options\get('module_imap')) {
		return;
	}
	add_submenu_page(
		'corsivo-mail',
		'Posta in arrivo',
		'Posta in arrivo',
		'manage_options',
		'corsivo-mail-inbox',
		__NAMESPACE__ . '\\render_page'
	);
}

function render_page() {
	echo '<div class="wrap"><h1>Posta in arrivo</h1><div id="corsivo-mail-inbox-app"></div></div>';
}

function inbox_build_manifest() {
	$build_dir = CORSIVO_MAIL_DIR . 'inbox-app/build/';
	$required = ['index.asset.php', 'index.js', 'index.css', 'index-rtl.css'];
	foreach ($required as $file) {
		if (!is_readable($build_dir . $file)) {
			return null;
		}
	}

	$asset = require $build_dir . 'index.asset.php';
	if (!is_array($asset) || !isset($asset['dependencies'], $asset['version']) || !is_array($asset['dependencies'])) {
		return null;
	}

	return [
		'dependencies' => array_values(array_unique(array_merge(INBOX_SCRIPT_DEPENDENCIES, $asset['dependencies']))),
		'version' => (string) $asset['version'],
		'url' => CORSIVO_MAIL_URL . 'inbox-app/build/',
	];
}

function enqueue($hook) {
	if (!Options\get('module_imap') || strpos($hook, 'corsivo-mail-inbox') === false) {
		return;
	}

	$build = inbox_build_manifest();
	if ($build) {
		wp_enqueue_script(
			'corsivo-mail-inbox',
			$build['url'] . 'index.js',
			$build['dependencies'],
			$build['version'],
			true
		);
		wp_enqueue_style(
			'corsivo-mail-inbox-ui',
			$build['url'] . 'index.css',
			['wp-components'],
			$build['version']
		);
		wp_style_add_data('corsivo-mail-inbox-ui', 'rtl', 'replace');
	} else {
		wp_enqueue_script(
			'corsivo-mail-inbox',
			CORSIVO_MAIL_URL . 'assets/inbox.js',
			INBOX_SCRIPT_DEPENDENCIES,
			CORSIVO_MAIL_VERSION,
			true
		);
		wp_enqueue_style(
			'corsivo-mail-inbox-ui',
			CORSIVO_MAIL_URL . 'assets/inbox.css',
			['wp-components'],
			CORSIVO_MAIL_VERSION
		);
	}

	wp_localize_script('corsivo-mail-inbox', 'CorsivoMailInbox', [
		'attachmentBase' => admin_url('admin-post.php?action=corsivo_mail_attachment&_wpnonce=' . wp_create_nonce('corsivo_mail_attachment')),
	]);
	wp_set_script_translations('corsivo-mail-inbox', 'corsivo-mail', CORSIVO_MAIL_DIR . 'languages');
}
