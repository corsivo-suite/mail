<?php

namespace CorsivoMail\Blocks;

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', __NAMESPACE__ . '\\register_content_slot');

function register_content_slot() {
	register_block_type('corsivo-mail/content-slot', [
		'api_version'     => 2,
		'title'           => 'Contenuto email',
		'category'        => 'design',
		'icon'            => 'editor-paste-text',
		'description'     => 'Segnaposto dove viene iniettato il messaggio della mail.',
		'editor_script'   => 'corsivo-mail-editor',
		'render_callback' => __NAMESPACE__ . '\\render_content_slot',
		'supports'        => ['html' => false, 'reusable' => false, 'multiple' => false],
	]);
}

function render_content_slot() {
	return '';
}

add_action('init', __NAMESPACE__ . '\\register_button');

function register_button() {
	register_block_type('corsivo-mail/button', [
		'api_version'     => 2,
		'title'           => 'Bottone email',
		'category'        => 'design',
		'icon'            => 'button',
		'description'     => 'Bottone con URL libero: accetta i merge tag (es. {{magic_link}}).',
		'editor_script'   => 'corsivo-mail-editor',
		'attributes'      => [
			'text'  => ['type' => 'string', 'default' => 'Apri'],
			'url'   => ['type' => 'string', 'default' => ''],
			'align' => ['type' => 'string', 'default' => 'left'],
		],
		'render_callback' => '__return_empty_string',
		'supports'        => ['html' => false],
	]);
}

add_action('init', __NAMESPACE__ . '\\register_footer');

function register_footer() {
	register_block_type('corsivo-mail/footer', [
		'api_version'     => 2,
		'title'           => 'Footer email',
		'category'        => 'design',
		'icon'            => 'align-wide',
		'description'     => 'Footer dell\'email: scegli una delle pagine Footer.',
		'editor_script'   => 'corsivo-mail-editor',
		'attributes'      => ['footer_id' => ['type' => 'integer', 'default' => 0]],
		'render_callback' => '__return_empty_string',
		'supports'        => ['html' => false, 'reusable' => false],
	]);
}
