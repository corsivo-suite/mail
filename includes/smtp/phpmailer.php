<?php

namespace CorsivoMail\Smtp;

use CorsivoMail\Accounts;
use PHPMailer\PHPMailer\PHPMailer;

if (!defined('ABSPATH')) {
	exit;
}

$GLOBALS['corsivo_mail_active_account'] = null;

function use_account($account) {
	$GLOBALS['corsivo_mail_active_account'] = $account;
}

function with_account($account, $callback) {
	$previous = $GLOBALS['corsivo_mail_active_account'] ?? null;
	$GLOBALS['corsivo_mail_active_account'] = $account;
	try {
		return $callback();
	} finally {
		$GLOBALS['corsivo_mail_active_account'] = $previous;
	}
}

function current_account() {
	$active_account = $GLOBALS['corsivo_mail_active_account'] ?? null;
	if ($active_account) {
		return $active_account;
	}
	$account_id = sanitize_text_field($GLOBALS['corsivo_mail_mail_account_id'] ?? '');
	return $account_id ? Accounts\get($account_id) : Accounts\get_default();
}

function configure($phpmailer) {
	$account = current_account();
	if (!$account || empty($account['smtp_host'])) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host = $account['smtp_host'];
	$phpmailer->Port = (int) $account['smtp_port'];
	$timeout = max(5, min(120, (int) apply_filters('corsivo_mail_smtp_timeout', 30, $account)));
	$phpmailer->Timeout = $timeout;
	$phpmailer->getSMTPInstance()->Timelimit = $timeout;

	if ($account['smtp_secure'] === 'ssl') {
		$phpmailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
	} elseif ($account['smtp_secure'] === 'tls') {
		$phpmailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
	} else {
		$phpmailer->SMTPSecure  = '';
		$phpmailer->SMTPAutoTLS = false;
	}

	if (!empty($account['smtp_auth'])) {
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = $account['smtp_user'];
		$phpmailer->Password = Accounts\password($account);
	} else {
		$phpmailer->SMTPAuth = false;
	}

	if (!empty($account['from_email'])) {
		$phpmailer->setFrom($account['from_email'], $account['from_name'] ?: '', false);
	}
}

add_action('phpmailer_init', __NAMESPACE__ . '\\configure');
add_action('phpmailer_init', __NAMESPACE__ . '\\add_plain_text_alternative', 20);
add_action('wp_mail_succeeded', __NAMESPACE__ . '\\clear_mail_account', PHP_INT_MAX);
add_action('wp_mail_failed', __NAMESPACE__ . '\\clear_mail_account', PHP_INT_MAX);

function clear_mail_account() {
	unset($GLOBALS['corsivo_mail_mail_account_id']);
}

function add_plain_text_alternative($phpmailer) {
	if (stripos((string) $phpmailer->ContentType, 'text/html') === false || $phpmailer->AltBody !== '') {
		return;
	}
	$phpmailer->AltBody = html_to_plain_text($phpmailer->Body);
}

function html_to_plain_text($html) {
	$text = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
	$text = preg_replace('/<br\s*\/?>/i', "\n", $text);
	$text = preg_replace('/<\/(p|div|h[1-6]|li|tr|td|table|blockquote)>/i', "\n", $text);
	$text = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $text);
	$text = wp_strip_all_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
	$text = str_replace("\xc2\xa0", ' ', $text);
	$text = preg_replace("/[ \t]+/", ' ', $text);
	$text = preg_replace("/ ?\n ?/", "\n", $text);
	$text = preg_replace("/\n{3,}/", "\n\n", $text);
	return trim($text);
}

add_filter('wp_mail_from', __NAMESPACE__ . '\\filter_from', 20);
add_filter('wp_mail_from_name', __NAMESPACE__ . '\\filter_from_name', 20);

function filter_from($email) {
	$account = current_account();
	return ($account && !empty($account['from_email'])) ? $account['from_email'] : $email;
}

function filter_from_name($name) {
	$account = current_account();
	return ($account && !empty($account['from_name'])) ? $account['from_name'] : $name;
}

function send_now($to, $subject, $message, $headers = [], $attachments = [], $account = null) {
	return with_account($account ?: current_account(), function () use ($to, $subject, $message, $headers, $attachments) {
		return wp_mail($to, $subject, $message, $headers, $attachments);
	});
}
