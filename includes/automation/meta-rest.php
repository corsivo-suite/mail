<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

add_action('rest_api_init', function () {
	$users_permission = function () {
		return current_user_can('manage_options');
	};

	register_rest_route('corsivo/v1/mail', '/user-meta-keys', [
		'methods'             => 'GET',
		'permission_callback' => $users_permission,
		'callback'            => __NAMESPACE__ . '\\rest_user_meta_keys',
	]);

	register_rest_route('corsivo/v1/mail', '/users', [
		'methods'             => 'GET',
		'permission_callback' => $users_permission,
		'callback'            => __NAMESPACE__ . '\\rest_users',
	]);

	register_rest_route('corsivo/v1/mail', '/send-test', [
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can('manage_options');
		},
		'callback'            => __NAMESPACE__ . '\\rest_send_test',
	]);
});

function rest_send_test($request) {
	$post_id = (int) $request['post'];
	$user_id = (int) ($request['user'] ?? 0);
	$post    = get_post($post_id);

	if (!$post || $post->post_type !== TYPE) {
		return new \WP_Error('not_found', 'Email non trovata.', ['status' => 404]);
	}
	if (!current_user_can('edit_post', $post_id)) {
		return new \WP_Error('forbidden', 'Non autorizzato.', ['status' => 403]);
	}
	if ($user_id && !get_userdata($user_id)) {
		return new \WP_Error('bad_user', 'Utente non valido.', ['status' => 400]);
	}

	$context = $user_id ? real_context($user_id) : sample_context();
	$config = config($post_id);
	$rendered = render_automation_email($post, $config, $context);
	$subject = $rendered['subject'];
	$html = $rendered['html'];
	$rendered_config = config($rendered['post_id']);
	$to      = wp_get_current_user()->user_email;

	$error    = '';
	$listener = function ($wp_error) use (&$error) {
		$error = $wp_error->get_error_message();
	};
	add_action('wp_mail_failed', $listener);

	unset($GLOBALS['corsivo_mail_last_send_suppressed']);
	$sent = \CorsivoMail\Template\send_immediately($to, '[TEST] ' . $subject, $html, $rendered_config['account_id'] ?? '');

	remove_action('wp_mail_failed', $listener);

	if (!empty($GLOBALS['corsivo_mail_last_send_suppressed'])) {
		return rest_ensure_response(['suppressed' => true, 'to' => $to]);
	}
	if ($sent) {
		return rest_ensure_response(['sent' => true, 'to' => $to]);
	}
	return new \WP_Error('send_failed', $error ?: 'Invio fallito: controlla la configurazione SMTP.', ['status' => 500]);
}

function rest_users($request) {
	$fields = ['ID', 'display_name', 'user_login', 'user_email'];
	$search = sanitize_text_field($request['search'] ?? '');

	if (!$search) {
		$users = get_users(['number' => 100, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => $fields]);
	} else {
		$users = get_users([
			'number'         => 100,
			'search'         => '*' . $search . '*',
			'search_columns' => ['user_login', 'user_email', 'display_name'],
			'fields'         => $fields,
		]);

	}

	$out = array_map(function ($u) {
		return ['id' => (int) $u->ID, 'name' => ($u->display_name ?: $u->user_login), 'email' => $u->user_email];
	}, $users);

	return rest_ensure_response($out);
}

function rest_user_meta_keys() {
	return rest_ensure_response(\CorsivoMail\Template\allowed_user_meta_keys());
}
