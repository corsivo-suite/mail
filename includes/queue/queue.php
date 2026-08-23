<?php

namespace CorsivoMail\Queue;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

function queue_runtime_unavailable_error() {
	return new \WP_Error('corsivo_mail_queue_unavailable', 'La coda email non è disponibile.');
}

function enqueue($args) {
	global $wpdb;
	if (!runtime_ready()) {
		return new \WP_Error('corsivo_mail_queue_unavailable', 'La coda email non è disponibile.');
	}

	$message_type = ($args['message_type'] ?? 'transactional') === 'marketing' ? 'marketing' : 'transactional';
	if ($message_type === 'marketing' && Options\get('module_subscriptions')) {
		if (!function_exists('CorsivoMail\\Newsletter\\marketing_unsubscribe_preflight')) {
			return new \WP_Error('corsivo_mail_unsubscribe_preflight_unavailable', 'Il controllo di disiscrizione non è disponibile.');
		}
		$preflight = \CorsivoMail\Newsletter\marketing_unsubscribe_preflight(
			$args['to'] ?? '',
			$args['headers'] ?? []
		);
		if (is_wp_error($preflight)) {
			return $preflight;
		}
	}

	$now = current_time('mysql');
	$scheduled_at = normalize_scheduled_at($args['scheduled_at'] ?? $now);
	if (is_wp_error($scheduled_at)) {
		return $scheduled_at;
	}

	$source_type = sanitize_key($args['source_type'] ?? '');
	$source_id = max(0, (int) ($args['source_id'] ?? 0));
	$source_guard_required = !empty($args['source_guard_required']);
	$retain_until = normalize_optional_datetime($args['retain_until'] ?? '');
	if (is_wp_error($retain_until)) {
		return $retain_until;
	}

	if (in_array($source_type, guarded_source_types(), true)
		&& (!$source_guard_required || $retain_until === null)) {
		return new \WP_Error('corsivo_mail_queue_source_policy_invalid', 'Policy della sorgente email non valida.');
	}

	$idempotency_key = sanitize_text_field($args['idempotency_key'] ?? '');
	$idempotency_fingerprint = strtolower(sanitize_text_field($args['idempotency_fingerprint'] ?? ''));
	if ('' !== $idempotency_fingerprint && !preg_match('/^[a-f0-9]{64}$/', $idempotency_fingerprint)) {
		return new \WP_Error('corsivo_mail_idempotency_fingerprint_invalid', 'Fingerprint idempotente non valido.');
	}
	if ($idempotency_key !== '' && $idempotency_fingerprint === '') {
		return new \WP_Error('corsivo_mail_idempotency_fingerprint_required', 'La chiave idempotente richiede una fingerprint verificabile.');
	}
	if ($idempotency_key !== '') {
		$idempotency_key = hash('sha256', $idempotency_key);
		$existing = get_by_idempotency_key($idempotency_key);
		if ($existing) {
			$replayed = verify_idempotency_replay($existing, $idempotency_fingerprint);
			if (is_wp_error($replayed)) {
				return $replayed;
			}
			$policy_updated = elevate_replay_source_policy(
				$replayed,
				$source_guard_required,
				$retain_until
			);
			if (is_wp_error($policy_updated)) {
				return $policy_updated;
			}
			return $replayed;
		}
	}

	try {
		$body = (string) ($args['message'] ?? '');
		$headers = encode_json($args['headers'] ?? []);
		$attachments = encode_json($args['attachments'] ?? []);
		$embeds = encode_json($args['embeds'] ?? []);
	} catch (\Throwable $error) {
		return new \WP_Error('corsivo_mail_queue_serialize_failed', $error->getMessage());
	}

	$row = [
		'account_id'   => sanitize_text_field((string) ($args['account_id'] ?? '')),
		'recipient'    => maybe_serialize_recipient($args['to'] ?? ''),
		'subject'      => sanitize_text_field((string) ($args['subject'] ?? '')),
		'body'         => $body,
		'headers'      => $headers,
		'attachments'  => $attachments,
		'embeds'       => $embeds,
		'source_type'  => $source_type,
		'source_id'    => $source_id,
		'source_guard_required' => $source_guard_required ? 1 : 0,
		'cancel_requested' => 0,
		'retain_until' => $retain_until,
		'message_type' => $message_type,
		'idempotency_key' => $idempotency_key !== '' ? $idempotency_key : null,
		'idempotency_fingerprint' => $idempotency_fingerprint !== '' ? $idempotency_fingerprint : null,
		'status'       => 'pending',
		'attempts'     => 0,
		'max_attempts' => (int) Options\get('queue_max_retries'),
		'scheduled_at' => $scheduled_at,
		'created_at'   => $now,
	];

	if ($wpdb->insert(table_name(), $row) === false) {
		if ($idempotency_key !== '' && ($existing = get_by_idempotency_key($idempotency_key))) {
			$replayed = verify_idempotency_replay($existing, $idempotency_fingerprint);
			if (is_wp_error($replayed)) {
				return $replayed;
			}
			$policy_updated = elevate_replay_source_policy(
				$replayed,
				$source_guard_required,
				$retain_until
			);
			if (is_wp_error($policy_updated)) {
				return $policy_updated;
			}
			return $replayed;
		}
		return new \WP_Error('corsivo_mail_queue_insert_failed', $wpdb->last_error ?: 'Inserimento in coda non riuscito.');
	}
	return (int) $wpdb->insert_id;
}

function claim_batch($limit, $queue_id = 0) {
	global $wpdb;
	if (!runtime_ready()) {
		return new \WP_Error('corsivo_mail_queue_unavailable', 'La coda email non è disponibile.');
	}
	$table = table_name();
	$now   = current_time('mysql');

	$token = wp_generate_uuid4();
	if ($queue_id) {
		$claimed = $wpdb->query($wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'sending', locked_at = %s, lock_token = %s,
			     transport_started_at = NULL, attempts = attempts + 1
			 WHERE id = %d AND status = 'pending' AND cancel_requested = 0 AND scheduled_at <= %s",
			$now,
			$token,
			(int) $queue_id,
			$now
		));
	} else {
		$claimed = $wpdb->query($wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'sending', locked_at = %s, lock_token = %s,
			     transport_started_at = NULL, attempts = attempts + 1
			 WHERE status = 'pending' AND cancel_requested = 0 AND scheduled_at <= %s
			 ORDER BY scheduled_at ASC, id ASC LIMIT %d",
			$now,
			$token,
			$now,
			$limit
		));
	}

	if ($claimed === false) {
		return new \WP_Error('corsivo_mail_queue_claim_failed', $wpdb->last_error ?: 'Claim della coda non riuscito.');
	}
	if (!$claimed) {
		return [];
	}

	$wpdb->last_error = '';
	$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE lock_token = %s ORDER BY scheduled_at ASC, id ASC", $token), ARRAY_A);
	if ($wpdb->last_error === '' && is_array($rows)) {
		return $rows;
	}

	$read_error = $wpdb->last_error ?: 'Lettura delle righe reclamate non riuscita.';
	$wpdb->last_error = '';
	$rolled_back = $wpdb->query($wpdb->prepare(
		"UPDATE {$table}
		 SET status = 'pending', locked_at = NULL, lock_token = '', transport_started_at = NULL,
		     attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END
		 WHERE status = 'sending' AND lock_token = %s",
		$token
	));
	if ($rolled_back === false || $wpdb->last_error !== '' || (int) $rolled_back !== (int) $claimed) {
		return new \WP_Error(
			'corsivo_mail_queue_claim_rollback_failed',
			'Il claim della coda non è leggibile e non può essere ripristinato.',
			array('read_error' => $read_error)
		);
	}
	return new \WP_Error(
		'corsivo_mail_queue_claim_read_failed',
		'Il claim della coda non è leggibile ed è stato ripristinato.',
		array('read_error' => $read_error)
	);
}

function requeue_stale($minutes, &$terminal_events = []) {
	global $wpdb;
	if (!runtime_ready()) {
		$terminal_events = [];
		return false;
	}
	$table  = table_name();
	$cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));
	$terminal_events = [];
	$cancelled_count = 0;
	$pending_cancelled_count = 0;
	$unknown_count = 0;
	$exhausted_count = 0;

	$wpdb->last_error = '';
	$pending_cancelled_rows = $wpdb->get_results(
		"SELECT id, source_type, source_id
		 FROM {$table}
		 WHERE status = 'pending' AND cancel_requested = 1
		 ORDER BY id ASC",
		ARRAY_A
	);
	if ($wpdb->last_error !== '') {
		return false;
	}
	foreach (is_array($pending_cancelled_rows) ? $pending_cancelled_rows : [] as $row) {
		$updated = mark_suppressed(
			(int) $row['id'],
			'',
			'Invio annullato dalla sorgente.',
			'source_cancelled',
			'pending',
			true
		);
		if ($updated === false) {
			return false;
		}
		if ($updated === 1) {
			$pending_cancelled_count++;
			$terminal_events[] = [
				'queue_id' => (int) $row['id'],
				'source_type' => (string) $row['source_type'],
				'source_id' => (int) $row['source_id'],
			];
		}
	}

	$wpdb->last_error = '';
	$cancelled_rows = $wpdb->get_results($wpdb->prepare(
		"SELECT id, lock_token, source_type, source_id
		 FROM {$table}
		 WHERE status = 'sending' AND cancel_requested = 1
		   AND locked_at IS NOT NULL AND locked_at < %s
		 ORDER BY id ASC",
		$cutoff
	), ARRAY_A);
	if ($wpdb->last_error !== '') {
		return false;
	}
	foreach (is_array($cancelled_rows) ? $cancelled_rows : [] as $row) {
		$updated = mark_suppressed(
			(int) $row['id'],
			(string) $row['lock_token'],
			'Invio annullato dalla sorgente.',
			'source_inactive',
			'sending',
			true
		);
		if ($updated === false) {
			return false;
		}
		if ($updated === 1) {
			$cancelled_count++;
			$terminal_events[] = [
				'queue_id' => (int) $row['id'],
				'source_type' => (string) $row['source_type'],
				'source_id' => (int) $row['source_id'],
			];
		}
	}

	$wpdb->last_error = '';
	$unknown_rows = $wpdb->get_results($wpdb->prepare(
		"SELECT id, lock_token, source_type, source_id
		 FROM {$table}
		 WHERE status = 'transmitting' AND transport_started_at IS NOT NULL
		   AND transport_started_at < %s
		 ORDER BY id ASC",
		$cutoff
	), ARRAY_A);
	if ($wpdb->last_error !== '') {
		return false;
	}
	foreach (is_array($unknown_rows) ? $unknown_rows : [] as $row) {
		$updated = mark_delivery_unknown(
			(int) $row['id'],
			(string) $row['lock_token'],
			'Lavorazione interrotta dopo l’avvio del trasporto.'
		);
		if ($updated === false) {
			return false;
		}
		if ($updated === 1) {
			$unknown_count++;
			$terminal_events[] = [
				'queue_id' => (int) $row['id'],
				'source_type' => (string) $row['source_type'],
				'source_id' => (int) $row['source_id'],
			];
		}
	}

	$wpdb->last_error = '';
	$exhausted_rows = $wpdb->get_results($wpdb->prepare(
		"SELECT id, lock_token, attempts, max_attempts, source_type, source_id
		 FROM {$table}
		 WHERE status = 'sending' AND locked_at IS NOT NULL AND locked_at < %s
		   AND attempts >= max_attempts
		 ORDER BY id ASC",
		$cutoff
	), ARRAY_A);
	if ($wpdb->last_error !== '') {
		return false;
	}
	foreach (is_array($exhausted_rows) ? $exhausted_rows : [] as $row) {
		$updated = mark_failed(
			(int) $row['id'],
			(string) $row['lock_token'],
			(int) $row['attempts'],
			(int) $row['max_attempts'],
			'Lavorazione interrotta ripetutamente prima del completamento.',
			'worker_died',
			true
		);
		if ($updated === false) {
			return false;
		}
		if ($updated === 1) {
			$exhausted_count++;
			$terminal_events[] = [
				'queue_id' => (int) $row['id'],
				'source_type' => (string) $row['source_type'],
				'source_id' => (int) $row['source_id'],
			];
		}
	}
	if ($pending_cancelled_count) {
		\CorsivoMail\Log\audit('queue_source_cancelled_pending', 'notice', ['rows' => $pending_cancelled_count]);
	}
	if ($cancelled_count) {
		\CorsivoMail\Log\audit('queue_source_cancelled_stale', 'notice', ['rows' => $cancelled_count]);
	}
	if ($unknown_count) {
		\CorsivoMail\Log\audit('delivery_unknown', 'failed', ['rows' => $unknown_count, 'error_code' => 'delivery_unknown']);
	}
	if ($exhausted_count) {
		\CorsivoMail\Log\audit('worker_died', 'failed', ['rows' => $exhausted_count, 'error_code' => 'worker_died']);
	}

	$requeued = $wpdb->query($wpdb->prepare(
		"UPDATE {$table} SET status = 'pending', locked_at = NULL, lock_token = ''
		 WHERE status = 'sending' AND locked_at IS NOT NULL AND locked_at < %s
		   AND attempts < max_attempts AND cancel_requested = 0",
		$cutoff
	));
	if ($requeued) {
		\CorsivoMail\Log\audit('queue_requeued_stale', 'notice', ['rows' => (int) $requeued]);
	}
	if ($requeued === false) {
		return false;
	}
	return $pending_cancelled_count + $cancelled_count + $unknown_count + $exhausted_count + (int) $requeued;
}
function requeue_one($id) {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}
	return $wpdb->update(
		table_name(),
		['status' => 'pending', 'attempts' => 0, 'error' => null, 'error_code' => '', 'locked_at' => null, 'lock_token' => '', 'transport_started_at' => null, 'scheduled_at' => current_time('mysql'), 'completed_at' => null, 'cancel_requested' => 0],
		['id' => (int) $id, 'status' => 'failed']
	);
}

function fence_queued_for_source($id, $source_type, $source_id) {
	global $wpdb;
	if (!runtime_ready()) {
		return queue_runtime_unavailable_error();
	}
	$id = (int) $id;
	$source_type = (string) $source_type;
	$source_id = (int) $source_id;
	if ($id < 1 || $source_type === '' || $source_id < 1) {
		return new \WP_Error('corsivo_mail_queue_status_identity_invalid', 'Identità fence coda non valida.');
	}
	$updated = $wpdb->query($wpdb->prepare(
		"UPDATE " . table_name() . "
		 SET cancel_requested = 1
		 WHERE id = %d AND source_type = %s AND source_id = %d
		   AND status IN ('pending', 'sending', 'transmitting') AND cancel_requested = 0",
		$id,
		$source_type,
		$source_id
	));
	if ($updated === false) {
		return new \WP_Error('corsivo_mail_queue_fence_failed', 'Il fence durevole della coda non è riuscito.');
	}
	$status = get_status_for_source($id, $source_type, $source_id);
	if (is_wp_error($status)) {
		return $status;
	}
	$mail_status = (string) ($status['status'] ?? '');
	if (in_array($mail_status, ['pending', 'sending', 'transmitting'], true)) {
		$cancel_requested = $wpdb->get_var($wpdb->prepare(
			"SELECT cancel_requested FROM " . table_name() . "
			 WHERE id = %d AND source_type = %s AND source_id = %d",
			$id,
			$source_type,
			$source_id
		));
		if ($cancel_requested === null || (int) $cancel_requested !== 1) {
			return new \WP_Error('corsivo_mail_queue_fence_failed', 'Il fence durevole della coda non è verificabile.');
		}
	}
	return $status;
}

function cancel_pending_for_source($id, $source_type, $source_id, $error_code = 'source_cancelled') {
	global $wpdb;
	if (!runtime_ready()) {
		return queue_runtime_unavailable_error();
	}
	$id = (int) $id;
	$source_type = (string) $source_type;
	$source_id = (int) $source_id;
	$error_code = sanitize_key((string) $error_code);
	if ($id < 1 || $source_type === '' || $source_id < 1 || $error_code === '') {
		return new \WP_Error('corsivo_mail_queue_status_identity_invalid', 'Identità cancellazione coda non valida.');
	}
	$requested = $wpdb->query($wpdb->prepare(
		"UPDATE " . table_name() . "
		 SET cancel_requested = 1
		 WHERE id = %d AND source_type = %s AND source_id = %d
		   AND status IN ('pending', 'sending', 'transmitting') AND cancel_requested = 0",
		$id,
		$source_type,
		$source_id
	));
	if ($requested === false) {
		return new \WP_Error('corsivo_mail_queue_cancel_failed', 'Richiesta cancellazione coda non riuscita.');
	}
	$updated = $wpdb->query($wpdb->prepare(
		"UPDATE " . table_name() . "
		 SET status = 'cancelled', sent_at = NULL, completed_at = %s,
		     last_attempt_at = %s, locked_at = NULL, lock_token = '',
		     error_code = %s, error = %s, body = '', headers = '', attachments = '', embeds = ''
		 WHERE id = %d AND source_type = %s AND source_id = %d
		   AND status = 'pending' AND cancel_requested = 1",
		current_time('mysql'),
		current_time('mysql'),
		$error_code,
		'Invio annullato dalla sorgente.',
		$id,
		$source_type,
		$source_id
	));
	if ($updated === false) {
		return new \WP_Error('corsivo_mail_queue_cancel_failed', 'Cancellazione coda non riuscita.');
	}
	$status = get_status_for_source($id, $source_type, $source_id);
	if (is_wp_error($status)) {
		return $status;
	}
	if ((string) $status['status'] === 'cancelled') {
		return $status;
	}
	if (in_array((string) $status['status'], ['sending', 'transmitting'], true)) {
		return new \WP_Error('corsivo_mail_queue_already_sending', 'La coda è già in lavorazione.');
	}
	return new \WP_Error('corsivo_mail_queue_cancel_conflict', 'La coda non può più essere annullata.');
}

function cleanup($days) {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}
	$table = table_name();
	$base_days = max(1, (int) $days);
	$source_days = apply_filters('corsivo_mail_queue_source_retention_days', [], $base_days);
	$source_days = is_array($source_days) ? $source_days : [];
	$protected_sources = [];
	foreach ($source_days as $source_type => $minimum_days) {
		$source_type = sanitize_key((string) $source_type);
		if ($source_type !== '') {
			$protected_sources[$source_type] = max($base_days, (int) $minimum_days);
		}
	}
	$total = 0;
	$delete_batches = static function ($sql, $params) use ($wpdb, &$total) {
		for ($batch = 0; $batch < 20; $batch++) {
			$deleted = $wpdb->query($wpdb->prepare($sql, ...$params));
			if ($deleted === false) {
				return false;
			}
			$total += (int) $deleted;
			if ($deleted < 1000) {
				break;
			}
		}
		return true;
	};
	$base_cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - ($base_days * DAY_IN_SECONDS));
	$now = current_time('mysql');
	if ($protected_sources) {
		$placeholders = implode(',', array_fill(0, count($protected_sources), '%s'));
		$generic_sql = "DELETE FROM {$table}
			WHERE status IN ('sent', 'failed', 'cancelled', 'delivery_unknown') AND completed_at IS NOT NULL
			  AND completed_at < %s AND (retain_until IS NULL OR retain_until < %s)
			  AND source_type NOT IN ({$placeholders}) LIMIT 1000";
		if (!$delete_batches($generic_sql, array_merge([$base_cutoff, $now], array_keys($protected_sources)))) {
			return false;
		}
	} else {
		$generic_sql = "DELETE FROM {$table}
			WHERE status IN ('sent', 'failed', 'cancelled', 'delivery_unknown') AND completed_at IS NOT NULL
			  AND completed_at < %s AND (retain_until IS NULL OR retain_until < %s) LIMIT 1000";
		if (!$delete_batches($generic_sql, [$base_cutoff, $now])) {
			return false;
		}
	}
	foreach ($protected_sources as $source_type => $retention_days) {
		$cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - ($retention_days * DAY_IN_SECONDS));
		$source_sql = "DELETE FROM {$table}
			WHERE status IN ('sent', 'failed', 'cancelled', 'delivery_unknown') AND completed_at IS NOT NULL
			  AND completed_at < %s AND (retain_until IS NULL OR retain_until < %s)
			  AND source_type = %s LIMIT 1000";
		if (!$delete_batches($source_sql, [$cutoff, $now, $source_type])) {
			return false;
		}
	}
	return $total;
}

function get_row($id) {
	global $wpdb;
	if (!runtime_ready()) {
		return null;
	}
	$table = table_name();
	return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
}

function get_status_for_source($id, $source_type, $source_id) {
	global $wpdb;
	if (!runtime_ready()) {
		return queue_runtime_unavailable_error();
	}
	$table = table_name();
	$wpdb->last_error = '';
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT id, source_type, source_id, status, error_code, sent_at, completed_at
		 FROM {$table}
		 WHERE id = %d AND source_type = %s AND source_id = %d
		 LIMIT 1",
		(int) $id,
		(string) $source_type,
		(int) $source_id
	), ARRAY_A);
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_queue_status_read_failed', 'Lettura stato coda non riuscita.');
	}
	if (!$row) {
		return new \WP_Error('corsivo_mail_queue_identity_mismatch', 'La coda non appartiene alla sorgente indicata.');
	}
	$status = (string) ($row['status'] ?? '');
	if (!in_array($status, ['pending', 'sending', 'transmitting', 'sent', 'failed', 'cancelled', 'delivery_unknown'], true)) {
		return new \WP_Error('corsivo_mail_queue_status_invalid', 'Lo stato della coda non è valido.');
	}
	$sent_at = empty($row['sent_at']) ? null : (string) $row['sent_at'];
	$completed_at = empty($row['completed_at']) ? null : (string) $row['completed_at'];
	$valid_lifecycle = ($status === 'sent' && $sent_at !== null && $completed_at !== null)
		|| (in_array($status, ['failed', 'cancelled', 'delivery_unknown'], true) && $sent_at === null && $completed_at !== null)
		|| (in_array($status, ['pending', 'sending', 'transmitting'], true) && $sent_at === null && $completed_at === null);
	if (!$valid_lifecycle) {
		return new \WP_Error('corsivo_mail_queue_status_invalid', 'Il ciclo di vita della coda non è coerente.');
	}
	return [
		'queue_id' => (int) $row['id'],
		'source_type' => (string) $row['source_type'],
		'source_id' => (int) $row['source_id'],
		'status' => $status,
		'error_code' => sanitize_key((string) ($row['error_code'] ?? '')),
		'sent_at' => $sent_at,
		'completed_at' => $completed_at,
	];
}

function mark_sent($id, $lock_token) {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}
	return $wpdb->update(
		table_name(),
		[
			'status' => 'sent',
			'sent_at' => current_time('mysql'),
			'completed_at' => current_time('mysql'),
			'error' => null,
			'error_code' => '',
			'locked_at' => null,
			'lock_token' => '',
			'body' => '',
			'headers' => '',
			'attachments' => '',
			'embeds' => '',
		],
		['id' => (int) $id, 'status' => 'transmitting', 'lock_token' => (string) $lock_token]
	);
}

function start_transport($id, $lock_token) {
	global $wpdb;
	if (!runtime_ready()) {
		return queue_runtime_unavailable_error();
	}
	$updated = $wpdb->query($wpdb->prepare(
		"UPDATE " . table_name() . "
		 SET status = 'transmitting', transport_started_at = %s
		 WHERE id = %d AND status = 'sending' AND lock_token = %s AND cancel_requested = 0",
		current_time('mysql'),
		(int) $id,
		(string) $lock_token
	));
	if ($updated === false) {
		return new \WP_Error('corsivo_mail_queue_transport_fence_failed');
	}
	return $updated === 1;
}

function mark_delivery_unknown($id, $lock_token, $error) {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}
	$safe_error = function_exists('CorsivoMail\\Log\\redact_error')
		? \CorsivoMail\Log\redact_error($error)
		: sanitize_text_field(substr((string) $error, 0, 1000));
	return $wpdb->update(
		table_name(),
		[
			'status' => 'delivery_unknown',
			'completed_at' => current_time('mysql'),
			'last_attempt_at' => current_time('mysql'),
			'locked_at' => null,
			'lock_token' => '',
			'error_code' => 'delivery_unknown',
			'error' => $safe_error,
			'body' => '',
			'headers' => '',
			'attachments' => '',
			'embeds' => '',
		],
		['id' => (int) $id, 'status' => 'transmitting', 'lock_token' => (string) $lock_token]
	);
}

function source_send_fence($id, $lock_token) {
	global $wpdb;
	if (!runtime_ready()) {
		return queue_runtime_unavailable_error();
	}
	$wpdb->last_error = '';
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT status, lock_token, source_guard_required, cancel_requested
		 FROM " . table_name() . " WHERE id = %d",
		(int) $id
	), ARRAY_A);
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_queue_source_guard_failed');
	}
	return $row
		&& (string) $row['status'] === 'sending'
		&& hash_equals((string) $row['lock_token'], (string) $lock_token)
		&& empty($row['cancel_requested'])
			? $row
			: new \WP_Error('corsivo_mail_queue_source_inactive');
}

function release_claim($id, $lock_token) {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}
	return $wpdb->update(
		table_name(),
		['status' => 'pending', 'locked_at' => null, 'lock_token' => ''],
		['id' => (int) $id, 'status' => 'sending', 'lock_token' => (string) $lock_token]
	);
}

function mark_failed($id, $lock_token, $attempts, $max_attempts, $error, $error_code = 'send_failed', $terminal = false, $expected_status = 'sending') {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}

	$failed_for_good = $terminal || $attempts >= $max_attempts;
	$delay = min(HOUR_IN_SECONDS, 60 * (2 ** max(0, $attempts - 1))) + wp_rand(0, 30);
	$next_attempt = current_datetime()->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s');
	$safe_error = function_exists('CorsivoMail\\Log\\redact_error') ? \CorsivoMail\Log\redact_error($error) : sanitize_text_field(substr((string) $error, 0, 1000));
	return $wpdb->update(
		table_name(),
		[
			'status'   => $failed_for_good ? 'failed' : 'pending',
			'attempts' => $attempts,
			'scheduled_at' => $failed_for_good ? current_time('mysql') : $next_attempt,
			'last_attempt_at' => current_time('mysql'),
			'completed_at' => $failed_for_good ? current_time('mysql') : null,
			'locked_at' => null,
			'lock_token' => '',
			'transport_started_at' => null,
			'error_code' => sanitize_key($error_code),
			'error'    => $safe_error,
		],
		['id' => (int) $id, 'status' => (string) $expected_status, 'lock_token' => (string) $lock_token, 'cancel_requested' => 0]
	);
}

function mark_suppressed($id, $lock_token, $reason = 'Consenso marketing assente.', $error_code = 'marketing_consent_missing', $expected_status = 'sending', $require_cancel = false) {
	global $wpdb;
	if (!runtime_ready()) {
		return false;
	}
	return $wpdb->update(
		table_name(),
		[
			'status' => 'cancelled',
			'completed_at' => current_time('mysql'),
			'last_attempt_at' => current_time('mysql'),
			'locked_at' => null,
			'lock_token' => '',
			'transport_started_at' => null,
			'error_code' => sanitize_key($error_code),
			'error' => $reason,
			'body' => '',
			'headers' => '',
			'attachments' => '',
			'embeds' => '',
		],
		array_filter([
			'id' => (int) $id,
			'status' => (string) $expected_status,
			'lock_token' => (string) $lock_token,
			'cancel_requested' => $require_cancel ? 1 : null,
		], static fn($value) => $value !== null)
	);
}

function counts() {
	global $wpdb;
	$out = ['pending' => 0, 'sending' => 0, 'transmitting' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'delivery_unknown' => 0];
	if (!runtime_ready()) {
		return $out;
	}
	$table = table_name();
	$rows  = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A);

	foreach ($rows as $row) {
		$out[$row['status']] = (int) $row['n'];
	}
	return $out;
}

function recent($limit = 50, $status = '', $offset = 0) {
	global $wpdb;
	if (!runtime_ready()) {
		return [];
	}
	$table = table_name();
	$limit = max(1, min(200, (int) $limit));
	$offset = max(0, (int) $offset);
	if ($status !== '') {
		return $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", sanitize_key($status), $limit, $offset),
			ARRAY_A
		);
	}
	return $wpdb->get_results(
		$wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset),
		ARRAY_A
	);
}

function total($status = '') {
	global $wpdb;
	if (!runtime_ready()) {
		return 0;
	}
	$table = table_name();
	if ($status !== '') {
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", sanitize_key($status)));
	}
	return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

function active_count_for_account($account_id) {
	global $wpdb;
	if (!runtime_ready()) {
		return 0;
	}
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM " . table_name() . " WHERE account_id = %s AND status IN ('pending', 'sending', 'transmitting', 'failed')",
		(string) $account_id
	));
}

function maybe_serialize_recipient($to) {
	return is_array($to) ? wp_json_encode($to) : (string) $to;
}

function unserialize_recipient($stored) {
	$decoded = json_decode($stored, true);
	return is_array($decoded) ? $decoded : $stored;
}

function decode_payload($stored, $fallback = []) {
	if ($stored === '' || $stored === null) {
		return $fallback;
	}
	$decoded = json_decode((string) $stored, true);
	if (!is_array($decoded)) {
		throw new \RuntimeException('Payload serializzato non valido.');
	}
	return $decoded;
}

function decode_body($stored) {
	return (string) $stored;
}

function encode_json($value) {
	$encoded = wp_json_encode($value);
	if ($encoded === false) {
		throw new \RuntimeException('Serializzazione del payload email non riuscita.');
	}
	return $encoded;
}

function normalize_optional_datetime($value) {
	$value = is_scalar($value) ? trim((string) $value) : '';
	if ($value === '') {
		return null;
	}
	$timezone = wp_timezone();
	$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
	$errors = \DateTimeImmutable::getLastErrors();
	if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
		return new \WP_Error('corsivo_mail_invalid_retention', 'Data di retention non valida.');
	}
	return $date->format('Y-m-d H:i:s');
}

function normalize_scheduled_at($value) {
	$value = trim((string) $value);
	$timezone = wp_timezone();
	$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
	$errors = \DateTimeImmutable::getLastErrors();
	if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
		return new \WP_Error('corsivo_mail_invalid_schedule', 'Data di invio non valida.');
	}
	return $date->format('Y-m-d H:i:s');
}

function elevate_replay_source_policy($queue_id, $guard_required, $retain_until) {
	global $wpdb;
	if (!runtime_ready()) {
		return queue_runtime_unavailable_error();
	}
	$retain_until = $retain_until ?? '';
	if (!$guard_required && $retain_until === '') {
		return true;
	}
	if ($retain_until === '') {
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE " . table_name() . "
			 SET source_guard_required = GREATEST(source_guard_required, %d)
			 WHERE id = %d",
			$guard_required ? 1 : 0,
			(int) $queue_id
		));
	} else {
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE " . table_name() . "
			 SET source_guard_required = GREATEST(source_guard_required, %d),
			     retain_until = CASE
			       WHEN retain_until IS NULL OR retain_until < %s THEN %s
			       ELSE retain_until
			     END
			 WHERE id = %d",
			$guard_required ? 1 : 0,
			$retain_until,
			$retain_until,
			(int) $queue_id
		));
	}
	if ($updated === false) {
		return new \WP_Error('corsivo_mail_queue_policy_update_failed', 'Policy della coda email non aggiornata.');
	}
	return true;
}

function verify_idempotency_replay($existing, $fingerprint) {
	$stored = strtolower((string) ($existing['idempotency_fingerprint'] ?? ''));
	if ('' === $stored && '' === $fingerprint) {
		return (int) $existing['id'];
	}
	if ('' !== $stored && '' !== $fingerprint && hash_equals($stored, $fingerprint)) {
		return (int) $existing['id'];
	}
	return new \WP_Error('corsivo_mail_idempotency_conflict', 'La chiave idempotente identifica un payload email diverso.');
}

function get_by_idempotency_key($key) {
	global $wpdb;
	if (!runtime_ready()) {
		return null;
	}
	$table = table_name();
	return $wpdb->get_row($wpdb->prepare("SELECT id, status, idempotency_fingerprint, source_guard_required FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key), ARRAY_A);
}

function get_by_idempotency($key) {
	$key = sanitize_text_field($key);
	return $key === '' ? null : get_by_idempotency_key(hash('sha256', $key));
}
