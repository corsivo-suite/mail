<?php

namespace CorsivoMail\Template;

use CorsivoMail\Options;
use CorsivoMail\Accounts;
use CorsivoMail\Queue;
use CorsivoMail\Smtp;

if (!defined('ABSPATH')) {
	exit;
}

add_filter('wp_mail', __NAMESPACE__ . '\\prepare_mail', 10, 1);
add_filter('pre_wp_mail', __NAMESPACE__ . '\\maybe_enqueue', 10, 2);

function prepare_mail($atts) {
	unset($GLOBALS['corsivo_mail_mail_account_id']);
	$atts = apply_filters('corsivo_mail_prepare_context', $atts);
	if (!empty($atts['corsivo_mail_skip_wrap'])) {
		return $atts;
	}

	if (!empty($GLOBALS['corsivo_mail_override_active'])) {
		$atts['headers'] = ensure_html_headers($atts['headers'] ?? []);
		$atts['corsivo_mail_account_id'] = sanitize_text_field($GLOBALS['corsivo_mail_override_account_id'] ?? '');
		$GLOBALS['corsivo_mail_override_active'] = false;
		unset($GLOBALS['corsivo_mail_override_account_id']);
		return $atts;
	}

	if (!empty($GLOBALS['corsivo_mail_bypass_queue']) || !empty($GLOBALS['corsivo_mail_skip_wrap'])) {
		return $atts;
	}

	if (!Options\get('wrap_wp_mail')) {
		return $atts;
	}

	$atts['message'] = wrap_message($atts['subject'] ?? '', $atts['message'] ?? '');
	$atts['headers'] = ensure_html_headers($atts['headers'] ?? []);

	return $atts;
}

function maybe_enqueue($short, $atts) {
	if ($short !== null) {
		return $short;
	}
	$account_id = sanitize_text_field($atts['corsivo_mail_account_id'] ?? '');
	$account = $account_id ? Accounts\get($account_id) : Smtp\current_account();
	if ($account_id && !$account) {
		if (function_exists('CorsivoMail\\Log\\audit')) {
			\CorsivoMail\Log\audit('send_rejected', 'failed', ['account_id' => $account_id, 'error_code' => 'account_missing']);
		}
		return false;
	}
	if (!empty($GLOBALS['corsivo_mail_bypass_queue']) || !empty($GLOBALS['corsivo_mail_force_sync'])) {
		activate_mail_account($account_id);
		return $short;
	}

	if (!Options\get('use_queue')) {
		activate_mail_account($account_id);
		return $short;
	}
	if (!empty($atts['corsivo_mail_bypass_queue'])) {
		activate_mail_account($account_id);
		return $short;
	}
	if (!empty($atts['attachments']) || !empty($atts['embeds'])) {
		if (function_exists('CorsivoMail\\Log\\audit')) {
			\CorsivoMail\Log\audit('queue_bypassed_files', 'notice', ['source_type' => $atts['corsivo_mail_source_type'] ?? 'wp_mail']);
		}
		activate_mail_account($account_id);
		return $short;
	}

	$queued = Queue\enqueue([
		'to'          => $atts['to'] ?? '',
		'subject'     => $atts['subject'] ?? '',
		'message'     => $atts['message'] ?? '',
		'headers'     => $atts['headers'] ?? [],
		'attachments' => $atts['attachments'] ?? [],
		'embeds'      => $atts['embeds'] ?? [],
		'account_id'  => $account['id'] ?? '',
		'source_type' => $atts['corsivo_mail_source_type'] ?? 'wp_mail',
		'source_id'   => $atts['corsivo_mail_source_id'] ?? 0,
		'message_type' => ($GLOBALS['corsivo_mail_message_type'] ?? 'transactional') === 'marketing' ? 'marketing' : 'transactional',
	]);

	if (is_wp_error($queued)) {
		if (function_exists('CorsivoMail\\Log\\audit')) {
			\CorsivoMail\Log\audit('enqueue_failed', 'failed', ['error_code' => $queued->get_error_code()]);
		}
		$error = new \WP_Error(
			'corsivo_mail_enqueue_failed',
			'L’accodamento dell’email non è riuscito.',
			[
				'error_code' => $queued->get_error_code(),
				'mail_data' => is_array($atts) ? $atts : [],
			]
		);
		$GLOBALS['corsivo_mail_last_error'] = $error;
		Queue\dispatch_action('wp_mail_failed', $error);
		return false;
	}

	if (function_exists('CorsivoMail\\Log\\audit')) {
		\CorsivoMail\Log\record('queued', $atts['to'] ?? '', $atts['subject'] ?? '', '', [
			'event' => 'queued',
			'queue_id' => (int) $queued,
			'source_type' => $atts['corsivo_mail_source_type'] ?? 'wp_mail',
			'source_id' => $atts['corsivo_mail_source_id'] ?? 0,
			'account_id' => $account['id'] ?? '',
		]);
	}
	$GLOBALS['corsivo_mail_last_queue_id'] = (int) $queued;
	return true;
}

function activate_mail_account($account_id) {
	if ($account_id !== '') {
		$GLOBALS['corsivo_mail_mail_account_id'] = $account_id;
	}
}

function send_rendered($to, $subject, $html, $account_id = '', $headers = [], $message_type = 'transactional', $source = []) {
	$message_type = $message_type === 'marketing' ? 'marketing' : 'transactional';
	$log_context = array_filter([
		'source_type' => sanitize_key($source['source_type'] ?? ''),
		'source_id' => max(0, (int) ($source['source_id'] ?? 0)),
		'account_id' => sanitize_text_field($account_id),
	]);
	unset($GLOBALS['corsivo_mail_last_send_suppressed']);
	if ($message_type === 'marketing' && function_exists('CorsivoMail\\Newsletter\\can_receive_marketing') && !\CorsivoMail\Newsletter\can_receive_marketing($to)) {
		$GLOBALS['corsivo_mail_last_send_suppressed'] = true;
		\CorsivoMail\Log\record('suppressed', $to, $subject, '', array_merge($log_context, ['event' => 'marketing_suppressed']));
		return true;
	}
	$account = $account_id ? Accounts\get($account_id) : Accounts\get_default();
	if ($account_id && !$account) {
		\CorsivoMail\Log\audit('send_rejected', 'failed', ['account_id' => $account_id, 'error_code' => 'account_missing']);
		return false;
	}

	$previous_skip = $GLOBALS['corsivo_mail_skip_wrap'] ?? false;
	$previous_type = $GLOBALS['corsivo_mail_message_type'] ?? 'transactional';
	$previous_log_context = $GLOBALS['corsivo_mail_log_context'] ?? null;
	$GLOBALS['corsivo_mail_skip_wrap'] = true;
	$GLOBALS['corsivo_mail_message_type'] = $message_type;
	if ($log_context) {
		$GLOBALS['corsivo_mail_log_context'] = array_merge(is_array($previous_log_context) ? $previous_log_context : [], $log_context);
	}
	try {
		return Smtp\with_account($account, function () use ($to, $subject, $html, $headers) {
			return wp_mail($to, $subject, $html, array_merge(['Content-Type: text/html; charset=UTF-8'], (array) $headers));
		});
	} finally {
		$GLOBALS['corsivo_mail_skip_wrap'] = $previous_skip;
		$GLOBALS['corsivo_mail_message_type'] = $previous_type;
		if ($previous_log_context === null) {
			unset($GLOBALS['corsivo_mail_log_context']);
		} else {
			$GLOBALS['corsivo_mail_log_context'] = $previous_log_context;
		}
	}
}

function ensure_html_headers($headers) {
	$lines = normalize_headers($headers);

	$kept = [];
	foreach ($lines as $line) {
		if (stripos($line, 'content-type:') === 0) {
			continue;
		}
		$kept[] = $line;
	}

	$kept[] = 'Content-Type: text/html; charset=UTF-8';
	return $kept;
}

function normalize_headers($headers) {
	if (is_array($headers)) {
		return $headers;
	}
	if (!is_string($headers) || $headers === '') {
		return [];
	}
	return preg_split('/\r\n|\r|\n/', $headers);
}

function send_immediately($to, $subject, $html, $account_id = '', $extra_headers = []) {
	unset($GLOBALS['corsivo_mail_last_send_suppressed']);
	$account = $account_id ? Accounts\get($account_id) : Accounts\get_default();
	if ($account_id && !$account) {
		\CorsivoMail\Log\audit('send_rejected', 'failed', ['account_id' => $account_id, 'error_code' => 'account_missing']);
		return false;
	}

	$previous_skip = $GLOBALS['corsivo_mail_skip_wrap'] ?? false;
	$previous_bypass = $GLOBALS['corsivo_mail_bypass_queue'] ?? false;
	$GLOBALS['corsivo_mail_skip_wrap'] = true;
	$GLOBALS['corsivo_mail_bypass_queue'] = true;
	try {
		$headers = array_merge(['Content-Type: text/html; charset=UTF-8'], (array) $extra_headers);
		return Smtp\with_account($account, function () use ($to, $subject, $html, $headers) {
			return wp_mail($to, $subject, $html, $headers);
		});
	} finally {
		$GLOBALS['corsivo_mail_bypass_queue'] = $previous_bypass;
		$GLOBALS['corsivo_mail_skip_wrap'] = $previous_skip;
	}
}
