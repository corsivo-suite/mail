<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$failures = [];
$passes = 0;

function corsivo_mail_runtime_check($condition, $label, &$passes, &$failures) {
	if ($condition) {
		$passes++;
		return;
	}
	$failures[] = $label;
}

function corsivo_mail_raw_option($name, $default = null) {
	global $wpdb;
	$value = $wpdb->get_var($wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		$name
	));
	return $value === null ? $default : maybe_unserialize($value);
}

wp_set_current_user(0);
$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/corsivo-mail-emails'));
corsivo_mail_runtime_check(in_array($response->get_status(), [401, 403], true), 'REST anonimo non bloccato.', $passes, $failures);

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
if ($admins) {
	wp_set_current_user((int) $admins[0]);
	$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/corsivo-mail-emails'));
	corsivo_mail_runtime_check($response->get_status() === 200, 'REST amministrativo non disponibile.', $passes, $failures);
}

$post_type = get_post_type_object(\CorsivoMail\Automation\TYPE);
corsivo_mail_runtime_check($post_type && $post_type->cap->edit_posts === 'manage_options' && $post_type->cap->publish_posts === 'manage_options', 'Capability CPT non amministrative.', $passes, $failures);
$subscription_block = WP_Block_Type_Registry::get_instance()->get_registered('corsivo-mail/newsletter');
corsivo_mail_runtime_check((bool) $subscription_block, 'Blocco newsletter esistente non registrato quando il modulo è disattivato.', $passes, $failures);

$secret = 'credential-' . wp_generate_uuid4();
$encrypted = \CorsivoMail\Crypto\encrypt($secret, 'account-smtp-pass');
corsivo_mail_runtime_check(\CorsivoMail\Crypto\decrypt($encrypted, 'account-smtp-pass') === $secret, 'Roundtrip AEAD fallito.', $passes, $failures);
$tampered = substr($encrypted, 0, -2) . 'aa';
corsivo_mail_runtime_check(\CorsivoMail\Crypto\decrypt($tampered, 'account-smtp-pass') === '', 'Payload AEAD alterato accettato.', $passes, $failures);
corsivo_mail_runtime_check(\CorsivoMail\Crypto\decrypt($secret, 'account-smtp-pass') === '', 'Credenziale non cifrata accettata.', $passes, $failures);
corsivo_mail_runtime_check(\CorsivoMail\Crypto\decrypt($encrypted, 'account-imap-pass') === '', 'Credenziale leggibile con il contesto di un altro campo.', $passes, $failures);

$html = \CorsivoMail\Security\sanitize_remote_email_html('<p onclick="alert(1)">ok</p><script>alert(1)</script>');
corsivo_mail_runtime_check(strpos($html, '<script') === false && strpos($html, 'onclick') === false, 'Sanitizzazione HTML IMAP fallita.', $passes, $failures);
corsivo_mail_runtime_check(!\CorsivoMail\Newsletter\can_receive_marketing(['first@example.invalid', 'second@example.invalid']), 'Invio marketing multi-destinatario non soppresso.', $passes, $failures);
if (function_exists('corsivo_newsletter_get_subscriber_by_email')) {
	$missing_external_email = 'corsivo-mail-missing-' . strtolower(wp_generate_password(10, false, false)) . '@example.invalid';
	corsivo_mail_runtime_check(!\CorsivoMail\Newsletter\can_receive_marketing($missing_external_email), 'Consenso esterno Corsivo Newsletter non rispettato.', $passes, $failures);
}

$imap_request = new WP_REST_Request('POST');
$imap_request->set_param('host', '');
$imap_result = \CorsivoMail\Imap\rest_test($imap_request);
corsivo_mail_runtime_check(is_wp_error($imap_result) && $imap_result->get_error_code() === 'no_imap', 'Test IMAP con host esplicitamente vuoto usa la configurazione salvata.', $passes, $failures);

$privacy_log_ids = [];
try {
	global $wpdb;
	$privacy_token = strtolower(wp_generate_password(10, false, false));
	$privacy_email = 'ann-' . $privacy_token . '@example.invalid';
	$privacy_collision_email = 'joann-' . $privacy_token . '@example.invalid';
	\CorsivoMail\Log\record('sent', $privacy_email, 'Privacy exact target');
	$privacy_log_ids[] = (int) $wpdb->insert_id;
	\CorsivoMail\Log\record('sent', $privacy_collision_email, 'Privacy substring collision');
	$privacy_log_ids[] = (int) $wpdb->insert_id;
	$privacy_recipient_hash = $wpdb->get_var($wpdb->prepare(
		'SELECT recipient_hash FROM ' . \CorsivoMail\Log\table_name() . ' WHERE id = %d',
		$privacy_log_ids[0]
	));
	corsivo_mail_runtime_check($privacy_recipient_hash === hash('sha256', strtolower($privacy_email)), 'Il digest persistente del destinatario non è uno SHA-256 stabile.', $passes, $failures);
	$privacy_export = \CorsivoMail\Log\export_personal_data($privacy_email);
	corsivo_mail_runtime_check(count($privacy_export['data']) === 1 && $privacy_export['data'][0]['item_id'] === 'corsivo-mail-log-' . $privacy_log_ids[0], 'Exporter privacy include destinatari per corrispondenza parziale.', $passes, $failures);
	\CorsivoMail\Log\erase_personal_data($privacy_email);
	$privacy_rows = $wpdb->get_results($wpdb->prepare(
		'SELECT id, recipient FROM ' . \CorsivoMail\Log\table_name() . ' WHERE id IN (%d, %d) ORDER BY id ASC',
		$privacy_log_ids[0],
		$privacy_log_ids[1]
	), ARRAY_A);
	corsivo_mail_runtime_check(count($privacy_rows) === 2 && $privacy_rows[0]['recipient'] === '[removed]' && $privacy_rows[1]['recipient'] === $privacy_collision_email, 'Eraser privacy modifica destinatari per corrispondenza parziale.', $passes, $failures);
} finally {
	if ($privacy_log_ids) {
		global $wpdb;
		$wpdb->query('DELETE FROM ' . \CorsivoMail\Log\table_name() . ' WHERE id IN (' . implode(',', array_map('intval', $privacy_log_ids)) . ')');
	}
}

$queue_id = 0;
try {
	$key = 'runtime-test-' . wp_generate_uuid4();
	$args = [
		'to' => 'nobody@example.invalid',
		'subject' => 'Queue runtime test',
		'message' => '<p>secret-runtime-payload</p>',
		'headers' => ['Content-Type: text/html; charset=UTF-8'],
		'scheduled_at' => current_time('mysql'),
		'idempotency_key' => $key,
		'idempotency_fingerprint' => hash('sha256', 'queue-runtime-payload-v1'),
		'source_type' => 'test',
		'source_id' => 1,
	];
	$first = \CorsivoMail\Queue\enqueue($args);
	$second = \CorsivoMail\Queue\enqueue($args);
	$conflicting_args = array_merge($args, ['idempotency_fingerprint' => hash('sha256', 'queue-runtime-payload-v2')]);
	$conflict = \CorsivoMail\Queue\enqueue($conflicting_args);
	$missing_fingerprint_args = $args;
	unset($missing_fingerprint_args['idempotency_fingerprint']);
	$missing_fingerprint = \CorsivoMail\Queue\enqueue($missing_fingerprint_args);
	$queue_id = is_wp_error($first) ? 0 : (int) $first;
	corsivo_mail_runtime_check(!is_wp_error($first) && $first === $second, 'Idempotenza coda fallita.', $passes, $failures);
	corsivo_mail_runtime_check(is_wp_error($conflict) && 'corsivo_mail_idempotency_conflict' === $conflict->get_error_code(), 'Replay con la stessa key e fingerprint diverso accettato.', $passes, $failures);
	corsivo_mail_runtime_check(
		is_wp_error($missing_fingerprint) && 'corsivo_mail_idempotency_fingerprint_required' === $missing_fingerprint->get_error_code(),
		'La coda accetta una operation key senza identità verificabile.',
		$passes,
		$failures
	);
	$row = $queue_id ? \CorsivoMail\Queue\get_row($queue_id) : null;
	corsivo_mail_runtime_check(
		$row
			&& $row['body'] === '<p>secret-runtime-payload</p>'
			&& json_decode($row['headers'], true) === ['Content-Type: text/html; charset=UTF-8']
			&& json_decode($row['attachments'], true) === []
			&& json_decode($row['embeds'], true) === [],
		'Payload coda non persistito in forma plain leggibile.',
		$passes,
		$failures
	);
	corsivo_mail_runtime_check($row && $row['message_type'] === 'transactional', 'Tipo messaggio non persistito in coda.', $passes, $failures);
	corsivo_mail_runtime_check($row && hash('sha256', 'queue-runtime-payload-v1') === $row['idempotency_fingerprint'], 'Fingerprint idempotente non persistito in coda.', $passes, $failures);
	$corrupted_headers_rejected = false;
	try {
		\CorsivoMail\Queue\decode_payload(substr($row['headers'], 0, -2) . 'aa');
	} catch (\Throwable $error) {
		$corrupted_headers_rejected = true;
	}
	corsivo_mail_runtime_check($corrupted_headers_rejected, 'Header JSON non validi accettati.', $passes, $failures);
	$claimed = \CorsivoMail\Queue\claim_batch(1, $queue_id);
	$claimed_again = \CorsivoMail\Queue\claim_batch(1, $queue_id);
	corsivo_mail_runtime_check(is_array($claimed) && count($claimed) === 1 && is_array($claimed_again) && count($claimed_again) === 0, 'Claim coda non atomico.', $passes, $failures);
	corsivo_mail_runtime_check($claimed && (int) $claimed[0]['attempts'] === 1, 'Il claim non incrementa i tentativi (poison message senza limite).', $passes, $failures);
	if ($claimed) {
		corsivo_mail_runtime_check(\CorsivoMail\Queue\decode_body($claimed[0]['body']) === '<p>secret-runtime-payload</p>', 'Lettura payload coda plain fallita.', $passes, $failures);
		$transport_started = \CorsivoMail\Queue\start_transport($queue_id, $claimed[0]['lock_token']);
		corsivo_mail_runtime_check($transport_started === true, 'Fence pre-trasporto non acquisito.', $passes, $failures);
		\CorsivoMail\Queue\mark_sent($queue_id, $claimed[0]['lock_token']);
		$sent = \CorsivoMail\Queue\get_row($queue_id);
		corsivo_mail_runtime_check($sent['status'] === 'sent' && $sent['body'] === '' && $sent['headers'] === '', 'Payload completato non eliminato.', $passes, $failures);
	}
} finally {
	if ($queue_id) {
		global $wpdb;
		$wpdb->delete(\CorsivoMail\Queue\table_name(), ['id' => $queue_id], ['%d']);
	}
}


$members_policy_rejected = \CorsivoMail\Queue\enqueue([
	'to' => 'members-policy@example.invalid',
	'subject' => 'Members policy',
	'message' => 'Members policy',
	'idempotency_key' => 'members-policy:' . wp_generate_uuid4(),
	'idempotency_fingerprint' => hash('sha256', 'members-policy'),
	'source_type' => 'corsivo-members-expiry',
	'source_id' => 1,
]);
corsivo_mail_runtime_check(
	is_wp_error($members_policy_rejected)
		&& $members_policy_rejected->get_error_code() === 'corsivo_mail_queue_source_policy_invalid',
	'La sorgente expiry Members accetta righe senza guard e retention.',
	$passes,
	$failures
);

$registered_post_id = 0;
$registered_queue_id = 0;
$registered_provider_id = 'runtime_provider_' . strtolower(wp_generate_password(8, false, false));
$registered_options_before = corsivo_mail_raw_option(\CorsivoMail\Options\OPTION);
$registered_provider = static function ($providers) use ($registered_provider_id) {
	$providers[$registered_provider_id] = [
		'label' => 'Runtime provider',
		'merge_tags' => ['runtime_value'],
	];
	return $providers;
};
add_filter('corsivo_mail_core_emails', $registered_provider);
try {
	$registered_options = \CorsivoMail\Options\all();
	$registered_options['disable_sending'] = false;
	update_option(\CorsivoMail\Options\OPTION, $registered_options);
	$registered_post_id = wp_insert_post([
		'post_type' => \CorsivoMail\Automation\TYPE,
		'post_status' => 'publish',
		'post_title' => 'Runtime {{runtime_value}}',
		'post_content' => '<!-- wp:paragraph --><p>{{runtime_value}}</p><!-- /wp:paragraph -->',
	]);
	update_post_meta($registered_post_id, 'corsivo_mail_trigger_type', 'core_email');
	update_post_meta($registered_post_id, 'corsivo_mail_core_email', $registered_provider_id);
	update_post_meta($registered_post_id, 'corsivo_mail_send_enabled', true);
	update_post_meta($registered_post_id, 'corsivo_mail_filter_enabled', true);
	$registered_key = 'runtime-registered-' . wp_generate_uuid4();
	$registered_retain = gmdate('Y-m-d H:i:s', current_time('timestamp') + 8 * DAY_IN_SECONDS);
	$registered_source = [
		'type' => 'runtime-test',
		'id' => 42,
		'source_guard_required' => true,
		'retain_until' => $registered_retain,
	];
	$registered_first = corsivo_mail_enqueue_registered($registered_provider_id, 'runtime@example.invalid', ['runtime_value' => 'alpha'], $registered_key, $registered_source);
	$registered_replay = corsivo_mail_enqueue_registered($registered_provider_id, 'runtime@example.invalid', ['runtime_value' => 'alpha'], $registered_key, $registered_source);
	$registered_conflict = corsivo_mail_enqueue_registered($registered_provider_id, 'runtime@example.invalid', ['runtime_value' => 'beta'], $registered_key, $registered_source);
	$registered_guard_conflict = corsivo_mail_enqueue_registered(
		$registered_provider_id,
		'runtime@example.invalid',
		['runtime_value' => 'alpha'],
		$registered_key,
		array_merge($registered_source, ['source_guard_required' => false])
	);
	$registered_retention_conflict = corsivo_mail_enqueue_registered(
		$registered_provider_id,
		'runtime@example.invalid',
		['runtime_value' => 'alpha'],
		$registered_key,
		array_merge($registered_source, ['retain_until' => gmdate('Y-m-d H:i:s', current_time('timestamp') + 9 * DAY_IN_SECONDS)])
	);
	$registered_queue_id = is_wp_error($registered_first) ? 0 : (int) $registered_first;
	$registered_queue_row = $registered_queue_id ? \CorsivoMail\Queue\get_row($registered_queue_id) : null;
	corsivo_mail_runtime_check($registered_queue_id > 0 && $registered_first === $registered_replay, 'L’API registrata non riusa il queue_id su replay identico.', $passes, $failures);
	corsivo_mail_runtime_check(is_wp_error($registered_conflict) && 'corsivo_mail_idempotency_conflict' === $registered_conflict->get_error_code(), 'L’API registrata accetta stessa key con contesto diverso.', $passes, $failures);
	corsivo_mail_runtime_check(is_wp_error($registered_guard_conflict) && 'corsivo_mail_idempotency_conflict' === $registered_guard_conflict->get_error_code(), 'Il replay può declassare il source guard senza conflitto.', $passes, $failures);
	corsivo_mail_runtime_check(is_wp_error($registered_retention_conflict) && 'corsivo_mail_idempotency_conflict' === $registered_retention_conflict->get_error_code(), 'Il replay può cambiare la retention senza conflitto.', $passes, $failures);
	corsivo_mail_runtime_check(
		is_array($registered_queue_row)
			&& (int) $registered_queue_row['source_guard_required'] === 1
			&& $registered_queue_row['retain_until'] === $registered_retain,
		'L’API registrata non persiste guard e retention nella coda.',
		$passes,
		$failures
	);
} finally {
	global $wpdb;
	remove_filter('corsivo_mail_core_emails', $registered_provider);
	if ($registered_queue_id) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), ['id' => $registered_queue_id], ['%d']);
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['queue_id' => $registered_queue_id], ['%d']);
	}
	if ($registered_post_id && !is_wp_error($registered_post_id)) {
		wp_delete_post($registered_post_id, true);
	}
	if (null === $registered_options_before) {
		delete_option(\CorsivoMail\Options\OPTION);
	} else {
		update_option(\CorsivoMail\Options\OPTION, $registered_options_before);
	}
}
$override_queue_id = 0;
$override_account_id = 'acc_runtime_' . strtolower(wp_generate_password(10, false, false));
$override_accounts_before = corsivo_mail_raw_option(\CorsivoMail\Accounts\OPTION);
$override_settings_before = corsivo_mail_raw_option(\CorsivoMail\Options\OPTION);
try {
	$override_account = array_merge(\CorsivoMail\Accounts\defaults(), [
		'id' => $override_account_id,
		'label' => 'Runtime account override',
		'from_email' => 'runtime-account@example.invalid',
		'is_default' => true,
	]);
	update_option(\CorsivoMail\Accounts\OPTION, [$override_account]);
	$override_settings = \CorsivoMail\Options\all();
	$override_settings['use_queue'] = true;
	$override_settings['disable_sending'] = false;
	update_option(\CorsivoMail\Options\OPTION, $override_settings);
	unset($GLOBALS['corsivo_mail_last_queue_id']);
	\CorsivoMail\Automation\begin_override($override_account_id);
	$override_result = wp_mail('runtime-override@example.invalid', 'Runtime override', 'Runtime override');
	$override_queue_id = (int) ($GLOBALS['corsivo_mail_last_queue_id'] ?? 0);
	$override_row = $override_queue_id ? \CorsivoMail\Queue\get_row($override_queue_id) : null;
	corsivo_mail_runtime_check($override_result && $override_row && $override_row['account_id'] === $override_account_id, 'Override core non propaga l\'account mittente alla coda.', $passes, $failures);
} finally {
	global $wpdb;
	unset($GLOBALS['corsivo_mail_override_active'], $GLOBALS['corsivo_mail_override_account_id'], $GLOBALS['corsivo_mail_last_queue_id'], $GLOBALS['corsivo_mail_mail_account_id']);
	if ($override_queue_id) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), ['id' => $override_queue_id], ['%d']);
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['queue_id' => $override_queue_id], ['%d']);
	}
	if ($override_accounts_before === null) {
		delete_option(\CorsivoMail\Accounts\OPTION);
	} else {
		update_option(\CorsivoMail\Accounts\OPTION, $override_accounts_before);
	}
	if ($override_settings_before === null) {
		delete_option(\CorsivoMail\Options\OPTION);
	} else {
		update_option(\CorsivoMail\Options\OPTION, $override_settings_before);
	}
	corsivo_mail_runtime_check(
		corsivo_mail_raw_option(\CorsivoMail\Accounts\OPTION) === $override_accounts_before && corsivo_mail_raw_option(\CorsivoMail\Options\OPTION) === $override_settings_before,
		'Il test override non ha ripristinato account e impostazioni.',
		$passes,
		$failures
	);
}

$marketing_post_id = 0;
$marketing_queue_id = 0;
$marketing_queue_settings_before = corsivo_mail_raw_option(\CorsivoMail\Options\OPTION, []);
try {
	$marketing_queue_settings = \CorsivoMail\Options\all();
	$marketing_queue_settings['module_subscriptions'] = false;
	update_option(\CorsivoMail\Options\OPTION, $marketing_queue_settings);
	$marketing_post_id = wp_insert_post([
		'post_type' => \CorsivoMail\Automation\TYPE,
		'post_status' => 'draft',
		'post_title' => 'Corsivo Mail marketing queue test',
	]);
	$marketing_queue_id = \CorsivoMail\Queue\enqueue([
		'to' => 'marketing-queue@example.invalid',
		'subject' => 'Marketing queue',
		'message' => 'Marketing queue',
		'source_type' => 'automation',
		'source_id' => $marketing_post_id,
		'message_type' => 'marketing',
	]);
	$marketing_row = is_wp_error($marketing_queue_id) ? null : \CorsivoMail\Queue\get_row($marketing_queue_id);
	corsivo_mail_runtime_check($marketing_row && $marketing_row['message_type'] === 'marketing', 'Tipo marketing non salvato nella coda.', $passes, $failures);
} finally {
	global $wpdb;
	update_option(\CorsivoMail\Options\OPTION, $marketing_queue_settings_before);
	if ($marketing_queue_id && !is_wp_error($marketing_queue_id)) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), ['id' => (int) $marketing_queue_id], ['%d']);
	}
	if ($marketing_post_id && !is_wp_error($marketing_post_id)) {
		wp_delete_post($marketing_post_id, true);
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['source_type' => \CorsivoMail\Automation\TYPE, 'source_id' => $marketing_post_id], ['%s', '%d']);
	}
}

$batch_post_id = 0;
$batch_queue_id = 0;
try {
	$batch_post_id = wp_insert_post([
		'post_type' => \CorsivoMail\Automation\TYPE,
		'post_status' => 'draft',
		'post_title' => 'Corsivo Mail batch runtime test',
		'post_content' => '<!-- wp:paragraph --><p>Batch test</p><!-- /wp:paragraph -->',
	]);
	update_post_meta($batch_post_id, 'corsivo_mail_send_enabled', true);
	update_post_meta($batch_post_id, 'corsivo_mail_message_type', 'transactional');
	$operation_id = 'runtime-batch-' . wp_generate_uuid4();
	$request = new WP_REST_Request('POST', '/corsivo/v1/mail/send-batch');
	$request->set_body_params([
		'post' => $batch_post_id,
		'operation_id' => $operation_id,
		'recipients' => [['email' => 'nobody@example.invalid', 'user_id' => 0]],
	]);
	$first_batch = rest_do_request($request);
	$first_data = $first_batch->get_data();
	$batch_queue_id = (int) ($first_data['results'][0]['queue_id'] ?? 0);
	$request = new WP_REST_Request('POST', '/corsivo/v1/mail/send-batch');
	$request->set_body_params([
		'post' => $batch_post_id,
		'operation_id' => $operation_id,
		'recipients' => [['email' => 'nobody@example.invalid', 'user_id' => 0]],
	]);
	$second_batch = rest_do_request($request);
	$second_data = $second_batch->get_data();
	corsivo_mail_runtime_check($first_batch->get_status() === 200 && $batch_queue_id > 0, 'Batch server-side non accodato.', $passes, $failures);
	corsivo_mail_runtime_check((int) ($second_data['results'][0]['queue_id'] ?? 0) === $batch_queue_id, 'Batch server-side non idempotente.', $passes, $failures);
} finally {
	global $wpdb;
	if ($batch_queue_id) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), ['id' => $batch_queue_id], ['%d']);
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['queue_id' => $batch_queue_id], ['%d']);
	}
	if ($batch_post_id) {
		wp_delete_post($batch_post_id, true);
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['source_type' => \CorsivoMail\Automation\TYPE, 'source_id' => $batch_post_id], ['%s', '%d']);
	}
}

$protocol_queue_ids = [];
$protocol_terminal_events = [];
$protocol_terminal_listener = static function ($event) use (&$protocol_terminal_events) {
	$protocol_terminal_events[] = $event;
};
add_action('corsivo_mail_queue_terminal', $protocol_terminal_listener, 90, 1);
try {
	$enqueue_protocol = static function ($source_type, $source_id, $guard_required = false, $retain_until = '') use (&$protocol_queue_ids) {
		$queue_id = \CorsivoMail\Queue\enqueue([
			'to' => 'protocol@example.invalid',
			'subject' => 'Protocol test',
			'message' => '<p>protocol-secret</p>',
			'headers' => ['Content-Type: text/html; charset=UTF-8'],
			'scheduled_at' => current_time('mysql'),
			'idempotency_key' => 'protocol:' . wp_generate_uuid4(),
			'idempotency_fingerprint' => hash('sha256', wp_generate_uuid4()),
			'source_type' => $source_type,
			'source_id' => $source_id,
			'source_guard_required' => $guard_required,
			'retain_until' => $retain_until,
		]);
		if (!is_wp_error($queue_id)) {
			$protocol_queue_ids[] = (int) $queue_id;
		}
		return $queue_id;
	};

	$status_queue_id = $enqueue_protocol('runtime-status', 101);
	$status_pending = corsivo_mail_queue_status($status_queue_id, 'runtime-status', 101);
	$status_mismatch = corsivo_mail_queue_status($status_queue_id, 'runtime-status', 102);
	corsivo_mail_runtime_check(
		is_array($status_pending) && $status_pending['status'] === 'pending',
		'Status API non restituisce la coda source-bound.',
		$passes,
		$failures
	);
	corsivo_mail_runtime_check(
		is_wp_error($status_mismatch) && $status_mismatch->get_error_code() === 'corsivo_mail_queue_identity_mismatch',
		'Status API accetta una source identity diversa.',
		$passes,
		$failures
	);
	$status_claimed = \CorsivoMail\Queue\claim_batch(1, $status_queue_id);
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['sent_at' => current_time('mysql')],
		['id' => $status_queue_id]
	);
	$status_corrupt = corsivo_mail_queue_status($status_queue_id, 'runtime-status', 101);
	corsivo_mail_runtime_check(
		is_wp_error($status_corrupt) && $status_corrupt->get_error_code() === 'corsivo_mail_queue_status_invalid',
		'Status API accetta un lifecycle incoerente.',
		$passes,
		$failures
	);
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['sent_at' => null],
		['id' => $status_queue_id]
	);
	if ($status_claimed) {
		\CorsivoMail\Queue\mark_failed(
			$status_queue_id,
			$status_claimed[0]['lock_token'],
			1,
			1,
			'Status cleanup',
			'runtime_cleanup',
			true
		);
	}

	$pending_cancel_id = $enqueue_protocol('runtime-cancel-pending', 201);
	$pending_cancelled = corsivo_mail_cancel_queued($pending_cancel_id, 'runtime-cancel-pending', 201);
	$pending_cancel_row = \CorsivoMail\Queue\get_row($pending_cancel_id);
	corsivo_mail_runtime_check(
		is_array($pending_cancelled)
			&& $pending_cancelled['status'] === 'cancelled'
			&& $pending_cancel_row['body'] === '',
		'Cancellazione pending non terminale o payload non purgato.',
		$passes,
		$failures
	);

	$fence_events_before = count($protocol_terminal_events);
	$pending_fence_id = $enqueue_protocol('runtime-fence-pending', 211);
	$pending_fence = corsivo_mail_fence_queued($pending_fence_id, 'runtime-fence-pending', 211);
	$pending_fence_row = \CorsivoMail\Queue\get_row($pending_fence_id);
	$pending_fence_mismatch = corsivo_mail_fence_queued($pending_fence_id, 'runtime-fence-pending', 212);
	corsivo_mail_runtime_check(
		is_array($pending_fence)
			&& $pending_fence['status'] === 'pending'
			&& (int) $pending_fence_row['cancel_requested'] === 1
			&& is_wp_error($pending_fence_mismatch)
			&& $pending_fence_mismatch->get_error_code() === 'corsivo_mail_queue_identity_mismatch'
			&& count($protocol_terminal_events) === $fence_events_before,
		'Fence pending non è source-bound, non persiste o emette terminal dispatch.',
		$passes,
		$failures
	);

	$rollback_fence_id = $enqueue_protocol('runtime-fence-rollback', 213);
	$wpdb->query('START TRANSACTION');
	$rollback_fence = corsivo_mail_fence_queued($rollback_fence_id, 'runtime-fence-rollback', 213);
	$rollback_inside = \CorsivoMail\Queue\get_row($rollback_fence_id);
	$wpdb->query('ROLLBACK');
	$rollback_after = \CorsivoMail\Queue\get_row($rollback_fence_id);
	corsivo_mail_runtime_check(
		is_array($rollback_fence)
			&& (int) $rollback_inside['cancel_requested'] === 1
			&& (int) $rollback_after['cancel_requested'] === 0,
		'Fence Mail non partecipa atomicamente alla transazione chiamante.',
		$passes,
		$failures
	);

	$sending_fence_id = $enqueue_protocol('runtime-fence-sending', 214);
	$sending_fence_claimed = \CorsivoMail\Queue\claim_batch(1, $sending_fence_id);
	$wpdb->query('START TRANSACTION');
	$sending_fence = corsivo_mail_fence_queued($sending_fence_id, 'runtime-fence-sending', 214);
	$wpdb->query('COMMIT');
	$sending_fence_row = \CorsivoMail\Queue\get_row($sending_fence_id);
	$sending_start_after_fence = $sending_fence_claimed
		? \CorsivoMail\Queue\start_transport($sending_fence_id, $sending_fence_claimed[0]['lock_token'])
		: null;
	corsivo_mail_runtime_check(
		is_array($sending_fence)
			&& $sending_fence['status'] === 'sending'
			&& (int) $sending_fence_row['cancel_requested'] === 1
			&& $sending_start_after_fence === false
			&& count($protocol_terminal_events) === $fence_events_before,
		'Fence committed non blocca start_transport o emette terminal dispatch.',
		$passes,
		$failures
	);

	$transmitting_fence_id = $enqueue_protocol('runtime-fence-transmitting', 215);
	$transmitting_fence_claimed = \CorsivoMail\Queue\claim_batch(1, $transmitting_fence_id);
	$transmitting_started = $transmitting_fence_claimed
		? \CorsivoMail\Queue\start_transport($transmitting_fence_id, $transmitting_fence_claimed[0]['lock_token'])
		: false;
	$transmitting_fence = corsivo_mail_fence_queued($transmitting_fence_id, 'runtime-fence-transmitting', 215);
	$transmitting_fence_row = \CorsivoMail\Queue\get_row($transmitting_fence_id);
	corsivo_mail_runtime_check(
		$transmitting_started === true
			&& is_array($transmitting_fence)
			&& $transmitting_fence['status'] === 'transmitting'
			&& (int) $transmitting_fence_row['cancel_requested'] === 1
			&& count($protocol_terminal_events) === $fence_events_before,
		'Fence transmitting non persiste senza terminal dispatch.',
		$passes,
		$failures
	);

	$terminal_fence_id = $enqueue_protocol('runtime-fence-terminal', 216);
	$terminal_fence_claimed = \CorsivoMail\Queue\claim_batch(1, $terminal_fence_id);
	if ($terminal_fence_claimed) {
		\CorsivoMail\Queue\start_transport($terminal_fence_id, $terminal_fence_claimed[0]['lock_token']);
		\CorsivoMail\Queue\mark_sent($terminal_fence_id, $terminal_fence_claimed[0]['lock_token']);
	}
	$terminal_events_before_fence = count($protocol_terminal_events);
	$terminal_fence = corsivo_mail_fence_queued($terminal_fence_id, 'runtime-fence-terminal', 216);
	$terminal_fence_row = \CorsivoMail\Queue\get_row($terminal_fence_id);
	corsivo_mail_runtime_check(
		is_array($terminal_fence)
			&& $terminal_fence['status'] === 'sent'
			&& (int) $terminal_fence_row['cancel_requested'] === 0
			&& count($protocol_terminal_events) === $terminal_events_before_fence,
		'Fence muta una riga terminale o ridispatcha il terminale.',
		$passes,
		$failures
	);

	$sending_cancel_id = $enqueue_protocol('runtime-cancel-sending', 202, true);
	$sending_claimed = \CorsivoMail\Queue\claim_batch(1, $sending_cancel_id);
	$sending_cancelled = corsivo_mail_cancel_queued($sending_cancel_id, 'runtime-cancel-sending', 202);
	$sending_enforced = $sending_claimed
		? \CorsivoMail\Queue\enforce_source_guard($sending_claimed[0], $sending_claimed[0]['lock_token'])
		: null;
	$sending_cancel_row = \CorsivoMail\Queue\get_row($sending_cancel_id);
	corsivo_mail_runtime_check(
		is_wp_error($sending_cancelled)
			&& $sending_cancelled->get_error_code() === 'corsivo_mail_queue_already_sending'
			&& $sending_enforced === false
			&& $sending_cancel_row['status'] === 'cancelled'
			&& $sending_cancel_row['body'] === '',
		'Fence di cancellazione sending non rispettato.',
		$passes,
		$failures
	);

	$sent_wins_id = $enqueue_protocol('runtime-sent-wins', 203);
	$sent_wins_claimed = \CorsivoMail\Queue\claim_batch(1, $sent_wins_id);
	$sent_wins_started = $sent_wins_claimed
		? \CorsivoMail\Queue\start_transport($sent_wins_id, $sent_wins_claimed[0]['lock_token'])
		: false;
	$sent_wins_cancel = corsivo_mail_cancel_queued($sent_wins_id, 'runtime-sent-wins', 203);
	$sent_wins_saved = $sent_wins_claimed
		? \CorsivoMail\Queue\mark_sent($sent_wins_id, $sent_wins_claimed[0]['lock_token'])
		: false;
	$sent_wins_row = \CorsivoMail\Queue\get_row($sent_wins_id);
	corsivo_mail_runtime_check(
		$sent_wins_started === true
			&& is_wp_error($sent_wins_cancel)
			&& $sent_wins_saved === 1
			&& $sent_wins_row['status'] === 'sent',
		'Una cancellazione concorrente falsifica un trasporto già riuscito.',
		$passes,
		$failures
	);

	$pending_cancel_crash_id = $enqueue_protocol('runtime-pending-cancel-crash', 2041);
	$pending_cancel_flagged = $wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['cancel_requested' => 1],
		['id' => $pending_cancel_crash_id, 'status' => 'pending']
	);
	$pending_cancel_crash_events = [];
	$pending_cancel_crash_reaped = \CorsivoMail\Queue\requeue_stale(3, $pending_cancel_crash_events);
	$pending_cancel_crash_row = \CorsivoMail\Queue\get_row($pending_cancel_crash_id);
	corsivo_mail_runtime_check(
		$pending_cancel_flagged === 1
			&& $pending_cancel_crash_reaped >= 1
			&& $pending_cancel_crash_row['status'] === 'cancelled'
			&& $pending_cancel_crash_row['body'] === ''
			&& in_array($pending_cancel_crash_id, array_map(static function ($event) { return (int) ($event['queue_id'] ?? 0); }, $pending_cancel_crash_events), true),
		'Crash tra flag e terminalizzazione lascia pending cancel_requested zombie.',
		$passes,
		$failures
	);

	$stale_cancel_id = $enqueue_protocol('runtime-stale-cancel', 204);
	$stale_cancel_claimed = \CorsivoMail\Queue\claim_batch(1, $stale_cancel_id);
	corsivo_mail_cancel_queued($stale_cancel_id, 'runtime-stale-cancel', 204);
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['locked_at' => gmdate('Y-m-d H:i:s', current_time('timestamp') - 10 * MINUTE_IN_SECONDS)],
		['id' => $stale_cancel_id]
	);
	$stale_cancel_events = [];
	$stale_cancel_count = \CorsivoMail\Queue\requeue_stale(3, $stale_cancel_events);
	$stale_cancel_row = \CorsivoMail\Queue\get_row($stale_cancel_id);
	corsivo_mail_runtime_check(
		$stale_cancel_claimed
			&& $stale_cancel_count >= 1
			&& $stale_cancel_row['status'] === 'cancelled'
			&& in_array($stale_cancel_id, array_map(static function ($event) { return (int) ($event['queue_id'] ?? 0); }, $stale_cancel_events), true),
		'Worker morto dopo cancel_requested lascia una coda zombie.',
		$passes,
		$failures
	);

	$unknown_id = $enqueue_protocol('runtime-delivery-unknown', 205);
	$unknown_claimed = \CorsivoMail\Queue\claim_batch(1, $unknown_id);
	$unknown_started = $unknown_claimed
		? \CorsivoMail\Queue\start_transport($unknown_id, $unknown_claimed[0]['lock_token'])
		: false;
	$unknown_mark_sent = $unknown_claimed
		? \CorsivoMail\Queue\mark_sent($unknown_id, 'lost-lock-token')
		: false;
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['transport_started_at' => gmdate('Y-m-d H:i:s', current_time('timestamp') - 10 * MINUTE_IN_SECONDS)],
		['id' => $unknown_id]
	);
	$unknown_events = [];
	$unknown_reaped = \CorsivoMail\Queue\requeue_stale(3, $unknown_events);
	$unknown_row = \CorsivoMail\Queue\get_row($unknown_id);
	$unknown_status = corsivo_mail_queue_status($unknown_id, 'runtime-delivery-unknown', 205);
	corsivo_mail_runtime_check(
		$unknown_started === true
			&& $unknown_mark_sent === 0
			&& $unknown_reaped >= 1
			&& $unknown_row['status'] === 'delivery_unknown'
			&& $unknown_row['body'] === ''
			&& $unknown_row['headers'] === ''
			&& $unknown_row['attachments'] === ''
			&& $unknown_row['embeds'] === ''
			&& is_array($unknown_status)
			&& $unknown_status['status'] === 'delivery_unknown'
			&& in_array($unknown_id, array_map(static function ($event) { return (int) ($event['queue_id'] ?? 0); }, $unknown_events), true),
		'Crash o write failure post-trasporto viene reinviato o conserva il payload.',
		$passes,
		$failures
	);

	$guard_missing_id = $enqueue_protocol('runtime-guard-missing', 301, true);
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['max_attempts' => 2],
		['id' => $guard_missing_id]
	);
	for ($guard_attempt = 0; $guard_attempt < 2; $guard_attempt++) {
		$wpdb->update(
			\CorsivoMail\Queue\table_name(),
			['scheduled_at' => current_time('mysql')],
			['id' => $guard_missing_id]
		);
		$guard_claimed = \CorsivoMail\Queue\claim_batch(1, $guard_missing_id);
		if ($guard_claimed) {
			\CorsivoMail\Queue\send_row($guard_claimed[0]);
		}
	}
	$guard_missing_row = \CorsivoMail\Queue\get_row($guard_missing_id);
	corsivo_mail_runtime_check(
		$guard_missing_row['status'] === 'failed'
			&& (int) $guard_missing_row['attempts'] === 2
			&& $guard_missing_row['error_code'] === 'source_guard_unavailable'
			&& $guard_missing_row['body'] !== '',
		'Guard obbligatorio assente non termina con limite o perde il payload.',
		$passes,
		$failures
	);

	$throw_source = 'runtime-guard-throw';
	$throw_guard = static function () {
		throw new RuntimeException('runtime guard failure');
	};
	add_filter('corsivo_mail_queue_source_guard_' . $throw_source, $throw_guard, 10, 2);
	$guard_throw_id = $enqueue_protocol($throw_source, 302, true);
	$guard_throw_claimed = \CorsivoMail\Queue\claim_batch(1, $guard_throw_id);
	if ($guard_throw_claimed) {
		\CorsivoMail\Queue\send_row($guard_throw_claimed[0]);
	}
	remove_filter('corsivo_mail_queue_source_guard_' . $throw_source, $throw_guard, 10);
	$guard_throw_row = \CorsivoMail\Queue\get_row($guard_throw_id);
	corsivo_mail_runtime_check(
		$guard_throw_row['status'] === 'pending'
			&& $guard_throw_row['error_code'] === 'source_guard_unavailable'
			&& $guard_throw_row['body'] !== '',
		'Throwable del guard degrada in invio o perde il payload.',
		$passes,
		$failures
	);

	$deny_source = 'runtime-guard-deny';
	$allow_called = false;
	$deny_guard = static function () {
		return false;
	};
	$allow_guard = static function () use (&$allow_called) {
		$allow_called = true;
		return true;
	};
	$throwing_terminal_listener = static function () {
		throw new RuntimeException('runtime terminal listener');
	};
	$surviving_terminal_calls = 0;
	$surviving_terminal_listener = static function () use (&$surviving_terminal_calls) {
		$surviving_terminal_calls++;
	};
	add_filter('corsivo_mail_queue_source_guard_' . $deny_source, $deny_guard, 5, 2);
	add_filter('corsivo_mail_queue_source_guard_' . $deny_source, $allow_guard, 20, 2);
	add_action('corsivo_mail_queue_terminal', $throwing_terminal_listener, 1, 1);
	add_action('corsivo_mail_queue_terminal', $surviving_terminal_listener, 2, 1);
	$guard_deny_id = $enqueue_protocol($deny_source, 303, true);
	$guard_deny_claimed = \CorsivoMail\Queue\claim_batch(1, $guard_deny_id);
	if ($guard_deny_claimed) {
		\CorsivoMail\Queue\send_row($guard_deny_claimed[0]);
	}
	remove_filter('corsivo_mail_queue_source_guard_' . $deny_source, $deny_guard, 5);
	remove_filter('corsivo_mail_queue_source_guard_' . $deny_source, $allow_guard, 20);
	remove_action('corsivo_mail_queue_terminal', $throwing_terminal_listener, 1);
	remove_action('corsivo_mail_queue_terminal', $surviving_terminal_listener, 2);
	$guard_deny_row = \CorsivoMail\Queue\get_row($guard_deny_id);
	corsivo_mail_runtime_check(
		$guard_deny_row['status'] === 'cancelled'
			&& $guard_deny_row['body'] === ''
			&& !$allow_called,
		'Un allow successivo sovrascrive il veto del guard.',
		$passes,
		$failures
	);
	corsivo_mail_runtime_check(
		$surviving_terminal_calls === 1,
		'Un listener terminale in eccezione blocca i listener successivi.',
		$passes,
		$failures
	);

	$requeue_id = $enqueue_protocol('runtime-requeue', 401);
	$requeue_claimed = \CorsivoMail\Queue\claim_batch(1, $requeue_id);
	if ($requeue_claimed) {
		\CorsivoMail\Queue\mark_failed(
			$requeue_id,
			$requeue_claimed[0]['lock_token'],
			1,
			1,
			'Runtime terminal failure',
			'runtime_failure',
			true
		);
	}
	$requeued = \CorsivoMail\Queue\requeue_one($requeue_id);
	$requeue_row = \CorsivoMail\Queue\get_row($requeue_id);
	corsivo_mail_runtime_check(
		$requeued === 1
			&& (int) $requeue_row['id'] === (int) $requeue_id
			&& $requeue_row['status'] === 'pending'
			&& (int) $requeue_row['attempts'] === 0,
		'Requeue manuale non conserva la queue identity.',
		$passes,
		$failures
	);

	$retain_future = current_datetime()->modify('+2 days')->format('Y-m-d H:i:s');
	$retention_id = $enqueue_protocol('runtime-retention', 501, false, $retain_future);
	$retention_claimed = \CorsivoMail\Queue\claim_batch(1, $retention_id);
	if ($retention_claimed) {
		\CorsivoMail\Queue\start_transport($retention_id, $retention_claimed[0]['lock_token']);
		\CorsivoMail\Queue\mark_sent($retention_id, $retention_claimed[0]['lock_token']);
	}
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['completed_at' => '1900-01-01 00:00:00'],
		['id' => $retention_id]
	);
	\CorsivoMail\Queue\cleanup(36500);
	$retained_row = \CorsivoMail\Queue\get_row($retention_id);
	$wpdb->update(
		\CorsivoMail\Queue\table_name(),
		['retain_until' => gmdate('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS)],
		['id' => $retention_id]
	);
	\CorsivoMail\Queue\cleanup(36500);
	$expired_retention_row = \CorsivoMail\Queue\get_row($retention_id);
	corsivo_mail_runtime_check(
		$retained_row
			&& $retained_row['retain_until'] === $retain_future
			&& !$expired_retention_row,
		'Cleanup ignora la retention persistita o non elimina dopo la scadenza.',
		$passes,
		$failures
	);

	$policy_key = 'runtime-policy-' . wp_generate_uuid4();
	$policy_fingerprint = hash('sha256', 'runtime-policy');
	$policy_id = \CorsivoMail\Queue\enqueue([
		'to' => 'policy@example.invalid',
		'subject' => 'Policy',
		'message' => 'Policy',
		'scheduled_at' => current_time('mysql'),
		'idempotency_key' => $policy_key,
		'idempotency_fingerprint' => $policy_fingerprint,
		'source_type' => 'runtime-policy',
		'source_id' => 601,
	]);
	if (!is_wp_error($policy_id)) {
		$protocol_queue_ids[] = (int) $policy_id;
		$wpdb->update(
			\CorsivoMail\Queue\table_name(),
			['source_guard_required' => 0],
			['id' => $policy_id]
		);
	}
	$policy_replay = \CorsivoMail\Queue\enqueue([
		'to' => 'policy@example.invalid',
		'subject' => 'Policy',
		'message' => 'Policy',
		'scheduled_at' => current_time('mysql'),
		'idempotency_key' => $policy_key,
		'idempotency_fingerprint' => $policy_fingerprint,
		'source_type' => 'runtime-policy',
		'source_id' => 601,
		'source_guard_required' => true,
	]);
	$policy_row = !is_wp_error($policy_id) ? \CorsivoMail\Queue\get_row($policy_id) : null;
	corsivo_mail_runtime_check(
		!is_wp_error($policy_replay)
			&& (int) $policy_replay === (int) $policy_id
			&& (int) $policy_row['source_guard_required'] === 1,
		'Replay idempotente declassa o non eleva la policy guard.',
		$passes,
		$failures
	);
} finally {
	remove_action('corsivo_mail_queue_terminal', $protocol_terminal_listener, 90);
	foreach (array_unique(array_map('intval', $protocol_queue_ids)) as $protocol_queue_id) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), ['id' => $protocol_queue_id], ['%d']);
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['queue_id' => $protocol_queue_id], ['%d']);
	}
}

$subscription_filter = 'corsivo_newsletter_disable_corsivo_mail_subscription_module';
$subscription_filter_priority = has_filter('option_' . \CorsivoMail\Options\OPTION, $subscription_filter);
if ($subscription_filter_priority !== false) {
	remove_filter('option_' . \CorsivoMail\Options\OPTION, $subscription_filter, $subscription_filter_priority);
}
$settings_before_marketing_test = corsivo_mail_raw_option(\CorsivoMail\Options\OPTION, []);
$marketing_user_id = 0;
try {
	$settings = \CorsivoMail\Options\all();
	$settings['module_subscriptions'] = true;
	update_option(\CorsivoMail\Options\OPTION, $settings);
	$email = 'corsivo-mail-test-' . wp_generate_password(10, false) . '@example.invalid';
	$marketing_user_id = wp_insert_user([
		'user_login' => 'corsivo_mail_' . wp_generate_password(10, false),
		'user_pass' => wp_generate_password(24, true, true),
		'user_email' => $email,
	]);
	if (!is_wp_error($marketing_user_id)) {
		\CorsivoMail\Newsletter\set_subscribed($marketing_user_id, false, 'test');
		corsivo_mail_runtime_check(!\CorsivoMail\Newsletter\can_receive_marketing($email), 'Utente disiscritto non soppresso.', $passes, $failures);
		\CorsivoMail\Newsletter\set_subscribed($marketing_user_id, true, 'test');
		corsivo_mail_runtime_check(\CorsivoMail\Newsletter\can_receive_marketing($email), 'Utente iscritto soppresso.', $passes, $failures);
		$marketing_headers = \CorsivoMail\Newsletter\add_marketing_headers([
			'to' => $email,
			'headers' => ['Content-Type: text/html; charset=UTF-8'],
		]);
		$marketing_header_lines = \CorsivoMail\Newsletter\marketing_header_lines($marketing_headers['headers'] ?? []);
		corsivo_mail_runtime_check(
			!is_wp_error(\CorsivoMail\Newsletter\marketing_unsubscribe_preflight($email, $marketing_header_lines, true))
				&& count(array_filter($marketing_header_lines, static fn($header) => stripos($header, 'List-Unsubscribe:') === 0)) === 1
				&& count(array_filter($marketing_header_lines, static fn($header) => stripos($header, 'List-Unsubscribe-Post:') === 0)) === 1,
			'Header di disiscrizione marketing non completi.',
			$passes,
			$failures
		);
		$copy_preflight = \CorsivoMail\Newsletter\marketing_unsubscribe_preflight($email, ['Cc: copy@example.invalid']);
		corsivo_mail_runtime_check(
			is_wp_error($copy_preflight) && $copy_preflight->get_error_code() === 'corsivo_mail_marketing_copies_unsupported',
			'Cc o Bcc accettati in una email marketing.',
			$passes,
			$failures
		);

		if (!defined(\CorsivoMail\Crypto\KEY_CONSTANT)) {
			$invalid_mail_key = static fn() => 'invalid';
			add_filter('option_' . \CorsivoMail\Crypto\MASTER_KEY_OPTION, $invalid_mail_key);
			$previous_message_type = $GLOBALS['corsivo_mail_message_type'] ?? null;
			try {
				$unavailable_preflight = \CorsivoMail\Newsletter\marketing_unsubscribe_preflight($email);
				corsivo_mail_runtime_check(
					is_wp_error($unavailable_preflight) && $unavailable_preflight->get_error_code() === 'corsivo_mail_unsubscribe_unavailable',
					'Master key Mail illeggibile non blocca il preflight marketing.',
					$passes,
					$failures
				);
				$unavailable_queue = \CorsivoMail\Queue\enqueue([
					'to' => $email,
					'subject' => 'Marketing unavailable key',
					'message' => 'Marketing unavailable key',
					'message_type' => 'marketing',
				]);
				corsivo_mail_runtime_check(
					is_wp_error($unavailable_queue) && $unavailable_queue->get_error_code() === 'corsivo_mail_unsubscribe_unavailable',
					'Email marketing accodata con master key Mail illeggibile.',
					$passes,
					$failures
				);
				$GLOBALS['corsivo_mail_message_type'] = 'marketing';
				$blocked_mail = \CorsivoMail\Newsletter\block_marketing_without_unsubscribe(null, [
					'to' => $email,
					'headers' => [],
				]);
				corsivo_mail_runtime_check(
					$blocked_mail === false
						&& is_wp_error($GLOBALS['corsivo_mail_last_error'] ?? null)
						&& $GLOBALS['corsivo_mail_last_error']->get_error_code() === 'corsivo_mail_unsubscribe_unavailable',
					'Trasporto marketing non bloccato con master key Mail illeggibile.',
					$passes,
					$failures
				);
			} finally {
				remove_filter('option_' . \CorsivoMail\Crypto\MASTER_KEY_OPTION, $invalid_mail_key);
				if ($previous_message_type === null) {
					unset($GLOBALS['corsivo_mail_message_type']);
				} else {
					$GLOBALS['corsivo_mail_message_type'] = $previous_message_type;
				}
				unset($GLOBALS['corsivo_mail_last_error']);
			}
		}
		$key = \CorsivoMail\Newsletter\token($marketing_user_id);
		corsivo_mail_runtime_check(\CorsivoMail\Newsletter\valid_token($marketing_user_id, $key, 'unsubscribe'), 'Token unsubscribe site-specifico non valido.', $passes, $failures);
		$auth_salt_before_rotation = wp_salt('auth');
		$wordpress_salt_token = hash_hmac(
			'sha256',
			'corsivo-mail-' . get_current_blog_id() . '-unsubscribe-' . (int) $marketing_user_id,
			$auth_salt_before_rotation
		);
		corsivo_mail_runtime_check(!\CorsivoMail\Newsletter\valid_token($marketing_user_id, $wordpress_salt_token, 'unsubscribe'), 'Token unsubscribe legacy basato sul salt WordPress ancora accettato.', $passes, $failures);
		$rotate_auth_salt = static function ($salt, $scheme) {
			return $scheme === 'auth' ? hash('sha256', 'rotated-' . $salt) : $salt;
		};
		add_filter('salt', $rotate_auth_salt, 99, 2);
		try {
			$auth_salt_after_rotation = wp_salt('auth');
			$key_after_salt_rotation = \CorsivoMail\Newsletter\token($marketing_user_id);
			corsivo_mail_runtime_check($auth_salt_after_rotation !== $auth_salt_before_rotation, 'Rotazione salt WordPress non simulata.', $passes, $failures);
			corsivo_mail_runtime_check(
				$key_after_salt_rotation === $key
					&& \CorsivoMail\Newsletter\valid_token($marketing_user_id, $key, 'unsubscribe')
					&& \CorsivoMail\Newsletter\valid_token($marketing_user_id, $key_after_salt_rotation, 'unsubscribe'),
				'Token unsubscribe invalidato dalla rotazione dei salt WordPress.',
				$passes,
				$failures
			);
		} finally {
			remove_filter('salt', $rotate_auth_salt, 99);
		}
		\CorsivoMail\Newsletter\set_subscribed($marketing_user_id, false, 'test');
		corsivo_mail_runtime_check(\CorsivoMail\Newsletter\has_explicit_optout($marketing_user_id), 'Opt-out esplicito non riconosciuto.', $passes, $failures);
		$settings_optout = \CorsivoMail\Options\all();
		$settings_optout['module_subscriptions'] = false;
		update_option(\CorsivoMail\Options\OPTION, $settings_optout);
		corsivo_mail_runtime_check(!\CorsivoMail\Newsletter\can_receive_marketing($email), 'Veto interno ignorato con modulo iscrizioni disattivato.', $passes, $failures);
		$settings_optout['module_subscriptions'] = true;
		update_option(\CorsivoMail\Options\OPTION, $settings_optout);
		\CorsivoMail\Newsletter\set_subscribed($marketing_user_id, true, 'test');
	} else {
		$failures[] = 'Creazione utente per test marketing fallita.';
	}
} finally {
	update_option(\CorsivoMail\Options\OPTION, $settings_before_marketing_test);
	corsivo_mail_runtime_check(corsivo_mail_raw_option(\CorsivoMail\Options\OPTION, []) === $settings_before_marketing_test, 'Il test marketing non ha ripristinato le impostazioni.', $passes, $failures);
	if ($subscription_filter_priority !== false) {
		add_filter('option_' . \CorsivoMail\Options\OPTION, $subscription_filter, $subscription_filter_priority);
	}
	if ($marketing_user_id && !is_wp_error($marketing_user_id)) {
		global $wpdb;
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['source_type' => 'user', 'source_id' => (int) $marketing_user_id], ['%s', '%d']);
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user($marketing_user_id);
	}
}

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
