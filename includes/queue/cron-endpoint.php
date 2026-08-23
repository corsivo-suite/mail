<?php

namespace CorsivoMail\Queue;

if (!defined('ABSPATH')) {
	exit;
}

function cron_endpoint_key() {
	$key = get_option('corsivo_mail_cron_key');
	if (!$key) {
		$key = wp_generate_password(40, false);
	}
	if ($key !== get_option('corsivo_mail_cron_key')) {
		update_option('corsivo_mail_cron_key', $key, false);
	}
	return $key;
}

add_action('init', __NAMESPACE__ . '\\maybe_run_cron_endpoint', 1);
function maybe_run_cron_endpoint() {
	if (!isset($_GET['corsivo_mail_cron'])) {
		return;
	}
	$provided = sanitize_text_field(wp_unslash($_GET['corsivo_mail_cron']));
	if (isset($_SERVER['HTTP_X_CORSIVO_MAIL_KEY'])) {
		$provided = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_CORSIVO_MAIL_KEY']));
	}
	if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(.+)$/i', wp_unslash($_SERVER['HTTP_AUTHORIZATION']), $match)) {
		$provided = sanitize_text_field($match[1]);
	}
	if ($provided === '') {
		return;
	}
	if (!hash_equals(cron_endpoint_key(), $provided)) {
		status_header(403);
		exit;
	}

	nocache_headers();
	header('Content-Type: text/plain; charset=utf-8');

	$verified = verify_storage_before_work();
	if (is_wp_error($verified)) {
		status_header(503);
		echo "error - storage_unavailable\n";
		exit;
	}
	$schedules = \CorsivoMail\Automation\run_schedules(true);
	if (is_wp_error($schedules)) {
		status_header(503);
		echo "error - storage_unavailable\n";
		exit;
	}
	$result = process();
	spawn_cron();

	if (is_wp_error($result) || ($result['status'] ?? '') === 'unavailable') {
		status_header(503);
		$error_code = is_wp_error($result)
			? $result->get_error_code()
			: ($result['error_code'] ?? 'corsivo_mail_storage_verification_failed');
		echo 'error - ' . sanitize_key($error_code) . "\n";
		exit;
	}
	if (($result['status'] ?? '') === 'paused') {
		echo "paused - invio globale disattivato\n";
		exit;
	}
	$counts = counts();
	$pending = (int) $counts['pending'];
	echo 'ok - coda processata, in attesa: ' . $pending . "\n";
	exit;
}
