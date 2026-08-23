<?php

namespace CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

const OPTION = 'corsivo_mail_settings';

function defaults() {
	return [
		'default_wrapper_id' => 0,
		'use_queue'          => true,
		'queue_batch_size'   => 20,
		'queue_max_retries'  => 3,
		'queue_sleep_ms'     => 0,
		'queue_lock_minutes' => 10,
		'queue_cleanup_days' => 30,
		'log_retention_days' => 90,
		'wrap_wp_mail'       => true,
		'disable_sending'    => false,
		'footer_floating'    => false,
		'container_radius'   => 0,
		'user_meta_keys'     => [],
		'module_imap'        => true,
		'module_subscriptions' => !external_newsletter_active(),
		'integration_corsivo_theme' => false,
		'integration_wpml'   => false,
		'integration_woocommerce' => false,
	];
}

function external_newsletter_active() {
	$active = (array) get_option('active_plugins', []);
	if (in_array('corsivo-newsletter/corsivo-newsletter.php', $active, true)) {
		return true;
	}
	if (is_multisite()) {
		$network = (array) get_site_option('active_sitewide_plugins', []);
		return isset($network['corsivo-newsletter/corsivo-newsletter.php']);
	}
	return false;
}

add_filter('pre_wp_mail', __NAMESPACE__ . '\\block_sending', 1, 2);

function block_sending($short, $atts) {
	if ($short !== null || !get('disable_sending') || !\CorsivoMail\governs_global_transport()) {
		return $short;
	}
	$GLOBALS['corsivo_mail_last_send_suppressed'] = true;
	if (function_exists('CorsivoMail\\Log\\record')) {
		\CorsivoMail\Log\record('suppressed', $atts['to'] ?? '', $atts['subject'] ?? '', '', [
			'event' => 'delivery_suppressed',
		]);
	}
	return true;
}

add_action('admin_notices', function () {
	if (get('disable_sending') && \CorsivoMail\governs_global_transport() && current_user_can('manage_options')) {
		echo '<div class="notice notice-warning"><p><strong>Mail:</strong> l\'invio email è <strong>disattivato</strong> (debug). Nessuna email esce, comprese quelle di WordPress.</p></div>';
	}
});

function all() {
	return wp_parse_args(get_option(OPTION, []), defaults());
}

function get($key) {
	$all = all();
	return $all[$key] ?? null;
}

function set_defaults() {
	$existing = get_option(OPTION, null);
	if ($existing === null) {
		update_option(OPTION, defaults());
	}
}

function save($input) {
	$current = all();
	$clean = [
		'default_wrapper_id' => (int) ($input['default_wrapper_id'] ?? 0),
		'use_queue'          => !empty($input['use_queue']),
		'queue_batch_size'   => max(1, min(100, (int) ($input['queue_batch_size'] ?? 20))),
		'queue_max_retries'  => max(1, min(10, (int) ($input['queue_max_retries'] ?? 3))),
		'queue_sleep_ms'     => max(0, min(10000, (int) ($input['queue_sleep_ms'] ?? 0))),
		'queue_lock_minutes' => max(3, min(1440, (int) ($input['queue_lock_minutes'] ?? 10))),
		'queue_cleanup_days' => max(1, min(365, (int) ($input['queue_cleanup_days'] ?? 30))),
		'log_retention_days' => max(1, min(3650, (int) ($input['log_retention_days'] ?? 90))),
		'wrap_wp_mail'       => !empty($input['wrap_wp_mail']),
		'disable_sending'    => !empty($input['disable_sending']),
		'footer_floating'    => !empty($current['footer_floating']),
		'container_radius'   => sanitize_container_radius($current['container_radius'] ?? 0),
		'user_meta_keys'     => (array) ($current['user_meta_keys'] ?? []),
		'module_imap'        => !empty($current['module_imap']),
		'module_subscriptions' => !empty($current['module_subscriptions']),
		'integration_corsivo_theme' => !empty($current['integration_corsivo_theme']),
		'integration_wpml'   => !empty($current['integration_wpml']),
		'integration_woocommerce' => !empty($current['integration_woocommerce']),
	];
	return persist($clean);
}

function save_integrations($input) {
	$clean = all();
	$keys = preg_split('/[\r\n,]+/', (string) ($input['user_meta_keys'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
	$clean['user_meta_keys'] = array_values(array_unique(array_filter(array_map('sanitize_key', $keys))));
	$clean['module_imap'] = !empty($input['module_imap']);
	$clean['module_subscriptions'] = !empty($input['module_subscriptions']);
	$clean['integration_corsivo_theme'] = !empty($input['integration_corsivo_theme']);
	$clean['integration_wpml'] = !empty($input['integration_wpml']);
	$clean['integration_woocommerce'] = !empty($input['integration_woocommerce']);
	return persist($clean);
}

function save_style($input) {
	$clean = all();

	$clean['footer_floating'] = !empty($input['footer_floating']);
	$clean['container_radius'] = sanitize_container_radius($input['container_radius'] ?? 0);

	return persist($clean);
}

function persist($clean) {
	if (!update_option(OPTION, $clean) && get_option(OPTION, null) !== $clean) {
		return new \WP_Error('corsivo_mail_settings_save_failed', 'Salvataggio delle impostazioni non riuscito.');
	}
	return $clean;
}

function sanitize_container_radius($value) {
	return max(0, min(48, (int) $value));
}
