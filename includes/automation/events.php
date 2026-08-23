<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

function events() {
	$events = [
		'user_register' => [
			'label'      => 'Registrazione utente — invia all\'utente',
			'hook'       => 'user_register',
			'args'       => 1,
			'merge_tags' => ['user_name', 'user_login', 'user_email', 'first_name', 'last_name'],
			'resolve'    => function ($args) {
				$user = get_userdata($args[0] ?? 0);
				return $user ? [['email' => $user->user_email, 'user' => $user]] : [];
			},
		],
		'user_register_admin' => [
			'label'      => 'Registrazione utente — notifica all\'admin',
			'hook'       => 'user_register',
			'args'       => 1,
			'merge_tags' => ['user_name', 'user_login', 'user_email'],
			'resolve'    => function ($args) {
				$user = get_userdata($args[0] ?? 0);
				return $user ? [['email' => get_option('admin_email'), 'user' => $user]] : [];
			},
		],
		'comment_posted_admin' => [
			'label'      => 'Nuovo commento — notifica all\'admin',
			'hook'       => 'comment_post',
			'args'       => 2,
			'merge_tags' => ['comment_author', 'comment_author_email', 'comment_content', 'post_title', 'post_url', 'comment_link'],
			'resolve'    => function ($args) {
				$comment  = get_comment($args[0] ?? 0);
				$approved = $args[1] ?? 0;
				if (!$comment || !in_array($approved, [0, 1, '0', '1'], true)) {
					return [];
				}
				$post = get_post($comment->comment_post_ID);
				return [[
					'email'   => get_option('admin_email'),
					'user'    => null,
					'context' => [
						'comment_author'       => $comment->comment_author,
						'comment_author_email' => $comment->comment_author_email,
						'comment_content'      => $comment->comment_content,
						'post_title'           => $post ? $post->post_title : '',
						'post_url'             => $post ? get_permalink($post) : '',
						'comment_link'         => get_comment_link($comment),
					],
				]];
			},
		],
	];

	return apply_filters('corsivo_mail_events', $events);
}

add_action('init', __NAMESPACE__ . '\\wire_events', 30);

function wire_events() {
	$events = events();
	$active = [];

	foreach (active_emails() as $post) {
		$config = config($post->ID);
		if ($config['trigger_type'] === 'event' && !empty($config['event_id']) && isset($events[$config['event_id']])) {
			if (!$config['send_enabled'] || !$config['filter_enabled']) {
				continue;
			}
			$active[$config['event_id']][] = $post->ID;
		}
	}

	foreach ($active as $event_id => $post_ids) {
		$event = $events[$event_id];
		add_action($event['hook'], function () use ($event, $post_ids) {
			$recipients = call_user_func($event['resolve'], func_get_args());
			if (!$recipients) {
				return;
			}
			foreach ($post_ids as $post_id) {
				$post   = get_post($post_id);
				$config = config($post_id);
				foreach ($recipients as $recipient) {
					if (empty($recipient['email'])) {
						continue;
					}
					$context = !empty($recipient['user']) ? user_context($recipient['user']) : [];
					if (!empty($recipient['context'])) {
						$context = array_merge($context, $recipient['context']);
					}
					dispatch_one($post, $config, $recipient['email'], $context);
				}
			}
		}, 10, (int) $event['args']);
	}
}
