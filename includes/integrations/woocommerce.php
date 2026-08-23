<?php

namespace CorsivoMail\Integrations\WooCommerce;

use CorsivoMail\Options;
use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

add_filter('woocommerce_mail_callback_params', __NAMESPACE__ . '\\mark_native_email', 10, 2);
add_filter('corsivo_mail_prepare_context', __NAMESPACE__ . '\\prepare_native_email');

function enabled() {
	return (bool) Options\get('integration_woocommerce') && class_exists('WooCommerce');
}

function mark_native_email($parameters, $email = null) {
	if (!enabled() || !is_array($parameters)) {
		return $parameters;
	}
	$headers = Template\normalize_headers($parameters[3] ?? []);
	$email_id = is_object($email) && isset($email->id) ? sanitize_key($email->id) : 'email';
	$headers[] = 'X-Corsivo-Mail-Context: woocommerce:' . $email_id;
	$parameters[3] = $headers;
	return $parameters;
}

function prepare_native_email($atts) {
	if (!enabled()) {
		return $atts;
	}
	$headers = Template\normalize_headers($atts['headers'] ?? []);
	$kept = [];
	foreach ($headers as $header) {
		if (stripos($header, 'X-Corsivo-Mail-Context: woocommerce:') === 0) {
			$atts['corsivo_mail_skip_wrap'] = true;
			$atts['corsivo_mail_bypass_queue'] = true;
			$atts['corsivo_mail_source_type'] = 'woocommerce';
			continue;
		}
		$kept[] = $header;
	}
	$atts['headers'] = $kept;
	return $atts;
}

