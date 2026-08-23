<?php

namespace CorsivoMail\Imap;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

const INSTALL_INTENT_OPTION = 'corsivo_mail_imap_install_intent';
const SCHEMA_ATTESTATION_OPTION = 'corsivo_mail_imap_schema_attestation';
const SCHEMA_FAILURE_OPTION = 'corsivo_mail_imap_schema_failure';

function messages_table() {
	global $wpdb;
	return $wpdb->prefix . 'corsivo_mail_messages';
}

function schema_sql() {
	global $wpdb;

	$table = messages_table();
	$collate = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		account_id VARCHAR(64) NOT NULL DEFAULT '',
		mailbox VARCHAR(64) NOT NULL DEFAULT 'INBOX',
		uid BIGINT UNSIGNED NOT NULL,
		uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0,
		message_id VARCHAR(255) NOT NULL DEFAULT '',
		in_reply_to VARCHAR(255) NOT NULL DEFAULT '',
		refs TEXT NULL,
		from_name VARCHAR(255) NOT NULL DEFAULT '',
		from_email VARCHAR(255) NOT NULL DEFAULT '',
		to_field TEXT NULL,
		subject TEXT NULL,
		mail_date DATETIME NULL,
		seen TINYINT(1) NOT NULL DEFAULT 0,
		synced_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY account_mailbox_uid (account_id, mailbox, uid),
		KEY account_date (account_id, mailbox, mail_date)
	) ENGINE=InnoDB {$collate}";
}

function storage_configuration() {
	return array(
		'domain' => 'corsivo-mail:imap-storage',
		'table' => messages_table(),
		'columns' => array(
			'account_id' => \CorsivoMail\Queue\column_definition('varchar(64)', false, ''),
			'from_email' => \CorsivoMail\Queue\column_definition('varchar(255)', false, ''),
			'from_name' => \CorsivoMail\Queue\column_definition('varchar(255)', false, ''),
			'id' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, null, 'auto_increment'),
			'in_reply_to' => \CorsivoMail\Queue\column_definition('varchar(255)', false, ''),
			'mail_date' => \CorsivoMail\Queue\column_definition('datetime', true, null),
			'mailbox' => \CorsivoMail\Queue\column_definition('varchar(64)', false, 'INBOX'),
			'message_id' => \CorsivoMail\Queue\column_definition('varchar(255)', false, ''),
			'refs' => \CorsivoMail\Queue\column_definition('text', true, null),
			'seen' => \CorsivoMail\Queue\column_definition('tinyint', false, '0'),
			'subject' => \CorsivoMail\Queue\column_definition('text', true, null),
			'synced_at' => \CorsivoMail\Queue\column_definition('datetime', false, null),
			'to_field' => \CorsivoMail\Queue\column_definition('text', true, null),
			'uid' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, null),
			'uidvalidity' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, '0'),
		),
		'indexes' => array(
			'PRIMARY' => array(
				'unique' => true,
				'columns' => array('id'),
				'sub_parts' => array(null),
			),
			'account_date' => array(
				'unique' => false,
				'columns' => array('account_id', 'mailbox', 'mail_date'),
				'sub_parts' => array(null, null, null),
			),
			'account_mailbox_uid' => array(
				'unique' => true,
				'columns' => array('account_id', 'mailbox', 'uid'),
				'sub_parts' => array(null, null, null),
			),
		),
		'sql' => schema_sql(),
		'intent_option' => INSTALL_INTENT_OPTION,
		'attestation_option' => SCHEMA_ATTESTATION_OPTION,
		'failure_option' => SCHEMA_FAILURE_OPTION,
		'identity' => array(
			'role' => 'imap-message-index',
		),
	);
}

function install() {
	return \CorsivoMail\Queue\ensure_fresh_storage(storage_configuration());
}

function table_exists() {
	return \CorsivoMail\Queue\table_exists_named(messages_table());
}

function schema_ready($refresh = false) {
	return \CorsivoMail\Queue\storage_runtime_ready(storage_configuration(), $refresh);
}
