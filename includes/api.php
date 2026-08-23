<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('corsivo_mail_send')) {
	function corsivo_mail_send($post_id, $to = null, $context = []) {
		return \CorsivoMail\Automation\send_email($post_id, $to, $context);
	}
}

if (!function_exists('corsivo_mail_canonicalize_idempotency_value')) {
	function corsivo_mail_canonicalize_idempotency_value($value) {
		if (is_array($value)) {
			if (!array_is_list($value)) {
				ksort($value, SORT_STRING);
			}
			foreach ($value as $key => $item) {
				$value[$key] = corsivo_mail_canonicalize_idempotency_value($item);
			}
			return $value;
		}
		if (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
			throw new \InvalidArgumentException('Valore non serializzabile nell’identità email.');
		}
		return $value;
	}
}

if (!function_exists('corsivo_mail_enqueue_registered')) {
	function corsivo_mail_enqueue_registered($provider_id, $to, $context, $idempotency_key, $source = []) {
		$provider_id     = sanitize_key($provider_id);
		$to              = sanitize_email($to);
		$idempotency_key = sanitize_text_field($idempotency_key);
		$context         = is_array($context) ? $context : [];
		$source          = is_array($source) ? $source : [];

		if ('' === $provider_id || !is_email($to) || '' === $idempotency_key) {
			return new \WP_Error('corsivo_mail_registered_request_invalid', 'Richiesta email registrata non valida.');
		}

		if (array_key_exists('retain_until', $source) && !is_scalar($source['retain_until']) && $source['retain_until'] !== null) {
			return new \WP_Error('corsivo_mail_invalid_retention', 'Data di retention non valida.');
		}
		$source = [
			'type' => sanitize_key($source['type'] ?? 'integration'),
			'id'   => max(0, (int) ($source['id'] ?? 0)),
			'source_guard_required' => !empty($source['source_guard_required']),
			'retain_until' => is_scalar($source['retain_until'] ?? null)
				? trim((string) $source['retain_until'])
				: '',
		];
		if ($source['retain_until'] !== '') {
			$retain_until = \CorsivoMail\Queue\normalize_optional_datetime($source['retain_until']);
			if (is_wp_error($retain_until)) {
				return $retain_until;
			}
			$source['retain_until'] = $retain_until;
		}
		try {
			$identity = corsivo_mail_canonicalize_idempotency_value([
				'version'  => 1,
				'domain'   => 'registered-provider',
				'provider' => $provider_id,
				'to'       => strtolower($to),
				'context'  => $context,
				'source'   => $source,
			]);
			$encoded_identity = wp_json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (!is_string($encoded_identity)) {
				throw new \InvalidArgumentException('Serializzazione dell’identità email non riuscita.');
			}
			$idempotency_fingerprint = hash('sha256', $encoded_identity);
		} catch (\Throwable $error) {
			return new \WP_Error('corsivo_mail_registered_identity_invalid', 'Identità della richiesta email non valida.');
		}

		$providers = \CorsivoMail\Automation\providers();
		if (!isset($providers[$provider_id])) {
			return new \WP_Error('corsivo_mail_provider_unavailable', 'Il provider email richiesto non è disponibile.');
		}

		$post = \CorsivoMail\Automation\find_post_for_core_email($provider_id);
		if (!$post) {
			return new \WP_Error('corsivo_mail_provider_unconfigured', 'Il template del provider email non è configurato.');
		}

		$config = \CorsivoMail\Automation\config($post->ID);
		if (!$config['send_enabled']) {
			return new \WP_Error('corsivo_mail_provider_disabled', 'L’invio del provider email è disabilitato.');
		}
		if (!$config['filter_enabled']) {
			return new \WP_Error('corsivo_mail_provider_inactive', 'Il provider email non è attivo.');
		}
		if (\CorsivoMail\Options\get('disable_sending')) {
			return new \WP_Error('corsivo_mail_delivery_paused', 'Gli invii di Corsivo Mail sono sospesi.');
		}

		$account = !empty($config['account_id'])
			? \CorsivoMail\Accounts\get($config['account_id'])
			: \CorsivoMail\Accounts\get_default();
		if (!empty($config['account_id']) && !$account) {
			return new \WP_Error('corsivo_mail_account_unavailable', 'L’account associato al provider email non è disponibile.');
		}

		try {
			$rendered = \CorsivoMail\Automation\render_automation_email($post, $config, $context);
		} catch (\Throwable $error) {
			return new \WP_Error('corsivo_mail_render_failed', 'Rendering del provider email non riuscito.', [
				'exception' => get_class($error),
			]);
		}

		$queue_key = 'registered:' . $provider_id . ':' . $idempotency_key;
		$existing  = \CorsivoMail\Queue\get_by_idempotency($queue_key);
		$queue_id  = \CorsivoMail\Queue\enqueue([
			'to'              => $to,
			'subject'         => $rendered['subject'],
			'message'         => $rendered['html'],
			'headers'         => ['Content-Type: text/html; charset=UTF-8'],
			'account_id'      => $account['id'] ?? '',
			'source_type'     => $source['type'],
			'source_id'       => $source['id'],
			'source_guard_required' => $source['source_guard_required'],
			'retain_until'    => $source['retain_until'],
			'message_type'    => 'transactional',
			'idempotency_key' => $queue_key,
			'idempotency_fingerprint' => $idempotency_fingerprint,
		]);
		if (is_wp_error($queue_id)) {
			return $queue_id;
		}

		if (!$existing) {
			\CorsivoMail\Log\record('queued', $to, $rendered['subject'], '', [
				'event'       => 'queued',
				'queue_id'    => (int) $queue_id,
				'source_type' => $source['type'],
				'source_id'   => $source['id'],
				'account_id'  => $account['id'] ?? '',
			]);
		}

		return (int) $queue_id;
	}
}


if (!function_exists('corsivo_mail_fence_queued')) {
	function corsivo_mail_fence_queued($queue_id, $source_type, $source_id) {
		$queue_id = (int) $queue_id;
		$source_id = (int) $source_id;
		if (!is_string($source_type)
			|| $source_type === ''
			|| sanitize_key($source_type) !== $source_type
			|| $queue_id < 1
			|| $source_id < 1) {
			return new \WP_Error('corsivo_mail_queue_status_identity_invalid', 'Identità fence coda non valida.');
		}
		return \CorsivoMail\Queue\fence_queued_for_source(
			$queue_id,
			$source_type,
			$source_id
		);
	}
}

if (!function_exists('corsivo_mail_cancel_queued')) {
	function corsivo_mail_cancel_queued($queue_id, $source_type, $source_id, $error_code = 'source_cancelled') {
		$queue_id = (int) $queue_id;
		$source_id = (int) $source_id;
		if (!is_string($source_type)
			|| $source_type === ''
			|| sanitize_key($source_type) !== $source_type
			|| $queue_id < 1
			|| $source_id < 1) {
			return new \WP_Error('corsivo_mail_queue_status_identity_invalid', 'Identità cancellazione coda non valida.');
		}
		$status = \CorsivoMail\Queue\cancel_pending_for_source(
			$queue_id,
			$source_type,
			$source_id,
			(string) $error_code
		);
		if (!is_wp_error($status)
			&& (string) ($status['status'] ?? '') === 'cancelled'
			&& function_exists('CorsivoMail\\Queue\\dispatch_terminal')) {
			\CorsivoMail\Queue\dispatch_terminal($queue_id, $source_type, $source_id);
		}
		return $status;
	}
}

if (!function_exists('corsivo_mail_queue_status')) {
	function corsivo_mail_queue_status($queue_id, $source_type, $source_id) {
		$queue_id = (int) $queue_id;
		$source_id = (int) $source_id;
		if (!is_string($source_type)
			|| $source_type === ''
			|| sanitize_key($source_type) !== $source_type
			|| $queue_id < 1
			|| $source_id < 1) {
			return new \WP_Error('corsivo_mail_queue_status_identity_invalid', 'Identità stato coda non valida.');
		}
		return \CorsivoMail\Queue\get_status_for_source($queue_id, $source_type, $source_id);
	}
}
