<?php

define('ABSPATH', __DIR__);

class WP_Error {
	private $code;
	private $message;

	public function __construct($code, $message) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error($value) {
	return $value instanceof WP_Error;
}

function corsivo_mail_crypto_early_boot_check($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

corsivo_mail_crypto_early_boot_check(
	!function_exists('wp_salt'),
	'Il test Crypto early boot non deve caricare pluggable.php.'
);

require dirname(__DIR__) . '/includes/settings/crypto.php';

$unavailable_key = \CorsivoMail\Crypto\master_key();
corsivo_mail_crypto_early_boot_check(
	is_wp_error($unavailable_key)
		&& $unavailable_key->get_error_code() === 'corsivo_mail_crypto_key_unavailable',
	'Crypto non fallisce quando né la costante né le opzioni sono disponibili.'
);

define(\CorsivoMail\Crypto\KEY_CONSTANT, str_repeat('s', 48));

$first_key = \CorsivoMail\Crypto\master_key();
$second_key = \CorsivoMail\Crypto\master_key();
corsivo_mail_crypto_early_boot_check(
	is_string($first_key) && strlen($first_key) === 48 && hash_equals($first_key, $second_key),
	'La master key Crypto early boot non è deterministica.'
);

$key_id = \CorsivoMail\Crypto\key_id($first_key);
corsivo_mail_crypto_early_boot_check(
	preg_match('/\A[a-f0-9]{32}\z/', $key_id) === 1,
	'Il key id non ha il formato atteso.'
);

$smtp_password = 'SMTP secret';
$imap_password = 'IMAP secret';
$encrypted_smtp = \CorsivoMail\Crypto\encrypt($smtp_password, 'account-smtp-pass');
$encrypted_imap = \CorsivoMail\Crypto\encrypt($imap_password, 'account-imap-pass');

corsivo_mail_crypto_early_boot_check(
	strpos($encrypted_smtp, \CorsivoMail\Crypto\PREFIX) === 0
		&& \CorsivoMail\Crypto\decrypt($encrypted_smtp, 'account-smtp-pass') === $smtp_password
		&& \CorsivoMail\Crypto\decrypt($encrypted_imap, 'account-imap-pass') === $imap_password,
	'Roundtrip delle credenziali Crypto early boot fallito.'
);

$mismatch = \CorsivoMail\Crypto\decrypt_strict($encrypted_smtp, 'account-imap-pass');
corsivo_mail_crypto_early_boot_check(
	is_wp_error($mismatch)
		&& $mismatch->get_error_code() === 'corsivo_mail_payload_authentication_failed',
	'Il contesto credenziale sbagliato non produce un errore di autenticazione.'
);

$envelope = json_decode(
	base64_decode(substr($encrypted_smtp, strlen(\CorsivoMail\Crypto\PREFIX)), true),
	true
);
corsivo_mail_crypto_early_boot_check(
	is_array($envelope)
		&& $envelope['version'] === \CorsivoMail\Crypto\ENVELOPE_VERSION
		&& $envelope['algorithm'] === \CorsivoMail\Crypto\ALGORITHM
		&& hash_equals($key_id, (string) $envelope['key_id']),
	'L\'envelope non dichiara versione, algoritmo e key id correnti.'
);

$tampered = substr($encrypted_smtp, 0, -2) . 'aa';
corsivo_mail_crypto_early_boot_check(
	\CorsivoMail\Crypto\decrypt($tampered, 'account-smtp-pass') === '',
	'Un envelope alterato viene accettato.'
);

corsivo_mail_crypto_early_boot_check(
	\CorsivoMail\Crypto\decrypt($smtp_password, 'account-smtp-pass') === '',
	'Una credenziale non cifrata viene accettata.'
);

$queue_context_rejected = false;
try {
	\CorsivoMail\Crypto\encrypt('queue payload', 'queue-body');
} catch (Throwable $error) {
	$queue_context_rejected = true;
}
corsivo_mail_crypto_early_boot_check(
	$queue_context_rejected
		&& !\CorsivoMail\Crypto\context_is_valid('queue-body'),
	'Crypto accetta ancora payload ordinari della coda.'
);

corsivo_mail_crypto_early_boot_check(
	!function_exists('wp_salt'),
	'Encrypt/decrypt Crypto ha richiesto wp_salt.'
);

echo "Corsivo Mail crypto early boot: OK\n";