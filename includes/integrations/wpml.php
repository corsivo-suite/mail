<?php

namespace CorsivoMail\Integrations\Wpml;

use CorsivoMail\Automation;
use CorsivoMail\Options;
use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

add_filter('wpml_is_translated_post_type', __NAMESPACE__ . '\\translated_post_type', 10, 2);
add_filter('corsivo_mail_resolve_post_id', __NAMESPACE__ . '\\resolve_post_id', 10, 3);
add_filter('corsivo_mail_is_automation_source', __NAMESPACE__ . '\\is_automation_source', 10, 2);
add_filter('corsivo_mail_render_context', __NAMESPACE__ . '\\render_context', 10, 2);

function enabled() {
	return (bool) Options\get('integration_wpml') && wpml_present();
}

function wpml_present() {
	return defined('ICL_SITEPRESS_VERSION') || has_filter('wpml_object_id');
}

function translated_post_type($translated, $post_type) {
	if (!enabled()) {
		return $translated;
	}
	return in_array($post_type, [Automation\TYPE, Template\TEMPLATE_TYPE, Template\FOOTER_TYPE], true) ? true : $translated;
}

function resolve_post_id($post_id, $post_type, $context) {
	if (!enabled() || !$post_id) {
		return $post_id;
	}
	$language = context_language((array) $context);
	if ($language === '') {
		return $post_id;
	}
	$translated = apply_filters('wpml_object_id', (int) $post_id, $post_type, true, $language);
	return $translated ? (int) $translated : (int) $post_id;
}

function is_automation_source($is_source, $post_id) {
	if (!wpml_present() || !$is_source) {
		return $is_source;
	}
	$details = apply_filters('wpml_element_language_details', null, [
		'element_id' => (int) $post_id,
		'element_type' => Automation\TYPE,
	]);
	return !is_object($details) || empty($details->source_language_code);
}

function context_language($context) {
	$locale = $context['_locale'] ?? '';
	if (!$locale && !empty($context['_user_id'])) {
		$locale = get_user_locale((int) $context['_user_id']);
	}
	if ($locale) {
		$mapped = language_for_locale($locale);
		if ($mapped) {
			return $mapped;
		}
	}
	if (!empty($context['_language'])) {
		return sanitize_key($context['_language']);
	}
	$current = apply_filters('wpml_current_language', null);
	return is_string($current) ? sanitize_key($current) : '';
}

function render_context($context, $post_id) {
	if (!enabled()) {
		return $context;
	}
	$language = context_language((array) $context);
	if ($language !== '') {
		$context['_language'] = $language;
	}
	return $context;
}

function language_for_locale($locale) {
	$languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
	foreach ((array) $languages as $code => $language) {
		$candidates = [$language['default_locale'] ?? '', $language['tag'] ?? '', $code];
		foreach ($candidates as $candidate) {
			if ($candidate !== '' && strcasecmp(str_replace('-', '_', $candidate), str_replace('-', '_', $locale)) === 0) {
				return sanitize_key($code);
			}
		}
	}
	return sanitize_key(strtok(str_replace('-', '_', $locale), '_'));
}
