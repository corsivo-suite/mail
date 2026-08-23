<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

function providers() {
	$providers = [
		'password_reset' => [
			'label'           => 'Reset password (all\'utente)',
			'merge_tags'      => ['user_login', 'user_email', 'display_name', 'reset_url'],
			'suppress_filter' => 'send_retrieve_password_email',
		],
		'new_user_user' => [
			'label'           => 'Nuovo utente — notifica all\'utente',
			'merge_tags'      => ['user_login', 'user_email', 'display_name', 'action_url'],
			'suppress_filter' => 'wp_send_new_user_notification_to_user',
		],
		'new_user_admin' => [
			'label'           => 'Nuovo utente — notifica all\'admin',
			'merge_tags'      => ['user_login', 'user_email', 'display_name'],
			'suppress_filter' => 'wp_send_new_user_notification_to_admin',
		],
	];

	return apply_filters('corsivo_mail_core_emails', $providers);
}

function wire_overrides() {
	$providers = providers();

	foreach ($providers as $id => $provider) {
		if (empty($provider['suppress_filter'])) {
			continue;
		}
		$post = find_post_for_core_email($id);
		if ($post && !config($post->ID)['send_enabled']) {
			add_filter($provider['suppress_filter'], '__return_false', 20);
		}
	}

	if (isset($providers['password_reset']) && find_active_post_for_core_email('password_reset')) {
		add_filter('retrieve_password_title', __NAMESPACE__ . '\\password_reset_title', 20, 3);
		add_filter('retrieve_password_message', __NAMESPACE__ . '\\password_reset_message', 20, 4);
		add_filter('retrieve_password_message', __NAMESPACE__ . '\\clear_override_when_message_suppressed', PHP_INT_MAX);
	}

	if (isset($providers['new_user_user']) && find_active_post_for_core_email('new_user_user')) {
		add_filter('wp_new_user_notification_email', __NAMESPACE__ . '\\new_user_user_email', 20, 3);
	}

	if (isset($providers['new_user_admin']) && find_active_post_for_core_email('new_user_admin')) {
		add_filter('wp_new_user_notification_email_admin', __NAMESPACE__ . '\\new_user_admin_email', 20, 3);
	}
}

function clear_override_when_message_suppressed($message) {
	if (empty($message)) {
		unset($GLOBALS['corsivo_mail_override_active'], $GLOBALS['corsivo_mail_override_account_id']);
	}
	return $message;
}

function user_context($user) {
	$user = is_numeric($user) ? get_userdata($user) : $user;
	if (!$user) {
		return [];
	}
	$locale = get_user_locale($user);
	return [
		'_user_id'     => $user->ID,
		'user_name'    => $user->display_name,
		'user_login'   => $user->user_login,
		'user_email'   => $user->user_email,
		'display_name' => $user->display_name,
		'first_name'   => $user->first_name,
		'last_name'    => $user->last_name,
		'_locale'      => $locale,
	];
}

function extract_action_url($message) {
	if (preg_match('#https?://\S*wp-login\.php\?action=rp\S*#', $message, $m)) {
		return rtrim($m[0], " \t\n\r\0\x0B>.,)");
	}
	if (preg_match('#https?://\S+#', $message, $m)) {
		return rtrim($m[0], " \t\n\r\0\x0B>.,)");
	}
	return '';
}

function password_reset_title($title, $user_login = '', $user_data = null) {
	$post = find_active_post_for_core_email('password_reset');
	if (!$post) {
		return $title;
	}
	$config  = config($post->ID);
	$subject = $config['subject'] ?: $post->post_title;
	if (!$subject) {
		return $title;
	}
	$context = $user_data ? user_context($user_data) : [];
	$translated_id = \CorsivoMail\Template\resolve_post_id($post->ID, TYPE, $context);
	$translated_post = get_post($translated_id) ?: $post;
	$translated_config = config($translated_post->ID);
	$subject = $translated_config['subject'] ?: $translated_post->post_title ?: $subject;
	return \CorsivoMail\Template\apply_merge_tags($subject, $context);
}

function password_reset_message($message, $key, $user_login, $user_data) {
	$post = find_active_post_for_core_email('password_reset');
	if (!$post) {
		return $message;
	}

	$reset_url = network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user_login), 'login');
	$context   = array_merge(user_context($user_data), ['reset_url' => $reset_url]);

	$rendered = render_automation_email($post, config($post->ID), $context);
	$rendered_config = config($rendered['post_id']);
	begin_override($rendered_config['account_id'] ?? '');
	return $rendered['html'];
}

function new_user_user_email($email, $user, $blogname) {
	$post = find_active_post_for_core_email('new_user_user');
	if (!$post) {
		return $email;
	}

	$action_url = extract_action_url($email['message']);
	$context    = array_merge(user_context($user), ['action_url' => $action_url]);
	$rendered   = render_automation_email($post, config($post->ID), $context);

	$email['subject'] = $rendered['subject'] ?: $email['subject'];
	$email['message'] = $rendered['html'];
	$email['headers'] = array_merge((array) ($email['headers'] ?? []), ['Content-Type: text/html; charset=UTF-8']);
	$rendered_config = config($rendered['post_id']);
	begin_override($rendered_config['account_id'] ?? '');

	return $email;
}

function new_user_admin_email($email, $user, $blogname) {
	$post = find_active_post_for_core_email('new_user_admin');
	if (!$post) {
		return $email;
	}

	$rendered         = render_automation_email($post, config($post->ID), user_context($user));
	$email['subject'] = $rendered['subject'] ?: $email['subject'];
	$email['message'] = $rendered['html'];
	$email['headers'] = array_merge((array) ($email['headers'] ?? []), ['Content-Type: text/html; charset=UTF-8']);
	$rendered_config = config($rendered['post_id']);
	begin_override($rendered_config['account_id'] ?? '');

	return $email;
}

function deliver_registered($core_email_id, $to, $context = [], $headers = []) {
	$providers = providers();
	if (!isset($providers[$core_email_id])) {
		return false;
	}
	$post = find_post_for_core_email($core_email_id);
	if (!$post) {
		return false;
	}
	$config = config($post->ID);
	if (!$config['send_enabled']) {
		return true;
	}
	if (!$config['filter_enabled']) {
		return false;
	}
	$rendered = render_automation_email($post, $config, $context);
	return (bool) \CorsivoMail\Template\send_rendered($to, $rendered['subject'], $rendered['html'], $config['account_id'] ?? '', $headers, 'transactional', [
		'source_type' => 'automation',
		'source_id' => (int) $post->ID,
	]);
}
