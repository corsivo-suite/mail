<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

function audiences() {
	$base = [
		'manual'     => 'Indirizzi specifici',
		'all_users'  => 'Tutti gli utenti',
		'role'       => 'Utenti per ruolo',
	];
	if (\CorsivoMail\Options\get('module_subscriptions')) {
		$base['subscribed'] = 'Iscritti newsletter';
	}

	foreach (\CorsivoMail\Groups\all() as $group) {
		$base['group:' . $group['id']] = 'Gruppo: ' . $group['name'];
	}

	return apply_filters('corsivo_mail_audiences', $base);
}

function resolve_recipients($config) {
	return iterator_to_array(iterate_recipients($config), false);
}

function iterate_recipients($config) {
	$type = $config['audience_type'] ?: 'manual';

	if (strpos($type, 'group:') === 0) {
		$group = \CorsivoMail\Groups\get(substr($type, 6));
		if ($group) {
			yield from iterate_group_recipients($group);
		}
		return;
	}

	if ($type === 'manual') {
		$parts = preg_split('/[\s,;]+/', (string) $config['audience_emails'], -1, PREG_SPLIT_NO_EMPTY);
		$seen = [];
		foreach ($parts as $email) {
			$email = sanitize_email($email);
			$key = strtolower($email);
			if ($email && !isset($seen[$key])) {
				$seen[$key] = true;
				$user  = get_user_by('email', $email);
				yield ['email' => $email, 'user' => $user ?: null];
			}
		}
		return;
	}

	if ($type === 'all_users') {
		yield from iterate_users([]);
		return;
	}

	if ($type === 'subscribed') {
		if (!\CorsivoMail\Options\get('module_subscriptions')) {
			return;
		}
		yield from iterate_subscribed_users();
		return;
	}

	if ($type === 'role') {
		if ($config['audience_role']) {
			yield from iterate_users(['role' => $config['audience_role']]);
		}
		return;
	}

	foreach ((array) apply_filters('corsivo_mail_resolve_recipients', [], $config) as $recipient) {
		yield $recipient;
	}
}

function iterate_group_recipients($group) {
	switch ($group['rule']) {
		case 'all_users':
			yield from iterate_users([]);
			return;
		case 'subscribed':
			if (\CorsivoMail\Options\get('module_subscriptions')) {
				yield from iterate_subscribed_users();
			}
			return;
		case 'role':
			if ($group['value']) {
				yield from iterate_users(['role' => $group['value']]);
			}
			return;
		case 'meta':
			list($key, $value) = array_pad(explode('=', $group['value'], 2), 2, '');
			if ($key) {
				yield from iterate_users(['meta_key' => trim($key), 'meta_value' => trim($value)]);
			}
			return;
		case 'manual':
			yield from iterate_recipients(['audience_type' => 'manual', 'audience_emails' => $group['value']]);
			return;
	}
}

function iterate_users($args, $batch_size = 200) {
	$offset = 0;
	do {
		$users = get_users(array_merge($args, [
			'fields' => 'all',
			'number' => $batch_size,
			'offset' => $offset,
			'orderby' => 'ID',
			'order' => 'ASC',
		]));
		foreach ($users as $user) {
			if (!empty($user->user_email)) {
				yield ['email' => $user->user_email, 'user' => $user];
			}
		}
		$offset += count($users);
	} while (count($users) === $batch_size);
}

function iterate_subscribed_users() {
	$keys = [\CorsivoMail\Newsletter\meta_key()];
	if (is_multisite()) {
		$keys[] = \CorsivoMail\Newsletter\META;
	}
	$seen = [];
	foreach (array_unique($keys) as $key) {
		foreach (iterate_users(['meta_key' => $key, 'meta_value' => '1']) as $recipient) {
			$user_id = (int) $recipient['user']->ID;
			if (!isset($seen[$user_id]) && \CorsivoMail\Newsletter\is_subscribed($user_id)) {
				$seen[$user_id] = true;
				yield $recipient;
			}
		}
	}
}

function map_users($users) {
	$out = [];
	foreach ($users as $user) {
		if (!empty($user->user_email)) {
			$out[] = ['email' => $user->user_email, 'user' => $user];
		}
	}
	return $out;
}

function recipient_count($config) {
	$type = $config['audience_type'] ?: 'manual';
	if (strpos($type, 'group:') === 0) {
		$group = \CorsivoMail\Groups\get(substr($type, 6));
		return $group ? group_recipient_count($group) : 0;
	}
	if ($type === 'manual') {
		return count_manual_recipients($config['audience_emails'] ?? '');
	}
	if ($type === 'all_users') {
		$counts = count_users();
		return (int) ($counts['total_users'] ?? 0);
	}
	if ($type === 'subscribed') {
		return \CorsivoMail\Options\get('module_subscriptions') ? subscribed_recipient_count() : 0;
	}
	if ($type === 'role') {
		return !empty($config['audience_role']) ? user_query_count(['role' => $config['audience_role']]) : 0;
	}
	$count = apply_filters('corsivo_mail_recipient_count', null, $config);
	return is_numeric($count) ? max(0, (int) $count) : null;
}

function group_recipient_count($group) {
	switch ($group['rule']) {
		case 'all_users':
			$counts = count_users();
			return (int) ($counts['total_users'] ?? 0);
		case 'subscribed':
			return \CorsivoMail\Options\get('module_subscriptions') ? subscribed_recipient_count() : 0;
		case 'role':
			return $group['value'] ? user_query_count(['role' => $group['value']]) : 0;
		case 'meta':
			list($key, $value) = array_pad(explode('=', $group['value'], 2), 2, '');
			return $key ? user_query_count(['meta_key' => trim($key), 'meta_value' => trim($value)]) : 0;
		case 'manual':
			return count_manual_recipients($group['value']);
	}
	return 0;
}

function user_query_count($args) {
	$query = new \WP_User_Query(array_merge($args, [
		'fields' => 'ID',
		'number' => 1,
		'count_total' => true,
	]));
	return (int) $query->get_total();
}

function subscribed_recipient_count() {
	global $wpdb;
	$keys = [\CorsivoMail\Newsletter\meta_key()];
	if (is_multisite()) {
		$keys[] = \CorsivoMail\Newsletter\META;
	}
	$keys = array_values(array_unique($keys));
	$placeholders = implode(', ', array_fill(0, count($keys), '%s'));
	$params = array_merge($keys, ['1']);
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders}) AND meta_value = %s",
		$params
	));
}

function count_manual_recipients($value) {
	$parts = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
	$emails = [];
	foreach ($parts as $email) {
		if (is_email($email)) {
			$emails[] = strtolower($email);
		}
	}
	return count(array_unique($emails));
}
