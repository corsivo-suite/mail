<?php

namespace CorsivoMail\Automation;

use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

function render_automation_email($post, $config, $context = []) {
	$context = apply_filters('corsivo_mail_render_context', $context, $post->ID);
	$post_id = Template\resolve_post_id($post->ID, TYPE, $context);
	$render_post = get_post($post_id) ?: $post;
	$render_config = $post_id === (int) $post->ID ? $config : config($post_id);
	$subject = Template\apply_merge_tags($render_config['subject'] ?: $render_post->post_title, $context);
	$html = Template\render_email_post($render_post->ID, array_merge($context, ['subject' => $subject]));

	return ['subject' => $subject, 'html' => $html, 'post_id' => (int) $render_post->ID];
}

function begin_override($account_id = '') {
	$GLOBALS['corsivo_mail_override_active'] = true;
	$GLOBALS['corsivo_mail_override_account_id'] = sanitize_text_field($account_id);
}
