<?php

namespace CorsivoMail\Automation;

use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_corsivo_mail_preview', __NAMESPACE__ . '\\preview');

function preview() {
	if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'corsivo_mail_preview')) {
		wp_die('Non autorizzato.');
	}

	$post_id = (int) ($_GET['post'] ?? 0);
	$user_id = (int) ($_GET['user'] ?? 0);
	$recipient_preview = !empty($_GET['recipient']);
	$post    = $post_id ? get_post($post_id) : null;

	if (!$post || $post->post_type !== TYPE || !current_user_can('edit_post', $post_id)) {
		\CorsivoMail\Security\send_isolated_document_headers();
		echo '<p>Nessuna email da mostrare.</p>';
		exit;
	}
	if ($user_id && !get_userdata($user_id)) {
		wp_die('Utente non valido.', 400);
	}
	$context = $user_id ? real_context($user_id) : ($recipient_preview ? [] : sample_context());

	$rendered = render_automation_email($post, config($post_id), $context);
	$subject = $rendered['subject'];
	$html = $rendered['html'];

	$bar = '<div style="background:#1e1e1e;color:#fff;font-family:-apple-system,Segoe UI,Roboto,sans-serif;padding:10px 16px;font-size:13px;">'
		. '<strong>Oggetto:</strong> ' . esc_html($subject) . '</div>';

	\CorsivoMail\Security\send_isolated_document_headers();
	echo wp_kses_post($bar . $html);
	exit;
}

function real_context($user_id) {
	$user = get_userdata($user_id);
	if (!$user) {
		return sample_context();
	}
	return array_merge(user_context($user), ['subject' => 'Anteprima email']);
}

function sample_context() {
	$user = wp_get_current_user();

	return [
		'subject'      => 'Anteprima email',
		'_user_id'     => $user->ID,
		'user_name'    => $user->display_name ?: 'Mario Rossi',
		'user_login'   => $user->user_login ?: 'mario.rossi',
		'user_email'   => $user->user_email ?: 'mario.rossi@example.com',
		'display_name' => $user->display_name ?: 'Mario Rossi',
		'first_name'   => $user->first_name ?: 'Mario',
		'last_name'    => $user->last_name ?: 'Rossi',
		'reset_url'    => home_url('/wp-login.php?action=rp&key=ESEMPIO&login=mario'),
		'action_url'   => home_url('/wp-login.php?action=rp&key=ESEMPIO&login=mario'),
	];
}
