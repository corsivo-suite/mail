<?php

namespace CorsivoMail\Template;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

function render_email_post($post_id, $context = []) {
	$post_id = resolve_post_id($post_id, \CorsivoMail\Automation\TYPE, $context);
	$post = get_post($post_id);
	if (!$post) {
		return '';
	}

	$context = array_merge(['subject' => $post->post_title], $context);

	$outer          = get_post_meta($post_id, 'corsivo_mail_outer_bg', true);
	$content_custom = get_post_meta($post_id, 'corsivo_mail_custom_bg', true);
	$content        = get_post_meta($post_id, 'corsivo_mail_content_bg', true);

	if ($outer) {
		$context['outer_bg'] = $outer;
	}
	if ($content_custom && $content) {
		$context['content_bg'] = $content;
	} elseif ($outer) {
		$context['content_bg'] = $outer;
	}

	$raw   = apply_merge_tags($post->post_content, $context);
	$inner = compile_body_and_footer(parse_blocks($raw), $context);

	return compile_document($inner, $context);
}

function render_template($template_id, $context = []) {
	$template_id = resolve_post_id($template_id, TEMPLATE_TYPE, $context);
	$template = get_post($template_id);
	if (!$template || $template->post_type !== TEMPLATE_TYPE) {
		return '';
	}

	$context = array_merge(['subject' => $template->post_title], $context);
	$raw     = apply_merge_tags($template->post_content, $context);
	$inner   = compile_body_and_footer(parse_blocks($raw), $context);

	return compile_document($inner, $context);
}

function render_template_with_content($template_id, $content_html, $context = []) {
	$template_id = resolve_post_id($template_id, TEMPLATE_TYPE, $context);
	$template = $template_id ? get_post($template_id) : null;
	$context  = array_merge($context, ['content' => $content_html]);

	if (!$template || $template->post_type !== TEMPLATE_TYPE) {
		return compile_document($content_html, $context);
	}

	$raw    = apply_merge_tags($template->post_content, $context);
	$blocks = parse_blocks($raw);
	$inner  = compile_body_and_footer($blocks, $context);

	if (!has_content_slot($blocks)) {
		$inner .= $content_html;
	}

	return compile_document($inner, $context);
}

function wrap_message($subject, $message) {
	$wrapper_id = (int) Options\get('default_wrapper_id');

	if (!looks_like_html($message)) {
		$message = wpautop(esc_html($message));
	} else {
		$message = wp_kses_post($message);
	}

	return render_template_with_content($wrapper_id, $message, [
		'subject' => $subject,
	]);
}

function has_content_slot($blocks) {
	foreach ($blocks as $block) {
		if (($block['blockName'] ?? '') === 'corsivo-mail/content-slot') {
			return true;
		}
		if (!empty($block['innerBlocks']) && has_content_slot($block['innerBlocks'])) {
			return true;
		}
	}
	return false;
}

function looks_like_html($string) {
	return (bool) preg_match(
		'#<(?:a|abbr|b|blockquote|br|button|caption|center|code|col|colgroup|dd|del|div|dl|dt|em|figure|font|h[1-6]|head|hr|html|i|img|ins|label|li|mark|ol|p|pre|q|s|section|small|span|strike|strong|sub|sup|table|tbody|td|tfoot|th|thead|tr|u|ul)(?:\s[^<>]*)?/?>#i',
		(string) $string
	);
}

function resolve_post_id($post_id, $post_type, $context = []) {
	return (int) apply_filters('corsivo_mail_resolve_post_id', (int) $post_id, $post_type, $context);
}
