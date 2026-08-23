<?php

namespace CorsivoMail\Queue;

if (!defined('ABSPATH')) {
	exit;
}

const INSTALL_INTENT_OPTION = 'corsivo_mail_queue_install_intent';
const SCHEMA_ATTESTATION_OPTION = 'corsivo_mail_queue_schema_attestation';
const SCHEMA_FAILURE_OPTION = 'corsivo_mail_queue_schema_failure';

require_once dirname(__DIR__) . '/storage/contract.php';

function guarded_source_types() {
	return array(
		'corsivo-bookings-payment-request',
		'corsivo-bookings-stay-reminder',
		'corsivo-members-expiry',
		'corsivo-members-renewal',
	);
}

function table_name() {
	global $wpdb;
	return $wpdb->prefix . 'corsivo_mail_queue';
}

function target_column_blueprint() {
	$columns = array(
		'id' => column_definition('bigint unsigned', false, null, 'auto_increment'),
		'account_id' => column_definition('varchar(64)', false, ''),
		'recipient' => column_definition('text', false, null),
		'subject' => column_definition('text', false, null),
		'body' => column_definition('longtext', false, null),
		'headers' => column_definition('longtext', false, null),
		'attachments' => column_definition('longtext', false, null),
		'embeds' => column_definition('longtext', false, null),
		'source_type' => column_definition('varchar(64)', false, ''),
		'source_id' => column_definition('bigint unsigned', false, '0'),
		'source_guard_required' => column_definition('tinyint unsigned', false, '0'),
		'cancel_requested' => column_definition('tinyint unsigned', false, '0'),
		'retain_until' => column_definition('datetime', true, null),
		'message_type' => column_definition('varchar(20)', false, 'transactional'),
		'idempotency_key' => column_definition('varchar(64)', true, null),
		'idempotency_fingerprint' => column_definition('char(64)', true, null),
		'status' => column_definition('varchar(20)', false, 'pending'),
		'attempts' => column_definition('smallint unsigned', false, '0'),
		'max_attempts' => column_definition('smallint unsigned', false, '3'),
		'scheduled_at' => column_definition('datetime', false, null),
		'locked_at' => column_definition('datetime', true, null),
		'lock_token' => column_definition('varchar(64)', false, ''),
		'transport_started_at' => column_definition('datetime', true, null),
		'last_attempt_at' => column_definition('datetime', true, null),
		'sent_at' => column_definition('datetime', true, null),
		'completed_at' => column_definition('datetime', true, null),
		'error_code' => column_definition('varchar(64)', false, ''),
		'error' => column_definition('text', true, null),
		'created_at' => column_definition('datetime', false, null),
	);
	ksort($columns);
	return $columns;
}

function target_index_blueprint() {
	$indexes = array(
		'PRIMARY' => array('unique' => true, 'columns' => array('id'), 'sub_parts' => array(null)),
		'status_scheduled' => array('unique' => false, 'columns' => array('status', 'scheduled_at'), 'sub_parts' => array(null, null)),
		'status_locked' => array('unique' => false, 'columns' => array('status', 'locked_at'), 'sub_parts' => array(null, null)),
		'status_completed' => array('unique' => false, 'columns' => array('status', 'completed_at'), 'sub_parts' => array(null, null)),
		'lock_token' => array('unique' => false, 'columns' => array('lock_token'), 'sub_parts' => array(null)),
		'idempotency_key' => array('unique' => true, 'columns' => array('idempotency_key'), 'sub_parts' => array(null)),
	);
	ksort($indexes);
	return $indexes;
}

function target_schema_sql() {
	global $wpdb;
	$table = table_name();
	$charset_collate = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		account_id VARCHAR(64) NOT NULL DEFAULT '',
		recipient TEXT NOT NULL,
		subject TEXT NOT NULL,
		body LONGTEXT NOT NULL,
		headers LONGTEXT NOT NULL,
		attachments LONGTEXT NOT NULL,
		embeds LONGTEXT NOT NULL,
		source_type VARCHAR(64) NOT NULL DEFAULT '',
		source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		source_guard_required TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
		cancel_requested TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
		retain_until DATETIME NULL,
		message_type VARCHAR(20) NOT NULL DEFAULT 'transactional',
		idempotency_key VARCHAR(64) NULL,
		idempotency_fingerprint CHAR(64) NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
		scheduled_at DATETIME NOT NULL,
		locked_at DATETIME NULL,
		lock_token VARCHAR(64) NOT NULL DEFAULT '',
		transport_started_at DATETIME NULL,
		last_attempt_at DATETIME NULL,
		sent_at DATETIME NULL,
		completed_at DATETIME NULL,
		error_code VARCHAR(64) NOT NULL DEFAULT '',
		error TEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY status_scheduled (status, scheduled_at),
		KEY status_locked (status, locked_at),
		KEY status_completed (status, completed_at),
		KEY lock_token (lock_token),
		UNIQUE KEY idempotency_key (idempotency_key)
	) ENGINE=InnoDB {$charset_collate}";
}

function current_storage_configuration() {
	return array(
		'domain' => 'corsivo-mail:queue-storage',
		'table' => table_name(),
		'columns' => target_column_blueprint(),
		'indexes' => target_index_blueprint(),
		'sql' => target_schema_sql(),
		'intent_option' => INSTALL_INTENT_OPTION,
		'attestation_option' => SCHEMA_ATTESTATION_OPTION,
		'failure_option' => SCHEMA_FAILURE_OPTION,
		'identity' => array(
			'payload_storage' => 'plain',
			'payload_fields' => payload_fields(),
			'structured_fields' => array('headers', 'attachments', 'embeds'),
			'guarded_sources' => guarded_source_types(),
			'guarded_retention_days' => 8,
			'lifecycle' => array('pending', 'sending', 'transmitting', 'sent', 'failed', 'cancelled', 'delivery_unknown'),
		),
	);
}

function payload_fields() {
	return array('body', 'headers', 'attachments', 'embeds');
}

function payload_value_is_valid($stored, $field) {
	if ($field === 'body') {
		return is_string($stored);
	}
	$decoded = json_decode((string) $stored, true);
	return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
}

function active_payloads_are_valid() {
	global $wpdb;
	$table = quoted_table(table_name());
	$cursor = 0;
	do {
		$wpdb->last_error = '';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, body, headers, attachments, embeds
			FROM {$table}
			WHERE id > %d AND status IN ('pending', 'failed', 'sending', 'transmitting')
			ORDER BY id ASC LIMIT 250",
			$cursor
		), ARRAY_A);
		if ($wpdb->last_error !== '' || !is_array($rows)) {
			return false;
		}
		foreach ($rows as $row) {
			foreach (payload_fields() as $field) {
				if (!payload_value_is_valid($row[$field], $field)) {
					return false;
				}
			}
			$cursor = (int) $row['id'];
		}
	} while (count($rows) === 250);
	return true;
}

function guarded_sources_are_valid() {
	global $wpdb;
	$sources = guarded_source_types();
	$placeholders = implode(', ', array_fill(0, count($sources), '%s'));
	$params = array_merge($sources, array(current_time('mysql')));
	$wpdb->last_error = '';
	$count = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM " . quoted_table(table_name()) . "
		WHERE source_type IN ({$placeholders})
		AND (
			source_guard_required <> 1
			OR retain_until IS NULL
			OR retain_until < DATE_ADD(
				COALESCE(completed_at, sent_at, last_attempt_at, created_at, %s),
				INTERVAL 8 DAY
			)
		)",
		$params
	));
	return $wpdb->last_error === '' && is_numeric($count) && (int) $count === 0;
}

function lifecycle_is_valid() {
	global $wpdb;
	$wpdb->last_error = '';
	$count = $wpdb->get_var(
		"SELECT COUNT(*) FROM " . quoted_table(table_name()) . "
		WHERE status NOT IN ('pending', 'sending', 'transmitting', 'sent', 'failed', 'cancelled', 'delivery_unknown')
		OR NOT (
			(status = 'sent' AND sent_at IS NOT NULL AND completed_at IS NOT NULL)
			OR (status IN ('failed', 'cancelled', 'delivery_unknown') AND sent_at IS NULL AND completed_at IS NOT NULL)
			OR (status IN ('pending', 'sending', 'transmitting') AND sent_at IS NULL AND completed_at IS NULL)
		)
		OR (status = 'pending' AND cancel_requested <> 0)"
	);
	return $wpdb->last_error === '' && is_numeric($count) && (int) $count === 0;
}

function queue_integrity_ready() {
	return guarded_sources_are_valid()
		&& active_payloads_are_valid()
		&& lifecycle_is_valid();
}

function schema_ready($refresh = false) {
	return storage_runtime_ready(current_storage_configuration(), $refresh);
}

function runtime_ready($refresh = false) {
	return schema_ready($refresh);
}

function schedule_cron() {
	if (!wp_next_scheduled('corsivo_mail_verify_storage', array('scheduled'))) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'corsivo_mail_verify_storage', array('scheduled'));
	}
	if (!runtime_ready()) {
		return;
	}
	if (!wp_next_scheduled('corsivo_mail_process_queue')) {
		wp_schedule_event(time() + 60, 'corsivo_mail_minute', 'corsivo_mail_process_queue');
	}
	if (!wp_next_scheduled('corsivo_mail_cleanup_queue')) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'corsivo_mail_cleanup_queue');
	}
}

function reconcile_cron() {
	schedule_cron();
	if (runtime_ready()) {
		return true;
	}
	wp_clear_scheduled_hook('corsivo_mail_process_queue');
	wp_clear_scheduled_hook('corsivo_mail_cleanup_queue');
	wp_clear_scheduled_hook('corsivo_mail_process_automations');
	return false;
}

function clear_cron() {
	wp_clear_scheduled_hook('corsivo_mail_process_queue');
	wp_clear_scheduled_hook('corsivo_mail_cleanup_queue');
	wp_clear_scheduled_hook('corsivo_mail_process_automations');
	wp_clear_scheduled_hook('corsivo_mail_verify_storage');
}

function cron_schedules($schedules) {
	$schedules['corsivo_mail_minute'] = array(
		'interval' => 60,
		'display' => __('Ogni minuto', 'corsivo-mail'),
	);
	return $schedules;
}

function schema_failure_notice() {
	if (!current_user_can('manage_options')) {
		return;
	}
	$configurations = array(current_storage_configuration());
	if (function_exists('\\CorsivoMail\\Storage\\Log\\configuration')) {
		$configurations[] = \CorsivoMail\Storage\Log\configuration();
	}
	if (function_exists('\\CorsivoMail\\Imap\\storage_configuration')) {
		$configurations[] = \CorsivoMail\Imap\storage_configuration();
	}
	foreach ($configurations as $configuration) {
		$failure = storage_failure($configuration);
		if (!is_array($failure)) {
			continue;
		}
		$code = is_string($failure['code'] ?? null) ? $failure['code'] : 'corsivo_mail_storage_failed';
		echo '<div class="notice notice-error"><p>'
			. esc_html(sprintf('Corsivo Mail ha bloccato lo storage non verificabile (%s).', $code))
			. '</p></div>';
	}
}
