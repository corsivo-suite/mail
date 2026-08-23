<?php

namespace CorsivoMail\Imap;

use CorsivoMail\Accounts;

if (!defined('ABSPATH')) {
	exit;
}

function sync_account($account_id, $limit = 80) {
	global $wpdb;

	$account = Accounts\get($account_id);
	if (!Accounts\has_imap($account)) {
		throw new \Exception('Account senza IMAP.');
	}

	$table = messages_table();
	$stored_validity = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT uidvalidity FROM {$table} WHERE account_id = %s AND mailbox = 'INBOX' ORDER BY id DESC LIMIT 1",
		$account_id
	));
	$highest_uid = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT MAX(uid) FROM {$table} WHERE account_id = %s AND mailbox = 'INBOX'",
		$account_id
	));

	$client = new Client();
	try {
		$client->connect($account['imap_host'], $account['imap_port'], $account['imap_secure']);
		$client->login(Accounts\imap_username($account), Accounts\imap_password($account));
		$info = $client->select('INBOX');
		$validity_changed = $stored_validity && $stored_validity !== $info['uidvalidity'];
		$criteria = !$validity_changed && $highest_uid ? 'UID ' . ($highest_uid + 1) . ':*' : 'ALL';
		$uids = array_slice($client->uid_search($criteria), -max(1, min(500, (int) $limit)));
		$existing = $validity_changed ? [] : $wpdb->get_col($wpdb->prepare(
			"SELECT uid FROM {$table} WHERE account_id = %s AND mailbox = 'INBOX'",
			$account_id
		));
		$new = array_values(array_diff($uids, array_map('intval', $existing)));
		$saved = 0;
		$messages = $new ? $client->fetch_headers($new) : [];
		if ($new && count($messages) !== count($new)) {
			throw new \RuntimeException('Il server IMAP non ha restituito tutti gli header richiesti.');
		}
		if ($wpdb->query('START TRANSACTION') === false) {
			throw new \RuntimeException('Impossibile iniziare la transazione di sincronizzazione IMAP.');
		}

		try {
			foreach ($messages as $message) {
				if (insert_message($account_id, $info['uidvalidity'], $message) === false) {
					throw new \RuntimeException($wpdb->last_error ?: 'Salvataggio del messaggio IMAP non riuscito.');
				}
				$saved++;
			}
			if ($validity_changed) {
				$deleted = $wpdb->query($wpdb->prepare(
					"DELETE FROM {$table} WHERE account_id = %s AND mailbox = 'INBOX' AND uidvalidity <> %d",
					$account_id,
					(int) $info['uidvalidity']
				));
				if ($deleted === false) {
					throw new \RuntimeException($wpdb->last_error ?: 'Pulizia della cache IMAP precedente non riuscita.');
				}
			}
			if ($wpdb->query('COMMIT') === false) {
				throw new \RuntimeException('Commit della sincronizzazione IMAP non riuscito.');
			}
		} catch (\Throwable $error) {
			$wpdb->query('ROLLBACK');
			throw $error;
		}
		return $saved;
	} finally {
		$client->logout();
	}
}

function insert_message($account_id, $uidvalidity, $message) {
	global $wpdb;

	$headers = $message['headers'] ?? [];
	$from    = parse_address($headers['from'] ?? '');

	return $wpdb->replace(messages_table(), [
		'account_id'  => $account_id,
		'mailbox'     => 'INBOX',
		'uid'         => (int) $message['uid'],
		'uidvalidity' => (int) $uidvalidity,
		'message_id'  => substr($headers['message-id'] ?? '', 0, 255),
		'in_reply_to' => substr($headers['in-reply-to'] ?? '', 0, 255),
		'refs'        => $headers['references'] ?? '',
		'from_name'   => substr($from['name'], 0, 255),
		'from_email'  => substr($from['email'], 0, 255),
		'to_field'    => $headers['to'] ?? '',
		'subject'     => $headers['subject'] ?? '(senza oggetto)',
		'mail_date'   => parse_date($headers['date'] ?? ''),
		'seen'        => in_array('\\Seen', $message['flags'] ?? [], true) ? 1 : 0,
		'synced_at'   => current_time('mysql'),
	]);
}

function parse_address($value) {
	$value = trim($value);
	if (preg_match('/^(.*?)<([^>]+)>/', $value, $m)) {
		return ['name' => trim(trim($m[1]), '"'), 'email' => trim($m[2])];
	}
	return ['name' => '', 'email' => $value];
}

function parse_date($value) {
	$ts = $value ? strtotime($value) : false;
	return $ts ? gmdate('Y-m-d H:i:s', $ts + (int) (get_option('gmt_offset') * 3600)) : null;
}
