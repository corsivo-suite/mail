<?php

namespace CorsivoMail\Groups;

if (!defined('ABSPATH')) {
	exit;
}

const OPTION = 'corsivo_mail_groups';

function rules() {
	return [
		'all_users'  => 'Tutti gli utenti',
		'subscribed' => 'Iscritti newsletter',
		'role'       => 'Utenti per ruolo (valore = slug ruolo)',
		'meta'       => 'Per meta utente (valore = chiave=valore)',
		'manual'     => 'Indirizzi manuali (valore = email separate da virgola)',
	];
}

function all() {
	$groups = get_option(OPTION, []);
	return is_array($groups) ? $groups : [];
}

function get($id) {
	foreach (all() as $group) {
		if ($group['id'] === $id) {
			return $group;
		}
	}
	return null;
}

function save_all($groups) {
	$clean = [];
	foreach ($groups as $group) {
		if (empty($group['name'])) {
			continue;
		}
		$clean[] = [
			'id'    => sanitize_key($group['id'] ?? '') ?: 'grp_' . wp_generate_uuid4(),
			'name'  => sanitize_text_field($group['name']),
			'rule'  => array_key_exists($group['rule'] ?? '', rules()) ? $group['rule'] : 'all_users',
			'value' => sanitize_text_field($group['value'] ?? ''),
		];
	}
	if (!update_option(OPTION, $clean) && get_option(OPTION, null) !== $clean) {
		return new \WP_Error('corsivo_mail_groups_save_failed', 'Salvataggio dei gruppi non riuscito.');
	}
	return $clean;
}

function resolve($group) {
	switch ($group['rule']) {
		case 'all_users':
			return map_users(get_users(['fields' => 'all']));
		case 'subscribed':
			if (!\CorsivoMail\Options\get('module_subscriptions')) {
				return [];
			}
			return map_users(\CorsivoMail\Newsletter\subscribed_users());
		case 'role':
			return $group['value'] ? map_users(get_users(['role' => $group['value']])) : [];
		case 'meta':
			list($key, $value) = array_pad(explode('=', $group['value'], 2), 2, '');
			return $key ? map_users(get_users(['meta_key' => trim($key), 'meta_value' => trim($value)])) : [];
		case 'manual':
			$out   = [];
			$parts = preg_split('/[\s,;]+/', $group['value'], -1, PREG_SPLIT_NO_EMPTY);
			foreach ($parts as $email) {
				if (is_email($email)) {
					$user  = get_user_by('email', $email);
					$out[] = ['email' => $email, 'user' => $user ?: null];
				}
			}
			return $out;
	}
	return [];
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
