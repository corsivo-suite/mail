<?php
/**
 * Plugin Name: Corsivo Mail
 * Description: Motore email per WordPress: connessioni SMTP multi-account, coda di invio, template email costruiti con l'editor a blocchi e wrapping automatico di wp_mail in un layout stilizzato.
 * Version: 0.23.4
 * Update URI: https://www.corsivo.dev/plugins/corsivo-mail
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Salvatore Corsi
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: corsivo-mail
 */

namespace CorsivoMail;

if (!defined('ABSPATH')) {
	exit;
}

define('CORSIVO_MAIL_VERSION', '0.23.4');
define('CORSIVO_MAIL_FILE', __FILE__);
define('CORSIVO_MAIL_DIR', plugin_dir_path(__FILE__));
define('CORSIVO_MAIL_URL', plugin_dir_url(__FILE__));
const ADMIN_STORAGE_VERIFY_OPTION = 'corsivo_mail_storage_admin_verify_after';
const ADMIN_STORAGE_VERIFY_INTERVAL = 900;

require_once CORSIVO_MAIL_DIR . 'includes/settings/crypto.php';
require_once CORSIVO_MAIL_DIR . 'includes/settings/accounts.php';
require_once CORSIVO_MAIL_DIR . 'includes/settings/options.php';
require_once CORSIVO_MAIL_DIR . 'includes/queue/schema.php';
require_once CORSIVO_MAIL_DIR . 'includes/storage/log.php';
require_once CORSIVO_MAIL_DIR . 'includes/imap/schema.php';
require_once CORSIVO_MAIL_DIR . 'includes/queue/queue.php';
require_once CORSIVO_MAIL_DIR . 'includes/queue/worker.php';
require_once CORSIVO_MAIL_DIR . 'includes/queue/cron-endpoint.php';

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');

function prime_required_storage_options() {
	static $primed = false;

	if ($primed || !function_exists('wp_prime_option_caches')) {
		return;
	}
	$options = array(
		Options\OPTION,
		Queue\STORAGE_UPDATE_VERIFY_OPTION,
		'corsivo_mail_seeded_core_emails',
		'corsivo_forms_settings',
	);
	foreach (array(
		Queue\current_storage_configuration(),
		Storage\Log\configuration(),
		Imap\storage_configuration(),
	) as $configuration) {
		$options[] = $configuration['intent_option'];
		$options[] = $configuration['failure_option'];
		$options[] = $configuration['attestation_option'];
	}
	wp_prime_option_caches(array_values(array_unique($options)));
	$primed = true;
}

function required_storage_ready($refresh = false) {
	prime_required_storage_options();
	if (!Queue\schema_ready($refresh) || !Storage\Log\ready($refresh)) {
		return false;
	}
	return !Options\get('module_imap') || Imap\schema_ready($refresh);
}

function required_storage_configurations() {
	$configurations = array(
		Queue\current_storage_configuration(),
		Storage\Log\configuration(),
	);
	if (Options\get('module_imap')) {
		$configurations[] = Imap\storage_configuration();
	}
	return $configurations;
}

function verified_storage_runtime_ready() {
	foreach (required_storage_configurations() as $configuration) {
		if (!Queue\storage_runtime_ready($configuration, true, true)) {
			return false;
		}
	}
	return true;
}

function install_required_storage() {
	foreach (required_storage_configurations() as $configuration) {
		if (!Queue\ensure_fresh_storage($configuration, true)) {
			return new \WP_Error('corsivo_mail_storage_activation_failed', 'Installazione dello storage Mail non riuscita.');
		}
	}
	if (!verified_storage_runtime_ready()) {
		return new \WP_Error(
			'corsivo_mail_storage_activation_unverified',
			'Lo storage Mail installato non supera la verifica finale.'
		);
	}
	return true;
}

function verify_required_storage() {
	foreach (required_storage_configurations() as $configuration) {
		if (!Queue\ensure_fresh_storage($configuration)) {
			return new \WP_Error('corsivo_mail_storage_verification_failed', 'Verifica fisica dello storage Mail non riuscita.');
		}
	}
	return verified_storage_runtime_ready()
		? true
		: new \WP_Error('corsivo_mail_storage_verification_failed', 'Lo storage Mail verificato non supera il gate runtime.');
}

function verify_storage_with_update_fence() {
	$requirement = Queue\storage_update_verification_record();
	if (is_wp_error($requirement)) {
		return $requirement;
	}
	if ($requirement['exists'] && !Queue\storage_uuid4_is_valid($requirement['value'])) {
		return new \WP_Error('corsivo_mail_storage_update_fence_invalid', 'Il fence di verifica storage Mail non è valido.');
	}
	$verified = verify_required_storage();
	if (is_wp_error($verified) || !$requirement['exists']) {
		return $verified;
	}
	return Queue\clear_storage_update_verification($requirement['value']);
}

function runtime_files() {
	return array(
		'includes/security.php',
		'includes/settings/page.php',
		'includes/settings/transfer.php',
		'includes/smtp/phpmailer.php',
		'includes/queue/log.php',
		'includes/template/cpt.php',
		'includes/template/merge-tags.php',
		'includes/template/compiler.php',
		'includes/template/render.php',
		'includes/template/wp-mail-hook.php',
		'includes/blocks/content-slot.php',
		'includes/blocks/editor.php',
		'includes/automation/cpt.php',
		'includes/automation/payload.php',
		'includes/automation/core-emails.php',
		'includes/functions.php',
		'includes/automation/audience.php',
		'includes/automation/send.php',
		'includes/automation/bulk.php',
		'includes/automation/admin-columns.php',
		'includes/automation/events.php',
		'includes/automation/meta-rest.php',
		'includes/automation/schedule.php',
		'includes/automation/runtime.php',
		'includes/automation/preview.php',
		'includes/automation/seed.php',
		'includes/newsletter/subscription.php',
		'includes/newsletter/unsubscribe.php',
		'includes/newsletter/groups.php',
		'includes/newsletter/groups-page.php',
		'includes/newsletter/block.php',
		'includes/imap/client.php',
		'includes/imap/sync.php',
		'includes/imap/inbox.php',
		'includes/imap/page.php',
		'includes/imap/test.php',
		'includes/integrations/layout-selector.php',
		'includes/integrations/corsivo-theme.php',
		'includes/integrations/wpml.php',
		'includes/integrations/woocommerce.php',
		'includes/api.php',
	);
}

function load_runtime() {
	static $loaded = false;

	if ($loaded) {
		return true;
	}
	if (!required_storage_ready()) {
		return false;
	}
	$loaded = true;
	foreach (runtime_files() as $relative_path) {
		require_once CORSIVO_MAIL_DIR . $relative_path;
	}
	return true;
}

function fail_closed_wp_mail($short_circuit, $attributes) {
	if ($short_circuit !== null) {
		return $short_circuit;
	}
	if (!governs_global_transport()) {
		return null;
	}
	if (required_storage_ready()) {
		return null;
	}
	$error = new \WP_Error(
		'corsivo_mail_storage_unavailable',
		'Corsivo Mail ha bloccato l’invio perché lo storage richiesto non è verificabile.',
		array('mail_data' => is_array($attributes) ? $attributes : array())
	);
	$GLOBALS['corsivo_mail_last_error'] = $error;
	global $wp_actions, $wp_current_filter, $wp_filter;
	$wp_actions['wp_mail_failed'] = ($wp_actions['wp_mail_failed'] ?? 0) + 1;
	$wp_current_filter[] = 'wp_mail_failed';
	$dispatch = static function ($callbacks, $args) {
		foreach ($callbacks as $priority_callbacks) {
			foreach ((array) $priority_callbacks as $callback) {
				try {
					$accepted_args = max(0, (int) ($callback['accepted_args'] ?? 0));
					if ($accepted_args === 0) {
						call_user_func($callback['function']);
					} else {
						call_user_func_array($callback['function'], array_slice($args, 0, $accepted_args));
					}
				} catch (\Throwable $throwable) {
					$GLOBALS['corsivo_mail_wp_mail_failed_listener_errors'] =
						(int) ($GLOBALS['corsivo_mail_wp_mail_failed_listener_errors'] ?? 0) + 1;
				}
			}
		}
	};
	try {
		if (isset($wp_filter['all']) && is_object($wp_filter['all']) && isset($wp_filter['all']->callbacks)) {
			$dispatch((array) $wp_filter['all']->callbacks, array('wp_mail_failed', $error));
		}
		if (isset($wp_filter['wp_mail_failed']) && is_object($wp_filter['wp_mail_failed']) && isset($wp_filter['wp_mail_failed']->callbacks)) {
			$dispatch((array) $wp_filter['wp_mail_failed']->callbacks, array($error));
		}
	} finally {
		array_pop($wp_current_filter);
	}
	return false;
}

function governs_global_transport() {
	if (Options\get('use_queue')) {
		return true;
	}
	$account = Accounts\get_default();
	return is_array($account) && trim((string) ($account['smtp_host'] ?? '')) !== '';
}

function verify_storage_event($reason = 'scheduled') {
	$verified = verify_storage_with_update_fence();
	if (!is_wp_error($verified)) {
		load_runtime();
	}
	Queue\reconcile_cron();
	return $verified;
}

function claim_admin_storage_verification() {
	global $wpdb;

	$now = time();
	$verify_after = (string) ($now + ADMIN_STORAGE_VERIFY_INTERVAL);
	if (add_option(ADMIN_STORAGE_VERIFY_OPTION, $verify_after, '', false)) {
		return $verify_after;
	}

	$wpdb->last_error = '';
	$current = $wpdb->get_var($wpdb->prepare(
		"SELECT option_value
		FROM {$wpdb->options}
		WHERE option_name = %s
		LIMIT 1",
		ADMIN_STORAGE_VERIFY_OPTION
	));
	if ($wpdb->last_error !== '') {
		return false;
	}
	if ($current === null) {
		wp_cache_delete(ADMIN_STORAGE_VERIFY_OPTION, 'options');
		wp_cache_delete('alloptions', 'options');
		return add_option(ADMIN_STORAGE_VERIFY_OPTION, $verify_after, '', false) ? $verify_after : false;
	}

	$current = (string) $current;
	if (
		preg_match('/^\d+$/D', $current)
		&& (int) $current > $now
		&& (int) $current <= $now + ADMIN_STORAGE_VERIFY_INTERVAL + 60
	) {
		return false;
	}

	$wpdb->last_error = '';
	$claimed = $wpdb->query($wpdb->prepare(
		"UPDATE {$wpdb->options}
		SET option_value = %s
		WHERE option_name = %s AND option_value = %s",
		$verify_after,
		ADMIN_STORAGE_VERIFY_OPTION,
		$current
	));
	wp_cache_delete(ADMIN_STORAGE_VERIFY_OPTION, 'options');
	wp_cache_delete('alloptions', 'options');
	return $wpdb->last_error === '' && $claimed === 1 ? $verify_after : false;
}

function release_admin_storage_verification($verify_after) {
	global $wpdb;

	$wpdb->last_error = '';
	$released = $wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		WHERE option_name = %s AND option_value = %s",
		ADMIN_STORAGE_VERIFY_OPTION,
		(string) $verify_after
	));
	wp_cache_delete(ADMIN_STORAGE_VERIFY_OPTION, 'options');
	wp_cache_delete('alloptions', 'options');
	return $wpdb->last_error === '' && $released === 1;
}

function verify_storage_on_admin($verification_callback = null) {
	if (!current_user_can('manage_options')) {
		return false;
	}

	$verify_after = claim_admin_storage_verification();
	if ($verify_after === false) {
		return false;
	}

	$verification_callback = is_callable($verification_callback)
		? $verification_callback
		: __NAMESPACE__ . '\\verify_storage_event';
	try {
		$verified = call_user_func($verification_callback, 'admin');
	} catch (\Throwable $error) {
		$verified = new \WP_Error('corsivo_mail_storage_verification_failed', 'Verifica fisica dello storage Mail non riuscita.');
	}
	if (is_wp_error($verified) || $verified !== true) {
		release_admin_storage_verification($verify_after);
	}
	return $verified;
}

function schedule_storage_verification_after_update($upgrader, $options) {
	if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
		return;
	}
	$plugins = array_map('strval', (array) ($options['plugins'] ?? array()));
	if (!empty($options['plugin'])) {
		$plugins[] = (string) $options['plugin'];
	}
	if (!in_array(plugin_basename(CORSIVO_MAIL_FILE), $plugins, true)) {
		return;
	}
	$requirement = Queue\persist_storage_update_verification();
	foreach (required_storage_configurations() as $configuration) {
		Queue\invalidate_storage_gate($configuration);
	}
	Queue\schedule_storage_verification('update', 1);
	return $requirement;
}

function storage_unavailable_notice() {
	if (!current_user_can('manage_options') || required_storage_ready()) {
		return;
	}
	echo '<div class="notice notice-error"><p>'
		. esc_html__('Corsivo Mail è bloccato: lo storage richiesto non è disponibile o non corrisponde allo schema corrente. Nessuna email viene inviata.', 'corsivo-mail')
		. '</p></div>';
}

function activate() {
	Options\set_defaults();
	$installed = install_required_storage();
	if (is_wp_error($installed)) {
		throw new \RuntimeException($installed->get_error_message());
	}
	if (!load_runtime()) {
		throw new \RuntimeException('Corsivo Mail runtime activation failed.');
	}
	Template\register_post_types();
	Automation\seed_core_emails(true);
	flush_rewrite_rules();
	Queue\reconcile_cron();
}

function deactivate() {
	Queue\clear_cron();
	delete_option(ADMIN_STORAGE_VERIFY_OPTION);
	delete_option(Queue\STORAGE_UPDATE_VERIFY_OPTION);
	flush_rewrite_rules();
}

add_action('admin_notices', __NAMESPACE__ . '\\storage_unavailable_notice');
add_action('admin_notices', 'CorsivoMail\\Queue\\schema_failure_notice');
add_action('corsivo_mail_verify_storage', __NAMESPACE__ . '\\verify_storage_event', 10, 1);
add_action('upgrader_process_complete', __NAMESPACE__ . '\\schedule_storage_verification_after_update', 10, 2);
add_filter('cron_schedules', 'CorsivoMail\\Queue\\cron_schedules');
add_filter('pre_wp_mail', __NAMESPACE__ . '\\fail_closed_wp_mail', PHP_INT_MAX, 2);

prime_required_storage_options();
if (Queue\storage_update_verification_required()) {
	verify_storage_with_update_fence();
}
load_runtime();
Queue\reconcile_cron();
