<?php

namespace CorsivoMail\Automation;

use CorsivoMail\Accounts;
use CorsivoMail\Queue;

if (!defined('ABSPATH')) {
	exit;
}

const SCHEDULE_LOCK = 'corsivo_mail_schedule_worker_lock';

add_action('corsivo_mail_process_queue', __NAMESPACE__ . '\\run_schedules', 5);

function run_schedules($force = false) {
	if (!$force && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
		return false;
	}
	$verified = Queue\verify_storage_before_work();
	if (is_wp_error($verified)) {
		return $verified;
	}
	return dispatch_schedules();
}

function dispatch_schedules() {
	if (!acquire_schedule_lock()) {
		return false;
	}

	try {
		foreach (active_emails() as $post) {
			$config = config($post->ID);
			if ($config['trigger_type'] !== 'schedule' || !$config['send_enabled'] || !$config['filter_enabled'] || !is_due($config)) {
				continue;
			}

			$key = occurrence_key($config);
			if (get_post_meta($post->ID, 'corsivo_mail_last_run_key', true) === $key) {
				continue;
			}
			if (get_transient('corsivo_mail_schedule_retry_' . $post->ID)) {
				continue;
			}

			$result = dispatch_schedule($post, $config, $key);
			if ($result['failed'] === 0) {
				update_post_meta($post->ID, 'corsivo_mail_last_run_key', $key);
			} else {
				set_transient('corsivo_mail_schedule_retry_' . $post->ID, 1, 15 * MINUTE_IN_SECONDS);
			}
		}
	} finally {
		delete_option(SCHEDULE_LOCK);
	}
	return true;
}

function acquire_schedule_lock() {
	$now = time();
	if (add_option(SCHEDULE_LOCK, $now, '', false)) {
		return true;
	}
	if ((int) get_option(SCHEDULE_LOCK, 0) > $now - 900) {
		return false;
	}
	delete_option(SCHEDULE_LOCK);
	return add_option(SCHEDULE_LOCK, $now, '', false);
}

function is_due($config) {
	$now = current_datetime();
	$time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $config['schedule_time']) ? $config['schedule_time'] : '09:00';
	$reached = $now->format('H:i') >= $time;
	$frequency = $config['schedule_freq'] ?: 'daily';

	if ($frequency === 'once') {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $config['schedule_date'] . ' ' . $time, wp_timezone());
		return $date && $now >= $date;
	}
	if ($frequency === 'daily') {
		return $reached;
	}
	if ($frequency === 'weekly') {
		return $reached && (int) $now->format('w') === (int) $config['schedule_day'];
	}
	if ($frequency === 'monthly') {
		return $reached && (int) $now->format('j') === (int) $config['schedule_day'];
	}
	return false;
}

function occurrence_key($config) {
	$now = current_datetime();
	$frequency = $config['schedule_freq'] ?: 'daily';
	if ($frequency === 'once') {
		return 'once:' . $config['schedule_date'];
	}
	if ($frequency === 'weekly') {
		return $now->format('o-\WW');
	}
	if ($frequency === 'monthly') {
		return $now->format('Y-m');
	}
	return $now->format('Y-m-d');
}

function dispatch_schedule($post, $config, $occurrence) {
	$result = ['queued' => 0, 'existing' => 0, 'failed' => 0, 'suppressed' => 0];
	$account = !empty($config['account_id']) ? Accounts\get($config['account_id']) : Accounts\get_default();
	if (!empty($config['account_id']) && !$account) {
		\CorsivoMail\Log\audit('schedule_account_missing', 'failed', [
			'source_type' => 'automation',
			'source_id' => $post->ID,
			'account_id' => $config['account_id'],
			'error_code' => 'account_missing',
		]);
		return ['queued' => 0, 'existing' => 0, 'failed' => 1, 'suppressed' => 0];
	}
	foreach (iterate_recipients($config) as $recipient) {
		if (empty($recipient['email'])) {
			continue;
		}
		$idempotency_key = 'schedule:' . $post->ID . ':' . $occurrence . ':' . strtolower($recipient['email']);
		$existing = Queue\get_by_idempotency($idempotency_key);
		if ($config['message_type'] === 'marketing' && function_exists('CorsivoMail\\Newsletter\\can_receive_marketing') && !\CorsivoMail\Newsletter\can_receive_marketing($recipient['email'])) {
			\CorsivoMail\Log\record('suppressed', $recipient['email'], $post->post_title, '', [
				'event' => 'marketing_suppressed',
				'source_type' => 'automation',
				'source_id' => $post->ID,
			]);
			$result['suppressed']++;
			continue;
		}
		$context = $recipient['user'] ? user_context($recipient['user']) : [];
		if (!empty($recipient['context'])) {
			$context = array_merge($context, $recipient['context']);
		}
		$rendered = render_automation_email($post, $config, $context);
		$headers = ['Content-Type: text/html; charset=UTF-8'];
		if ($config['message_type'] === 'marketing' && function_exists('CorsivoMail\\Newsletter\\add_marketing_headers')) {
			$mail = \CorsivoMail\Newsletter\add_marketing_headers(['to' => $recipient['email'], 'headers' => $headers]);
			$headers = $mail['headers'];
		}
		$idempotency_payload = wp_json_encode([
			'version' => 1,
			'domain' => 'scheduled-automation',
			'occurrence' => $occurrence,
			'to' => strtolower($recipient['email']),
			'subject' => $rendered['subject'],
			'message' => $rendered['html'],
			'headers' => $headers,
			'account_id' => $account['id'] ?? '',
			'source_type' => 'automation',
			'source_id' => (int) $post->ID,
			'message_type' => $config['message_type'],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($idempotency_payload)) {
			$result['failed']++;
			continue;
		}
		$queued = Queue\enqueue([
			'to' => $recipient['email'],
			'subject' => $rendered['subject'],
			'message' => $rendered['html'],
			'headers' => $headers,
			'account_id' => $account['id'] ?? '',
			'source_type' => 'automation',
			'source_id' => $post->ID,
			'message_type' => $config['message_type'],
			'idempotency_key' => $idempotency_key,
			'idempotency_fingerprint' => hash('sha256', $idempotency_payload),
		]);
		if (is_wp_error($queued)) {
			$result['failed']++;
			continue;
		}
		if ($existing && (int) $existing['id'] === (int) $queued) {
			$result['existing']++;
			continue;
		}
		\CorsivoMail\Log\record('queued', $recipient['email'], $rendered['subject'], '', [
			'event' => 'queued',
			'queue_id' => (int) $queued,
			'source_type' => 'automation',
			'source_id' => $post->ID,
			'account_id' => $account['id'] ?? '',
		]);
		$result['queued']++;
	}

	$produced = $result['queued'] + $result['existing'] + $result['suppressed'];
	if ($produced > 0 || $result['failed'] === 0) {
		update_post_meta($post->ID, 'corsivo_mail_last_run_count', $result['queued']);
		update_post_meta($post->ID, 'corsivo_mail_last_run_at', current_time('mysql'));
	}
	update_post_meta($post->ID, 'corsivo_mail_queued_total', (int) get_post_meta($post->ID, 'corsivo_mail_queued_total', true) + $result['queued']);
	$status = 'queued';
	if ($result['failed'] > 0) {
		$status = $result['queued'] > 0 ? 'partial' : 'failed';
	}
	\CorsivoMail\Log\audit('schedule_dispatched', $status, [
		'source_type' => 'automation',
		'source_id' => $post->ID,
		'processed' => $result['queued'],
		'queued' => $result['queued'],
		'failed' => $result['failed'],
		'suppressed' => $result['suppressed'],
		'existing' => $result['existing'],
	]);
	return $result;
}
