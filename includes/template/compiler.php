<?php

namespace CorsivoMail\Template;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

const SUPPORTED_BLOCKS = [
	'core/paragraph',
	'core/heading',
	'core/image',
	'core/buttons',
	'core/button',
	'core/columns',
	'core/column',
	'core/group',
	'core/spacer',
	'core/separator',
	'core/list',
	'core/list-item',
	'core/social-links',
	'corsivo-mail/content-slot',
	'corsivo-mail/footer',
	'corsivo-mail/button',
];

function base_styles() {
	return apply_filters('corsivo_mail_base_styles', [
		'font_family'        => "Arial, Helvetica, sans-serif",
		'font_size'          => '16px',
		'line_height'        => '1.5',
		'text_color'         => '#333333',
		'heading_color'      => '#111111',
		'link_color'         => '#0067b8',
		'body_background'    => '#ffffff',
		'content_background' => '#ffffff',
		'content_width'      => '600px',
		'content_min_height' => '280px',
		'button_background'  => '#0067b8',
		'button_color'       => '#ffffff',
		'button_radius'      => '4px',
		'paragraph_spacing'  => '16px',
		'content_padding'    => '28px 30px',
		'container_radius'   => (int) Options\get('container_radius') . 'px',
		'footer_bg'          => '#f6f7f9',
		'footer_text'        => '#8a94a0',
		'footer_line'        => '#e8ecf0',
	]);
}

function color_palette() {
	$map = [];

	if (function_exists('wp_get_global_settings')) {
		$palette = wp_get_global_settings(['color', 'palette']);
		foreach (['default', 'theme', 'custom'] as $origin) {
			if (empty($palette[$origin]) || !is_array($palette[$origin])) {
				continue;
			}
			foreach ($palette[$origin] as $entry) {
				if (!empty($entry['slug']) && !empty($entry['color'])) {
					$map[$entry['slug']] = $entry['color'];
				}
			}
		}
	}

	return apply_filters('corsivo_mail_color_palette', $map);
}

function font_size_map() {
	return apply_filters('corsivo_mail_font_sizes', [
		'small'   => '13px',
		'medium'  => '16px',
		'large'   => '22px',
		'x-large' => '30px',
	]);
}

function spacing_scale() {
	return apply_filters('corsivo_mail_spacing_scale', [
		'20' => '8px',
		'30' => '16px',
		'40' => '24px',
		'50' => '36px',
		'60' => '48px',
		'70' => '64px',
		'80' => '96px',
	]);
}

function compile_document($inner_html, $context = []) {
	$base = base_styles();

	$body_background    = !empty($context['outer_bg']) ? $context['outer_bg'] : $base['body_background'];
	$content_background = !empty($context['content_bg']) ? $context['content_bg'] : $body_background;

	$body = apply_merge_tags($inner_html, $context);

	$width = esc_attr(intval($base['content_width']));
	$max   = esc_attr($base['content_width']);
	$radius = radius_value($base['container_radius'] ?? '0px');
	$footer_is_floating = array_key_exists('footer_floating', $context) ? !empty($context['footer_floating']) : (bool) Options\get('footer_floating');
	$has_footer = !empty($context['footer_html']);
	$content_radius = content_radius($radius, $has_footer && $footer_is_floating);
	$content_radius_style = $content_radius ? 'border-radius:' . esc_attr($content_radius) . ';border-collapse:separate;' : '';

	$footer_row = '';
	if ($has_footer) {
		$footer_row = $footer_is_floating
			? floating_footer_row($context['footer_html'], $context['footer_bg'] ?? '', $width, $max, $base)
			: full_width_footer_row($context['footer_html'], $context['footer_bg'] ?? '', $width, $max, $base);
	}

	$preheader = isset($context['preheader']) ? esc_html($context['preheader']) : '';

	$language = sanitize_key($context['_language'] ?? get_bloginfo('language'));
	$direction = is_rtl_language($language) ? 'rtl' : 'ltr';
	$html = '<!DOCTYPE html><html lang="' . esc_attr($language) . '" dir="' . esc_attr($direction) . '"><head>'
		. '<meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
		. '<title>' . esc_html($context['subject'] ?? '') . '</title>'
		. apply_filters('corsivo_mail_document_head', '', $context)
		. '</head>'
		. '<body style="margin:0;padding:0;background:' . esc_attr($body_background) . ';">';

	if ($preheader !== '') {
		$html .= '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . $preheader . '</div>';
	}

	$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . esc_attr($body_background) . ';">'
		. '<tr><td align="center" style="padding:24px 12px 0;">'
		. '<table role="presentation" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . $max . ';background:' . esc_attr($content_background) . ';font-family:' . esc_attr($base['font_family']) . ';color:' . esc_attr($base['text_color']) . ';font-size:' . esc_attr($base['font_size']) . ';line-height:' . esc_attr($base['line_height']) . ';' . $content_radius_style . '">'
		. '<tr><td style="padding:' . esc_attr($base['content_padding']) . ';height:' . esc_attr($base['content_min_height']) . ';' . $content_radius_style . '" height="' . esc_attr(intval($base['content_min_height'])) . '" valign="top">'
		. $body
		. '</td></tr>'
		. '</table>'
		. '</td></tr>'
		. $footer_row
		. '</table>'
		. '</body></html>';

	return $html;
}

function compile_blocks($blocks, $context = []) {
	$out = '';
	foreach ($blocks as $block) {
		$out .= render_one($block, $context);
	}
	return $out;
}

function full_width_footer_row($html, $background, $width, $max, $base) {
	$bg = $background ? 'background:' . esc_attr($background) . ';' : '';

	return '<tr><td align="center" style="padding:0;' . $bg . '">'
		. '<table role="presentation" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . $max . ';">'
		. '<tr><td style="padding:28px 30px;font-family:' . esc_attr($base['font_family']) . ';">' . $html . '</td></tr>'
		. '</table>'
		. '</td></tr>';
}

function floating_footer_row($html, $background, $width, $max, $base) {
	$bg = $background ? 'background:' . esc_attr($background) . ';' : '';
	$radius = radius_value($base['container_radius'] ?? '0px');
	$radius_style = $radius ? 'border-radius:0 0 ' . esc_attr($radius) . ' ' . esc_attr($radius) . ';' : '';

	return '<tr><td align="center" style="padding:0 12px 32px;">'
		. '<table role="presentation" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . $max . ';' . $bg . $radius_style . 'border-collapse:separate;">'
		. '<tr><td style="padding:28px 30px;font-family:' . esc_attr($base['font_family']) . ';' . $bg . $radius_style . '">' . $html . '</td></tr>'
		. '</table>'
		. '</td></tr>';
}

function content_radius($radius, $attach_footer) {
	if (!$radius) {
		return '';
	}

	return $attach_footer ? $radius . ' ' . $radius . ' 0 0' : $radius;
}

function radius_value($value) {
	$value = trim((string) $value);
	if ($value === '' || $value === '0' || $value === '0px') {
		return '';
	}

	return preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $value) ? $value : '';
}

function compile_body_and_footer($blocks, &$context) {
	$body = [];
	foreach ($blocks as $block) {
		if (($block['blockName'] ?? '') === 'corsivo-mail/footer') {
			$fid   = resolve_post_id((int) ($block['attrs']['footer_id'] ?? 0), FOOTER_TYPE, $context);
			$fpost = $fid ? get_post($fid) : null;
			if ($fpost && $fpost->post_type === 'corsivo_mail_footer') {
				list($html, $bg, $floating) = footer_content($fpost, $context);
				$context['footer_html']     = $html;
				$context['footer_bg']       = $bg;
				$context['footer_floating'] = $floating;
			}
		} else {
			$body[] = $block;
		}
	}
	return compile_blocks($body, $context);
}

function render_one($block, $context) {
	$name = $block['blockName'] ?? null;

	if ($name === null) {
		return '';
	}

	switch ($name) {
		case 'corsivo-mail/content-slot':
			return $context['content'] ?? '';
		case 'corsivo-mail/footer':
			$fid   = resolve_post_id((int) ($block['attrs']['footer_id'] ?? 0), FOOTER_TYPE, $context);
			$fpost = $fid ? get_post($fid) : null;
			if (!$fpost || $fpost->post_type !== 'corsivo_mail_footer') {
				return '';
			}
			list($fhtml, $fbg, $floating) = footer_content($fpost, $context);
			$base = base_styles();
			$width = esc_attr(intval($base['content_width']));
			$max = esc_attr($base['content_width']);
			$footer_row = $floating
				? floating_footer_row($fhtml, $fbg, $width, $max, $base)
				: full_width_footer_row($fhtml, $fbg, $width, $max, $base);
			return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $footer_row . '</table>';
		case 'corsivo-mail/button':
			return render_corsivo_mail_button($block);
		case 'core/paragraph':
			return render_paragraph($block);
		case 'core/heading':
			return render_heading($block);
		case 'core/image':
			return render_image($block);
		case 'core/buttons':
			return render_buttons($block, $context);
		case 'core/button':
			return render_button($block);
		case 'core/columns':
			return render_columns($block, $context);
		case 'core/group':
			return render_group($block, $context);
		case 'core/spacer':
			return render_spacer($block);
		case 'core/separator':
			return render_separator($block);
		case 'core/list':
			return render_list($block);
		case 'core/social-links':
			return render_passthrough($block);
		default:
			return render_passthrough($block);
	}
}

function is_rtl_language($language) {
	$base = strtolower(strtok(str_replace('_', '-', (string) $language), '-'));
	return in_array($base, ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ps', 'ug', 'ur', 'yi'], true);
}

function render_paragraph($block) {
	$attrs   = $block['attrs'] ?? [];
	$base    = base_styles();
	$content = inner_html($block);

	$styles = [
		'margin'    => '0 0 ' . $base['paragraph_spacing'],
		'font-size' => $base['font_size'],
	];
	merge_text_styles($styles, $attrs);

	$content = style_links($content);
	return '<p style="' . css($styles) . '">' . $content . '</p>';
}

function render_heading($block) {
	$attrs = $block['attrs'] ?? [];
	$base  = base_styles();
	$level = isset($attrs['level']) ? (int) $attrs['level'] : 2;
	$level = max(1, min(6, $level));
	$content = inner_html($block);

	$default_size = ['1' => '32px', '2' => '26px', '3' => '22px', '4' => '18px', '5' => '16px', '6' => '14px'][(string) $level];

	$styles = [
		'margin'      => '0 0 16px',
		'color'       => $base['heading_color'],
		'font-size'   => $default_size,
		'line-height' => '1.25',
		'font-weight' => 'bold',
	];
	merge_text_styles($styles, $attrs);

	return '<h' . $level . ' style="' . css($styles) . '">' . style_links($content) . '</h' . $level . '>';
}

function render_image($block) {
	$attrs   = $block['attrs'] ?? [];
	$content = $block['innerHTML'] ?? '';

	if (!preg_match('/<img[^>]+>/i', $content, $m)) {
		return '';
	}

	$img   = $m[0];
	$align = $attrs['align'] ?? 'left';
	$img   = inject_attr($img, 'style', 'display:block;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;');

	$td_align = in_array($align, ['center', 'right'], true) ? $align : 'left';
	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="' . $td_align . '" style="padding:0 0 16px;">' . $img . '</td></tr></table>';
}

function render_button($block) {
	$attrs = $block['attrs'] ?? [];
	$base  = base_styles();

	list($href, $label) = button_link_data($block);

	$bg     = resolve_color($attrs['backgroundColor'] ?? '', $attrs['style']['color']['background'] ?? '') ?: $base['button_background'];
	$color  = resolve_color($attrs['textColor'] ?? '', $attrs['style']['color']['text'] ?? '') ?: $base['button_color'];
	$radius = $attrs['style']['border']['radius'] ?? $base['button_radius'];

	return render_email_button([
		'url'        => $href,
		'label'      => $label,
		'align'      => $attrs['textAlign'] ?? ($attrs['align'] ?? ''),
		'background' => $bg,
		'color'      => $color,
		'radius'     => $radius,
		'wide'       => in_array($attrs['width'] ?? '', [25, 50, 75, 100, '25', '50', '75', '100'], true) ? $attrs['width'] . '%' : '',
		'target'     => $attrs['linkTarget'] ?? '_blank',
	]);
}

function render_buttons($block, $context) {
	$attrs = $block['attrs'] ?? [];
	$align = $attrs['layout']['justifyContent'] ?? ($attrs['align'] ?? '');
	$out   = '';

	foreach (($block['innerBlocks'] ?? []) as $inner) {
		if (($inner['blockName'] ?? '') === 'core/button' && $align && empty($inner['attrs']['align']) && empty($inner['attrs']['textAlign'])) {
			$inner['attrs']['align'] = $align;
		}
		$out .= render_one($inner, $context);
	}

	return $out;
}

function render_columns($block, $context) {
	$inner = $block['innerBlocks'] ?? [];
	if (!$inner) {
		return '';
	}

	$cells = '';
	$count = count($inner);
	$width = round(100 / $count, 4) . '%';

	foreach ($inner as $column) {
		$attrs    = $column['attrs'] ?? [];
		$col_w    = isset($attrs['width']) ? $attrs['width'] : $width;
		$col_html = compile_blocks($column['innerBlocks'] ?? [], $context);
		$cells   .= '<td valign="top" width="' . esc_attr($col_w) . '" style="padding:0 8px;vertical-align:top;">' . $col_html . '</td>';
	}

	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>';
}

function render_group($block, $context) {
	$attrs = $block['attrs'] ?? [];
	$base  = base_styles();
	$inner = compile_blocks($block['innerBlocks'] ?? [], $context);

	$bg      = resolve_color($attrs['backgroundColor'] ?? '', $attrs['style']['color']['background'] ?? '');
	$padding = resolve_spacing($attrs['style']['spacing']['padding'] ?? null, '0');

	$styles = ['padding' => $padding];
	if ($bg) {
		$styles['background'] = $bg;
	}

	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="' . css($styles) . '">' . $inner . '</td></tr></table>';
}

function render_spacer($block) {
	$attrs  = $block['attrs'] ?? [];
	$height = isset($attrs['height']) ? $attrs['height'] : '24px';
	$height = is_numeric($height) ? $height . 'px' : $height;

	return '<div style="line-height:' . esc_attr($height) . ';height:' . esc_attr($height) . ';font-size:1px;">&nbsp;</div>';
}

function render_separator($block) {
	$attrs = $block['attrs'] ?? [];
	$color = resolve_color($attrs['color'] ?? '', $attrs['style']['color']['background'] ?? '') ?: '#dddddd';
	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:16px 0;"><div style="border-top:1px solid ' . esc_attr($color) . ';font-size:1px;line-height:1px;">&nbsp;</div></td></tr></table>';
}

function render_list($block) {
	$base    = base_styles();
	$ordered = !empty($block['attrs']['ordered']);
	$tag     = $ordered ? 'ol' : 'ul';
	$content = inner_html($block);

	return '<' . $tag . ' style="margin:0 0 16px;padding-left:24px;font-size:' . esc_attr($base['font_size']) . ';line-height:' . esc_attr($base['line_height']) . ';">' . style_links($content) . '</' . $tag . '>';
}

function render_passthrough($block) {
	return wp_kses_post($block['innerHTML'] ?? '');
}

function render_corsivo_mail_button($block) {
	$attrs = $block['attrs'] ?? [];
	$label = $attrs['text'] ?? 'Apri';
	$url   = $attrs['url'] ?? '#';

	return render_email_button([
		'url'   => $url,
		'label' => esc_html($label),
		'align' => $attrs['align'] ?? 'left',
	]);
}

function render_email_button($args) {
	$base = base_styles();

	$url        = $args['url'] ?? '#';
	$label      = $args['label'] ?? 'Apri';
	$align      = $args['align'] ?? 'left';
	$background = $args['background'] ?? $base['button_background'];
	$color      = $args['color'] ?? $base['button_color'];
	$radius     = $args['radius'] ?? $base['button_radius'];
	$wide       = $args['wide'] ?? '';
	$target     = $args['target'] ?? '_blank';

	$td_align = in_array($align, ['center', 'right'], true) ? $align : 'left';
	$width    = $wide ? ' width="' . esc_attr($wide) . '"' : '';
	$a_width  = $wide ? 'width:100%;' : '';

	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="' . esc_attr($td_align) . '" style="padding:8px 0 18px;">'
		. '<table role="presentation"' . $width . ' cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr>'
		. '<td align="center" bgcolor="' . esc_attr($background) . '" style="border-radius:' . esc_attr($radius) . ';background:' . esc_attr($background) . ';">'
		. '<a href="' . esc_url($url) . '" target="' . esc_attr($target ?: '_blank') . '" style="display:inline-block;' . esc_attr($a_width) . 'padding:14px 24px;font-family:' . esc_attr($base['font_family']) . ';font-size:' . esc_attr($base['font_size']) . ';line-height:1.2;font-weight:bold;color:' . esc_attr($color) . ';text-decoration:none;border-radius:' . esc_attr($radius) . ';background:' . esc_attr($background) . ';box-sizing:border-box;">'
		. wp_kses_post($label)
		. '</a></td></tr></table>'
		. '</td></tr></table>';
}

function button_link_data($block) {
	$attrs = $block['attrs'] ?? [];
	$html  = $block['innerHTML'] ?? '';
	$href  = $attrs['url'] ?? '#';
	$label = $attrs['text'] ?? '';

	if (preg_match('/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $m)) {
		$href  = html_entity_decode($m[2], ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
		$label = $m[3];
	}

	if ($label === '') {
		$label = trim(wp_strip_all_tags(inner_html($block))) ?: 'Apri';
	}

	return [$href ?: '#', $label];
}

function footer_content($post, $context = []) {
	static $rendering = false;
	if ($rendering) {
		return ['', '', false];
	}

	$rendering = true;
	$raw       = apply_merge_tags($post->post_content, $context);
	$inner     = compile_blocks(parse_blocks($raw), $context);
	$rendering = false;

	$base     = base_styles();
	$bg       = get_post_meta($post->ID, 'corsivo_mail_footer_bg', true);
	$text     = get_post_meta($post->ID, 'corsivo_mail_footer_text', true);
	$floating = (bool) Options\get('footer_floating');
	$color = sanitize_hex_or_value($text ?: $base['footer_text']);

	$inner = inject_text_color($inner, $color);

	return [$inner, $bg ? sanitize_hex_or_value($bg) : '', $floating];
}

function merge_text_styles(&$styles, $attrs) {
	$base  = base_styles();
	$color = resolve_color($attrs['textColor'] ?? '', $attrs['style']['color']['text'] ?? '');
	if ($color) {
		$styles['color'] = $color;
	}

	$bg = resolve_color($attrs['backgroundColor'] ?? '', $attrs['style']['color']['background'] ?? '');
	if ($bg) {
		$styles['background'] = $bg;
		$styles['padding']    = '12px';
	}

	$size = resolve_font_size($attrs['fontSize'] ?? '', $attrs['style']['typography']['fontSize'] ?? '');
	if ($size) {
		$styles['font-size'] = $size;
	}

	if (!empty($attrs['style']['typography']['fontWeight'])) {
		$styles['font-weight'] = $attrs['style']['typography']['fontWeight'];
	}

	if (!empty($attrs['style']['typography']['lineHeight'])) {
		$styles['line-height'] = $attrs['style']['typography']['lineHeight'];
	}

	$align = $attrs['textAlign'] ?? ($attrs['align'] ?? '');
	if (in_array($align, ['left', 'center', 'right'], true)) {
		$styles['text-align'] = $align;
	}
}

function resolve_color($slug, $custom) {
	if (!empty($custom)) {
		return sanitize_hex_or_value($custom);
	}
	if (!empty($slug)) {
		$palette = color_palette();
		return $palette[$slug] ?? '';
	}
	return '';
}

function resolve_font_size($slug, $custom) {
	if (!empty($custom)) {
		return $custom;
	}
	if (!empty($slug)) {
		$map = font_size_map();
		return $map[$slug] ?? '';
	}
	return '';
}

function resolve_spacing($value, $fallback = '0') {
	if (is_string($value)) {
		return resolve_spacing_token($value);
	}

	if (is_array($value)) {
		$top    = resolve_spacing_token($value['top'] ?? '0');
		$right  = resolve_spacing_token($value['right'] ?? '0');
		$bottom = resolve_spacing_token($value['bottom'] ?? '0');
		$left   = resolve_spacing_token($value['left'] ?? '0');
		return "{$top} {$right} {$bottom} {$left}";
	}

	return $fallback;
}

function resolve_spacing_token($value) {
	if (strpos((string) $value, 'var:preset|spacing|') === 0) {
		$key   = substr($value, strlen('var:preset|spacing|'));
		$scale = spacing_scale();
		return $scale[$key] ?? '0';
	}
	return $value === '' ? '0' : $value;
}

function sanitize_hex_or_value($value) {
	return preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', (string) $value);
}

function inner_html($block) {
	$html = $block['innerHTML'] ?? '';
	if (preg_match('/^\s*<[a-z0-9]+[^>]*>(.*)<\/[a-z0-9]+>\s*$/is', $html, $m)) {
		return $m[1];
	}
	return $html;
}

function inject_text_color($html, $color) {
	return preg_replace_callback('/<(p|h[1-6]|li|td)\b([^>]*?)style="([^"]*)"/i', function ($m) use ($color) {
		if (stripos($m[3], 'color:') !== false) {
			return $m[0];
		}
		return '<' . $m[1] . $m[2] . 'style="' . $m[3] . 'color:' . $color . ';"';
	}, $html);
}

function style_links($html) {
	$base = base_styles();
	return preg_replace_callback('/<a\b(?![^>]*\bstyle=)([^>]*)>/i', function ($m) use ($base) {
		return '<a' . $m[1] . ' style="color:' . $base['link_color'] . ';text-decoration:underline;">';
	}, $html);
}

function inject_attr($tag, $attr, $value) {
	if (preg_match('/\b' . preg_quote($attr, '/') . '="[^"]*"/i', $tag)) {
		return preg_replace('/\b' . preg_quote($attr, '/') . '="[^"]*"/i', $attr . '="' . $value . '"', $tag);
	}
	return preg_replace('/<img/i', '<img ' . $attr . '="' . $value . '"', $tag, 1);
}

function css($styles) {
	$out = '';
	foreach ($styles as $prop => $value) {
		if ($value === '' || $value === null) {
			continue;
		}
		$out .= $prop . ':' . $value . ';';
	}
	return esc_attr($out);
}
