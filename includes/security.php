<?php

namespace CorsivoMail\Security;

if (!defined('ABSPATH')) {
	exit;
}

function post_type_capabilities() {
	return [
		'edit_post'              => 'manage_options',
		'read_post'              => 'manage_options',
		'delete_post'            => 'manage_options',
		'edit_posts'             => 'manage_options',
		'edit_others_posts'      => 'manage_options',
		'publish_posts'          => 'manage_options',
		'read_private_posts'     => 'manage_options',
		'delete_posts'           => 'manage_options',
		'delete_private_posts'   => 'manage_options',
		'delete_published_posts' => 'manage_options',
		'delete_others_posts'    => 'manage_options',
		'edit_private_posts'     => 'manage_options',
		'edit_published_posts'   => 'manage_options',
		'create_posts'           => 'manage_options',
	];
}

add_filter('rest_request_before_callbacks', __NAMESPACE__ . '\\protect_private_rest_content', 10, 3);

function protect_private_rest_content($response, $handler, $request) {
	$route = $request->get_route();
	$private_bases = [
		'/wp/v2/corsivo-mail-emails',
		'/wp/v2/corsivo-mail-layouts',
		'/wp/v2/corsivo-mail-footers',
	];

	foreach ($private_bases as $base) {
		if (strpos($route, $base) !== 0) {
			continue;
		}
		if (current_user_can('manage_options')) {
			return $response;
		}
		return new \WP_Error('corsivo_mail_rest_forbidden', 'Contenuto email riservato.', ['status' => rest_authorization_required_code()]);
	}

	return $response;
}

function send_isolated_document_headers($allow_remote_images = true) {
	nocache_headers();
	header('Content-Type: text/html; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: no-referrer');
	$images = $allow_remote_images ? "img-src data: http: https:;" : 'img-src data: cid:;';
	header("Content-Security-Policy: sandbox; default-src 'none'; {$images} style-src 'unsafe-inline'; font-src data: http: https:;");
}

function sanitize_remote_email_html($html) {
	$html = wp_kses_post((string) $html);
	$policy = "default-src 'none'; img-src data: cid:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'";
	return '<meta http-equiv="Content-Security-Policy" content="' . esc_attr($policy) . '">' . $html;
}

