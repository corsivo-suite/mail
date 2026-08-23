<?php

namespace CorsivoMail\Automation;

use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

function send_email($post_id, $to = null, $context = []) {
	$post = get_post($post_id);
	if (!$post || $post->post_type !== TYPE) {
		return false;
	}

	$config = config($post_id);

	if (!$config['send_enabled']) {
		return false;
	}

	if ($to !== null) {
		if (is_numeric($to)) {
			$user = get_userdata((int) $to);
			if (!$user) {
				return false;
			}
			$to      = $user->user_email;
			$context = array_merge(user_context($user), $context);
		}
		return dispatch_one($post, $config, $to, $context) ? 1 : 0;
	}

	$count = 0;
	$considered = 0;
	foreach (iterate_recipients($config) as $recipient) {
		if (empty($recipient['email'])) {
			continue;
		}
		$considered++;
		$ctx = $recipient['user'] ? user_context($recipient['user']) : [];
		if (!empty($recipient['context'])) {
			$ctx = array_merge($ctx, $recipient['context']);
		}
		if (dispatch_one($post, $config, $recipient['email'], array_merge($ctx, $context))) {
			$count++;
		}
	}
	\CorsivoMail\Log\audit(
		$considered === 0 ? 'audience_empty' : 'audience_dispatched',
		$considered === 0 ? 'notice' : ($count === $considered ? 'queued' : 'partial'),
		['source_type' => 'automation', 'source_id' => (int) $post_id, 'processed' => $count, 'failed' => $considered - $count]
	);
	return $count;
}

function dispatch_one($post, $config, $email, $context) {
	$rendered = render_automation_email($post, $config, $context);
	unset($GLOBALS['corsivo_mail_last_send_suppressed']);
	$sent = Template\send_rendered($email, $rendered['subject'], $rendered['html'], $config['account_id'] ?? '', [], $config['message_type'], [
		'source_type' => 'automation',
		'source_id' => (int) $post->ID,
	]);
	return $sent && empty($GLOBALS['corsivo_mail_last_send_suppressed']);
}
