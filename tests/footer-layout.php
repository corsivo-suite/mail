<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$plugin_directory = dirname(__DIR__) . DIRECTORY_SEPARATOR;

if (!function_exists('CorsivoMail\Template\render_template_with_content')) {
	require_once $plugin_directory . 'includes/settings/options.php';
	require_once $plugin_directory . 'includes/template/cpt.php';
	require_once $plugin_directory . 'includes/template/merge-tags.php';
	require_once $plugin_directory . 'includes/template/compiler.php';
	require_once $plugin_directory . 'includes/template/render.php';
}

$failures = [];
$created_post_ids = [];
$original_options = get_option(\CorsivoMail\Options\OPTION, null);
$registered_post_types = [];

function corsivo_mail_footer_layout_check($condition, $message, &$failures) {
	if (!$condition) {
		$failures[] = $message;
	}
}

try {
	foreach ([\CorsivoMail\Template\TEMPLATE_TYPE, \CorsivoMail\Template\FOOTER_TYPE] as $post_type) {
		if (!post_type_exists($post_type)) {
			register_post_type($post_type, ['public' => false]);
			$registered_post_types[] = $post_type;
		}
	}

	$footer_id = wp_insert_post([
		'post_type'    => \CorsivoMail\Template\FOOTER_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Footer layout test',
		'post_content' => '<!-- wp:paragraph --><p>Footer test</p><!-- /wp:paragraph -->',
	], true);
	if (is_wp_error($footer_id)) {
		throw new RuntimeException($footer_id->get_error_message());
	}
	$created_post_ids[] = $footer_id;
	update_post_meta($footer_id, 'corsivo_mail_footer_bg', '#102030');

	$template_id = wp_insert_post([
		'post_type'    => \CorsivoMail\Template\TEMPLATE_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Wrapper layout test',
		'post_content' => '<!-- wp:corsivo-mail/content-slot /--><!-- wp:corsivo-mail/footer {"footer_id":' . $footer_id . '} /-->',
	], true);
	if (is_wp_error($template_id)) {
		throw new RuntimeException($template_id->get_error_message());
	}
	$created_post_ids[] = $template_id;

	\CorsivoMail\Options\save_style([
		'container_radius' => 12,
	]);
	$full_width_html = \CorsivoMail\Template\render_template_with_content($template_id, '<p>Wrapped content</p>');

	corsivo_mail_footer_layout_check(
		strpos($full_width_html, '</td></tr></table></td></tr><tr><td align="center" style="padding:0;background:#102030;">') !== false,
		'Il footer non flottante è rimasto dentro il riquadro del contenuto.',
		$failures
	);
	corsivo_mail_footer_layout_check(
		strpos($full_width_html, 'padding:0 12px 32px;') === false,
		'Il footer non flottante usa ancora la spaziatura del footer flottante.',
		$failures
	);

	\CorsivoMail\Options\save_style([
		'footer_floating'  => 1,
		'container_radius' => 12,
	]);
	$floating_html = \CorsivoMail\Template\render_template_with_content($template_id, '<p>Wrapped content</p>');

	corsivo_mail_footer_layout_check(
		strpos($floating_html, '</td></tr></table></td></tr><tr><td align="center" style="padding:0 12px 32px;">') !== false,
		'Il footer flottante non viene renderizzato fuori dal riquadro del contenuto.',
		$failures
	);
} catch (Throwable $error) {
	$failures[] = $error->getMessage();
} finally {
	foreach ($created_post_ids as $post_id) {
		wp_delete_post($post_id, true);
	}
	if ($original_options === null) {
		delete_option(\CorsivoMail\Options\OPTION);
	} else {
		update_option(\CorsivoMail\Options\OPTION, $original_options);
	}
	foreach ($registered_post_types as $post_type) {
		unregister_post_type($post_type);
	}
}

if ($failures) {
	$message = count($failures) . ' FAIL: ' . implode(' | ', $failures);
	if (class_exists('WP_CLI')) {
		WP_CLI::error($message);
	}
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$message = '3 PASS, 0 FAIL';
if (class_exists('WP_CLI')) {
	WP_CLI::success($message);
}
echo $message . PHP_EOL;
