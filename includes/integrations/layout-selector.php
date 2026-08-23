<?php

namespace CorsivoMail\Integrations\LayoutSelector;

use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

add_filter('corsivo_layouts_templates', __NAMESPACE__ . '\\register_starters');

function register_starters($collections) {
	$collection = $collections[Template\TEMPLATE_TYPE] ?? [];

	$collection['open_on_new_post'] = true;
	$collection['inherit_all']      = false;
	$collection['templates']        = array_merge(
		$collection['templates'] ?? [],
		apply_filters('corsivo_mail_starter_templates', starters())
	);

	$collections[Template\TEMPLATE_TYPE] = $collection;
	return $collections;
}

function starters() {
	return [
		[
			'name'   => 'Transazionale (con slot)',
			'blocks' => [
				['core/image', ['align' => 'center'], []],
				['core/heading', ['level' => 1, 'textAlign' => 'center', 'content' => '{{subject}}'], []],
				['corsivo-mail/content-slot', [], []],
				['core/separator', [], []],
				['core/paragraph', ['align' => 'center', 'content' => '{{site_name}} — {{year}}'], []],
			],
		],
		[
			'name'   => 'Annuncio semplice',
			'blocks' => [
				['core/heading', ['level' => 1, 'content' => 'Titolo annuncio'], []],
				['core/paragraph', ['content' => 'Scrivi qui il corpo del messaggio.'], []],
				['core/buttons', [], [
					['core/button', ['text' => 'Scopri di più'], []],
				]],
				['core/separator', [], []],
				['core/paragraph', ['align' => 'center', 'content' => '{{site_name}}'], []],
			],
		],
	];
}
