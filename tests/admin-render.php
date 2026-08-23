<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$administrators = get_users([
	'role'   => 'administrator',
	'number' => 1,
	'fields' => 'ids',
]);

if (!$administrators) {
	fwrite(STDERR, "Nessun amministratore disponibile per il test.\n");
	exit(1);
}

wp_set_current_user((int) $administrators[0]);

$renderers = [
	'general'      => 'CorsivoMail\\Settings\\render_general_tab',
	'style'        => 'CorsivoMail\\Settings\\render_style_tab',
	'accounts'     => 'CorsivoMail\\Settings\\render_accounts_tab',
	'queue'        => 'CorsivoMail\\Settings\\render_queue_tab',
	'log'          => 'CorsivoMail\\Settings\\render_log_tab',
	'integrations' => 'CorsivoMail\\Settings\\render_integrations_tab',
	'diagnostics'  => 'CorsivoMail\\Settings\\render_diagnostics_tab',
	'test'         => 'CorsivoMail\\Settings\\render_test_tab',
	'groups'       => 'CorsivoMail\\Groups\\render_body',
	'transfer'     => 'CorsivoMail\\Transfer\\render_body',
];

$failures = [];

foreach ($renderers as $tab => $renderer) {
	if (!is_callable($renderer)) {
		$failures[] = $tab . ': renderer non disponibile';
		continue;
	}

	$_GET = [
		'page' => 'corsivo-mail',
		'tab'  => $tab,
	];

	ob_start();
	call_user_func($renderer);
	$output = ob_get_clean();

	if (trim($output) === '') {
		$failures[] = $tab . ': output vuoto';
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

$message = count($renderers) . ' PASS, 0 FAIL';
if (class_exists('WP_CLI')) {
	WP_CLI::success($message);
}
echo $message . PHP_EOL;
