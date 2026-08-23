<?php

namespace CorsivoMail\Template;

use CorsivoMail\Security;

if (!defined('ABSPATH')) {
	exit;
}

const TEMPLATE_TYPE = 'corsivo_mail_layout';
const FOOTER_TYPE   = 'corsivo_mail_footer';

add_action('init', __NAMESPACE__ . '\\register_post_types');

function register_post_types() {
	register_post_type(TEMPLATE_TYPE, [
		'labels' => [
			'name'          => 'Layout',
			'singular_name' => 'Layout',
			'add_new_item'  => 'Nuovo layout',
			'edit_item'     => 'Modifica layout',
			'menu_name'     => 'Layout',
		],
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'corsivo-mail',
		'show_in_rest'    => true,
		'rest_base'       => 'corsivo-mail-layouts',
		'supports'        => ['title', 'editor', 'revisions'],
		'capabilities'    => Security\post_type_capabilities(),
		'map_meta_cap'    => false,
		'menu_icon'       => 'dashicons-layout',
	]);

	register_post_type(FOOTER_TYPE, [
		'labels' => [
			'name'          => 'Footer',
			'singular_name' => 'Footer',
			'add_new_item'  => 'Nuovo footer',
			'edit_item'     => 'Modifica footer',
			'menu_name'     => 'Footer',
		],
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'corsivo-mail',
		'show_in_rest'    => true,
		'rest_base'       => 'corsivo-mail-footers',
		'supports'        => ['title', 'editor', 'revisions', 'custom-fields'],
		'capabilities'    => Security\post_type_capabilities(),
		'map_meta_cap'    => false,
		'menu_icon'       => 'dashicons-align-wide',
	]);

	foreach (['corsivo_mail_footer_bg', 'corsivo_mail_footer_text'] as $key) {
		register_post_meta(FOOTER_TYPE, $key, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can('manage_options');
			},
		]);
	}

}

function footer_options() {
	$posts = get_posts([
		'post_type'   => FOOTER_TYPE,
		'post_status' => ['publish', 'draft'],
		'numberposts' => -1,
		'orderby'     => 'title',
		'order'       => 'ASC',
	]);

	$options = [];
	foreach ($posts as $post) {
		$options[$post->ID] = $post->post_title ?: '(senza titolo)';
	}
	return $options;
}

function template_options() {
	$posts = get_posts([
		'post_type'   => TEMPLATE_TYPE,
		'post_status' => ['publish', 'draft'],
		'numberposts' => -1,
		'orderby'     => 'title',
		'order'       => 'ASC',
	]);

	$options = [];
	foreach ($posts as $post) {
		$options[$post->ID] = $post->post_title ?: '(senza titolo)';
	}
	return $options;
}
