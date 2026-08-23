<?php

if (!defined('ABSPATH')) {
	exit(1);
}

global $wpdb;

$failures = array();
$passes = 0;
$original_prefix = $wpdb->prefix;
$state_options = array(
	\CorsivoMail\Queue\INSTALL_INTENT_OPTION,
	\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION,
	\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION,
	\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION,
);
$original_options = array();
foreach ($state_options as $option_name) {
	$original_options[$option_name] = \CorsivoMail\Queue\option_record($option_name);
}
$original_cron = \CorsivoMail\Queue\option_record('cron');
$GLOBALS['corsivo_mail_storage_original_prefix'] = $original_prefix;

function corsivo_mail_storage_check($condition, $label, &$passes, &$failures) {
	if ($condition) {
		$passes++;
		return;
	}
	$failures[] = $label;
}

function corsivo_mail_storage_reset_options() {
	foreach (array(
		\CorsivoMail\Queue\INSTALL_INTENT_OPTION,
		\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION,
		\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION,
		\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION,
	) as $option_name) {
		delete_option($option_name);
		wp_cache_delete($option_name, 'options');
	}
	wp_cache_delete('alloptions', 'options');
	$GLOBALS['corsivo_mail_storage_gate_invalidated'] = array();
	$GLOBALS['corsivo_mail_storage_readiness_cache'] = array();
}

function corsivo_mail_storage_restore_option($option_name, $record) {
	if (is_wp_error($record)) {
		return;
	}
	if ($record['exists']) {
		update_option($option_name, $record['value'], false);
	} else {
		delete_option($option_name);
	}
	wp_cache_delete($option_name, 'options');
	wp_cache_delete('alloptions', 'options');
}

function corsivo_mail_storage_prefix($label) {
	global $wpdb;
	$prefix = $GLOBALS['corsivo_mail_storage_original_prefix']
		. 'cmq_'
		. substr(hash('sha256', $label . ':' . wp_generate_uuid4()), 0, 12)
		. '_';
	if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
		throw new RuntimeException('Invalid queue storage fixture prefix.');
	}
	$wpdb->prefix = $prefix;
	return $prefix;
}

function corsivo_mail_storage_drop_fixture($table) {
	global $wpdb;
	$expected_prefix = $GLOBALS['corsivo_mail_storage_original_prefix'] . 'cmq_';
	if (strpos($table, $expected_prefix) !== 0
		|| substr($table, -strlen('corsivo_mail_queue')) !== 'corsivo_mail_queue'
		|| !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
		throw new RuntimeException('Refusing to drop an unexpected queue fixture table.');
	}
	$wpdb->query('DROP TABLE IF EXISTS ' . \CorsivoMail\Queue\quoted_table($table));
}

function corsivo_mail_storage_create_exact($configuration) {
	global $wpdb;
	$sql = rtrim((string) $configuration['sql'], " \t\n\r\0\x0B;") . ' ROW_FORMAT=DYNAMIC';
	return $wpdb->query($sql) !== false;
}

function corsivo_mail_storage_failure_code($configuration) {
	$failure = \CorsivoMail\Queue\storage_failure($configuration);
	return is_array($failure) ? (string) ($failure['code'] ?? '') : '';
}

function corsivo_mail_storage_force_option($option_name, $value) {
	global $wpdb;

	delete_option($option_name);
	$inserted = $wpdb->insert(
		$wpdb->options,
		array(
			'option_name' => $option_name,
			'option_value' => $value === null ? serialize(null) : maybe_serialize($value),
			'autoload' => 'no',
		),
		array('%s', '%s', '%s')
	);
	wp_cache_delete($option_name, 'options');
	wp_cache_delete('alloptions', 'options');
	wp_cache_delete('notoptions', 'options');
	return $inserted === 1;
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('fresh');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration, true);
	$snapshot = \CorsivoMail\Queue\schema_snapshot($table);
	$marker = \CorsivoMail\Queue\storage_attestation($configuration);
	corsivo_mail_storage_check(
		$ready
			&& is_array($snapshot)
			&& \CorsivoMail\Queue\storage_schema_matches($snapshot, $configuration)
			&& is_array($marker)
			&& \CorsivoMail\Queue\storage_marker_is_valid($marker, $configuration)
			&& \CorsivoMail\Queue\storage_intent($configuration) === null
			&& \CorsivoMail\Queue\storage_failure($configuration) === null
			&& \CorsivoMail\Queue\runtime_ready(true),
		'La fresh install non crea un marker plain exact.',
		$passes,
		$failures
	);

	$marker_before = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	$second = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$marker_after = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	corsivo_mail_storage_check(
		$second && $marker_after === $marker_before,
		'La seconda verifica fisica muta un marker corrente.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('invalid-marker');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$invalid = array('payload' => array('domain' => 'invalid-envelope'), 'signature' => str_repeat('b', 64));
	update_option(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, $invalid, false);
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, 'options');
	$hot_ready = \CorsivoMail\Queue\runtime_ready(true);
	$before = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	$verified = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$after = \CorsivoMail\Queue\storage_attestation($configuration);
	corsivo_mail_storage_reset_options();
	wp_clear_scheduled_hook('corsivo_mail_verify_storage', array('runtime'));
	update_option(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, '0', false);
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, 'options');
	$zero_hot_ready = \CorsivoMail\Queue\runtime_ready(true);
	$zero_scheduled = wp_next_scheduled('corsivo_mail_verify_storage', array('runtime'));
	$zero_before = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	$zero_verified = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$zero_after = \CorsivoMail\Queue\storage_attestation($configuration);
	$empty_markers_closed = true;
	foreach (array('', false, array(), null) as $empty_marker) {
		update_option(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, $empty_marker, false);
		wp_cache_delete(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, 'options');
		$empty_markers_closed = $empty_markers_closed
			&& !\CorsivoMail\Queue\runtime_ready(true);
	}
	corsivo_mail_storage_check(
		!$hot_ready
			&& $before['value'] === $invalid
			&& $verified
			&& \CorsivoMail\Queue\storage_marker_is_valid($after, $configuration)
			&& !$zero_hot_ready
			&& $zero_scheduled !== false
			&& $zero_before['exists']
			&& $zero_before['value'] === '0'
			&& $zero_verified
			&& \CorsivoMail\Queue\storage_marker_is_valid($zero_after, $configuration)
			&& $empty_markers_closed,
		'Un marker non corrente viene letto come compatibile nel percorso caldo.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('future-marker');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$future = array('marker_format' => '2', 'opaque' => array('keep' => true));
	update_option(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, $future, false);
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, 'options');
	$hot_ready = \CorsivoMail\Queue\runtime_ready(true);
	$verified = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$stored = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	$failure = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION);
	corsivo_mail_storage_check(
		!$hot_ready
			&& !$verified
			&& $stored['exists']
			&& $stored['value'] === $future
			&& !$failure['exists'],
		'Un marker futuro non resta byte-identico e fail-closed.',
		$passes,
		$failures
	);

	corsivo_mail_storage_reset_options();
	$future_intent = array('intent_format' => 2, 'opaque' => array('keep' => 'intent'));
	update_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, $future_intent, false);
	wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
	$intent_verified = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$stored_intent = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\INSTALL_INTENT_OPTION);
	corsivo_mail_storage_check(
		!$intent_verified && $stored_intent['exists'] && $stored_intent['value'] === $future_intent,
		'Un intent futuro non resta byte-identico e fail-closed.',
		$passes,
		$failures
	);

	corsivo_mail_storage_reset_options();
	$future_failure = array('failure_format' => '2', 'opaque' => array('keep' => 'failure'));
	update_option(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, $future_failure, false);
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, 'options');
	$failure_verified = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$stored_failure = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION);
	corsivo_mail_storage_check(
		!$failure_verified && $stored_failure['exists'] && $stored_failure['value'] === $future_failure,
		'Una failure futura non resta byte-identica e fail-closed.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('source-mismatch');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$snapshot = \CorsivoMail\Queue\schema_snapshot($table);
	$marker = \CorsivoMail\Queue\storage_marker_payload($configuration, $snapshot);
	$marker['source_fingerprint'] = str_repeat('c', 64);
	update_option(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, $marker, false);
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, 'options');
	corsivo_mail_storage_check(
		!\CorsivoMail\Queue\runtime_ready(true),
		'Un source mismatch del marker resta disponibile nel percorso caldo.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('intent-closes');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$snapshot = \CorsivoMail\Queue\schema_snapshot($table);
	update_option(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, \CorsivoMail\Queue\storage_marker_payload($configuration, $snapshot), false);
	update_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, \CorsivoMail\Queue\storage_intent_payload($configuration), false);
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
	$intent_closed = !\CorsivoMail\Queue\runtime_ready(true);
	delete_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION);
	update_option(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, \CorsivoMail\Queue\storage_failure_payload($configuration, new WP_Error('fixture_failure')), false);
	wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, 'options');
	$failure_closed = !\CorsivoMail\Queue\runtime_ready(true);
	corsivo_mail_storage_check(
		$intent_closed && $failure_closed,
		'Intent o failure presenti non chiudono il gate caldo.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('populated-adoption');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$wpdb->query($wpdb->prepare(
		"INSERT INTO {$table} (recipient, subject, body, headers, attachments, embeds, scheduled_at, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)",
		'preserve@example.invalid',
		'Preserve',
		'ciphertext-fixture',
		'[]',
		'[]',
		'[]',
		current_time('mysql'),
		current_time('mysql')
	));
	$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$row = $wpdb->get_row("SELECT recipient, subject FROM {$table} LIMIT 1", ARRAY_A);
	corsivo_mail_storage_check(
		$ready
			&& is_array($row)
			&& $row['subject'] === 'Preserve'
			&& \CorsivoMail\Queue\storage_marker_is_valid(\CorsivoMail\Queue\storage_attestation($configuration), $configuration),
		'Uno schema exact popolato non viene riattestato senza mutare i dati.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('resume-missing');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	$intent = \CorsivoMail\Queue\persist_storage_intent($configuration);
	$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$snapshot = \CorsivoMail\Queue\schema_snapshot($table);
	corsivo_mail_storage_check(
		is_array($intent)
			&& \CorsivoMail\Queue\storage_intent_is_valid($intent, $configuration)
			&& $ready
			&& \CorsivoMail\Queue\storage_schema_matches($snapshot, $configuration)
			&& \CorsivoMail\Queue\storage_intent($configuration) === null,
		'Il resume con intent exact non crea la tabella mancante.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('resume-existing');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$intent = \CorsivoMail\Queue\persist_storage_intent($configuration);
	$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$marker = \CorsivoMail\Queue\storage_attestation($configuration);
	corsivo_mail_storage_check(
		is_array($intent)
			&& $ready
			&& \CorsivoMail\Queue\storage_marker_is_valid($marker, $configuration)
			&& \CorsivoMail\Queue\storage_intent($configuration) === null,
		'Il resume su tabella esistente non finalizza con l’intent atteso.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('corrupt-existing-intent');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$corrupt_intent = array('intent_format' => 1, 'opaque' => 'preserve');
	update_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, $corrupt_intent, false);
	wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
	$raw_before = $wpdb->get_var($wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
		\CorsivoMail\Queue\INSTALL_INTENT_OPTION
	));
	$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	$raw_after = $wpdb->get_var($wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
		\CorsivoMail\Queue\INSTALL_INTENT_OPTION
	));
	$marker = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	$failure = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION);
	corsivo_mail_storage_check(
		!$ready
			&& is_string($raw_before)
			&& hash_equals($raw_before, (string) $raw_after)
			&& !$marker['exists']
			&& !$failure['exists'],
		'Un intent corrotto su tabella esistente viene adottato o mutato.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('changed-existing-intent');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$expected_intent = \CorsivoMail\Queue\storage_intent_payload($configuration);
	$changed_intent = \CorsivoMail\Queue\storage_intent_payload($configuration);
	update_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, $changed_intent, false);
	wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
	$raw_before = $wpdb->get_var($wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
		\CorsivoMail\Queue\INSTALL_INTENT_OPTION
	));
	$finalized = \CorsivoMail\Queue\finalize_current_storage(
		$configuration,
		array('exists' => true, 'value' => $expected_intent),
		array('exists' => false, 'value' => null)
	);
	$raw_after = $wpdb->get_var($wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
		\CorsivoMail\Queue\INSTALL_INTENT_OPTION
	));
	$marker = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	corsivo_mail_storage_check(
		is_wp_error($finalized)
			&& $finalized->get_error_code() === 'corsivo_mail_storage_finalize_unfenced'
			&& is_string($raw_before)
			&& hash_equals($raw_before, (string) $raw_after)
			&& !$marker['exists'],
		'Finalize adotta o cancella un intent cambiato rispetto al fence atteso.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('corrupt-existing-failure');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	foreach (array(
		null,
		array('failure_format' => 1, 'opaque' => 'preserve'),
	) as $corrupt_failure) {
		corsivo_mail_storage_reset_options();
		corsivo_mail_storage_force_option(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, $corrupt_failure);
		$raw_before = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION
		));
		$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
		$raw_after = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION
		));
		$failure = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION);
		$marker = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
		corsivo_mail_storage_check(
			!$ready
				&& is_string($raw_before)
				&& hash_equals($raw_before, (string) $raw_after)
				&& $failure['exists']
				&& $failure['value'] === $corrupt_failure
				&& !$marker['exists'],
			'Una failure nulla o corrotta su tabella esatta viene cancellata o adottata.',
			$passes,
			$failures
		);
	}
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('empty-corrupt-artifacts');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	foreach (array(
		array(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, null),
		array(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, array('intent_format' => 1, 'opaque' => 'preserve')),
		array(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, null),
		array(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION, array('failure_format' => 1, 'opaque' => 'preserve')),
		array(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, null),
		array(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, array('marker_format' => 1, 'opaque' => 'preserve')),
		array(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION, '0'),
	) as $artifact_case) {
		corsivo_mail_storage_reset_options();
		corsivo_mail_storage_force_option($artifact_case[0], $artifact_case[1]);
		$records_before = array(
			\CorsivoMail\Queue\option_record(\CorsivoMail\Queue\INSTALL_INTENT_OPTION),
			\CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION),
			\CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION),
		);
		$ddl_queries = array();
		$ddl_observer = static function ($query) use (&$ddl_queries) {
			if (preg_match('/^\s*(?:CREATE|ALTER|DROP)\b/i', $query)) {
				$ddl_queries[] = $query;
			}
			return $query;
		};
		add_filter('query', $ddl_observer, PHP_INT_MAX);
		try {
			$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration, true);
		} finally {
			remove_filter('query', $ddl_observer, PHP_INT_MAX);
		}
		$records_after = array(
			\CorsivoMail\Queue\option_record(\CorsivoMail\Queue\INSTALL_INTENT_OPTION),
			\CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION),
			\CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION),
		);
		corsivo_mail_storage_check(
			!$ready
				&& $records_after === $records_before
				&& $ddl_queries === array(),
			'Un artefatto nullo, corrotto o opaco autorizza DDL o viene mutato su namespace vuoto.',
			$passes,
			$failures
		);
	}
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('successor-intent-fence');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	corsivo_mail_storage_create_exact($configuration);
	$expected_intent = \CorsivoMail\Queue\storage_intent_payload($configuration);
	$successor_intent = \CorsivoMail\Queue\storage_intent_payload($configuration);
	update_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, $expected_intent, false);
	wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
	$successor_swapped = false;
	$successor_filter = static function ($query) use (&$successor_swapped, $successor_intent) {
		if (!$successor_swapped
			&& preg_match('/^\s*DELETE\s+FROM\b/i', $query)
			&& strpos($query, \CorsivoMail\Queue\INSTALL_INTENT_OPTION) !== false) {
			$successor_swapped = true;
			update_option(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, $successor_intent, false);
			wp_cache_delete(\CorsivoMail\Queue\INSTALL_INTENT_OPTION, 'options');
		}
		return $query;
	};
	add_filter('query', $successor_filter, PHP_INT_MAX);
	try {
		$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	} finally {
		remove_filter('query', $successor_filter, PHP_INT_MAX);
	}
	$stored_intent = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\INSTALL_INTENT_OPTION);
	$stored_failure = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_FAILURE_OPTION);
	$stored_marker = \CorsivoMail\Queue\option_record(\CorsivoMail\Queue\SCHEMA_ATTESTATION_OPTION);
	corsivo_mail_storage_check(
		!$ready
			&& $successor_swapped
			&& $stored_intent['exists']
			&& $stored_intent['value'] === $successor_intent
			&& !$stored_failure['exists']
			&& $stored_marker['exists']
			&& \CorsivoMail\Queue\storage_marker_is_valid($stored_marker['value'], $configuration)
			&& !\CorsivoMail\Queue\runtime_ready(true),
		'La finalizzazione cancella un intent successore o persiste un failure dopo la perdita del fence.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

try {
	$scenario_prefix = corsivo_mail_storage_prefix('partial-preserved');
	$table = $scenario_prefix . 'corsivo_mail_queue';
	corsivo_mail_storage_reset_options();
	corsivo_mail_storage_drop_fixture($table);
	$configuration = \CorsivoMail\Queue\current_storage_configuration();
	$intent = \CorsivoMail\Queue\persist_storage_intent($configuration);
	$wpdb->query("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, payload VARCHAR(64) NOT NULL DEFAULT '', PRIMARY KEY (id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC");
	$wpdb->insert($table, array('payload' => 'preserve-me'), array('%s'));
	$before = \CorsivoMail\Queue\schema_snapshot($table);
	$storage_query_events = array();
	$storage_query_observer = static function ($query) use (&$storage_query_events) {
		if (preg_match('/^\s*(?:INSERT|UPDATE|REPLACE)\b/i', $query)
			&& strpos($query, \CorsivoMail\Queue\SCHEMA_FAILURE_OPTION) !== false) {
			$storage_query_events[] = 'failure_write';
		}
		if (stripos($query, 'RELEASE_LOCK(') !== false) {
			$storage_query_events[] = 'lock_release';
		}
		return $query;
	};
	add_filter('query', $storage_query_observer, PHP_INT_MAX);
	try {
		$ready = \CorsivoMail\Queue\ensure_fresh_storage($configuration);
	} finally {
		remove_filter('query', $storage_query_observer, PHP_INT_MAX);
	}
	$after = \CorsivoMail\Queue\schema_snapshot($table);
	$row = $wpdb->get_row("SELECT * FROM {$table} WHERE id = 1", ARRAY_A);
	corsivo_mail_storage_check(
		is_array($intent)
			&& !$ready
			&& is_array($row)
			&& $row['payload'] === 'preserve-me'
			&& $before['fingerprint'] === $after['fingerprint']
			&& corsivo_mail_storage_failure_code($configuration) === 'corsivo_mail_current_storage_incompatible'
			&& !\CorsivoMail\Queue\runtime_ready(true),
		'Uno schema parziale viene modificato o cancellato.',
		$passes,
		$failures
	);
	$failure_write_position = array_search('failure_write', $storage_query_events, true);
	$lock_release_position = array_search('lock_release', $storage_query_events, true);
	corsivo_mail_storage_check(
		$failure_write_position !== false
			&& $lock_release_position !== false
			&& $failure_write_position < $lock_release_position,
		'La failure storage viene persistita dopo RELEASE_LOCK.',
		$passes,
		$failures
	);
} finally {
	corsivo_mail_storage_drop_fixture($table);
	corsivo_mail_storage_reset_options();
}

$wpdb->prefix = $original_prefix;
foreach ($original_options as $option_name => $record) {
	corsivo_mail_storage_restore_option($option_name, $record);
}
corsivo_mail_storage_restore_option('cron', $original_cron);
unset($GLOBALS['corsivo_mail_storage_original_prefix']);
$GLOBALS['corsivo_mail_storage_gate_invalidated'] = array();
$GLOBALS['corsivo_mail_storage_readiness_cache'] = array();

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
