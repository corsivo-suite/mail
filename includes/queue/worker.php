<?php

namespace CorsivoMail\Queue;

use CorsivoMail\Options;
use CorsivoMail\Accounts;
use CorsivoMail\Smtp;

if (!defined('ABSPATH')) {
	exit;
}

add_action('corsivo_mail_process_queue', __NAMESPACE__ . '\\process');
add_action('corsivo_mail_cleanup_queue', __NAMESPACE__ . '\\run_cleanup');

function storage_preflight_request_key() {
	$identities = array();
	foreach (\CorsivoMail\required_storage_configurations() as $configuration) {
		$identities[] = storage_cache_key($configuration) . ':' . storage_source_fingerprint($configuration);
	}
	sort($identities, SORT_STRING);
	return hash('sha256', implode("\0", $identities));
}

function verify_storage_before_work() {
	static $verified_contexts = array();

	$request_key = storage_preflight_request_key();
	if (!empty($verified_contexts[$request_key]) && \CorsivoMail\required_storage_ready()) {
		return true;
	}
	unset($verified_contexts[$request_key]);
	$verified = \CorsivoMail\verify_required_storage();
	if (is_wp_error($verified)) {
		return $verified;
	}
	if (!\CorsivoMail\load_runtime() || !\CorsivoMail\required_storage_ready(true)) {
		return new \WP_Error('corsivo_mail_storage_verification_failed', 'Lo storage Mail non è disponibile per il worker.');
	}
	$verified_contexts[$request_key] = true;
	return true;
}

function worker_unavailable_result($error) {
	$result = [
		'status' => 'unavailable',
		'processed' => 0,
		'error_code' => is_wp_error($error) ? $error->get_error_code() : 'corsivo_mail_storage_verification_failed',
		'created_at' => current_time('mysql'),
	];
	update_option('corsivo_mail_last_worker_run', $result, false);
	return $result;
}

function delivery_probe_headers($headers, $token) {
	if (is_array($headers)) {
		$lines = array();
		foreach ($headers as $name => $value) {
			$lines[] = is_string($name) ? $name . ': ' . $value : (string) $value;
		}
	} else {
		$lines = preg_split('/\r\n|\r|\n/', (string) $headers, -1, PREG_SPLIT_NO_EMPTY);
	}
	$lines = array_values(array_filter($lines, static function ($line) {
		return stripos((string) $line, 'X-Corsivo-Mail-Delivery:') !== 0;
	}));
	$lines[] = 'X-Corsivo-Mail-Delivery: ' . $token;
	return $lines;
}

function delivery_event_matches($mail_data, $token) {
	$headers = $mail_data['headers'] ?? array();
	if (is_string($headers)) {
		$headers = preg_split('/\r\n|\r|\n/', $headers, -1, PREG_SPLIT_NO_EMPTY);
	}
	foreach ((array) $headers as $name => $value) {
		if (is_string($name) && strcasecmp($name, 'X-Corsivo-Mail-Delivery') === 0) {
			return is_string($value) && hash_equals($token, trim($value));
		}
		$line = (string) $value;
		if (stripos($line, 'X-Corsivo-Mail-Delivery:') === 0) {
			return hash_equals($token, trim(substr($line, strlen('X-Corsivo-Mail-Delivery:'))));
		}
	}
	return false;
}

function dispatch_action_callbacks($hook, $args) {
	global $wp_filter;
	$registered = $wp_filter[$hook] ?? null;
	$callbacks = is_object($registered) && isset($registered->callbacks)
		? (array) $registered->callbacks
		: [];
	$succeeded = true;
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
				$succeeded = false;
				\CorsivoMail\Log\audit('queue_terminal_listener_failed', 'failed', [
					'error_code' => 'listener_exception',
				]);
			}
		}
	}
	return $succeeded;
}

function dispatch_action($hook, ...$args) {
	global $wp_actions, $wp_current_filter, $wp_filter;
	$hook = (string) $hook;
	$wp_actions[$hook] = ($wp_actions[$hook] ?? 0) + 1;
	$wp_current_filter[] = $hook;
	$succeeded = true;
	try {
		if (isset($wp_filter['all'])) {
			$succeeded = dispatch_action_callbacks('all', array_merge([$hook], $args)) && $succeeded;
		}
		if (isset($wp_filter[$hook])) {
			$succeeded = dispatch_action_callbacks($hook, $args ?: ['']) && $succeeded;
		}
	} finally {
		array_pop($wp_current_filter);
	}
	return $succeeded;
}

function dispatch_terminal($queue_id, $source_type, $source_id) {
	$queue_id = (int) $queue_id;
	$source_type = (string) $source_type;
	$source_id = (int) $source_id;
	if ($queue_id < 1 || $source_type === '' || $source_id < 1) {
		return true;
	}
	$event = get_status_for_source($queue_id, $source_type, $source_id);
	if (is_wp_error($event)) {
		\CorsivoMail\Log\audit('queue_terminal_status_failed', 'failed', [
			'queue_id' => $queue_id,
			'error_code' => $event->get_error_code(),
		]);
		return false;
	}
	if (!in_array((string) $event['status'], ['sent', 'failed', 'cancelled', 'delivery_unknown'], true)) {
		return false;
	}
	return dispatch_action('corsivo_mail_queue_terminal', $event);
}

function dispatch_source_guard($source_type, $identity) {
	global $wp_filter;
	$hook = 'corsivo_mail_queue_source_guard_' . sanitize_key((string) $source_type);
	$registered = $wp_filter[$hook] ?? null;
	$callbacks = is_object($registered) && isset($registered->callbacks)
		? (array) $registered->callbacks
		: [];
	$handled = false;
	$unverifiable = null;
	foreach ($callbacks as $priority_callbacks) {
		foreach ((array) $priority_callbacks as $callback) {
			try {
				$accepted_args = max(1, (int) ($callback['accepted_args'] ?? 1));
				$args = array_slice([$handled ? true : null, $identity], 0, $accepted_args);
				$result = call_user_func_array($callback['function'], $args);
				if ($result === false) {
					return false;
				}
				if (is_wp_error($result)) {
					$unverifiable = $result;
				} elseif ($result === true) {
					$handled = true;
				} elseif ($result !== null) {
					$unverifiable = new \WP_Error('corsivo_mail_queue_source_guard_invalid');
				}
			} catch (\Throwable $throwable) {
				$unverifiable = new \WP_Error('corsivo_mail_queue_source_guard_failed');
			}
		}
	}
	return $unverifiable ?: ($handled ? true : null);
}

function guard_source($row) {
	$fence = source_send_fence((int) ($row['id'] ?? 0), (string) ($row['lock_token'] ?? ''));
	if (is_wp_error($fence)) {
		return $fence;
	}
	$identity = [
		'queue_id' => (int) ($row['id'] ?? 0),
		'source_type' => (string) ($row['source_type'] ?? ''),
		'source_id' => (int) ($row['source_id'] ?? 0),
	];
	$allowed = dispatch_source_guard((string) ($row['source_type'] ?? ''), $identity);
	if (is_wp_error($allowed)) {
		return $allowed;
	}
	if ($allowed === true) {
		return true;
	}
	if ($allowed === false) {
		return new \WP_Error('corsivo_mail_queue_source_inactive');
	}
	return !empty($fence['source_guard_required'])
		? new \WP_Error('corsivo_mail_queue_source_guard_unavailable')
		: true;
}

function enforce_source_guard($row, $lock_token) {
	$guard = guard_source($row);
	if (!is_wp_error($guard)) {
		return true;
	}
	$error_code = sanitize_key($guard->get_error_code());
	if ($error_code === 'corsivo_mail_queue_source_inactive') {
		$updated = mark_suppressed($row['id'], $lock_token, 'Invio annullato dalla sorgente.', 'source_inactive');
		if ($updated === 1) {
			dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		}
		return false;
	}
	$attempt = max(1, (int) ($row['attempts'] ?? 1));
	$max_attempts = max(1, (int) ($row['max_attempts'] ?? 1));
	$terminal = $attempt >= $max_attempts;
	$updated = mark_failed(
		$row['id'],
		$lock_token,
		$attempt,
		$max_attempts,
		'Autorizzazione della sorgente non verificabile.',
		'source_guard_unavailable'
	);
	\CorsivoMail\Log\audit($terminal ? 'queue_source_guard_failed_final' : 'queue_source_guard_deferred', 'failed', [
		'queue_id' => (int) $row['id'],
		'error_code' => $updated === 1 ? 'source_guard_unavailable' : 'queue_lock_lost',
	]);
	if ($updated === 1 && $terminal) {
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
	}
	return false;
}

function process() {
	$verified = verify_storage_before_work();
	if (is_wp_error($verified)) {
		return worker_unavailable_result($verified);
	}
	if (Options\get('disable_sending')) {
		update_option('corsivo_mail_last_worker_run', [
			'status' => 'paused',
			'processed' => 0,
			'created_at' => current_time('mysql'),
		], false);
		return ['status' => 'paused', 'processed' => 0];
	}

	$terminal_events = [];
	$stale_result = requeue_stale(max(3, (int) Options\get('queue_lock_minutes')), $terminal_events);
	if ($stale_result === false) {
		\CorsivoMail\Log\audit('queue_requeue_failed', 'failed', ['error_code' => 'queue_requeue_failed']);
		$error = new \WP_Error('corsivo_mail_queue_requeue_failed', 'Il ripristino delle lease scadute non è riuscito.');
		update_option('corsivo_mail_last_worker_run', [
			'status' => 'failed',
			'processed' => 0,
			'error_code' => $error->get_error_code(),
			'created_at' => current_time('mysql'),
		], false);
		return $error;
	}
	foreach ($terminal_events as $terminal_event) {
		dispatch_terminal(
			(int) $terminal_event['queue_id'],
			(string) $terminal_event['source_type'],
			(int) $terminal_event['source_id']
		);
	}

	$limit = (int) Options\get('queue_batch_size');
	$sleep_ms = (int) Options\get('queue_sleep_ms');
	$processed = 0;

	for ($i = 0; $i < $limit; $i++) {
		if ($i > 0 && $sleep_ms > 0) {
			usleep($sleep_ms * 1000);
		}
		$batch = claim_batch(1);
		if (is_wp_error($batch)) {
			$error_code = $batch->get_error_code();
			\CorsivoMail\Log\audit('worker_failed', 'failed', ['error_code' => $error_code]);
			update_option('corsivo_mail_last_worker_run', [
				'status' => 'failed',
				'processed' => 0,
				'error_code' => $error_code,
				'created_at' => current_time('mysql'),
			], false);
			return $batch;
		}
		if (!$batch) {
			break;
		}
		try {
			send_row($batch[0]);
		} catch (\Throwable $error) {
			$row = $batch[0];
			$attempt = max(1, (int) $row['attempts']);
			$max_attempts = (int) $row['max_attempts'];
			$terminal = $attempt >= $max_attempts;
			$updated = mark_failed($row['id'], $row['lock_token'], $attempt, $max_attempts, 'Errore interno del worker.', 'worker_exception');
			\CorsivoMail\Log\audit('worker_row_exception', 'failed', [
				'queue_id' => (int) $row['id'],
				'attempt' => $attempt,
				'error_code' => $updated === 1 ? 'worker_exception' : 'queue_lock_lost',
			]);
			if ($updated === 1 && $terminal) {
				dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
			}
		}
		$processed++;
	}

	$status = $processed ? 'completed' : 'idle';
	$result = ['status' => $status, 'processed' => $processed, 'created_at' => current_time('mysql')];
	update_option('corsivo_mail_last_worker_run', $result, false);
	return $result;
}

function run_cleanup() {
	$verified = verify_storage_before_work();
	if (is_wp_error($verified)) {
		return false;
	}
	return cleanup((int) Options\get('queue_cleanup_days'));
}

function send_row($row) {
	if (!runtime_ready()) {
		return false;
	}
	$to          = unserialize_recipient($row['recipient']);
	$lock_token = (string) ($row['lock_token'] ?? '');
	$attempt = max(1, (int) $row['attempts']);
	$account = $row['account_id'] ? Accounts\get($row['account_id']) : Accounts\get_default();
	if ($row['account_id'] && !$account) {
		$updated = mark_failed($row['id'], $lock_token, $attempt, (int) $row['max_attempts'], 'L\'account SMTP associato non esiste più.', 'account_missing', true);
		if ($updated !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		\CorsivoMail\Log\record('failed', $row['recipient'], $row['subject'], 'Account SMTP mancante.', [
			'event' => 'failed_final',
			'queue_id' => (int) $row['id'],
			'attempt' => $attempt,
			'error_code' => 'account_missing',
		]);
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return false;
	}

	if (!enforce_source_guard($row, $lock_token)) {
		return false;
	}

	try {
		$headers = decode_payload($row['headers']);
		$attachments = decode_payload($row['attachments']);
		$embeds = decode_payload($row['embeds'] ?? '');
		$body = decode_body($row['body']);
	} catch (\Throwable $error) {
		$updated = mark_failed($row['id'], $lock_token, $attempt, (int) $row['max_attempts'], 'Payload email non valido.', 'payload_invalid', true);
		if ($updated !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		\CorsivoMail\Log\audit('payload_invalid', 'failed', [
			'queue_id' => (int) $row['id'],
			'error_code' => 'payload_invalid',
		]);
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return false;
	}

	$message_type = ($row['message_type'] ?? 'transactional') === 'marketing' ? 'marketing' : 'transactional';
	if ($message_type === 'marketing' && function_exists('CorsivoMail\\Newsletter\\can_receive_marketing') && !\CorsivoMail\Newsletter\can_receive_marketing($to)) {
		$multi_recipient = function_exists('CorsivoMail\\Newsletter\\normalize_recipients') && count(\CorsivoMail\Newsletter\normalize_recipients($to)) !== 1;
		$updated = $multi_recipient
			? mark_suppressed($row['id'], $lock_token, 'Invio marketing multi-destinatario non supportato: usa un destinatario per messaggio.', 'marketing_multi_recipient')
			: mark_suppressed($row['id'], $lock_token);
		if ($updated !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		$suppression_context = [
			'event' => 'marketing_suppressed',
			'queue_id' => (int) $row['id'],
			'attempt' => $attempt,
			'source_type' => $row['source_type'] ?? '',
			'source_id' => (int) ($row['source_id'] ?? 0),
			'error_code' => $multi_recipient ? 'marketing_multi_recipient' : 'marketing_consent_missing',
		];
		if (!empty($row['account_id'])) {
			$suppression_context['account_id'] = (string) $row['account_id'];
		}
		\CorsivoMail\Log\record('suppressed', $row['recipient'], $row['subject'], '', $suppression_context);
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return false;
	}

	if ($message_type === 'marketing' && Options\get('module_subscriptions')) {
		$preflight = function_exists('CorsivoMail\\Newsletter\\marketing_unsubscribe_preflight')
			? \CorsivoMail\Newsletter\marketing_unsubscribe_preflight($to, $headers)
			: new \WP_Error('corsivo_mail_unsubscribe_preflight_unavailable', 'Il controllo di disiscrizione non è disponibile.');
		if (is_wp_error($preflight)) {
			$updated = mark_failed(
				$row['id'],
				$lock_token,
				$attempt,
				(int) $row['max_attempts'],
				$preflight->get_error_message(),
				$preflight->get_error_code(),
				true
			);
			if ($updated !== 1) {
				\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
				return false;
			}
			\CorsivoMail\Log\audit('failed_final', 'failed', [
				'queue_id' => (int) $row['id'],
				'attempt' => $attempt,
				'error_code' => $preflight->get_error_code(),
			]);
			dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
			return false;
		}
	}

	$error = '';
	$delivery_confirmed = false;
	$delivery_token = bin2hex(random_bytes(32));
	$headers = delivery_probe_headers($headers, $delivery_token);
	$listener = function ($wp_error) use (&$error) {
		$error = $wp_error->get_error_message();
	};
	$success_listener = function ($mail_data) use (&$delivery_confirmed, $delivery_token) {
		if (is_array($mail_data) && delivery_event_matches($mail_data, $delivery_token)) {
			$delivery_confirmed = true;
		}
	};
	$strip_probe = function ($phpmailer) use ($delivery_token) {
		if (is_object($phpmailer) && method_exists($phpmailer, 'clearCustomHeader')) {
			$phpmailer->clearCustomHeader('X-Corsivo-Mail-Delivery', $delivery_token);
		}
	};
	add_action('wp_mail_failed', $listener);
	add_action('wp_mail_succeeded', $success_listener, PHP_INT_MAX);
	add_action('phpmailer_init', $strip_probe, PHP_INT_MAX);

	$previous_bypass = $GLOBALS['corsivo_mail_bypass_queue'] ?? false;
	$previous_context = $GLOBALS['corsivo_mail_log_context'] ?? null;
	$previous_type = $GLOBALS['corsivo_mail_message_type'] ?? 'transactional';
	$GLOBALS['corsivo_mail_bypass_queue'] = true;
	$GLOBALS['corsivo_mail_message_type'] = $message_type;
	unset($GLOBALS['corsivo_mail_last_send_suppressed']);
	$GLOBALS['corsivo_mail_log_context'] = [
		'queue_id' => (int) $row['id'],
		'attempt' => $attempt,
		'source_type' => $row['source_type'] ?? '',
		'source_id' => (int) ($row['source_id'] ?? 0),
	];
	$started_at = microtime(true);

	$source_guard_deferred = false;
	$transport_threw = false;
	try {
		if (!enforce_source_guard($row, $lock_token)) {
			$source_guard_deferred = true;
			$sent = false;
		} else {
			$transport_fence = start_transport((int) $row['id'], $lock_token);
			if (is_wp_error($transport_fence)) {
				$source_guard_deferred = true;
				$sent = false;
			} elseif (!$transport_fence) {
				enforce_source_guard($row, $lock_token);
				$source_guard_deferred = true;
				$sent = false;
			} else {
				$sent = Smtp\with_account($account, function () use ($to, $row, $body, $headers, $attachments, $embeds) {
					if (version_compare(get_bloginfo('version'), '6.9', '>=')) {
						return wp_mail($to, $row['subject'], $body, $headers, $attachments, $embeds);
					}
					return wp_mail($to, $row['subject'], $body, $headers, $attachments);
				});
			}
		}
	} catch (\Throwable $throwable) {
		$sent = false;
		$error = $throwable->getMessage();
		$transport_threw = true;
	} finally {
		$GLOBALS['corsivo_mail_bypass_queue'] = $previous_bypass;
		$GLOBALS['corsivo_mail_message_type'] = $previous_type;
		if ($previous_context === null) {
			unset($GLOBALS['corsivo_mail_log_context']);
		} else {
			$GLOBALS['corsivo_mail_log_context'] = $previous_context;
		}
		remove_action('wp_mail_failed', $listener);
		remove_action('wp_mail_succeeded', $success_listener, PHP_INT_MAX);
		remove_action('phpmailer_init', $strip_probe, PHP_INT_MAX);
	}

	if ($source_guard_deferred) {
		return false;
	}

	if ($transport_threw) {
		$updated = mark_delivery_unknown($row['id'], $lock_token, 'Esito del trasporto non verificabile.');
		if ($updated !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return false;
	}

	if (!empty($GLOBALS['corsivo_mail_last_send_suppressed'])) {
		if (mark_suppressed($row['id'], $lock_token, 'Invio soppresso durante il trasporto.', 'send_suppressed', 'transmitting') !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		\CorsivoMail\Log\record('suppressed', $row['recipient'], $row['subject'], '', [
			'event' => 'send_suppressed',
			'queue_id' => (int) $row['id'],
			'attempt' => $attempt,
			'source_type' => $row['source_type'] ?? '',
			'source_id' => (int) ($row['source_id'] ?? 0),
			'error_code' => 'send_suppressed',
		]);
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return false;
	}

	if ($sent === true && $delivery_confirmed) {
		$updated = mark_sent($row['id'], $lock_token);
		if ($updated !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return true;
	}
	if ($sent === true) {
		$updated = mark_delivery_unknown($row['id'], $lock_token, 'Evento wp_mail_succeeded non correlato allo specifico invio.');
		if ($updated !== 1) {
			\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
			return false;
		}
		\CorsivoMail\Log\audit('delivery_unknown', 'failed', [
			'queue_id' => (int) $row['id'],
			'attempt' => $attempt,
			'error_code' => 'delivery_event_missing',
		]);
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
		return false;
	}

	$updated = mark_failed($row['id'], $lock_token, $attempt, (int) $row['max_attempts'], $error, 'transport_failed', false, 'transmitting');
	if ($updated !== 1) {
		$cancelled = mark_suppressed(
			$row['id'],
			$lock_token,
			'Invio annullato dalla sorgente.',
			'source_inactive',
			'transmitting',
			true
		);
		if ($cancelled === 1) {
			dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
			return false;
		}
		\CorsivoMail\Log\audit('queue_lock_lost', 'failed', ['queue_id' => (int) $row['id'], 'error_code' => 'queue_lock_lost']);
		return false;
	}
	$terminal = $attempt >= (int) $row['max_attempts'];
	\CorsivoMail\Log\audit($terminal ? 'failed_final' : 'retry_scheduled', 'failed', [
		'queue_id' => (int) $row['id'],
		'attempt' => $attempt,
		'error_code' => 'transport_failed',
		'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
	]);
	if ($terminal) {
		dispatch_terminal((int) $row['id'], (string) ($row['source_type'] ?? ''), (int) ($row['source_id'] ?? 0));
	}
	return false;
}
