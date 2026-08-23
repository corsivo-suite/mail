<?php

namespace CorsivoMail\Imap;

use CorsivoMail\Accounts;
use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

add_action('rest_api_init', function () {
	if (!Options\get('module_imap')) {
		return;
	}
	$permission = function () {
		return current_user_can('manage_options');
	};

	register_rest_route('corsivo/v1/mail', '/imap-accounts', [
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_accounts',
	]);
	register_rest_route('corsivo/v1/mail', '/inbox', [
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_list',
	]);
	register_rest_route('corsivo/v1/mail', '/inbox-sync', [
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_sync',
	]);
	register_rest_route('corsivo/v1/mail', '/inbox-body', [
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_body',
	]);
	register_rest_route('corsivo/v1/mail', '/reply', [
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_reply',
	]);
	register_rest_route('corsivo/v1/mail', '/inbox-delete', [
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => __NAMESPACE__ . '\\rest_delete',
	]);
});

function rest_reply($request) {
	$account_id  = sanitize_text_field($request['account'] ?? '');
	$to          = sanitize_text_field($request['to'] ?? '');
	$subject     = sanitize_text_field($request['subject'] ?? '');
	$body        = wp_kses_post($request['body'] ?? '');
	$in_reply_to = trim(preg_replace('/[\r\n]+/', '', (string) ($request['in_reply_to'] ?? '')));
	$references  = trim(preg_replace('/[\r\n]+/', '', (string) ($request['references'] ?? '')));

	$recipients = array_filter(array_map('trim', explode(',', $to)));
	foreach ($recipients as $email) {
		if (!is_email($email)) {
			return new \WP_Error('bad_to', 'Destinatario non valido: ' . $email, ['status' => 400]);
		}
	}
	if (!$recipients) {
		return new \WP_Error('bad_to', 'Indica almeno un destinatario.', ['status' => 400]);
	}

	$html    = \CorsivoMail\Template\compile_document('<div>' . $body . '</div>', ['subject' => $subject]);
	$headers = [];
	if ($in_reply_to) {
		$headers[] = 'In-Reply-To: ' . $in_reply_to;
		$headers[] = 'References: ' . trim($references . ' ' . $in_reply_to);
	}

	unset($GLOBALS['corsivo_mail_last_send_suppressed']);
	$sent = \CorsivoMail\Template\send_immediately(implode(',', $recipients), $subject, $html, $account_id, $headers);

	if (!empty($GLOBALS['corsivo_mail_last_send_suppressed'])) {
		return new \WP_Error('sending_suppressed', 'Invio soppresso dalle impostazioni globali.', ['status' => 409]);
	}
	if ($sent) {
		\CorsivoMail\Log\audit('imap_reply_sent', 'sent', ['account_id' => $account_id]);
		return rest_ensure_response(['sent' => true]);
	}
	return new \WP_Error('send_failed', 'Invio fallito.', ['status' => 500]);
}

function rest_accounts() {
	$out = [];
	foreach (Accounts\all() as $account) {
		if (Accounts\has_imap($account)) {
			$out[] = ['id' => $account['id'], 'label' => $account['label'] ?: $account['from_email']];
		}
	}
	return rest_ensure_response($out);
}

function rest_list($request) {
	global $wpdb;
	$account_id = sanitize_text_field($request['account'] ?? '');
	$search     = sanitize_text_field($request['search'] ?? '');
	$offset     = max(0, (int) ($request['offset'] ?? 0));
	$limit      = min(100, max(1, (int) ($request['limit'] ?? 50)));
	$table      = messages_table();
	$account = Accounts\get($account_id);
	if (!Accounts\has_imap($account)) {
		return new \WP_Error('bad_account', 'Casella IMAP non valida.', ['status' => 400]);
	}

	$where  = "account_id = %s AND mailbox = 'INBOX'";
	$params = [$account_id];
	if ($search !== '') {
		$like   = '%' . $wpdb->esc_like($search) . '%';
		$where .= ' AND (subject LIKE %s OR from_name LIKE %s OR from_email LIKE %s)';
		array_push($params, $like, $like, $like);
	}

	$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params));

	$params[] = $limit;
	$params[] = $offset;
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT id, uid, from_name, from_email, to_field, subject, mail_date, seen, message_id, refs
		 FROM {$table} WHERE {$where}
		 ORDER BY mail_date DESC, uid DESC LIMIT %d OFFSET %d",
		$params
	), ARRAY_A);

	return rest_ensure_response(['messages' => $rows ?: [], 'total' => $total]);
}

function rest_delete($request) {
	global $wpdb;
	$account_id = sanitize_text_field($request['account'] ?? '');
	$uid        = (int) ($request['uid'] ?? 0);
	$account    = Accounts\get($account_id);

	if (!Accounts\has_imap($account) || !$uid) {
		return new \WP_Error('bad_request', 'Parametri non validi.', ['status' => 400]);
	}

	$client = new Client();
	try {
		$client->connect($account['imap_host'], $account['imap_port'], $account['imap_secure']);
		$client->login(Accounts\imap_username($account), Accounts\imap_password($account));
		$client->select('INBOX');
		$client->delete_uid($uid);
		$client->logout();
	} catch (\Exception $e) {
		$client->logout();
		return new \WP_Error('delete_failed', $e->getMessage(), ['status' => 500]);
	}

	$deleted = $wpdb->delete(messages_table(), ['account_id' => $account_id, 'mailbox' => 'INBOX', 'uid' => $uid]);
	if ($deleted === false) {
		\CorsivoMail\Log\audit('imap_message_delete_local_failed', 'failed', ['account_id' => $account_id, 'error_code' => 'database_delete_failed']);
		return new \WP_Error('local_delete_failed', 'Messaggio eliminato dal server, ma la cache locale non è stata aggiornata.', ['status' => 500]);
	}
	\CorsivoMail\Log\audit('imap_message_deleted', 'notice', ['account_id' => $account_id, 'deleted' => 1]);
	return rest_ensure_response(['deleted' => true]);
}

function rest_sync($request) {
	$account_id = sanitize_text_field($request['account'] ?? '');
	try {
		$new = sync_account($account_id);
		\CorsivoMail\Log\audit('imap_synced', 'notice', ['account_id' => $account_id, 'created' => $new]);
		return rest_ensure_response(['new' => $new]);
	} catch (\Exception $e) {
		return new \WP_Error('sync_failed', $e->getMessage(), ['status' => 500]);
	}
}

function rest_body($request) {
	$account_id = sanitize_text_field($request['account'] ?? '');
	$uid        = (int) ($request['uid'] ?? 0);
	$account    = Accounts\get($account_id);

	if (!Accounts\has_imap($account) || !$uid) {
		return new \WP_Error('bad_request', 'Parametri non validi.', ['status' => 400]);
	}

	$client = new Client();
	try {
		$client->connect($account['imap_host'], $account['imap_port'], $account['imap_secure']);
		$client->login(Accounts\imap_username($account), Accounts\imap_password($account));
		$client->select('INBOX');
		$raw = $client->fetch_body($uid);
		$client->uid_store($uid, '\\Seen');
		$client->logout();

		mark_seen($account_id, $uid);
		$body = extract_body($raw);
		$body['attachments'] = array_map(function ($attachment, $index) {
			return [
				'index'    => $index,
				'filename' => $attachment['filename'],
				'mime'     => $attachment['mime'],
				'size'     => $attachment['size'],
			];
		}, $body['attachments'], array_keys($body['attachments']));
		return rest_ensure_response($body);
	} catch (\Exception $e) {
		$client->logout();
		return new \WP_Error('body_failed', $e->getMessage(), ['status' => 500]);
	}
}

add_action('admin_post_corsivo_mail_attachment', __NAMESPACE__ . '\\download_attachment');

function download_attachment() {
	if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'corsivo_mail_attachment')) {
		wp_die('Non autorizzato.');
	}

	$account_id = sanitize_text_field($_GET['account'] ?? '');
	$uid        = (int) ($_GET['uid'] ?? 0);
	$index      = (int) ($_GET['index'] ?? -1);
	$account    = Accounts\get($account_id);

	if (!Accounts\has_imap($account) || !$uid || $index < 0) {
		wp_die('Parametri non validi.');
	}

	$client = new Client();
	try {
		$client->connect($account['imap_host'], $account['imap_port'], $account['imap_secure']);
		$client->login(Accounts\imap_username($account), Accounts\imap_password($account));
		$client->select('INBOX');
		$raw = $client->fetch_body($uid);
		$client->logout();
	} catch (\Exception $e) {
		$client->logout();
		wp_die(esc_html($e->getMessage()));
	}

	$body = extract_body($raw);
	if (empty($body['attachments'][$index])) {
		wp_die('Allegato non trovato.');
	}

	$attachment = $body['attachments'][$index];
	nocache_headers();
	header('X-Content-Type-Options: nosniff');
	$mime = sanitize_mime_type($attachment['mime']) ?: 'application/octet-stream';
	$filename = sanitize_file_name($attachment['filename']) ?: 'allegato';
	header('Content-Type: ' . $mime);
	header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
	header('Content-Length: ' . strlen($attachment['data']));
	echo $attachment['data'];
	exit;
}

function mark_seen($account_id, $uid) {
	global $wpdb;
	return $wpdb->update(messages_table(), ['seen' => 1], ['account_id' => $account_id, 'mailbox' => 'INBOX', 'uid' => $uid]);
}

function extract_body($raw) {
	if (strlen((string) $raw) > 25 * MB_IN_BYTES) {
		throw new \RuntimeException('Messaggio oltre il limite consentito.');
	}
	$result = ['html' => '', 'text' => '', 'attachments' => []];
	parse_part($raw, $result, 0);
	if ($result['html'] === '' && $result['text'] !== '') {
		$result['html'] = '<pre style="white-space:pre-wrap;font-family:inherit;margin:0;">' . esc_html($result['text']) . '</pre>';
	}
	$result['html'] = \CorsivoMail\Security\sanitize_remote_email_html($result['html']);
	return $result;
}

function parse_part($raw, &$result, $depth = 0) {
	if ($depth > 20 || count($result['attachments']) >= 50) {
		return;
	}
	list($headers, $body) = split_message($raw);
	$ctype = strtolower($headers['content-type'] ?? 'text/plain');

	if (strpos($ctype, 'multipart/') === 0) {
		if (preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'], $bm)) {
			$segments = explode('--' . $bm[1], $body);
			foreach ($segments as $segment) {
				$segment = trim($segment, "\r\n");
				if ($segment === '' || $segment === '--') {
					continue;
				}
				parse_part($segment, $result, $depth + 1);
			}
		}
		return;
	}

	$disposition = strtolower($headers['content-disposition'] ?? '');
	$filename    = part_filename($headers);
	if (strpos($disposition, 'attachment') === 0 || ($filename !== '' && strpos($ctype, 'text/') !== 0)) {
		$data = decode_transfer($body, strtolower($headers['content-transfer-encoding'] ?? ''));
		$result['attachments'][] = [
			'filename' => $filename !== '' ? $filename : 'allegato-' . (count($result['attachments']) + 1),
			'mime'     => trim(explode(';', $ctype)[0]),
			'size'     => strlen($data),
			'data'     => $data,
		];
		return;
	}

	$decoded = decode_transfer($body, strtolower($headers['content-transfer-encoding'] ?? ''));
	$charset = 'UTF-8';
	if (preg_match('/charset="?([^";]+)"?/i', $headers['content-type'] ?? '', $cm)) {
		$charset = $cm[1];
	}
	$decoded = to_utf8($decoded, $charset);

	if (strpos($ctype, 'text/html') === 0) {
		$result['html'] = $decoded;
	} elseif (strpos($ctype, 'text/plain') === 0 && $result['text'] === '') {
		$result['text'] = $decoded;
	}
}

function split_message($raw) {
	$raw   = preg_replace('/\r\n/', "\n", $raw);
	$split = preg_split('/\n\n/', $raw, 2);
	$head  = parse_part_headers($split[0]);
	$body  = $split[1] ?? '';
	return [$head, $body];
}

function parse_part_headers($block) {
	$block   = preg_replace('/\n[ \t]+/', ' ', $block);
	$headers = [];
	foreach (explode("\n", $block) as $line) {
		if (strpos($line, ':') !== false) {
			list($key, $value) = explode(':', $line, 2);
			$headers[strtolower(trim($key))] = trim($value);
		}
	}
	return $headers;
}

function part_filename($headers) {
	foreach (['content-disposition', 'content-type'] as $key) {
		if (preg_match('/(?:filename|name)="?([^";]+)"?/i', $headers[$key] ?? '', $m)) {
			return sanitize_file_name(decode_mime(trim($m[1])));
		}
	}
	return '';
}

function decode_transfer($body, $encoding) {
	if ($encoding === 'base64') {
		$decoded = base64_decode(preg_replace('/\s+/', '', $body), true);
		return $decoded === false ? '' : $decoded;
	}
	if ($encoding === 'quoted-printable') {
		return quoted_printable_decode($body);
	}
	return $body;
}

function to_utf8($string, $charset) {
	$charset = strtoupper(trim($charset, "\"' "));
	if ($charset === 'UTF-8' || $charset === '') {
		return $string;
	}
	$converted = @iconv($charset, 'UTF-8//TRANSLIT', $string);
	return $converted !== false ? $converted : $string;
}
