<?php

namespace CorsivoMail\Storage\Log;

if (!defined('ABSPATH')) {
	exit;
}

const INSTALL_INTENT_OPTION = 'corsivo_mail_log_install_intent';
const SCHEMA_ATTESTATION_OPTION = 'corsivo_mail_log_schema_attestation';
const SCHEMA_FAILURE_OPTION = 'corsivo_mail_log_schema_failure';

function table_name() {
	global $wpdb;
	return $wpdb->prefix . 'corsivo_mail_log';
}

function schema_sql() {
	global $wpdb;

	$table = table_name();
	$collate = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event VARCHAR(64) NOT NULL DEFAULT 'transport_accepted',
		queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		attempt SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		source_type VARCHAR(64) NOT NULL DEFAULT '',
		source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		account_id VARCHAR(64) NOT NULL DEFAULT '',
		actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		recipient TEXT NOT NULL,
		recipient_hash CHAR(64) NOT NULL DEFAULT '',
		subject TEXT NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'sent',
		error_code VARCHAR(64) NOT NULL DEFAULT '',
		error TEXT NULL,
		metadata LONGTEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY status_created (status, created_at),
		KEY event_created (event, created_at),
		KEY queue_id (queue_id),
		KEY recipient_hash (recipient_hash)
	) ENGINE=InnoDB {$collate}";
}

function configuration() {
	return array(
		'domain' => 'corsivo-mail:log-storage',
		'table' => table_name(),
		'columns' => array(
			'account_id' => \CorsivoMail\Queue\column_definition('varchar(64)', false, ''),
			'actor_user_id' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, '0'),
			'attempt' => \CorsivoMail\Queue\column_definition('smallint unsigned', false, '0'),
			'created_at' => \CorsivoMail\Queue\column_definition('datetime', false, null),
			'error' => \CorsivoMail\Queue\column_definition('text', true, null),
			'error_code' => \CorsivoMail\Queue\column_definition('varchar(64)', false, ''),
			'event' => \CorsivoMail\Queue\column_definition('varchar(64)', false, 'transport_accepted'),
			'id' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, null, 'auto_increment'),
			'metadata' => \CorsivoMail\Queue\column_definition('longtext', true, null),
			'queue_id' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, '0'),
			'recipient' => \CorsivoMail\Queue\column_definition('text', false, null),
			'recipient_hash' => \CorsivoMail\Queue\column_definition('char(64)', false, ''),
			'source_id' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, '0'),
			'source_type' => \CorsivoMail\Queue\column_definition('varchar(64)', false, ''),
			'status' => \CorsivoMail\Queue\column_definition('varchar(20)', false, 'sent'),
			'subject' => \CorsivoMail\Queue\column_definition('text', false, null),
		),
		'indexes' => array(
			'PRIMARY' => array(
				'unique' => true,
				'columns' => array('id'),
				'sub_parts' => array(null),
			),
			'event_created' => array(
				'unique' => false,
				'columns' => array('event', 'created_at'),
				'sub_parts' => array(null, null),
			),
			'queue_id' => array(
				'unique' => false,
				'columns' => array('queue_id'),
				'sub_parts' => array(null),
			),
			'recipient_hash' => array(
				'unique' => false,
				'columns' => array('recipient_hash'),
				'sub_parts' => array(null),
			),
			'status_created' => array(
				'unique' => false,
				'columns' => array('status', 'created_at'),
				'sub_parts' => array(null, null),
			),
		),
		'sql' => schema_sql(),
		'intent_option' => INSTALL_INTENT_OPTION,
		'attestation_option' => SCHEMA_ATTESTATION_OPTION,
		'failure_option' => SCHEMA_FAILURE_OPTION,
		'identity' => array(
			'role' => 'immutable-mail-audit',
		),
	);
}

function install() {
	return \CorsivoMail\Queue\ensure_fresh_storage(configuration());
}

function ready($refresh = false) {
	return \CorsivoMail\Queue\storage_runtime_ready(configuration(), $refresh);
}
