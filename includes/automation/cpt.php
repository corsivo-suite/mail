<?php

namespace CorsivoMail\Automation;

use CorsivoMail\Security;

if (!defined('ABSPATH')) {
	exit;
}

const TYPE = 'corsivo_mail_email';

add_action('init', __NAMESPACE__ . '\\register');

function register() {
	register_post_type(TYPE, [
		'labels' => [
			'name'          => 'Email',
			'singular_name' => 'Email',
			'add_new_item'  => 'Nuova email',
			'edit_item'     => 'Modifica email',
			'menu_name'     => 'Email',
		],
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'corsivo-mail',
		'show_in_rest'    => true,
		'rest_base'       => 'corsivo-mail-emails',
		'supports'        => ['title', 'editor', 'revisions', 'custom-fields'],
		'capabilities'    => Security\post_type_capabilities(),
		'map_meta_cap'    => false,
		'menu_icon'       => 'dashicons-email-alt',
	]);

	register_meta_fields();
}

function register_meta_fields() {
	$fields = [
		'corsivo_mail_trigger_type'    => ['string', 'manual'],
		'corsivo_mail_send_enabled'    => ['boolean', true],
		'corsivo_mail_filter_enabled'  => ['boolean', true],
		'corsivo_mail_core_email'      => ['string', ''],
		'corsivo_mail_event_id'        => ['string', ''],
		'corsivo_mail_subject'         => ['string', ''],
		'corsivo_mail_audience_type'   => ['string', 'manual'],
		'corsivo_mail_audience_emails' => ['string', ''],
		'corsivo_mail_audience_role'   => ['string', ''],
		'corsivo_mail_schedule_freq'   => ['string', 'daily'],
		'corsivo_mail_schedule_time'   => ['string', '09:00'],
		'corsivo_mail_schedule_day'    => ['integer', 1],
		'corsivo_mail_schedule_date'   => ['string', ''],
		'corsivo_mail_custom_bg'       => ['boolean', false],
		'corsivo_mail_outer_bg'        => ['string', ''],
		'corsivo_mail_content_bg'      => ['string', ''],
		'corsivo_mail_message_type'    => ['string', 'transactional'],
		'corsivo_mail_account_id'      => ['string', ''],
	];

	foreach ($fields as $key => $spec) {
		register_post_meta(TYPE, $key, [
			'type'          => $spec[0],
			'single'        => true,
			'default'       => $spec[1],
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can('manage_options');
			},
		]);
	}

	register_post_meta(TYPE, 'corsivo_mail_custom_tags', [
		'type'          => 'array',
		'single'        => true,
		'default'       => [],
		'show_in_rest'  => ['schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
		'auth_callback' => function () {
			return current_user_can('manage_options');
		},
	]);
}

function active_emails() {
	$posts = get_posts([
		'post_type'   => TYPE,
		'post_status' => 'publish',
		'numberposts' => -1,
	]);

	return array_values(array_filter($posts, function ($post) {
		return apply_filters('corsivo_mail_is_automation_source', true, $post->ID);
	}));
}

function config($post_id) {
	return [
		'trigger_type'    => get_post_meta($post_id, 'corsivo_mail_trigger_type', true) ?: 'manual',
		'send_enabled'    => (bool) get_post_meta($post_id, 'corsivo_mail_send_enabled', true),
		'filter_enabled'  => (bool) get_post_meta($post_id, 'corsivo_mail_filter_enabled', true),
		'core_email'      => get_post_meta($post_id, 'corsivo_mail_core_email', true),
		'event_id'        => get_post_meta($post_id, 'corsivo_mail_event_id', true),
		'subject'         => get_post_meta($post_id, 'corsivo_mail_subject', true),
		'audience_type'   => get_post_meta($post_id, 'corsivo_mail_audience_type', true) ?: 'manual',
		'audience_emails' => get_post_meta($post_id, 'corsivo_mail_audience_emails', true),
		'audience_role'   => get_post_meta($post_id, 'corsivo_mail_audience_role', true),
		'schedule_freq'   => get_post_meta($post_id, 'corsivo_mail_schedule_freq', true) ?: 'daily',
		'schedule_time'   => get_post_meta($post_id, 'corsivo_mail_schedule_time', true) ?: '09:00',
		'schedule_day'    => get_post_meta($post_id, 'corsivo_mail_schedule_day', true),
		'schedule_date'   => get_post_meta($post_id, 'corsivo_mail_schedule_date', true),
		'message_type'    => get_post_meta($post_id, 'corsivo_mail_message_type', true) === 'marketing' ? 'marketing' : 'transactional',
		'account_id'      => sanitize_text_field(get_post_meta($post_id, 'corsivo_mail_account_id', true)),
	];
}

function find_active_post_for_core_email($core_email_id) {
	$post = find_post_for_core_email($core_email_id);
	if (!$post) {
		return null;
	}
	$config = config($post->ID);
	return ($config['send_enabled'] && $config['filter_enabled']) ? $post : null;
}

function find_post_for_core_email($core_email_id) {
	foreach (active_emails() as $post) {
		$config = config($post->ID);
		if ($config['trigger_type'] === 'core_email' && $config['core_email'] === $core_email_id) {
			return $post;
		}
	}
	return null;
}
