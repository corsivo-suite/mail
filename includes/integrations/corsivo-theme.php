<?php

namespace CorsivoMail\Integrations\CorsivoTheme;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

add_filter('corsivo_mail_color_palette', __NAMESPACE__ . '\\palette', 5);
add_filter('corsivo_mail_base_styles', __NAMESPACE__ . '\\base_styles', 5);
add_filter('corsivo_mail_document_head', __NAMESPACE__ . '\\font_imports', 5);

function font_imports($head) {
	if (!available()) {
		return $head;
	}

	$fonts = apply_filters('corsivo_theme_custom_fonts', []);
	foreach ((array) $fonts as $font) {
		$url = is_array($font) ? ($font['url'] ?? '') : $font;
		if ($url) {
			$head .= '<link rel="stylesheet" href="' . esc_url($url) . '">';
		}
	}
	return $head;
}

function available() {
	return (bool) Options\get('integration_corsivo_theme') && function_exists('corsivo_theme_get_settings');
}

function settings() {
	static $cache = null;
	if ($cache === null) {
		$cache = available() ? corsivo_theme_get_settings() : [];
	}
	return $cache;
}

function color_map() {
	$config = settings();
	$colors = $config['configurator']['colors'] ?? [];
	$map    = [];
	foreach ($colors as $color) {
		if (!empty($color['slug']) && !empty($color['color'])) {
			$map[$color['slug']] = $color['color'];
		}
	}
	return $map;
}

function resolve_color($value, $map) {
	if ($value === '' || $value === null) {
		return '';
	}
	if (strpos($value, '#') === 0) {
		return $value;
	}
	return $map[$value] ?? '';
}

function email_style() {
	$templates = apply_filters('corsivo_theme_style_templates', []);
	$contexts  = apply_filters('corsivo_theme_context_styles', []);

	foreach ($contexts as $cs) {
		if (($cs['context'] ?? '') === 'email' && function_exists('corsivo_theme_get_style_template_map')) {
			$map = corsivo_theme_get_style_template_map($templates);
			return $map[$cs['template']] ?? null;
		}
	}
	return null;
}

function palette($map) {
	if (!available()) {
		return $map;
	}
	return array_merge($map, color_map());
}

function base_styles($styles) {
	if (!available()) {
		return $styles;
	}

	$config  = settings();
	$colors  = color_map();
	$globals = $config['configurator']['global_styles'] ?? [];
	$fonts   = $config['configurator']['fonts'] ?? [];
	$style   = email_style();

	$style_body = $style['body'] ?? [];
	$body       = $style_body ?: ($globals['body'] ?? []);
	$heading    = ($style['heading'] ?? null) ?: ($globals['heading'] ?? []);
	$link       = $globals['link'] ?? [];

	$bg      = resolve_color($style_body['background'] ?? '', $colors);
	$text    = resolve_color($body['color'] ?? '', $colors);
	$head    = resolve_color($heading['color'] ?? '', $colors);
	$linkcol = resolve_color($link['color'] ?? '', $colors);

	if ($bg) {
		$styles['body_background'] = $bg;
	}
	if ($text) {
		$styles['text_color'] = $text;
	}
	if ($head) {
		$styles['heading_color'] = $head;
	}
	if ($linkcol) {
		$styles['link_color'] = $linkcol;
	}

	$footer_bg   = first_color($colors, ['bg', 'surface-2', 'subtle', 'blue-50']);
	$footer_text = first_color($colors, ['muted', 'secondary', 'gray', 'text-muted']);
	$footer_line = first_color($colors, ['line', 'border', 'divider']);
	if ($footer_bg) {
		$styles['footer_bg'] = $footer_bg;
	}
	if ($footer_text) {
		$styles['footer_text'] = $footer_text;
	}
	if ($footer_line) {
		$styles['footer_line'] = $footer_line;
	}

	if (!empty($body['font']) && function_exists('corsivo_theme_font_css_declarations')) {
		$decl   = corsivo_theme_font_css_declarations($body['font']);
		$family = css_value($decl, 'font-family');
		$size   = css_value($decl, 'font-size');
		$lh     = css_value($decl, 'line-height');

		if ($family) {
			$styles['font_family'] = $family;
		}
		if ($size) {
			$styles['font_size'] = $size;
		}
		if ($lh) {
			$styles['line_height'] = $lh;
		}
	}

	return $styles;
}

function first_color($map, $slugs) {
	foreach ($slugs as $slug) {
		if (!empty($map[$slug])) {
			return $map[$slug];
		}
	}
	return '';
}

function css_value($declarations, $property) {
	if (preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+)/i', $declarations, $m)) {
		return trim($m[1]);
	}
	return '';
}
