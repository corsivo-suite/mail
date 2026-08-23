<?php

namespace CorsivoMail\Accounts;

use CorsivoMail\Crypto;

if (!defined('ABSPATH')) {
	exit;
}

const OPTION = 'corsivo_mail_accounts';

function defaults() {
	return [
		'id'          => '',
		'label'       => '',
		'provider'    => 'custom',
		'from_name'   => '',
		'from_email'  => '',
		'smtp_host'   => '',
		'smtp_port'   => 587,
		'smtp_secure' => 'tls',
		'smtp_auth'   => true,
		'smtp_user'   => '',
		'smtp_pass'   => '',
		'imap_host'   => '',
		'imap_port'   => 993,
		'imap_secure' => 'ssl',
		'imap_user'   => '',
		'imap_pass'   => '',
		'is_default'  => false,
	];
}

function imap_username($account) {
	return $account['imap_user'] ?: $account['smtp_user'];
}

function imap_password($account) {
	if (!empty($account['imap_pass'])) {
		return Crypto\decrypt($account['imap_pass'], 'account-imap-pass');
	}
	return password($account);
}

function has_imap($account) {
	return $account && !empty($account['imap_host']);
}

function smtp_presets() {
	$presets = apply_filters('corsivo_mail_smtp_presets', [
		'custom'    => ['label' => 'Personalizzato', 'host' => '', 'port' => 587, 'secure' => 'tls', 'imap_host' => '', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => ''],
		'gmail'     => ['label' => 'Gmail / Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'secure' => 'tls', 'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => 'Serve una "password per le app" (verifica in due passaggi attiva). Username = indirizzo Gmail completo.'],
		'aruba'     => ['label' => 'Aruba', 'host' => 'smtps.aruba.it', 'port' => 465, 'secure' => 'ssl', 'imap_host' => 'imaps.aruba.it', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => 'Username = indirizzo email completo.'],
		'ovh'       => ['label' => 'OVH', 'host' => 'ssl0.ovh.net', 'port' => 465, 'secure' => 'ssl', 'imap_host' => 'ssl0.ovh.net', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => 'Username = indirizzo email completo.'],
		'office365' => ['label' => 'Microsoft 365 / Outlook', 'host' => 'smtp.office365.com', 'port' => 587, 'secure' => 'tls', 'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => 'SMTP AUTH deve essere abilitato sulla casella.'],
		'ionos'     => ['label' => 'IONOS (1&1)', 'host' => 'smtp.ionos.it', 'port' => 587, 'secure' => 'tls', 'imap_host' => 'imap.ionos.it', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => ''],
		'register'  => ['label' => 'Register.it', 'host' => 'authsmtp.register.it', 'port' => 465, 'secure' => 'ssl', 'imap_host' => 'imap.register.it', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => ''],
		'zoho'      => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.eu', 'port' => 465, 'secure' => 'ssl', 'imap_host' => 'imap.zoho.eu', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => 'Usa smtp.zoho.com / imap.zoho.com per account .com.'],
		'yahoo'     => ['label' => 'Yahoo', 'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'secure' => 'ssl', 'imap_host' => 'imap.mail.yahoo.com', 'imap_port' => 993, 'imap_secure' => 'ssl', 'note' => 'Serve una password per le app.'],
		'brevo'     => ['label' => 'Brevo (Sendinblue)', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'secure' => 'tls', 'note' => ''],
		'sendgrid'  => ['label' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'secure' => 'tls', 'user' => 'apikey', 'note' => 'Username fisso "apikey"; come password usa la tua API key.'],
		'mailgun'   => ['label' => 'Mailgun', 'host' => 'smtp.mailgun.org', 'port' => 587, 'secure' => 'tls', 'note' => ''],
		'postmark'  => ['label' => 'Postmark', 'host' => 'smtp.postmarkapp.com', 'port' => 587, 'secure' => 'tls', 'note' => 'Username e password = il tuo Server API Token.'],
	]);
	$defaults = [
		'label' => '',
		'host' => '',
		'port' => 587,
		'secure' => 'tls',
		'user' => '',
		'imap_host' => '',
		'imap_port' => 993,
		'imap_secure' => 'ssl',
		'imap_user' => '',
		'note' => '',
	];
	foreach ($presets as $key => $preset) {
		$presets[$key] = wp_parse_args($preset, $defaults);
	}
	return $presets;
}

function all() {
	$accounts = get_option(OPTION, []);
	if (!is_array($accounts)) {
		return [];
	}
	return array_map(function ($account) {
		return wp_parse_args($account, defaults());
	}, $accounts);
}

function get($id) {
	foreach (all() as $account) {
		if ($account['id'] === $id) {
			return $account;
		}
	}
	return null;
}

function get_default() {
	$accounts = all();
	if (empty($accounts)) {
		return null;
	}

	foreach ($accounts as $account) {
		if (!empty($account['is_default'])) {
			return $account;
		}
	}

	return $accounts[0];
}

function password($account) {
	if (!$account || empty($account['smtp_pass'])) {
		return '';
	}
	return Crypto\decrypt($account['smtp_pass'], 'account-smtp-pass');
}

function save_all($accounts) {
	$clean   = [];
	$has_def = false;

	foreach ($accounts as $account) {
		$account = wp_parse_args($account, defaults());

		if (empty($account['id'])) {
			$account['id'] = 'acc_' . wp_generate_uuid4();
		}
		$account['id'] = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $account['id']);

		$presets                = smtp_presets();
		$account['provider']    = isset($presets[$account['provider']]) ? $account['provider'] : 'custom';
		$account['label']       = sanitize_text_field($account['label']);
		$account['from_name']   = sanitize_text_field($account['from_name']);
		$account['from_email']  = sanitize_email($account['from_email']);
		$account['smtp_host']   = sanitize_text_field($account['smtp_host']);
		$account['smtp_port']   = max(1, min(65535, (int) $account['smtp_port']));
		$account['smtp_secure'] = in_array($account['smtp_secure'], ['ssl', 'tls', 'none'], true) ? $account['smtp_secure'] : 'tls';
		$account['smtp_auth']   = (bool) $account['smtp_auth'];
		$account['smtp_user']   = sanitize_text_field($account['smtp_user']);
		$account['imap_host']   = sanitize_text_field($account['imap_host']);
		$account['imap_port']   = max(1, min(65535, (int) $account['imap_port']));
		$account['imap_secure'] = in_array($account['imap_secure'], ['ssl', 'tls', 'none'], true) ? $account['imap_secure'] : 'ssl';
		$account['imap_user']   = sanitize_text_field($account['imap_user']);
		$account['is_default']  = (bool) $account['is_default'];

		try {
			$account['smtp_pass'] = normalize_password($account['smtp_pass'], 'account-smtp-pass');
			$account['imap_pass'] = normalize_password($account['imap_pass'], 'account-imap-pass');
		} catch (\Throwable $error) {
			return new \WP_Error('corsivo_mail_crypto_failed', $error->getMessage());
		}

		if ($account['is_default'] && !$has_def) {
			$has_def = true;
		} else {
			$account['is_default'] = false;
		}

		$clean[] = $account;
	}

	if (!$has_def && !empty($clean)) {
		$clean[0]['is_default'] = true;
	}

	if (!update_option(OPTION, $clean) && get_option(OPTION, null) !== $clean) {
		return new \WP_Error('corsivo_mail_accounts_save_failed', 'Salvataggio degli account non riuscito.');
	}
	return $clean;
}

function normalize_password($value, $context) {
	if ($value === '' || $value === null) {
		return '';
	}
	return Crypto\is_encrypted($value) ? $value : Crypto\encrypt((string) $value, $context);
}
