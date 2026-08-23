<?php

if (!defined('ABSPATH')) {
	exit(1);
}

global $wpdb;

$failures = array();
$passes = 0;

function corsivo_mail_database_check($condition, $label, &$passes, &$failures) {
	if ($condition) {
		$passes++;
		return;
	}
	$failures[] = $label;
}

$tables = array(
	$wpdb->prefix . 'corsivo_mail_queue',
	$wpdb->prefix . 'corsivo_mail_log',
);
if (\CorsivoMail\Options\get('module_imap')) {
	$tables[] = $wpdb->prefix . 'corsivo_mail_messages';
}

foreach ($tables as $table) {
	$engine = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		)
	);
	corsivo_mail_database_check(
		$engine === 'InnoDB',
		$table . ': engine non InnoDB',
		$passes,
		$failures
	);
}

$queue_columns = $wpdb->get_col(
	'SHOW COLUMNS FROM ' . $wpdb->prefix . 'corsivo_mail_queue',
	0
);
corsivo_mail_database_check(
	!array_diff(
		array(
			'lock_token',
			'completed_at',
			'embeds',
			'message_type',
			'idempotency_fingerprint',
			'source_guard_required',
			'cancel_requested',
			'retain_until',
			'transport_started_at',
		),
		$queue_columns
	),
	'Schema coda incompleto.',
	$passes,
	$failures
);
corsivo_mail_database_check(
	\CorsivoMail\Queue\schema_ready(true),
	'Schema coda o attestazione corrente non pronti.',
	$passes,
	$failures
);
corsivo_mail_database_check(
	\CorsivoMail\Log\schema_ready(true),
	'Schema log o attestazione corrente non pronti.',
	$passes,
	$failures
);
if (\CorsivoMail\Options\get('module_imap')) {
	corsivo_mail_database_check(
		\CorsivoMail\Imap\schema_ready(true),
		'Schema IMAP o attestazione corrente non pronti.',
		$passes,
		$failures
	);
}

corsivo_mail_database_check(
	(int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}corsivo_mail_queue WHERE source_type = 'test'"
	) === 0,
	'Righe di test rimaste nella coda.',
	$passes,
	$failures
);
corsivo_mail_database_check(
	(int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE 'corsivo-mail-test-%@example.invalid'"
	) === 0,
	'Utenti di test non rimossi.',
	$passes,
	$failures
);

$plugin_source = file_get_contents(CORSIVO_MAIL_FILE);
corsivo_mail_database_check(
	is_string($plugin_source)
		&& strpos($plugin_source, 'Queue\\migration_in_progress') === false
		&& strpos($plugin_source, 'Queue\\ensure_fresh_storage($configuration, true)') !== false
		&& strpos($plugin_source, 'function verify_required_storage()') !== false,
	'Activation conserva un percorso di migrazione o non verifica lo storage corrente.',
	$passes,
	$failures
);

$schema_source = file_get_contents(CORSIVO_MAIL_DIR . 'includes/queue/schema.php')
	. file_get_contents(CORSIVO_MAIL_DIR . 'includes/storage/contract.php');
$forbidden_schema_tokens = array(
	'mailstudio',
	'msenc:',
	'db3',
	'db_version',
	'dbDelta',
	'ALTER TABLE',
	'legacy',
	'migrat',
	'hash_hmac',
	'DROP TABLE',
	'ALTER TABLE',
);
$present_schema_tokens = array_filter(
	$forbidden_schema_tokens,
	static function ($token) use ($schema_source) {
		return !is_string($schema_source)
			|| stripos($schema_source, $token) !== false;
	}
);
corsivo_mail_database_check(
	$present_schema_tokens === array(),
	'Lo schema fresh-only conserva meccaniche storiche: ' . implode(', ', $present_schema_tokens),
	$passes,
	$failures
);

$uninstall_source = file_get_contents(CORSIVO_MAIL_DIR . 'uninstall.php');
$forbidden_uninstall_tokens = array(
	'mailstudio',
	'db3',
	'db_version',
	'migration',
	'corsivo_mail_template',
);
$present_uninstall_tokens = array_filter(
	$forbidden_uninstall_tokens,
	static function ($token) use ($uninstall_source) {
		return !is_string($uninstall_source)
			|| stripos($uninstall_source, $token) !== false;
	}
);
corsivo_mail_database_check(
	$present_uninstall_tokens === array(),
	'Uninstall conserva namespace storici: ' . implode(', ', $present_uninstall_tokens),
	$passes,
	$failures
);

if ($failures) {
	$message = count($failures) . ' FAIL: ' . implode(' | ', $failures);
	if (class_exists('WP_CLI')) {
		WP_CLI::error($message);
	}
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$message = $passes . ' PASS, 0 FAIL';
if (class_exists('WP_CLI')) {
	WP_CLI::success($message);
}
echo $message . PHP_EOL;
