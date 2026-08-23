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
	register_rest_route('corsivo/v1/mail', '/imap-test', [
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can('manage_options');
		},
		'callback'            => __NAMESPACE__ . '\\rest_test',
	]);
});

function rest_test($request) {
	$account = Accounts\get(sanitize_text_field($request['account'] ?? ''));
	$has_host = $request->get_param('host') !== null;

	if ($has_host) {
		$host = sanitize_text_field($request['host'] ?? '');
		if (!$host) {
			return new \WP_Error('no_imap', 'Compila l\'host IMAP (es. imaps.aruba.it) prima di testare.', ['status' => 400]);
		}
		$port   = (int) ($request['port'] ?? 993);
		$secure = in_array($request['secure'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $request['secure'] : 'ssl';
		$user   = sanitize_text_field($request['user'] ?? '') ?: ($account ? Accounts\imap_username($account) : '');
		$pass   = (string) ($request['pass'] ?? '');
		if ($pass === '' && $account) {
			$matches_saved_identity = $host === (string) $account['imap_host']
				&& $port === (int) $account['imap_port']
				&& $secure === (string) $account['imap_secure']
				&& $user === Accounts\imap_username($account);
			if ($matches_saved_identity) {
				$pass = Accounts\imap_password($account);
			}
		}
	} elseif ($account && Accounts\has_imap($account)) {
		$host   = $account['imap_host'];
		$port   = $account['imap_port'];
		$secure = $account['imap_secure'];
		$user   = Accounts\imap_username($account);
		$pass   = Accounts\imap_password($account);
	} else {
		return new \WP_Error('no_imap', 'Compila l\'host IMAP (es. imaps.aruba.it) prima di testare.', ['status' => 400]);
	}

	if ($pass === '') {
		return new \WP_Error('no_pass', 'Password IMAP mancante (e nessuna password SMTP da riutilizzare).', ['status' => 400]);
	}

	$client = new Client();
	try {
		$client->connect($host, $port, $secure);
		$client->login($user, $pass);
		$info = $client->select('INBOX');
		$client->logout();

		return rest_ensure_response(['ok' => true, 'exists' => $info['exists'], 'uidnext' => $info['uidnext']]);
	} catch (\Exception $e) {
		$client->logout();
		return new \WP_Error('imap_failed', $e->getMessage(), ['status' => 500]);
	}
}
