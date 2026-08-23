<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_init', __NAMESPACE__ . '\\seed_core_emails');

function seed_core_emails($force = false) {
	if (!$force && !current_user_can('manage_options')) {
		return false;
	}

	$providers = providers();
	$seeded = (array) get_option('corsivo_mail_seeded_core_emails', []);
	if (!array_diff(array_keys($providers), $seeded)) {
		return true;
	}

	$locked_at = (int) get_option('corsivo_mail_seed_lock', 0);
	if ($locked_at && $locked_at < time() - 300) {
		delete_option('corsivo_mail_seed_lock');
	}
	if (!add_option('corsivo_mail_seed_lock', time(), '', false)) {
		return false;
	}

	try {
		$seeded = (array) get_option('corsivo_mail_seeded_core_emails', []);
		$changed = false;

		foreach ($providers as $id => $provider) {
			if (in_array($id, $seeded, true)) {
				continue;
			}

			$post = find_any_post_for_core_email($id);
			if (!$post) {
				$defaults = core_email_defaults($id, $provider);
				$post_id = wp_insert_post([
					'post_type' => TYPE,
					'post_status' => 'publish',
					'post_title' => $defaults['title'],
					'post_content' => $defaults['content'],
				], true);
				if (is_wp_error($post_id)) {
					continue;
				}
				update_post_meta($post_id, 'corsivo_mail_trigger_type', 'core_email');
				update_post_meta($post_id, 'corsivo_mail_core_email', $id);
			}

			$seeded[] = $id;
			$changed = true;
		}

		if ($changed) {
			update_option('corsivo_mail_seeded_core_emails', array_values(array_unique($seeded)), false);
		}
		return true;
	} finally {
		delete_option('corsivo_mail_seed_lock');
	}
}

function find_any_post_for_core_email($core_email_id) {
	$posts = get_posts([
		'post_type'   => TYPE,
		'post_status' => 'any',
		'numberposts' => 1,
		'meta_key'    => 'corsivo_mail_core_email',
		'meta_value'  => $core_email_id,
	]);
	return $posts ? $posts[0] : null;
}

function core_email_defaults($id, $provider) {
	$defaults = [
		'password_reset' => [
			'title'   => 'Reimposta la tua password su {{site_name}}',
			'content' => paragraphs([
				'Ciao {{user_login}},',
				'abbiamo ricevuto una richiesta per reimpostare la password del tuo account su {{site_name}}.',
				'<a href="{{reset_url}}">Reimposta la password</a>',
				'Se non hai richiesto tu la reimpostazione, ignora questa email: la tua password resterà invariata.',
			]),
		],
		'new_user_user' => [
			'title'   => 'Il tuo account su {{site_name}}',
			'content' => paragraphs([
				'Ciao {{user_login}},',
				'il tuo account su {{site_name}} è pronto.',
				'<a href="{{action_url}}">Imposta la tua password</a>',
			]),
		],
		'new_user_admin' => [
			'title'   => 'Nuovo utente su {{site_name}}',
			'content' => paragraphs([
				'È stato creato un nuovo utente su {{site_name}}.',
				'Nome utente: {{user_login}}<br>Email: {{user_email}}',
			]),
		],
	];

	if (isset($defaults[$id])) {
		return $defaults[$id];
	}

	if (!empty($provider['default_title']) || !empty($provider['default_content'])) {
		return [
			'title'   => $provider['default_title'] ?? $provider['label'],
			'content' => $provider['default_content'] ?? paragraphs(['Contenuto da comporre per: ' . $provider['label']]),
		];
	}

	return [
		'title'   => $provider['label'],
		'content' => paragraphs(['Contenuto da comporre per: ' . $provider['label']]),
	];
}

function paragraphs($texts) {
	$out = '';
	foreach ($texts as $text) {
		$out .= "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return trim($out);
}
