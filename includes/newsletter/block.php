<?php

namespace CorsivoMail\Newsletter;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', __NAMESPACE__ . '\\register_block');

function register_block() {
	register_block_type('corsivo-mail/newsletter', [
		'api_version' => 3,
		'title' => 'Iscrizione newsletter',
		'icon' => 'email',
		'category' => 'widgets',
		'editor_script' => 'corsivo-mail-newsletter-editor',
		'render_callback' => __NAMESPACE__ . '\\render_block',
		'attributes' => [
			'values' => ['type' => 'object', 'default' => []],
		],
		'supports' => [
			'inserter' => (bool) Options\get('module_subscriptions'),
		],
	]);
}

function render_block($attributes) {
	if (!Options\get('module_subscriptions') || !is_user_logged_in()) {
		return '';
	}

	$values       = $attributes['values'] ?? [];
	$label        = $values['label'] ?? 'Voglio ricevere la newsletter';
	$privacy_text = trim($values['privacy_text'] ?? '');
	$privacy_url  = trim($values['privacy_url'] ?? '');

	wp_enqueue_script('corsivo-mail-newsletter');

	$privacy_note = '';
	if ($privacy_text !== '') {
		$privacy_body = $privacy_url !== ''
			? sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($privacy_url), esc_html($privacy_text))
			: esc_html($privacy_text);
		$privacy_note = '<small class="corsivo-mail-newsletter-privacy" style="display:block;margin-top:4px;font-size:0.8em;color:#646970;">' . $privacy_body . '</small>';
	}

	return sprintf(
		'<div class="corsivo-mail-newsletter-optin" data-rest="%s" data-nonce="%s"><label><input type="checkbox" %s> %s <span class="corsivo-mail-newsletter-status" style="margin-left:8px;font-size:0.85em;color:#1a7f37;"></span></label>%s</div>',
		esc_url(rest_url('corsivo/v1/mail/subscription')),
		esc_attr(wp_create_nonce('wp_rest')),
		checked(is_subscribed(get_current_user_id()), true, false),
		esc_html($label),
		$privacy_note
	);
}

add_action('init', __NAMESPACE__ . '\\register_assets', 5);

function register_assets() {
	wp_register_script('corsivo-mail-newsletter', CORSIVO_MAIL_URL . 'assets/newsletter.js', [], CORSIVO_MAIL_VERSION, true);
	wp_register_script(
		'corsivo-mail-newsletter-editor',
		CORSIVO_MAIL_URL . 'assets/newsletter-editor.js',
		['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
		CORSIVO_MAIL_VERSION,
		true
	);
}
