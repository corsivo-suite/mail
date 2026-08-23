<?php

namespace CorsivoMail\Log;

use CorsivoMail\Options;
use CorsivoMail\Smtp;

if (!defined('ABSPATH')) {
	exit;
}

function table_name() {
	return \CorsivoMail\Storage\Log\table_name();
}

function schema_ready($refresh = false) {
	return \CorsivoMail\Storage\Log\ready($refresh);
}

add_action('wp_mail_succeeded', __NAMESPACE__ . '\\log_success');
add_action('wp_mail_failed', __NAMESPACE__ . '\\log_failure');
add_action('corsivo_mail_cleanup_queue', __NAMESPACE__ . '\\purge');
add_action('save_post', __NAMESPACE__ . '\\log_content_saved', 20, 3);
add_action('before_delete_post', __NAMESPACE__ . '\\log_content_deleted', 10, 2);

function log_content_saved($post_id, $post, $update) {
	$post_types = [\CorsivoMail\Automation\TYPE, \CorsivoMail\Template\TEMPLATE_TYPE, \CorsivoMail\Template\FOOTER_TYPE];
	if (!$post || !in_array($post->post_type, $post_types, true) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
		return;
	}
	if ($post->post_status === 'auto-draft') {
		return;
	}
	audit($update ? 'content_updated' : 'content_created', 'notice', [
		'source_type' => $post->post_type,
		'source_id' => (int) $post_id,
		'current' => $post->post_status,
	]);
}

function log_content_deleted($post_id, $post) {
	$post_types = [\CorsivoMail\Automation\TYPE, \CorsivoMail\Template\TEMPLATE_TYPE, \CorsivoMail\Template\FOOTER_TYPE];
	if ($post && in_array($post->post_type, $post_types, true)) {
		audit('content_deleted', 'notice', ['source_type' => $post->post_type, 'source_id' => (int) $post_id]);
	}
}

function log_success($mail_data) {
	record('sent', $mail_data['to'] ?? '', $mail_data['subject'] ?? '', '', ['event' => 'transport_accepted']);
}

function log_failure($wp_error) {
	$data = $wp_error->get_error_data('wp_mail_failed');
	record('failed', $data['to'] ?? '', $data['subject'] ?? '', $wp_error->get_error_message(), [
		'event' => 'transport_failed',
		'error_code' => $wp_error->get_error_code(),
	]);
}

function record($status, $to, $subject, $error = '', $context = []) {
	global $wpdb;
	if (!schema_ready()) {
		return false;
	}

	$runtime = is_array($GLOBALS['corsivo_mail_log_context'] ?? null) ? $GLOBALS['corsivo_mail_log_context'] : [];
	$context = array_merge($runtime, $context);
	$account = Smtp\current_account();
	$recipient = normalize_recipient($to);
	$recipient_emails = recipient_emails($recipient);
	$metadata = sanitize_metadata($context['metadata'] ?? []);

	return $wpdb->insert(table_name(), [
		'event' => sanitize_key($context['event'] ?? ($status === 'sent' ? 'transport_accepted' : 'transport_failed')),
		'queue_id' => max(0, (int) ($context['queue_id'] ?? 0)),
		'attempt' => max(0, (int) ($context['attempt'] ?? 0)),
		'source_type' => sanitize_key($context['source_type'] ?? ''),
		'source_id' => max(0, (int) ($context['source_id'] ?? 0)),
		'account_id' => sanitize_text_field($context['account_id'] ?? ($account['id'] ?? '')),
		'actor_user_id' => max(0, (int) ($context['actor_user_id'] ?? get_current_user_id())),
		'recipient' => $recipient,
		'recipient_hash' => count($recipient_emails) === 1 ? hash('sha256', strtolower($recipient_emails[0])) : '',
		'subject' => sanitize_text_field((string) $subject),
		'status' => sanitize_key($status),
		'error_code' => sanitize_key($context['error_code'] ?? ''),
		'error' => $error !== '' ? redact_error($error) : null,
		'metadata' => $metadata ? wp_json_encode($metadata) : null,
		'created_at' => current_time('mysql'),
	]);
}

function redact_error($error) {
	$error = sanitize_text_field(substr((string) $error, 0, 1000));
	$error = preg_replace('/\b(password|pass|token|secret|key)=([^\s&]+)/i', '$1=[redacted]', $error);
	return preg_replace_callback('#https?://[^\s]+#i', function ($match) {
		$url = wp_parse_url(rtrim($match[0], '.,;)'));
		if (!$url || empty($url['host'])) {
			return '[url]';
		}
		$scheme = $url['scheme'] ?? 'https';
		$path = $url['path'] ?? '';
		return $scheme . '://' . $url['host'] . $path;
	}, $error);
}

function audit($event, $status = 'notice', $context = []) {
	$metadata = $context;
	foreach (['queue_id', 'attempt', 'source_type', 'source_id', 'account_id', 'actor_user_id', 'error_code'] as $key) {
		unset($metadata[$key]);
	}
	$context['event'] = $event;
	$context['metadata'] = $metadata;
	return record($status, '', '', '', $context);
}

function sanitize_metadata($metadata) {
	if (!is_array($metadata)) {
		return [];
	}
	$allowed = ['duration_ms', 'processed', 'created', 'updated', 'deleted', 'reason', 'module', 'previous', 'current', 'groups', 'records', 'field', 'queued', 'failed', 'suppressed', 'existing', 'rows'];
	$out = [];
	foreach ($allowed as $key) {
		if (!array_key_exists($key, $metadata) || !is_scalar($metadata[$key])) {
			continue;
		}
		$out[$key] = is_numeric($metadata[$key]) ? 0 + $metadata[$key] : sanitize_text_field((string) $metadata[$key]);
	}
	return $out;
}

function normalize_recipient($to) {
	if (is_array($to)) {
		$to = implode(', ', array_map('strval', $to));
	}
	return sanitize_text_field(substr((string) $to, 0, 2000));
}

function mask_recipient($recipient) {
	return preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', function ($match) {
		$email = $match[0];
		list($local, $domain) = explode('@', $email, 2);
		return substr($local, 0, 1) . str_repeat('*', max(2, min(8, strlen($local) - 1))) . '@' . $domain;
	}, (string) $recipient);
}

function recipient_emails($recipient) {
	preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $recipient, $matches);
	return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
}

function recipient_regexp($email_address) {
	$email = preg_quote(strtolower($email_address), '/');
	return '(^|[^a-z0-9._%+-])' . $email . '($|[^a-z0-9._%+-])';
}

function purge() {
	global $wpdb;
	if (!schema_ready()) {
		return 0;
	}
	$cutoff = current_datetime()->modify('-' . (int) Options\get('log_retention_days') . ' days')->format('Y-m-d H:i:s');
	$total = 0;
	for ($batch = 0; $batch < 20; $batch++) {
		$deleted = $wpdb->query($wpdb->prepare("DELETE FROM " . table_name() . " WHERE created_at < %s LIMIT 1000", $cutoff));
		if ($deleted === false) {
			return false;
		}
		$total += $deleted;
		if ($deleted < 1000) {
			break;
		}
	}
	return $total;
}

function counts($search = '', $status = '', $event = '') {
	global $wpdb;
	if (!schema_ready()) {
		return ['sent' => 0, 'failed' => 0, 'suppressed' => 0, 'queued' => 0, 'notice' => 0];
	}
	list($where, $params) = query_conditions($search, $status, $event);
	$sql = "SELECT status, COUNT(*) AS n FROM " . table_name() . " WHERE {$where} GROUP BY status";
	$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
	$out = ['sent' => 0, 'failed' => 0, 'suppressed' => 0, 'queued' => 0, 'notice' => 0];
	foreach ($rows as $row) {
		$out[$row['status']] = (int) $row['n'];
	}
	return $out;
}

function recent($limit = 50, $search = '', $status = '', $event = '', $offset = 0) {
	global $wpdb;
	if (!schema_ready()) {
		return [];
	}
	list($where, $params) = query_conditions($search, $status, $event);
	$params[] = max(1, min(200, (int) $limit));
	$params[] = max(0, (int) $offset);
	return $wpdb->get_results(
		$wpdb->prepare("SELECT * FROM " . table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params),
		ARRAY_A
	);
}

function total($search = '', $status = '', $event = '') {
	global $wpdb;
	if (!schema_ready()) {
		return 0;
	}
	list($where, $params) = query_conditions($search, $status, $event);
	$sql = "SELECT COUNT(*) FROM " . table_name() . " WHERE {$where}";
	return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
}

function query_conditions($search, $status, $event) {
	global $wpdb;
	$where = '1=1';
	$params = [];
	if ($status !== '') {
		$where .= ' AND status = %s';
		$params[] = sanitize_key($status);
	}
	if ($event !== '') {
		$where .= ' AND event = %s';
		$params[] = sanitize_key($event);
	}
	if ($search !== '') {
		$like = '%' . $wpdb->esc_like($search) . '%';
		$where .= ' AND (recipient LIKE %s OR subject LIKE %s OR error LIKE %s)';
		array_push($params, $like, $like, $like);
	}
	return [$where, $params];
}

function events() {
	global $wpdb;
	if (!schema_ready()) {
		return [];
	}
	return $wpdb->get_col('SELECT DISTINCT event FROM ' . table_name() . ' ORDER BY event ASC');
}

add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
	$erasers['corsivo-mail-log'] = [
		'eraser_friendly_name' => 'Corsivo Mail log',
		'callback' => __NAMESPACE__ . '\\erase_personal_data',
	];
	$erasers['corsivo-mail-queue'] = [
		'eraser_friendly_name' => 'Corsivo Mail coda di invio',
		'callback' => __NAMESPACE__ . '\\erase_queue_personal_data',
	];
	return $erasers;
});

add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
	$exporters['corsivo-mail-log'] = [
		'exporter_friendly_name' => 'Corsivo Mail log',
		'callback' => __NAMESPACE__ . '\\export_personal_data',
	];
	$exporters['corsivo-mail-queue'] = [
		'exporter_friendly_name' => 'Corsivo Mail coda di invio',
		'callback' => __NAMESPACE__ . '\\export_queue_personal_data',
	];
	return $exporters;
});

function export_queue_personal_data($email_address, $page = 1) {
	global $wpdb;
	if (!is_email($email_address) || !\CorsivoMail\Queue\runtime_ready()) {
		return ['data' => [], 'done' => true];
	}
	$per_page = 100;
	$recipient_pattern = recipient_regexp($email_address);
	$rows = $wpdb->get_results($wpdb->prepare(
		'SELECT id, subject, status, scheduled_at, created_at FROM ' . \CorsivoMail\Queue\table_name() . ' WHERE LOWER(recipient) REGEXP %s ORDER BY id ASC LIMIT %d OFFSET %d',
		$recipient_pattern,
		$per_page,
		(max(1, (int) $page) - 1) * $per_page
	), ARRAY_A);
	$data = [];
	foreach ($rows as $row) {
		$data[] = [
			'group_id' => 'corsivo-mail-queue',
			'group_label' => 'Corsivo Mail — coda di invio',
			'item_id' => 'corsivo-mail-queue-' . (int) $row['id'],
			'data' => [
				['name' => 'Creata il', 'value' => $row['created_at']],
				['name' => 'Programmata per', 'value' => $row['scheduled_at']],
				['name' => 'Oggetto', 'value' => $row['subject']],
				['name' => 'Stato', 'value' => $row['status']],
			],
		];
	}
	return ['data' => $data, 'done' => count($rows) < $per_page];
}

function erase_queue_personal_data($email_address, $page = 1) {
	global $wpdb;
	if (!is_email($email_address) || !\CorsivoMail\Queue\runtime_ready()) {
		return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
	}
	$table = \CorsivoMail\Queue\table_name();
	$recipient_pattern = recipient_regexp($email_address);
	$deleted = (int) $wpdb->query($wpdb->prepare(
		"DELETE FROM {$table} WHERE status IN ('pending', 'failed') AND LOWER(recipient) REGEXP %s LIMIT 500",
		$recipient_pattern
	));
	$masked = (int) $wpdb->query($wpdb->prepare(
		"UPDATE {$table} SET recipient = '[removed]', subject = '[removed]', error = NULL, idempotency_key = NULL
		 WHERE status IN ('sent', 'cancelled', 'delivery_unknown') AND LOWER(recipient) REGEXP %s LIMIT 500",
		$recipient_pattern
	));
	$in_flight = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE status IN ('sending', 'transmitting') AND LOWER(recipient) REGEXP %s",
		$recipient_pattern
	));
	$messages = [];
	if ($in_flight > 0) {
		$messages[] = 'Alcune email verso questo indirizzo sono in lavorazione: rilancia la cancellazione al termine dell\'invio in corso.';
	}
	return [
		'items_removed' => ($deleted > 0 || $masked > 0),
		'items_retained' => $in_flight > 0,
		'messages' => $messages,
		'done' => ($deleted < 500 && $masked < 500),
	];
}

function export_personal_data($email_address, $page = 1) {
	global $wpdb;
	if (!is_email($email_address) || !schema_ready()) {
		return ['data' => [], 'done' => true];
	}
	$per_page = 100;
	$hash = hash('sha256', strtolower($email_address));
	$recipient_pattern = recipient_regexp($email_address);
	$user = get_user_by('email', $email_address);
	$user_id = $user ? (int) $user->ID : -1;
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT id, event, subject, status, created_at FROM " . table_name() . " WHERE recipient_hash = %s OR LOWER(recipient) REGEXP %s OR (source_type = 'user' AND source_id = %d) ORDER BY id ASC LIMIT %d OFFSET %d",
		$hash,
		$recipient_pattern,
		$user_id,
		$per_page,
		(max(1, (int) $page) - 1) * $per_page
	), ARRAY_A);
	$data = [];
	foreach ($rows as $row) {
		$data[] = [
			'group_id' => 'corsivo-mail-log',
			'group_label' => 'Corsivo Mail',
			'item_id' => 'corsivo-mail-log-' . (int) $row['id'],
			'data' => [
				['name' => 'Data', 'value' => $row['created_at']],
				['name' => 'Evento', 'value' => $row['event']],
				['name' => 'Oggetto', 'value' => $row['subject']],
				['name' => 'Stato', 'value' => $row['status']],
			],
		];
	}
	return ['data' => $data, 'done' => count($rows) < $per_page];
}

function erase_personal_data($email_address, $page = 1) {
	global $wpdb;
	if (!is_email($email_address) || !schema_ready()) {
		return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
	}
	$hash = hash('sha256', strtolower($email_address));
	$recipient_pattern = recipient_regexp($email_address);
	$user = get_user_by('email', $email_address);
	$user_id = $user ? (int) $user->ID : -1;
	$removed = $wpdb->query($wpdb->prepare(
		"UPDATE " . table_name() . "
		 SET recipient = '[removed]', recipient_hash = '', subject = '[removed]', error = NULL, metadata = NULL,
		     actor_user_id = IF(actor_user_id = %d, 0, actor_user_id),
		     source_id = IF(source_type = 'user' AND source_id = %d, 0, source_id)
		 WHERE recipient_hash = %s OR LOWER(recipient) REGEXP %s OR (source_type = 'user' AND source_id = %d) LIMIT 500",
		$user_id,
		$user_id,
		$hash,
		$recipient_pattern,
		$user_id
	));
	return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => $removed < 500];
}
