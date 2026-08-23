<?php

namespace CorsivoMail\Blocks;

use CorsivoMail\Template;
use CorsivoMail\Automation;
use CorsivoMail\Accounts;

if (!defined('ABSPATH')) {
	exit;
}

const ALLOWED = [
	'core/paragraph',
	'core/heading',
	'core/image',
	'core/buttons',
	'core/button',
	'core/columns',
	'core/column',
	'core/group',
	'core/spacer',
	'core/separator',
	'core/list',
	'core/list-item',
	'core/social-links',
	'core/social-link',
	'corsivo-mail/content-slot',
	'corsivo-mail/footer',
	'corsivo-mail/button',
];

const EDITOR_SCRIPT_DEPENDENCIES = [
	'wp-blocks',
	'wp-element',
	'wp-block-editor',
	'wp-components',
	'wp-data',
	'wp-core-data',
	'wp-plugins',
	'wp-editor',
	'wp-edit-post',
	'wp-api-fetch',
	'wp-i18n',
];

add_filter('allowed_block_types_all', __NAMESPACE__ . '\\restrict_blocks', 10, 2);
add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets');

function restrict_blocks($allowed, $context) {
	if (!isset($context->post) || !is_email_post_type($context->post->post_type)) {
		return $allowed;
	}
	if ($context->post->post_type === Template\FOOTER_TYPE) {
		return array_values(array_diff(ALLOWED, ['corsivo-mail/content-slot', 'corsivo-mail/footer']));
	}
	return ALLOWED;
}

function editor_build_manifest() {
	$build_dir = CORSIVO_MAIL_DIR . 'admin-editor/build/';
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
		'dependencies' => array_values(array_unique(array_merge(EDITOR_SCRIPT_DEPENDENCIES, $asset['dependencies']))),
		'version' => (string) $asset['version'],
		'url' => CORSIVO_MAIL_URL . 'admin-editor/build/',
	];
}

function enqueue_editor_assets() {
	$screen = get_current_screen();
	if (!$screen || !is_email_post_type($screen->post_type)) {
		return;
	}

	$build = editor_build_manifest();
	if ($build) {
		wp_enqueue_script(
			'corsivo-mail-email-editor',
			$build['url'] . 'index.js',
			$build['dependencies'],
			$build['version'],
			true
		);
		wp_register_script(
			'corsivo-mail-editor',
			false,
			['corsivo-mail-email-editor'],
			$build['version'],
			true
		);
		wp_enqueue_script('corsivo-mail-editor');
		wp_enqueue_style(
			'corsivo-mail-admin-ui',
			$build['url'] . 'index.css',
			['wp-components'],
			$build['version']
		);
		wp_style_add_data('corsivo-mail-admin-ui', 'rtl', 'replace');
	} else {
		wp_enqueue_script(
			'corsivo-mail-editor',
			CORSIVO_MAIL_URL . 'assets/editor.js',
			EDITOR_SCRIPT_DEPENDENCIES,
			CORSIVO_MAIL_VERSION,
			true
		);
		wp_enqueue_script(
			'corsivo-mail-email-editor',
			CORSIVO_MAIL_URL . 'assets/email-editor.js',
			['corsivo-mail-editor'],
			CORSIVO_MAIL_VERSION,
			true
		);
	}

	wp_enqueue_style(
		'corsivo-mail-editor',
		CORSIVO_MAIL_URL . 'assets/editor.css',
		[],
		CORSIVO_MAIL_VERSION
	);

	if (is_email_post_type($screen->post_type)) {
		$providers = [];
		foreach (Automation\providers() as $id => $p) {
			$providers[$id] = ['label' => $p['label'], 'merge_tags' => $p['merge_tags']];
		}

		$events = [];
		foreach (Automation\events() as $id => $e) {
			$events[$id] = ['label' => $e['label'], 'merge_tags' => $e['merge_tags']];
		}

		$roles = [];
		foreach (get_editable_roles() as $slug => $role) {
			$roles[$slug] = $role['name'];
		}

		$accounts = [];
		foreach (Accounts\all() as $account) {
			$accounts[$account['id']] = $account['label'] ?: $account['from_email'];
		}

		wp_localize_script('corsivo-mail-email-editor', 'CorsivoMailEmail', [
			'postType'    => Automation\TYPE,
			'layoutType'  => Template\TEMPLATE_TYPE,
			'footerType'  => Template\FOOTER_TYPE,
			'layoutRest'  => 'corsivo-mail-layouts',
			'providers'   => $providers,
			'events'      => $events,
			'audiences'   => Automation\audiences(),
			'roles'       => $roles,
			'accounts'    => $accounts,
			'previewBase' => admin_url('admin-post.php?action=corsivo_mail_preview&_wpnonce=' . wp_create_nonce('corsivo_mail_preview')),
			'baseTags'    => ['site_name', 'site_url', 'admin_email', 'date', 'year', 'subject'],
			'userTags'    => ['user_name', 'user_login', 'user_email', 'first_name', 'last_name', 'display_name'],
		]);
		wp_set_script_translations('corsivo-mail-email-editor', 'corsivo-mail', CORSIVO_MAIL_DIR . 'languages');
	}
}

function is_email_post_type($post_type) {
	return in_array($post_type, [Template\TEMPLATE_TYPE, Template\FOOTER_TYPE, Automation\TYPE], true);
}
