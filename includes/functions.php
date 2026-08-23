<?php

if (!defined('ABSPATH')) {
	exit;
}

function corsivo_mail_deliver($core_email_id, $to, $context = [], $headers = []) {
	return \CorsivoMail\Automation\deliver_registered($core_email_id, $to, $context, $headers);
}

function corsivo_mail_core_email_status($core_email_id) {
	if (!function_exists('CorsivoMail\\Automation\\providers') || !function_exists('CorsivoMail\\Automation\\find_post_for_core_email')) {
		return [
			'available' => false,
			'message'   => 'Corsivo Mail non ha ancora caricato i template email.',
		];
	}

	$providers = \CorsivoMail\Automation\providers();
	if (!isset($providers[$core_email_id])) {
		return [
			'available' => false,
			'message'   => 'Template email non registrato: ' . $core_email_id,
		];
	}

	$post = \CorsivoMail\Automation\find_post_for_core_email($core_email_id);
	if (!$post) {
		return [
			'available' => false,
			'message'   => 'Nessuna email pubblicata collegata a: ' . $providers[$core_email_id]['label'],
		];
	}

	$config = \CorsivoMail\Automation\config($post->ID);
	if (!$config['send_enabled']) {
		return [
			'available' => false,
			'post_id'   => (int) $post->ID,
			'message'   => 'Invio spento per: ' . $post->post_title,
		];
	}
	if (!$config['filter_enabled']) {
		return [
			'available' => false,
			'post_id'   => (int) $post->ID,
			'message'   => 'Filtro spento per: ' . $post->post_title,
		];
	}

	return [
		'available' => true,
		'post_id'   => (int) $post->ID,
		'title'     => $post->post_title,
	];
}
