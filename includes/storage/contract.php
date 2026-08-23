<?php

namespace CorsivoMail\Queue;

if (!defined('ABSPATH')) {
	exit;
}

const STORAGE_MARKER_FORMAT = 1;
const STORAGE_INTENT_FORMAT = 1;
const STORAGE_FAILURE_FORMAT = 1;
const STORAGE_SCOPE = 'corsivo-mail:storage';
const STORAGE_UPDATE_VERIFY_OPTION = 'corsivo_mail_storage_update_verification';

function quoted_table($table) {
	return '`' . str_replace('`', '``', $table) . '`';
}

function option_record($option_name) {
	global $wpdb;
	$wpdb->last_error = '';
	$row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name), ARRAY_A);
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_option_read_failed', 'Impossibile leggere lo stato dello storage Mail.');
	}
	return is_array($row)
		? array('exists' => true, 'value' => maybe_unserialize($row['option_value']))
		: array('exists' => false, 'value' => null);
}

function hot_option_record($option_name) {
	$missing = new \stdClass();
	$value = get_option($option_name, $missing);
	return $value === $missing
		? array('exists' => false, 'value' => null)
		: array('exists' => true, 'value' => $value);
}

function persist_option_verified($option_name, $value) {
	$record = option_record($option_name);
	if (is_wp_error($record)) {
		return $record;
	}
	if ($record['exists']) {
		update_option($option_name, $value, false);
	} else {
		add_option($option_name, $value, '', false);
	}
	wp_cache_delete($option_name, 'options');
	wp_cache_delete('alloptions', 'options');
	$stored = option_record($option_name);
	if (is_wp_error($stored)) {
		return $stored;
	}
	return $stored['exists'] && $stored['value'] === $value
		? true
		: new \WP_Error('corsivo_mail_option_write_failed', 'Impossibile persistere e rileggere lo stato dello storage Mail.');
}

function delete_option_verified($option_name) {
	delete_option($option_name);
	wp_cache_delete($option_name, 'options');
	wp_cache_delete('alloptions', 'options');
	$record = option_record($option_name);
	if (is_wp_error($record)) {
		return $record;
	}
	return !$record['exists']
		? true
		: new \WP_Error('corsivo_mail_option_delete_failed', 'Impossibile rimuovere lo stato dello storage Mail.');
}

function delete_option_verified_cas($option_name, $expected_record) {
	global $wpdb;

	$current = option_record($option_name);
	if (is_wp_error($current)) {
		return $current;
	}
	if ($current !== $expected_record) {
		return new \WP_Error('corsivo_mail_storage_finalize_unfenced', 'Lo stato transitorio dello storage Mail è cambiato prima della finalizzazione.');
	}
	if (!$expected_record['exists']) {
		return true;
	}

	$wpdb->last_error = '';
	$deleted = $wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
		$option_name,
		maybe_serialize($expected_record['value'])
	));
	wp_cache_delete($option_name, 'options');
	wp_cache_delete('alloptions', 'options');
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_option_delete_failed', 'Impossibile rimuovere lo stato dello storage Mail.');
	}
	if ((int) $deleted !== 1) {
		return new \WP_Error('corsivo_mail_storage_finalize_unfenced', 'Lo stato transitorio dello storage Mail è cambiato durante la finalizzazione.');
	}

	$verified = option_record($option_name);
	if (is_wp_error($verified)) {
		return $verified;
	}
	return !$verified['exists']
		? true
		: new \WP_Error('corsivo_mail_storage_finalize_unfenced', 'Lo stato transitorio dello storage Mail è cambiato durante la finalizzazione.');
}

function storage_update_verification_record($hot = false) {
	return $hot
		? hot_option_record(STORAGE_UPDATE_VERIFY_OPTION)
		: option_record(STORAGE_UPDATE_VERIFY_OPTION);
}

function storage_update_verification_required() {
	$record = storage_update_verification_record(true);
	return $record['exists'];
}

function persist_storage_update_verification() {
	global $wpdb;

	$token = wp_generate_uuid4();
	$wpdb->last_error = '';
	$written = $wpdb->query($wpdb->prepare(
		"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
		VALUES (%s, %s, %s)
		ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
		STORAGE_UPDATE_VERIFY_OPTION,
		$token,
		'no'
	));
	wp_cache_delete(STORAGE_UPDATE_VERIFY_OPTION, 'options');
	wp_cache_delete('alloptions', 'options');
	wp_cache_delete('notoptions', 'options');
	if ($written === false || $wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_storage_update_fence_write_failed', 'Il fence di verifica storage Mail non può essere persistito.');
	}
	$stored = storage_update_verification_record();
	if (is_wp_error($stored)) {
		return $stored;
	}
	return $stored['exists'] && $stored['value'] === $token
		? $token
		: new \WP_Error('corsivo_mail_storage_update_fence_changed', 'Il fence di verifica storage Mail è cambiato.');
}

function clear_storage_update_verification($expected_token) {
	global $wpdb;

	if (!storage_uuid4_is_valid($expected_token)) {
		return new \WP_Error('corsivo_mail_storage_update_fence_invalid', 'Il fence di verifica storage Mail non è valido.');
	}
	$wpdb->last_error = '';
	$deleted = $wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		WHERE option_name = %s AND option_value = %s",
		STORAGE_UPDATE_VERIFY_OPTION,
		$expected_token
	));
	wp_cache_delete(STORAGE_UPDATE_VERIFY_OPTION, 'options');
	wp_cache_delete('alloptions', 'options');
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_storage_update_fence_delete_failed', 'Il fence di verifica storage Mail non può essere rimosso.');
	}
	if ($deleted === 1) {
		return true;
	}
	$current = storage_update_verification_record();
	if (is_wp_error($current)) {
		return $current;
	}
	return !$current['exists']
		? true
		: new \WP_Error('corsivo_mail_storage_update_fence_changed', 'Il fence di verifica storage Mail è cambiato.');
}

function canonicalize_identity($value) {
	if (!is_array($value)) {
		return $value;
	}
	$is_list = array_keys($value) === range(0, count($value) - 1);
	if (!$is_list) {
		ksort($value, SORT_STRING);
	}
	foreach ($value as $key => $entry) {
		$value[$key] = canonicalize_identity($entry);
	}
	return $value;
}

function encode_identity($identity) {
	$encoded = wp_json_encode(canonicalize_identity($identity), JSON_UNESCAPED_SLASHES);
	return is_string($encoded) ? $encoded : '';
}

function normalize_column_type($type) {
	$type = strtolower(trim((string) $type));
	$type = preg_replace('/\s+/', ' ', $type);
	return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
}

function storage_charset() {
	global $wpdb;
	return strtolower(trim((string) ($wpdb->charset ?? '')));
}

function storage_collation() {
	global $wpdb;
	return strtolower(trim((string) ($wpdb->collate ?? '')));
}

function column_uses_collation($type) {
	return preg_match('/^(?:char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/', normalize_column_type($type)) === 1;
}

function column_definition($type, $nullable, $default, $extra = '', $attributes = array()) {
	$uses_collation = column_uses_collation($type);
	return array(
		'type' => normalize_column_type($type),
		'nullable' => (bool) $nullable,
		'default' => $default === null ? null : (string) $default,
		'extra' => strtolower(trim(preg_replace('/\s+/', ' ', (string) $extra))),
		'charset' => strtolower((string) ($attributes['charset'] ?? ($uses_collation ? storage_charset() : ''))),
		'collation' => strtolower((string) ($attributes['collation'] ?? ($uses_collation ? storage_collation() : ''))),
		'comment' => (string) ($attributes['comment'] ?? ''),
		'generation_expression' => trim((string) ($attributes['generation_expression'] ?? '')),
	);
}

function normalize_index_definition($index) {
	$columns = array_values((array) ($index['columns'] ?? array()));
	$count = count($columns);
	return array(
		'unique' => !empty($index['unique']),
		'type' => strtolower((string) ($index['type'] ?? 'btree')),
		'comment' => (string) ($index['comment'] ?? ''),
		'visible' => !array_key_exists('visible', $index) || $index['visible'] === true,
		'parser' => (string) ($index['parser'] ?? ''),
		'columns' => $columns,
		'sub_parts' => array_values($index['sub_parts'] ?? array_fill(0, $count, null)),
		'orders' => array_values($index['orders'] ?? array_fill(0, $count, 'A')),
		'expressions' => array_values($index['expressions'] ?? array_fill(0, $count, null)),
	);
}

function normalize_create_options($options) {
	$parts = preg_split('/\s+/', strtolower(trim(str_replace(',', ' ', (string) $options))), -1, PREG_SPLIT_NO_EMPTY);
	$parts = array_values(array_filter($parts, static function ($part) {
		return strpos($part, 'row_format=') !== 0;
	}));
	sort($parts, SORT_STRING);
	return $parts;
}

function storage_context($configuration) {
	global $wpdb;
	return array(
		'blog_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
		'database' => (string) ($wpdb->dbname ?? ''),
		'table' => (string) $configuration['table'],
	);
}

function expected_constraints($indexes) {
	$constraints = array();
	foreach ($indexes as $name => $index) {
		$index = normalize_index_definition($index);
		if (!$index['unique']) {
			continue;
		}
		$constraints[(string) $name] = array(
			'type' => $name === 'PRIMARY' ? 'primary key' : 'unique',
			'columns' => $index['columns'],
			'references' => array_fill(0, count($index['columns']), null),
			'match' => '',
			'update_rule' => '',
			'delete_rule' => '',
			'check_clause' => '',
		);
	}
	ksort($constraints, SORT_STRING);
	return $constraints;
}

function expected_storage_descriptor($configuration) {
	$columns = array();
	foreach ((array) $configuration['columns'] as $name => $column) {
		$columns[(string) $name] = column_definition(
			$column['type'] ?? '',
			!empty($column['nullable']),
			array_key_exists('default', $column) ? $column['default'] : null,
			$column['extra'] ?? '',
			$column
		);
	}
	ksort($columns, SORT_STRING);
	$indexes = array();
	foreach ((array) $configuration['indexes'] as $name => $index) {
		$indexes[(string) $name] = normalize_index_definition($index);
	}
	ksort($indexes, SORT_STRING);
	return array(
		'engine' => 'innodb',
		'row_format' => 'dynamic',
		'charset' => storage_charset(),
		'collation' => storage_collation(),
		'comment' => '',
		'create_options' => array(),
		'columns' => $columns,
		'indexes' => $indexes,
		'constraints' => expected_constraints($indexes),
	);
}

function storage_source_fingerprint($configuration) {
	$identity = array(
		'code_generation' => defined('CORSIVO_MAIL_VERSION') ? (string) CORSIVO_MAIL_VERSION : 'standalone',
		'domain' => (string) $configuration['domain'],
		'table_suffix' => preg_replace('/^.*(?=corsivo_mail_)/', '', (string) $configuration['table']),
		'physical' => expected_storage_descriptor($configuration),
		'identity' => $configuration['identity'] ?? array(),
	);
	return hash('sha256', encode_identity($identity));
}

function table_presence($table) {
	global $wpdb;
	$wpdb->last_error = '';
	$found = $wpdb->get_var($wpdb->prepare(
		'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
		$table
	));
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_storage_inspection_failed', 'Impossibile verificare la presenza dello storage Mail.');
	}
	return is_string($found) && $found === $table;
}

function table_exists_named($table) {
	return table_presence($table) === true;
}

function canonical_storage_tables() {
	global $wpdb;
	$tables = array(
		$wpdb->prefix . 'corsivo_mail_log',
		$wpdb->prefix . 'corsivo_mail_messages',
		$wpdb->prefix . 'corsivo_mail_queue',
	);
	sort($tables, SORT_STRING);
	return $tables;
}

function storage_namespace_inventory() {
	global $wpdb;
	$prefix = $wpdb->prefix . 'corsivo_mail_';
	$wpdb->last_error = '';
	$tables = $wpdb->get_col($wpdb->prepare(
		'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME ASC',
		$wpdb->esc_like($prefix) . '%'
	));
	if ($wpdb->last_error !== '' || !is_array($tables)) {
		return new \WP_Error('corsivo_mail_namespace_inspection_failed', 'Impossibile verificare l’inventario dello storage Mail.');
	}
	return array_values(array_map('strval', $tables));
}

function storage_namespace_is_canonical($inventory) {
	if (!is_array($inventory)) {
		return false;
	}
	$canonical = canonical_storage_tables();
	$normalized = array_values(array_unique(array_map('strval', $inventory)));
	sort($normalized, SORT_STRING);
	return $normalized === $inventory && array_diff($normalized, $canonical) === array();
}

function schema_snapshot($table = null) {
	global $wpdb;
	$table = $table ?: table_name();
	$present = table_presence($table);
	if (is_wp_error($present)) {
		return $present;
	}
	if (!$present) {
		return array(
			'exists' => false,
			'descriptor' => array(),
			'engine' => '',
			'columns' => array(),
			'indexes' => array(),
			'constraints' => array(),
			'row_count' => 0,
			'fingerprint' => hash('sha256', encode_identity(array('exists' => false))),
		);
	}

	$wpdb->last_error = '';
	$table_row = $wpdb->get_row($wpdb->prepare(
		'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION, TABLE_COMMENT, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
		$table
	), ARRAY_A);
	$column_rows = $wpdb->get_results($wpdb->prepare(
		'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION ASC',
		$table
	), ARRAY_A);
	$index_rows = $wpdb->get_results('SHOW INDEX FROM ' . quoted_table($table), ARRAY_A);
	$constraint_rows = $wpdb->get_results($wpdb->prepare(
		'SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, kcu.ORDINAL_POSITION, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_SCHEMA, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME FROM information_schema.TABLE_CONSTRAINTS tc LEFT JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME = tc.TABLE_NAME AND kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s ORDER BY tc.CONSTRAINT_NAME ASC, kcu.ORDINAL_POSITION ASC',
		$table
	), ARRAY_A);
	$reference_rows = $wpdb->get_results($wpdb->prepare(
		'SELECT CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY CONSTRAINT_NAME ASC',
		$table
	), ARRAY_A);
	if ($wpdb->last_error !== '' || !is_array($table_row) || !is_array($column_rows) || !is_array($index_rows) || !is_array($constraint_rows) || !is_array($reference_rows)) {
		return new \WP_Error('corsivo_mail_storage_inspection_failed', 'Impossibile ispezionare lo schema dello storage Mail.');
	}

	$columns = array();
	foreach ($column_rows as $row) {
		$columns[(string) $row['COLUMN_NAME']] = column_definition(
			$row['COLUMN_TYPE'],
			strtoupper((string) $row['IS_NULLABLE']) === 'YES',
			$row['COLUMN_DEFAULT'],
			$row['EXTRA'],
			array(
				'charset' => $row['CHARACTER_SET_NAME'],
				'collation' => $row['COLLATION_NAME'],
				'comment' => $row['COLUMN_COMMENT'],
				'generation_expression' => $row['GENERATION_EXPRESSION'],
			)
		);
	}
	ksort($columns, SORT_STRING);

	$indexes = array();
	foreach ($index_rows as $row) {
		$name = (string) $row['Key_name'];
		$sequence = max(1, (int) $row['Seq_in_index']) - 1;
		if (!isset($indexes[$name])) {
			$visible = isset($row['Visible'])
				? strtoupper((string) $row['Visible']) !== 'NO'
				: strtoupper((string) ($row['Ignored'] ?? 'NO')) !== 'YES';
			$indexes[$name] = array(
				'unique' => (int) $row['Non_unique'] === 0,
				'type' => strtolower((string) ($row['Index_type'] ?? 'btree')),
				'comment' => (string) ($row['Index_comment'] ?? ''),
				'visible' => $visible,
				'parser' => (string) ($row['Parser'] ?? ''),
				'columns' => array(),
				'sub_parts' => array(),
				'orders' => array(),
				'expressions' => array(),
			);
		}
		$indexes[$name]['columns'][$sequence] = $row['Column_name'] === null ? null : (string) $row['Column_name'];
		$indexes[$name]['sub_parts'][$sequence] = $row['Sub_part'] === null ? null : (int) $row['Sub_part'];
		$indexes[$name]['orders'][$sequence] = strtoupper((string) ($row['Collation'] ?? 'A')) ?: 'A';
		$indexes[$name]['expressions'][$sequence] = isset($row['Expression']) && $row['Expression'] !== null ? (string) $row['Expression'] : null;
	}
	foreach ($indexes as &$index) {
		foreach (array('columns', 'sub_parts', 'orders', 'expressions') as $field) {
			ksort($index[$field]);
			$index[$field] = array_values($index[$field]);
		}
	}
	unset($index);
	ksort($indexes, SORT_STRING);

	$references = array();
	foreach ($reference_rows as $row) {
		$references[(string) $row['CONSTRAINT_NAME']] = array(
			'match' => strtolower((string) $row['MATCH_OPTION']),
			'update_rule' => strtolower((string) $row['UPDATE_RULE']),
			'delete_rule' => strtolower((string) $row['DELETE_RULE']),
		);
	}
	$constraints = array();
	$has_checks = false;
	foreach ($constraint_rows as $row) {
		$name = (string) $row['CONSTRAINT_NAME'];
		if (!isset($constraints[$name])) {
			$type = strtolower((string) $row['CONSTRAINT_TYPE']);
			$constraints[$name] = array(
				'type' => $type,
				'columns' => array(),
				'references' => array(),
				'match' => $references[$name]['match'] ?? '',
				'update_rule' => $references[$name]['update_rule'] ?? '',
				'delete_rule' => $references[$name]['delete_rule'] ?? '',
				'check_clause' => '',
			);
			$has_checks = $has_checks || $type === 'check';
		}
		if ($row['COLUMN_NAME'] !== null) {
			$position = max(1, (int) $row['ORDINAL_POSITION']) - 1;
			$constraints[$name]['columns'][$position] = (string) $row['COLUMN_NAME'];
			$constraints[$name]['references'][$position] = $row['REFERENCED_TABLE_NAME'] === null
				? null
				: array(
					'schema' => (string) $row['REFERENCED_TABLE_SCHEMA'],
					'table' => (string) $row['REFERENCED_TABLE_NAME'],
					'column' => (string) $row['REFERENCED_COLUMN_NAME'],
				);
		}
	}
	if ($has_checks) {
		$wpdb->last_error = '';
		$check_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_TYPE = 'CHECK')",
			$table
		), ARRAY_A);
		if ($wpdb->last_error !== '' || !is_array($check_rows)) {
			return new \WP_Error('corsivo_mail_storage_inspection_failed', 'Impossibile ispezionare i vincoli dello storage Mail.');
		}
		foreach ($check_rows as $row) {
			$name = (string) $row['CONSTRAINT_NAME'];
			if (isset($constraints[$name])) {
				$constraints[$name]['check_clause'] = trim((string) $row['CHECK_CLAUSE']);
			}
		}
	}
	foreach ($constraints as &$constraint) {
		ksort($constraint['columns']);
		ksort($constraint['references']);
		$constraint['columns'] = array_values($constraint['columns']);
		$constraint['references'] = array_values($constraint['references']);
	}
	unset($constraint);
	ksort($constraints, SORT_STRING);

	$collation = strtolower((string) $table_row['TABLE_COLLATION']);
	$charset = $collation === '' ? '' : explode('_', $collation, 2)[0];
	$descriptor = array(
		'engine' => strtolower((string) $table_row['ENGINE']),
		'row_format' => strtolower((string) $table_row['ROW_FORMAT']),
		'charset' => $charset,
		'collation' => $collation,
		'comment' => (string) $table_row['TABLE_COMMENT'],
		'create_options' => normalize_create_options($table_row['CREATE_OPTIONS']),
		'columns' => $columns,
		'indexes' => $indexes,
		'constraints' => $constraints,
	);
	$wpdb->last_error = '';
	$row_count = $wpdb->get_var('SELECT COUNT(*) FROM ' . quoted_table($table));
	if ($wpdb->last_error !== '' || !is_numeric($row_count)) {
		return new \WP_Error('corsivo_mail_storage_count_failed', 'Impossibile contare le righe dello storage Mail.');
	}
	return array(
		'exists' => true,
		'descriptor' => $descriptor,
		'engine' => $descriptor['engine'],
		'columns' => $columns,
		'indexes' => $indexes,
		'constraints' => $constraints,
		'row_count' => (int) $row_count,
		'fingerprint' => hash('sha256', encode_identity($descriptor)),
	);
}

function storage_schema_matches($snapshot, $configuration) {
	return is_array($snapshot)
		&& !empty($snapshot['exists'])
		&& ($snapshot['descriptor'] ?? null) === expected_storage_descriptor($configuration);
}

function storage_marker_payload($configuration, $snapshot) {
	return array(
		'kind' => 'verified',
		'marker_format' => STORAGE_MARKER_FORMAT,
		'context' => storage_context($configuration),
		'physical_fingerprint' => (string) $snapshot['fingerprint'],
		'scope' => STORAGE_SCOPE,
		'source_fingerprint' => storage_source_fingerprint($configuration),
		'verified_at' => gmdate('Y-m-d H:i:s'),
	);
}

function storage_intent_payload($configuration) {
	return array(
		'intent_format' => STORAGE_INTENT_FORMAT,
		'kind' => 'fresh',
		'context' => storage_context($configuration),
		'operation_id' => wp_generate_uuid4(),
		'scope' => STORAGE_SCOPE,
		'source_fingerprint' => storage_source_fingerprint($configuration),
		'started_at' => gmdate('Y-m-d H:i:s'),
		'token' => bin2hex(random_bytes(32)),
	);
}

function storage_failure_payload($configuration, $error) {
	$code = is_wp_error($error) ? (string) $error->get_error_code() : 'corsivo_mail_storage_failed';
	$code = function_exists('sanitize_key') ? sanitize_key($code) : preg_replace('/[^a-z0-9_\-]/', '', strtolower($code));
	return array(
		'failure_format' => STORAGE_FAILURE_FORMAT,
		'kind' => 'failure',
		'context' => storage_context($configuration),
		'scope' => STORAGE_SCOPE,
		'source_fingerprint' => storage_source_fingerprint($configuration),
		'code' => substr($code ?: 'corsivo_mail_storage_failed', 0, 96),
		'failed_at' => gmdate('Y-m-d H:i:s'),
	);
}

function storage_timestamp_is_valid($value) {
	return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1;
}

function storage_uuid4_is_valid($value) {
	return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value) === 1;
}

function artifact_has_future_format($value, $format_key) {
	if (!is_array($value) || !array_key_exists($format_key, $value)) {
		return false;
	}
	$format = $value[$format_key];
	if (is_int($format)) {
		return $format > 1;
	}
	return is_string($format) && preg_match('/^\d+$/D', $format) === 1 && (int) $format > 1;
}

function storage_marker_is_valid($value, $configuration, $require_current_source = true) {
	$keys = array('kind', 'marker_format', 'context', 'physical_fingerprint', 'scope', 'source_fingerprint', 'verified_at');
	if (!is_array($value) || array_keys($value) !== $keys) {
		return false;
	}
	if ($value['kind'] !== 'verified'
		|| $value['marker_format'] !== STORAGE_MARKER_FORMAT
		|| $value['context'] !== storage_context($configuration)
		|| $value['scope'] !== STORAGE_SCOPE
		|| !is_string($value['physical_fingerprint'])
		|| preg_match('/^[a-f0-9]{64}$/D', $value['physical_fingerprint']) !== 1
		|| !is_string($value['source_fingerprint'])
		|| preg_match('/^[a-f0-9]{64}$/D', $value['source_fingerprint']) !== 1
		|| !storage_timestamp_is_valid($value['verified_at'])) {
		return false;
	}
	return !$require_current_source || hash_equals(storage_source_fingerprint($configuration), $value['source_fingerprint']);
}

function storage_intent_is_valid($value, $configuration) {
	$keys = array('intent_format', 'kind', 'context', 'operation_id', 'scope', 'source_fingerprint', 'started_at', 'token');
	return is_array($value)
		&& array_keys($value) === $keys
		&& $value['intent_format'] === STORAGE_INTENT_FORMAT
		&& $value['kind'] === 'fresh'
		&& $value['context'] === storage_context($configuration)
		&& storage_uuid4_is_valid($value['operation_id'])
		&& $value['scope'] === STORAGE_SCOPE
		&& is_string($value['source_fingerprint'])
		&& hash_equals(storage_source_fingerprint($configuration), $value['source_fingerprint'])
		&& storage_timestamp_is_valid($value['started_at'])
		&& is_string($value['token'])
		&& preg_match('/^[a-f0-9]{64}$/D', $value['token']) === 1;
}

function storage_failure_is_valid($value, $configuration) {
	$keys = array('failure_format', 'kind', 'context', 'scope', 'source_fingerprint', 'code', 'failed_at');
	return is_array($value)
		&& array_keys($value) === $keys
		&& $value['failure_format'] === STORAGE_FAILURE_FORMAT
		&& $value['kind'] === 'failure'
		&& $value['context'] === storage_context($configuration)
		&& $value['scope'] === STORAGE_SCOPE
		&& is_string($value['source_fingerprint'])
		&& hash_equals(storage_source_fingerprint($configuration), $value['source_fingerprint'])
		&& is_string($value['code'])
		&& $value['code'] !== ''
		&& strlen($value['code']) <= 96
		&& storage_timestamp_is_valid($value['failed_at']);
}

function storage_intent($configuration) {
	$record = option_record($configuration['intent_option']);
	if (is_wp_error($record)) {
		return $record;
	}
	if (!$record['exists']) {
		return null;
	}
	return storage_intent_is_valid($record['value'], $configuration)
		? $record['value']
		: new \WP_Error('corsivo_mail_install_intent_incompatible', 'L’intent di installazione non appartiene allo storage corrente.');
}

function storage_attestation($configuration) {
	$record = option_record($configuration['attestation_option']);
	if (is_wp_error($record)) {
		return $record;
	}
	if (!$record['exists']) {
		return null;
	}
	return storage_marker_is_valid($record['value'], $configuration, true)
		? $record['value']
		: new \WP_Error('corsivo_mail_storage_attestation_incompatible', 'L’attestazione non appartiene allo storage corrente.');
}

function persist_storage_intent($configuration) {
	$intent = storage_intent_payload($configuration);
	$persisted = persist_option_verified($configuration['intent_option'], $intent);
	return is_wp_error($persisted) ? $persisted : $intent;
}

function storage_failure($configuration) {
	$record = option_record($configuration['failure_option']);
	if (is_wp_error($record)) {
		return $record;
	}
	return $record['exists'] ? $record['value'] : null;
}

function clear_storage_failure($configuration) {
	$record = option_record($configuration['failure_option']);
	if (is_wp_error($record)) {
		return $record;
	}
	return $record['exists'] ? delete_option_verified($configuration['failure_option']) : true;
}

function storage_cache_key($configuration) {
	return hash('sha256', encode_identity(array(
		'domain' => $configuration['domain'],
		'context' => storage_context($configuration),
	)));
}

function invalidate_storage_gate($configuration) {
	$key = storage_cache_key($configuration);
	$GLOBALS['corsivo_mail_storage_gate_invalidated'][$key] = true;
	unset($GLOBALS['corsivo_mail_storage_readiness_cache'][$key]);
}

function reset_storage_gate($configuration) {
	$key = storage_cache_key($configuration);
	unset($GLOBALS['corsivo_mail_storage_gate_invalidated'][$key]);
	unset($GLOBALS['corsivo_mail_storage_readiness_cache'][$key]);
}

function schedule_storage_verification($reason = 'runtime', $delay = 1) {
	if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
		return false;
	}
	$args = array((string) $reason);
	if (wp_next_scheduled('corsivo_mail_verify_storage', $args)) {
		return true;
	}
	return wp_schedule_single_event(time() + max(1, (int) $delay), 'corsivo_mail_verify_storage', $args) !== false;
}

function storage_runtime_ready($configuration, $refresh = false, $ignore_update_verification = false) {
	$key = storage_cache_key($configuration);
	if ($refresh) {
		unset($GLOBALS['corsivo_mail_storage_readiness_cache'][$key]);
	}
	if (!$ignore_update_verification && storage_update_verification_required()) {
		$GLOBALS['corsivo_mail_storage_readiness_cache'][$key] = false;
		return false;
	}
	if (!empty($GLOBALS['corsivo_mail_storage_gate_invalidated'][$key])) {
		return false;
	}
	if (!$refresh && array_key_exists($key, $GLOBALS['corsivo_mail_storage_readiness_cache'] ?? array())) {
		return $GLOBALS['corsivo_mail_storage_readiness_cache'][$key];
	}

	$intent = hot_option_record($configuration['intent_option']);
	$failure = hot_option_record($configuration['failure_option']);
	if ($intent['exists'] || $failure['exists']) {
		$GLOBALS['corsivo_mail_storage_readiness_cache'][$key] = false;
		schedule_storage_verification();
		return false;
	}

	$marker = hot_option_record($configuration['attestation_option']);
	if (!$marker['exists']) {
		$GLOBALS['corsivo_mail_storage_readiness_cache'][$key] = false;
		schedule_storage_verification();
		return false;
	}
	if (artifact_has_future_format($marker['value'], 'marker_format')) {
		$GLOBALS['corsivo_mail_storage_readiness_cache'][$key] = false;
		return false;
	}

	if (!storage_marker_is_valid($marker['value'], $configuration, true)) {
		$GLOBALS['corsivo_mail_storage_readiness_cache'][$key] = false;
		schedule_storage_verification();
		return false;
	}

	$GLOBALS['corsivo_mail_storage_readiness_cache'][$key] = true;
	return true;
}

function storage_lock_name($configuration) {
	global $wpdb;
	$identity = implode("\0", array(
		(string) ($wpdb->dbname ?? ''),
		(string) ($wpdb->prefix ?? ''),
		(string) (function_exists('get_current_blog_id') ? get_current_blog_id() : 0),
		STORAGE_SCOPE,
	));
	return 'csm_' . substr(hash('sha256', $identity), 0, 60);
}

function acquire_storage_lock($configuration) {
	global $wpdb;
	$wpdb->last_error = '';
	$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', storage_lock_name($configuration), 5));
	if ($wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_storage_lock_failed', 'Il lock dello storage Mail non è disponibile.');
	}
	return (string) $acquired === '1'
		? true
		: new \WP_Error('corsivo_mail_storage_lock_contended', 'Lo storage Mail è già in verifica.');
}

function release_storage_lock($configuration) {
	global $wpdb;
	$wpdb->last_error = '';
	$released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', storage_lock_name($configuration)));
	return $wpdb->last_error === '' && (string) $released === '1';
}

function record_storage_failure($configuration, $error) {
	invalidate_storage_gate($configuration);
	$existing_failure = option_record($configuration['failure_option']);
	if (is_wp_error($existing_failure)) {
		return $existing_failure;
	}
	if ($existing_failure['exists'] && artifact_has_future_format($existing_failure['value'], 'failure_format')) {
		return new \WP_Error('corsivo_mail_storage_future_artifact', 'Lo stato storage appartiene a un formato futuro.');
	}
	$marker = option_record($configuration['attestation_option']);
	if (is_wp_error($marker)) {
		return $marker;
	}
	if ($marker['exists'] && artifact_has_future_format($marker['value'], 'marker_format')) {
		return new \WP_Error('corsivo_mail_storage_future_artifact', 'Lo stato storage appartiene a un formato futuro.');
	}
	if ($marker['exists']) {
		$deleted = delete_option_verified($configuration['attestation_option']);
		if (is_wp_error($deleted)) {
			return $deleted;
		}
	}
	return persist_option_verified($configuration['failure_option'], storage_failure_payload($configuration, $error));
}

function storage_physical_ready($configuration, $marker = null) {
	$inventory = storage_namespace_inventory();
	$snapshot = schema_snapshot($configuration['table']);
	if (is_wp_error($inventory) || is_wp_error($snapshot)) {
		return false;
	}
	if (!storage_namespace_is_canonical($inventory)
		|| !in_array($configuration['table'], $inventory, true)
		|| !storage_schema_matches($snapshot, $configuration)) {
		return false;
	}
	return $marker === null
		|| (storage_marker_is_valid($marker, $configuration, true)
			&& hash_equals($marker['physical_fingerprint'], $snapshot['fingerprint']));
}

function create_current_storage($configuration, $intent) {
	global $wpdb;
	$current_intent = storage_intent($configuration);
	if (!is_array($current_intent) || $current_intent !== $intent) {
		return new \WP_Error('corsivo_mail_storage_create_unfenced', 'Creazione dello storage Mail priva di un intent verificabile.');
	}
	$inventory_before = storage_namespace_inventory();
	if (is_wp_error($inventory_before)) {
		return $inventory_before;
	}
	if (!storage_namespace_is_canonical($inventory_before) || in_array($configuration['table'], $inventory_before, true)) {
		return new \WP_Error('corsivo_mail_storage_create_inventory_conflict', 'L’inventario Mail non consente la creazione dello storage.');
	}
	$current_intent = storage_intent($configuration);
	if (!is_array($current_intent) || $current_intent !== $intent) {
		return new \WP_Error('corsivo_mail_storage_create_unfenced', 'L’intent dello storage Mail è cambiato prima della creazione.');
	}

	$sql = rtrim((string) $configuration['sql'], " \t\n\r\0\x0B;");
	if (stripos($sql, 'ROW_FORMAT=') === false) {
		$sql .= ' ROW_FORMAT=DYNAMIC';
	}
	$wpdb->last_error = '';
	$created = $wpdb->query($sql);
	if ($created === false || $wpdb->last_error !== '') {
		return new \WP_Error('corsivo_mail_storage_create_failed', 'Creazione dello storage Mail non riuscita.');
	}

	$current_intent = storage_intent($configuration);
	if (!is_array($current_intent) || $current_intent !== $intent) {
		return new \WP_Error('corsivo_mail_storage_create_fence_lost', 'L’intent dello storage Mail è cambiato durante la creazione.');
	}
	$inventory_after = storage_namespace_inventory();
	if (is_wp_error($inventory_after)) {
		return $inventory_after;
	}
	$expected_inventory = $inventory_before;
	$expected_inventory[] = $configuration['table'];
	sort($expected_inventory, SORT_STRING);
	if (!storage_namespace_is_canonical($inventory_after) || $inventory_after !== $expected_inventory) {
		return new \WP_Error('corsivo_mail_storage_create_inventory_unverified', 'L’inventario Mail è cambiato durante la creazione dello storage.');
	}
	$snapshot = schema_snapshot($configuration['table']);
	if (is_wp_error($snapshot)) {
		return $snapshot;
	}
	if (!storage_schema_matches($snapshot, $configuration) || $snapshot['row_count'] !== 0) {
		return new \WP_Error('corsivo_mail_storage_create_unverified', 'Lo storage Mail appena creato non corrisponde allo schema corrente.');
	}
	return $snapshot;
}

function finalize_current_storage($configuration, $expected_intent_record, $expected_failure_record) {
	$inventory = storage_namespace_inventory();
	if (is_wp_error($inventory)) {
		return $inventory;
	}
	$snapshot = schema_snapshot($configuration['table']);
	if (is_wp_error($snapshot)) {
		return $snapshot;
	}
	if (!storage_namespace_is_canonical($inventory)
		|| !in_array($configuration['table'], $inventory, true)
		|| !storage_schema_matches($snapshot, $configuration)) {
		return new \WP_Error('corsivo_mail_storage_finalize_failed', 'Lo storage Mail non supera la verifica finale.');
	}

	$current_records = array(
		'intent' => option_record($configuration['intent_option']),
		'failure' => option_record($configuration['failure_option']),
	);
	foreach ($current_records as $record) {
		if (is_wp_error($record)) {
			return $record;
		}
	}
	if ($current_records['intent'] !== $expected_intent_record
		|| $current_records['failure'] !== $expected_failure_record) {
		return new \WP_Error('corsivo_mail_storage_finalize_unfenced', 'Lo stato transitorio dello storage Mail è cambiato prima dell’attestazione.');
	}

	$marker = storage_marker_payload($configuration, $snapshot);
	$persisted = persist_option_verified($configuration['attestation_option'], $marker);
	if (is_wp_error($persisted)) {
		return $persisted;
	}
	foreach (array(
		$configuration['failure_option'] => $expected_failure_record,
		$configuration['intent_option'] => $expected_intent_record,
	) as $option_name => $expected_record) {
		$deleted = delete_option_verified_cas($option_name, $expected_record);
		if (is_wp_error($deleted)) {
			return $deleted;
		}
	}
	reset_storage_gate($configuration);
	if (!storage_runtime_ready($configuration, true, true)) {
		return new \WP_Error('corsivo_mail_storage_attestation_failed', 'L’attestazione dello storage Mail non è verificabile.');
	}
	return true;
}

function artifact_set_has_future_format($records) {
	return ($records['marker']['exists'] && artifact_has_future_format($records['marker']['value'], 'marker_format'))
		|| ($records['intent']['exists'] && artifact_has_future_format($records['intent']['value'], 'intent_format'))
		|| ($records['failure']['exists'] && artifact_has_future_format($records['failure']['value'], 'failure_format'));
}

function install_current_storage_locked($configuration, $allow_fresh_install = false) {
	$records = array(
		'marker' => option_record($configuration['attestation_option']),
		'intent' => option_record($configuration['intent_option']),
		'failure' => option_record($configuration['failure_option']),
	);
	foreach ($records as $record) {
		if (is_wp_error($record)) {
			return $record;
		}
	}
	if (artifact_set_has_future_format($records)) {
		return new \WP_Error('corsivo_mail_storage_future_artifact', 'Lo stato storage appartiene a un formato futuro.');
	}
	if ($records['intent']['exists'] && !storage_intent_is_valid($records['intent']['value'], $configuration)) {
		return new \WP_Error('corsivo_mail_install_intent_incompatible', 'L’intent di installazione non appartiene allo storage corrente.');
	}
	if ($records['failure']['exists'] && !storage_failure_is_valid($records['failure']['value'], $configuration)) {
		return new \WP_Error('corsivo_mail_storage_failure_incompatible', 'Lo stato di errore non appartiene allo storage corrente.');
	}

	$inventory = storage_namespace_inventory();
	if (is_wp_error($inventory)) {
		return $inventory;
	}
	if (!storage_namespace_is_canonical($inventory)) {
		return new \WP_Error('corsivo_mail_namespace_incompatible', 'L’inventario dello storage Mail contiene tabelle non canoniche.');
	}
	$snapshot = schema_snapshot($configuration['table']);
	if (is_wp_error($snapshot)) {
		return $snapshot;
	}

	if (!empty($snapshot['exists'])) {
		if (!storage_schema_matches($snapshot, $configuration)) {
			return new \WP_Error('corsivo_mail_current_storage_incompatible', 'Lo storage Mail esistente non corrisponde allo schema corrente.');
		}
		if (!$records['intent']['exists']
			&& !$records['failure']['exists']
			&& $records['marker']['exists']
			&& storage_marker_is_valid($records['marker']['value'], $configuration, true)
			&& hash_equals($records['marker']['value']['physical_fingerprint'], $snapshot['fingerprint'])) {
			reset_storage_gate($configuration);
			return true;
		}
		return finalize_current_storage($configuration, $records['intent'], $records['failure']);
	}

	if ($records['marker']['exists']) {
		return new \WP_Error('corsivo_mail_storage_missing_after_marker', 'Lo storage Mail attestato non esiste più.');
	}
	if ($records['failure']['exists']) {
		return new \WP_Error('corsivo_mail_storage_blocked', 'Lo storage Mail è bloccato da un errore persistito.');
	}
	if ($records['intent']['exists']) {
		$intent = $records['intent']['value'];
	} else {
		if (!$allow_fresh_install) {
			return new \WP_Error('corsivo_mail_storage_missing_without_intent', 'Lo storage Mail mancante non ha un intent di installazione corrente.');
		}
		$intent = persist_storage_intent($configuration);
		if (is_wp_error($intent)) {
			return $intent;
		}
		$records['intent'] = option_record($configuration['intent_option']);
		if (is_wp_error($records['intent'])) {
			return $records['intent'];
		}
		if (!$records['intent']['exists'] || $records['intent']['value'] !== $intent) {
			return new \WP_Error('corsivo_mail_storage_create_unfenced', 'L’intent dello storage Mail è cambiato prima della creazione.');
		}
	}

	$snapshot = create_current_storage($configuration, $intent);
	if (is_wp_error($snapshot)) {
		return $snapshot;
	}
	return finalize_current_storage($configuration, $records['intent'], $records['failure']);
}

function storage_error_can_be_persisted($error) {
	return is_wp_error($error) && !in_array($error->get_error_code(), array(
		'corsivo_mail_storage_lock_contended',
		'corsivo_mail_storage_future_artifact',
		'corsivo_mail_install_intent_incompatible',
		'corsivo_mail_storage_failure_incompatible',
		'corsivo_mail_storage_blocked',
		'corsivo_mail_storage_missing_after_marker',
		'corsivo_mail_storage_create_unfenced',
		'corsivo_mail_storage_create_fence_lost',
		'corsivo_mail_storage_finalize_unfenced',
	), true);
}

function ensure_fresh_storage($configuration, $allow_fresh_install = false) {
	$lock = acquire_storage_lock($configuration);
	if (is_wp_error($lock)) {
		return false;
	}

	try {
		$result = install_current_storage_locked($configuration, $allow_fresh_install);
	} catch (\Throwable $error) {
		$result = new \WP_Error('corsivo_mail_storage_exception', 'Errore inatteso durante la verifica dello storage Mail.');
	}

	if (is_wp_error($result)) {
		invalidate_storage_gate($configuration);
		if (storage_error_can_be_persisted($result)) {
			try {
				$persisted = record_storage_failure($configuration, $result);
				if (is_wp_error($persisted)) {
					$result = $persisted;
				}
			} catch (\Throwable $error) {
				$result = new \WP_Error('corsivo_mail_storage_failure_persist_failed', 'Persistenza dell’errore storage Mail non riuscita.');
			}
		}
	}

	try {
		$released = release_storage_lock($configuration);
	} catch (\Throwable $error) {
		$released = false;
	}
	if (!$released) {
		invalidate_storage_gate($configuration);
		return false;
	}
	return !is_wp_error($result);
}
