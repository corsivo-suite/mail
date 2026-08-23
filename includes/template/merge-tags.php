<?php

namespace CorsivoMail\Template;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

function merge_tags($context = []) {
	$tags = [
		'site_name'   => get_bloginfo('name'),
		'site_url'    => home_url('/'),
		'admin_email' => get_bloginfo('admin_email'),
		'date'        => date_i18n(get_option('date_format')),
		'year'        => date_i18n('Y'),
		'subject'     => $context['subject'] ?? '',
	];

	return apply_filters('corsivo_mail_merge_tags', array_merge($tags, $context), $context);
}

function apply_merge_tags($html, $context = []) {
	$tags = merge_tags($context);
	$raw_keys = apply_filters('corsivo_mail_merge_tags_raw_keys', ['content'], $context);

	$html = preg_replace('/%7B%7B(.+?)%7D%7D/i', '{{$1}}', $html);

	$html = preg_replace_callback('/\{\{\s*([A-Za-z0-9_:.%\-]+?)\s*\}\}/', function ($matches) use ($tags, $context, $raw_keys) {
		$raw = urldecode($matches[1]);
		$key = strtolower($raw);

		if (array_key_exists($key, $tags) && is_scalar($tags[$key])) {
			$value = (string) $tags[$key];
			return in_array($key, (array) $raw_keys, true) ? $value : esc_html($value);
		}

		if (stripos($raw, 'meta:') === 0 && !empty($context['_user_id'])) {
			$meta_key = sanitize_key(substr($raw, 5));
			if (!in_array($meta_key, allowed_user_meta_keys(), true)) {
				return '';
			}
			$value = get_user_meta((int) $context['_user_id'], $meta_key, true);
			return is_scalar($value) ? esc_html((string) $value) : '';
		}

		return $matches[0];
	}, $html);

	return preg_replace('#\bhttps?://(https?://)#i', '$1', $html);
}

function allowed_user_meta_keys() {
	$keys = array_filter(array_map('sanitize_key', (array) Options\get('user_meta_keys')));
	return array_values(array_unique(apply_filters('corsivo_mail_user_meta_keys', $keys)));
}
