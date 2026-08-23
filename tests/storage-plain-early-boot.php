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

function wp_json_encode($value, $flags = 0, $depth = 512) {
	return json_encode($value, $flags, $depth);
}

function get_current_blog_id() {
	return 7;
}

function wp_generate_uuid4() {
	return 'e369b6d2-8a42-4b0e-aee7-d5c33b8d76d2';
}

function corsivo_mail_storage_plain_check($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$wpdb = (object) array(
	'dbname' => 'fixture_database',
	'prefix' => 'fixture_',
	'charset' => 'utf8mb4',
	'collate' => 'utf8mb4_unicode_520_ci',
);

require dirname(__DIR__) . '/includes/storage/contract.php';

$configuration = array(
	'domain' => 'corsivo-mail:queue-storage',
	'table' => 'fixture_corsivo_mail_queue',
	'columns' => array(
		'id' => \CorsivoMail\Queue\column_definition('bigint unsigned', false, null, 'auto_increment'),
	),
	'indexes' => array(
		'PRIMARY' => array('unique' => true, 'columns' => array('id'), 'sub_parts' => array(null)),
	),
	'identity' => array('role' => 'fixture'),
);
$snapshot = array('fingerprint' => str_repeat('a', 64));
$marker = \CorsivoMail\Queue\storage_marker_payload($configuration, $snapshot);
$intent = \CorsivoMail\Queue\storage_intent_payload($configuration);

corsivo_mail_storage_plain_check(
	\CorsivoMail\Queue\storage_marker_is_valid($marker, $configuration),
	'Il marker plain corrente non è accettato.'
);
corsivo_mail_storage_plain_check(
	array_keys($marker) === array('kind', 'marker_format', 'context', 'physical_fingerprint', 'scope', 'source_fingerprint', 'verified_at')
		&& !array_key_exists('signature', $marker)
		&& !array_key_exists('payload', $marker),
	'Il marker plain non ha la shape exact congelata.'
);
corsivo_mail_storage_plain_check(
	\CorsivoMail\Queue\storage_intent_is_valid($intent, $configuration)
		&& preg_match('/^[a-f0-9]{64}$/D', $intent['token']) === 1,
	'L’intent plain non ha UUID, token e fencing correnti.'
);

$tampered = $marker;
$tampered['extra'] = true;
corsivo_mail_storage_plain_check(
	!\CorsivoMail\Queue\storage_marker_is_valid($tampered, $configuration),
	'Il marker plain accetta campi extra.'
);
corsivo_mail_storage_plain_check(
	\CorsivoMail\Queue\artifact_has_future_format(array('marker_format' => 2), 'marker_format')
		&& \CorsivoMail\Queue\artifact_has_future_format(array('marker_format' => '2'), 'marker_format')
		&& !\CorsivoMail\Queue\artifact_has_future_format(array('marker_format' => 1), 'marker_format'),
	'Il rilevamento dei formati futuri non è rigoroso.'
);

$fingerprint_before = \CorsivoMail\Queue\storage_source_fingerprint($configuration);
foreach (array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT') as $index => $constant_name) {
	define($constant_name, hash('sha256', 'rotated-storage-fixture-' . $index));
}
corsivo_mail_storage_plain_check(
	hash_equals($fingerprint_before, \CorsivoMail\Queue\storage_source_fingerprint($configuration)),
	'La readiness storage dipende ancora dai salt WordPress.'
);

$contract_source = file_get_contents(dirname(__DIR__) . '/includes/storage/contract.php');
corsivo_mail_storage_plain_check(
	is_string($contract_source)
		&& stripos($contract_source, 'hash_hmac') === false
		&& stripos($contract_source, 'storage_signing') === false,
	'Il contratto storage conserva firme HMAC.'
);

echo "storage plain early boot: OK\n";
