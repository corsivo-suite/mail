<?php

namespace CorsivoMail\Crypto;

if (!defined('ABSPATH')) {
	exit;
}

const CIPHER = 'aes-256-gcm';
const PREFIX = 'cmenc:v3:';
const ENVELOPE_VERSION = 3;
const ALGORITHM = 'A256GCM';
const KEY_CONSTANT = 'CORSIVO_MAIL_ENCRYPTION_KEY';
const MASTER_KEY_OPTION = 'corsivo_mail_encryption_master_key';

function decode_master_key($encoded) {
	if (!is_string($encoded) || $encoded === '') {
		return new \WP_Error('corsivo_mail_crypto_key_unavailable', 'Il materiale crittografico Mail non è disponibile.');
	}

	$master_key = base64_decode($encoded, true);
	if (!is_string($master_key) || strlen($master_key) !== 32 || !hash_equals(base64_encode($master_key), $encoded)) {
		return new \WP_Error('corsivo_mail_crypto_key_invalid', 'La chiave di cifratura Mail persistita non è valida.');
	}

	return $master_key;
}

function store_master_key_once($candidate) {
	global $wpdb;

	$table = is_object($wpdb ?? null) && isset($wpdb->options) ? (string) $wpdb->options : '';
	if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table)
		|| !method_exists($wpdb, 'prepare')
		|| !method_exists($wpdb, 'query')
		|| !method_exists($wpdb, 'get_var')) {
		return '';
	}

	$autoload = function_exists('wp_determine_option_autoload_value')
		? wp_determine_option_autoload_value(MASTER_KEY_OPTION, $candidate, $candidate, false)
		: 'no';
	if (false === $wpdb->query($wpdb->prepare(
		"INSERT IGNORE INTO `{$table}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)",
		MASTER_KEY_OPTION,
		$candidate,
		$autoload
	))) {
		return '';
	}

	$stored = $wpdb->get_var($wpdb->prepare(
		"SELECT `option_value` FROM `{$table}` WHERE `option_name` = %s LIMIT 1",
		MASTER_KEY_OPTION
	));
	if (function_exists('wp_cache_delete')) {
		wp_cache_delete(MASTER_KEY_OPTION, 'options');
		wp_cache_delete('notoptions', 'options');
	}

	return is_string($stored) ? $stored : '';
}

function master_key() {
	if (defined(KEY_CONSTANT)) {
		$material = constant(KEY_CONSTANT);
		return is_string($material) && strlen($material) >= 32
			? $material
			: new \WP_Error('corsivo_mail_crypto_key_invalid', KEY_CONSTANT . ' deve contenere almeno 32 byte.');
	}

	if (!function_exists('get_option')) {
		return new \WP_Error('corsivo_mail_crypto_key_unavailable', 'Il materiale crittografico Mail non è disponibile.');
	}

	$missing = new \stdClass();
	$encoded = get_option(MASTER_KEY_OPTION, $missing);
	if ($encoded === $missing) {
		try {
			$candidate = base64_encode(random_bytes(32));
		} catch (\Throwable $error) {
			return new \WP_Error('corsivo_mail_crypto_key_generation_failed', 'Generazione della chiave di cifratura Mail fallita.');
		}
		$encoded = store_master_key_once($candidate);
	}

	return decode_master_key($encoded);
}

function derive_key($master_key, $purpose) {
	if (!is_string($master_key) || strlen($master_key) < 32 || !function_exists('hash_hkdf')) {
		return '';
	}
	if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', (string) $purpose)) {
		return '';
	}

	return hash_hkdf('sha256', $master_key, 32, 'corsivo-mail:' . $purpose, 'corsivo-mail:v3');
}

function key_id($master_key) {
	$identifier = derive_key($master_key, 'key-identifier');
	return $identifier === '' ? '' : bin2hex(substr($identifier, 0, 16));
}

function context_is_valid($context) {
	return preg_match('/\Aaccount-(?:smtp|imap)-pass\z/', (string) $context) === 1;
}

function aad($context, $key_id) {
	return json_encode(array(
		'plugin'   => 'corsivo-mail',
		'blog_id'  => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
		'version'  => ENVELOPE_VERSION,
		'key_id'   => (string) $key_id,
		'identity' => (string) $context,
	), JSON_UNESCAPED_SLASHES);
}

function parse_envelope($stored) {
	if (!is_string($stored) || strpos($stored, PREFIX) !== 0) {
		return null;
	}

	$decoded = base64_decode(substr($stored, strlen(PREFIX)), true);
	if (!is_string($decoded)) {
		return null;
	}

	$envelope = json_decode($decoded, true);
	if (!is_array($envelope)
		|| array_keys($envelope) !== array('version', 'algorithm', 'key_id', 'nonce', 'tag', 'ciphertext')
		|| $envelope['version'] !== ENVELOPE_VERSION
		|| $envelope['algorithm'] !== ALGORITHM
		|| !is_string($envelope['key_id'])
		|| preg_match('/\A[a-f0-9]{32}\z/', $envelope['key_id']) !== 1) {
		return null;
	}

	$nonce = decode_component($envelope['nonce'], 12);
	$tag = decode_component($envelope['tag'], 16);
	$ciphertext = decode_component($envelope['ciphertext']);
	if ($nonce === null || $tag === null || $ciphertext === null) {
		return null;
	}

	return array(
		'key_id'     => $envelope['key_id'],
		'nonce'      => $nonce,
		'tag'        => $tag,
		'ciphertext' => $ciphertext,
	);
}

function decode_component($value, $length = null) {
	if (!is_string($value) || $value === '') {
		return null;
	}
	$decoded = base64_decode($value, true);
	if (!is_string($decoded)
		|| !hash_equals(base64_encode($decoded), $value)
		|| ($length !== null && strlen($decoded) !== $length)) {
		return null;
	}

	return $decoded;
}

function cipher_is_available() {
	return function_exists('openssl_encrypt')
		&& function_exists('openssl_decrypt')
		&& in_array(CIPHER, openssl_get_cipher_methods(), true);
}

function encrypt($plaintext, $context) {
	if ($plaintext === '' || $plaintext === null) {
		return '';
	}
	if (!context_is_valid($context)) {
		throw new \RuntimeException('Contesto di cifratura Mail non valido.');
	}
	if (!cipher_is_available()) {
		throw new \RuntimeException('OpenSSL AES-GCM non disponibile.');
	}

	$master_key = master_key();
	if (is_wp_error($master_key)) {
		throw new \RuntimeException($master_key->get_error_message());
	}

	$key = derive_key($master_key, 'payload');
	$identifier = key_id($master_key);
	if ($key === '' || $identifier === '') {
		throw new \RuntimeException('Derivazione della chiave di cifratura Mail fallita.');
	}

	$nonce = random_bytes(12);
	$tag = '';
	$ciphertext = openssl_encrypt(
		(string) $plaintext,
		CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		$nonce,
		$tag,
		aad($context, $identifier),
		16
	);
	if ($ciphertext === false || strlen($tag) !== 16) {
		throw new \RuntimeException('Cifratura del payload Mail non riuscita.');
	}

	$envelope = json_encode(array(
		'version'    => ENVELOPE_VERSION,
		'algorithm'  => ALGORITHM,
		'key_id'     => $identifier,
		'nonce'      => base64_encode($nonce),
		'tag'        => base64_encode($tag),
		'ciphertext' => base64_encode($ciphertext),
	), JSON_UNESCAPED_SLASHES);
	if (!is_string($envelope)) {
		throw new \RuntimeException('Serializzazione del payload Mail cifrato non riuscita.');
	}

	return PREFIX . base64_encode($envelope);
}

function decrypt($stored, $context) {
	$plaintext = decrypt_strict($stored, $context);
	return is_wp_error($plaintext) ? '' : $plaintext;
}

function decrypt_strict($stored, $context) {
	if (!context_is_valid($context)) {
		return new \WP_Error('corsivo_mail_payload_context_invalid', 'Contesto di cifratura Mail non valido.');
	}
	$envelope = parse_envelope($stored);
	if ($envelope === null) {
		return new \WP_Error('corsivo_mail_payload_prefix_invalid', 'Il payload Mail cifrato non è nel formato corrente.');
	}
	if (!cipher_is_available()) {
		return new \WP_Error('corsivo_mail_payload_cipher_unavailable', 'AES-GCM non è disponibile per leggere il payload Mail.');
	}

	$master_key = master_key();
	if (is_wp_error($master_key)) {
		return $master_key;
	}

	$key = derive_key($master_key, 'payload');
	$identifier = key_id($master_key);
	if ($key === '' || $identifier === '') {
		return new \WP_Error('corsivo_mail_payload_key_unavailable', 'Derivazione della chiave di cifratura Mail fallita.');
	}
	if (!hash_equals($identifier, $envelope['key_id'])) {
		return new \WP_Error('corsivo_mail_payload_key_mismatch', 'Il payload Mail è cifrato con un\'altra chiave.');
	}

	$plaintext = openssl_decrypt(
		$envelope['ciphertext'],
		CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		$envelope['nonce'],
		$envelope['tag'],
		aad($context, $identifier)
	);

	return $plaintext === false
		? new \WP_Error('corsivo_mail_payload_authentication_failed', 'Autenticazione del payload Mail fallita.')
		: $plaintext;
}

function is_encrypted($value) {
	return is_string($value) && strpos($value, PREFIX) === 0;
}
