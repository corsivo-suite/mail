<?php

namespace CorsivoMail\Automation;

use CorsivoMail\Template;
use CorsivoMail\Queue;
use CorsivoMail\Accounts;
use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

add_action('rest_api_init', function () {
	$permission = function () {
		return current_user_can('manage_options');
	};

	register_rest_route('corsivo/v1/mail', '/recipients', [
		'methods'             => ['GET', 'POST'],
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_recipients',
	]);
	register_rest_route('corsivo/v1/mail', '/send-one', [
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_send_one',
	]);
	register_rest_route('corsivo/v1/mail', '/send-batch', [
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_send_batch',
	]);
});

function rest_recipients($request) {
	$post_id = (int) ($request['post'] ?? 0);
	$post    = get_post($post_id);
	if (!$post || $post->post_type !== TYPE) {
		return new \WP_Error('not_found', 'Email non trovata.', ['status' => 404]);
	}
	if (!current_user_can('edit_post', $post_id)) {
		return new \WP_Error('forbidden', 'Non autorizzato.', ['status' => 403]);
	}

	if ($request->get_param('audience_type') !== null) {
		$config = [
			'audience_type'   => sanitize_text_field($request['audience_type']),
			'audience_role'   => sanitize_text_field($request['audience_role'] ?? ''),
			'audience_emails' => sanitize_textarea_field($request['audience_emails'] ?? ''),
		];
	} else {
		$config = config($post_id);
	}
	$limit = max(1, min(500, (int) apply_filters('corsivo_mail_editor_recipient_limit', 500)));
	$count = recipient_count($config);
	if ($count !== null && $count > $limit) {
		return new \WP_Error('audience_too_large', 'L\'audience contiene ' . $count . ' destinatari: usa un invio programmato, che viene gestito dalla coda sul server.', ['status' => 413]);
	}

	$out = [];
	foreach (iterate_recipients($config) as $recipient) {
		if (empty($recipient['email'])) {
			continue;
		}
		if (count($out) >= $limit) {
			return new \WP_Error('audience_too_large', 'L\'audience supera il limite dell\'invio interattivo: usa un invio programmato.', ['status' => 413]);
		}
		$user  = $recipient['user'] ?? null;
		$out[] = [
			'email'   => $recipient['email'],
			'name'    => $user ? $user->display_name : '',
			'user_id' => $user ? (int) $user->ID : 0,
		];
	}

	return rest_ensure_response($out);
}

function rest_send_one($request) {
	$post_id     = (int) ($request['post'] ?? 0);
	$email       = sanitize_email($request['email'] ?? '');
	$user_id     = (int) ($request['user'] ?? 0);
	$schedule_at = sanitize_text_field($request['schedule_at'] ?? '');
	$post        = get_post($post_id);

	if (!$post || $post->post_type !== TYPE || !$email || !current_user_can('edit_post', $post_id)) {
		return new \WP_Error('bad_request', 'Parametri non validi.', ['status' => 400]);
	}
	$config = config($post_id);
	if (!$config['send_enabled']) {
		return new \WP_Error('sending_disabled', 'L\'invio di questa email è disattivato.', ['status' => 409]);
	}
	$account = !empty($config['account_id']) ? Accounts\get($config['account_id']) : Accounts\get_default();
	if (!empty($config['account_id']) && !$account) {
		return new \WP_Error('account_missing', 'L\'account mittente configurato non esiste più.', ['status' => 409]);
	}
	if ($user_id) {
		$user = get_userdata($user_id);
		if (!$user || strcasecmp($user->user_email, $email) !== 0) {
			return new \WP_Error('recipient_mismatch', 'Il contesto utente non corrisponde al destinatario.', ['status' => 400]);
		}
	}
	if ($config['message_type'] === 'marketing' && function_exists('CorsivoMail\\Newsletter\\can_receive_marketing') && !\CorsivoMail\Newsletter\can_receive_marketing($email)) {
		\CorsivoMail\Log\record('suppressed', $email, $post->post_title, '', [
			'event' => 'marketing_suppressed',
			'source_type' => 'automation',
			'source_id' => $post_id,
		]);
		return rest_ensure_response(['suppressed' => true]);
	}

	$context = $user_id ? user_context(get_userdata($user_id)) : [];
	$rendered = render_automation_email($post, $config, $context);
	$subject = $rendered['subject'];
	$html = $rendered['html'];

	if ($schedule_at !== '') {
		$scheduled = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $schedule_at, wp_timezone());
		$errors = \DateTimeImmutable::getLastErrors();
		if (!$scheduled || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $scheduled <= current_datetime()) {
			return new \WP_Error('bad_schedule', 'Data di programmazione non valida.', ['status' => 400]);
		}
		$queued = Queue\enqueue([
			'to'           => $email,
			'subject'      => $subject,
			'message'      => $html,
			'headers'      => ['Content-Type: text/html; charset=UTF-8'],
			'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
			'source_type' => 'automation',
			'source_id' => $post_id,
			'message_type' => $config['message_type'],
			'account_id' => $account['id'] ?? '',
		]);
		if (is_wp_error($queued)) {
			return $queued;
		}
		return rest_ensure_response(['queued' => true, 'queue_id' => (int) $queued]);
	}

	unset($GLOBALS['corsivo_mail_last_queue_id']);
	$sent = Template\send_rendered($email, $subject, $html, $config['account_id'] ?? '', [], $config['message_type']);

	if (!empty($GLOBALS['corsivo_mail_last_queue_id'])) {
		return rest_ensure_response(['queued' => true, 'queue_id' => (int) $GLOBALS['corsivo_mail_last_queue_id']]);
	}
	if (!empty($GLOBALS['corsivo_mail_last_send_suppressed'])) {
		return rest_ensure_response(['suppressed' => true]);
	}
	if ($sent) {
		return rest_ensure_response(['sent' => true]);
	}
	return new \WP_Error('send_failed', 'Invio fallito.', ['status' => 500]);
}

function rest_send_batch($request) {
	$post_id = (int) ($request['post'] ?? 0);
	$post = get_post($post_id);
	$recipients = is_array($request['recipients'] ?? null) ? $request['recipients'] : [];
	$operation_id = substr(sanitize_text_field($request['operation_id'] ?? ''), 0, 100);
	$schedule_at = sanitize_text_field($request['schedule_at'] ?? '');

	if (!$post || $post->post_type !== TYPE || !current_user_can('edit_post', $post_id) || !$operation_id || !$recipients || count($recipients) > 500) {
		return new \WP_Error('bad_request', 'Parametri batch non validi.', ['status' => 400]);
	}

	$config = config($post_id);
	if (!$config['send_enabled']) {
		return new \WP_Error('sending_disabled', 'L\'invio di questa email è disattivato.', ['status' => 409]);
	}
	$account = !empty($config['account_id']) ? Accounts\get($config['account_id']) : Accounts\get_default();
	if (!empty($config['account_id']) && !$account) {
		return new \WP_Error('account_missing', 'L\'account mittente configurato non esiste più.', ['status' => 409]);
	}

	$scheduled = current_datetime();
	if ($schedule_at !== '') {
		$scheduled = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $schedule_at, wp_timezone());
		$errors = \DateTimeImmutable::getLastErrors();
		if (!$scheduled || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $scheduled <= current_datetime()) {
			return new \WP_Error('bad_schedule', 'Data di programmazione non valida.', ['status' => 400]);
		}
	}

	$results = [];
	$seen = [];
	foreach ($recipients as $recipient) {
		if (!is_array($recipient)) {
			continue;
		}
		$email = sanitize_email($recipient['email'] ?? '');
		$user_id = (int) ($recipient['user_id'] ?? 0);
		$email_key = strtolower($email);
		if (!$email || isset($seen[$email_key])) {
			continue;
		}
		$seen[$email_key] = true;
		$user = $user_id ? get_userdata($user_id) : null;
		if ($user_id && (!$user || strcasecmp($user->user_email, $email) !== 0)) {
			$results[] = ['email' => $email, 'status' => 'failed', 'error' => 'recipient_mismatch'];
			continue;
		}
		if ($schedule_at === '' && Options\get('disable_sending')) {
			\CorsivoMail\Log\record('suppressed', $email, $post->post_title, '', ['event' => 'delivery_suppressed', 'source_type' => 'automation', 'source_id' => $post_id]);
			$results[] = ['email' => $email, 'status' => 'suppressed'];
			continue;
		}
		if ($config['message_type'] === 'marketing' && function_exists('CorsivoMail\\Newsletter\\can_receive_marketing') && !\CorsivoMail\Newsletter\can_receive_marketing($email)) {
			\CorsivoMail\Log\record('suppressed', $email, $post->post_title, '', ['event' => 'marketing_suppressed', 'source_type' => 'automation', 'source_id' => $post_id]);
			$results[] = ['email' => $email, 'status' => 'suppressed'];
			continue;
		}

		$idempotency_key = 'manual-batch:' . $post_id . ':' . $operation_id . ':' . $email_key;
		$existing = Queue\get_by_idempotency($idempotency_key);
		$context = $user ? user_context($user) : [];
		$rendered = render_automation_email($post, $config, $context);
		$headers = ['Content-Type: text/html; charset=UTF-8'];
		$idempotency_payload = wp_json_encode([
			'version' => 1,
			'domain' => 'manual-batch',
			'operation_id' => $operation_id,
			'to' => $email_key,
			'subject' => $rendered['subject'],
			'message' => $rendered['html'],
			'headers' => $headers,
			'account_id' => $account['id'] ?? '',
			'schedule_at' => $schedule_at,
			'source_type' => 'automation',
			'source_id' => (int) $post_id,
			'message_type' => $config['message_type'],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($idempotency_payload)) {
			$results[] = ['email' => $email, 'status' => 'failed', 'error' => 'idempotency_identity_invalid'];
			continue;
		}
		$queued = Queue\enqueue([
			'to' => $email,
			'subject' => $rendered['subject'],
			'message' => $rendered['html'],
			'headers' => $headers,
			'account_id' => $account['id'] ?? '',
			'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
			'source_type' => 'automation',
			'source_id' => $post_id,
			'message_type' => $config['message_type'],
			'idempotency_key' => $idempotency_key,
			'idempotency_fingerprint' => hash('sha256', $idempotency_payload),
		]);
		if (is_wp_error($queued)) {
			$results[] = ['email' => $email, 'status' => 'failed', 'error' => $queued->get_error_code()];
			continue;
		}
		if ($existing && (int) $existing['id'] === (int) $queued) {
			$status = in_array($existing['status'], ['pending', 'sending', 'transmitting'], true)
				? 'queued'
				: ($existing['status'] === 'cancelled'
					? 'suppressed'
					: ($existing['status'] === 'delivery_unknown' ? 'attention_required' : $existing['status']));
			$results[] = ['email' => $email, 'status' => $status, 'queue_id' => (int) $existing['id']];
			continue;
		}
		\CorsivoMail\Log\record('queued', $email, $rendered['subject'], '', [
			'event' => 'queued',
			'queue_id' => (int) $queued,
			'source_type' => 'automation',
			'source_id' => $post_id,
			'account_id' => $account['id'] ?? '',
		]);
		$results[] = ['email' => $email, 'status' => 'queued', 'queue_id' => (int) $queued];
	}

	return rest_ensure_response(['results' => $results]);
}
