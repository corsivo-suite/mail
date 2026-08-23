<?php

function corsivo_mail_schema_static_check($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$plugin = dirname(__DIR__);
$main = file_get_contents($plugin . '/corsivo-mail.php');
$schema = file_get_contents($plugin . '/includes/queue/schema.php');
$queue = file_get_contents($plugin . '/includes/queue/queue.php');
$contract = file_get_contents($plugin . '/includes/storage/contract.php');
$worker = file_get_contents($plugin . '/includes/queue/worker.php');
$schedule = file_get_contents($plugin . '/includes/automation/schedule.php');
$cron_endpoint = file_get_contents($plugin . '/includes/queue/cron-endpoint.php');
$uninstall = file_get_contents($plugin . '/uninstall.php');
$crypto = file_get_contents($plugin . '/includes/settings/crypto.php');
$log = file_get_contents($plugin . '/includes/queue/log.php');
$unsubscribe = file_get_contents($plugin . '/includes/newsletter/unsubscribe.php');
$seed = file_get_contents($plugin . '/includes/automation/seed.php');

foreach (array($main, $schema, $queue, $contract, $worker, $schedule, $cron_endpoint, $uninstall, $crypto, $log, $unsubscribe, $seed) as $source) {
	corsivo_mail_schema_static_check(is_string($source) && $source !== '', 'Un sorgente Mail richiesto non è leggibile.');
}

$storage_source = $schema . "\n" . $contract;
foreach (array('hash_hmac', 'storage_signing', 'DROP TABLE', 'ALTER TABLE', 'dbDelta', 'db_version', 'schema_version', 'migration', 'legacy') as $token) {
	corsivo_mail_schema_static_check(stripos($storage_source, $token) === false, 'Il contratto storage conserva un meccanismo vietato: ' . $token);
}
foreach (array('marker_format', 'intent_format', 'failure_format', 'operation_id', "'token' => bin2hex(random_bytes(32))", 'physical_fingerprint', 'source_fingerprint', 'generation_expression', 'create_options', 'constraints', 'row_format') as $token) {
	corsivo_mail_schema_static_check(strpos($storage_source, $token) !== false, 'Il contratto storage è incompleto: ' . $token);
}
corsivo_mail_schema_static_check(
	strpos($schema, "'payload_storage' => 'plain'") !== false
		&& strpos($queue, 'CorsivoMail\\Crypto') === false
		&& strpos($queue, 'Crypto\\encrypt') === false
		&& strpos($queue, 'Crypto\\decrypt') === false
		&& strpos($crypto, 'queue-body') === false
		&& strpos($crypto, 'queue-headers') === false
		&& strpos($crypto, 'queue-attachments') === false
		&& strpos($crypto, 'queue-embeds') === false,
	'La coda Mail conserva cifratura o reader crypto per payload ordinari.'
);

$hot_start = strpos($contract, 'function storage_runtime_ready(');
$hot_end = strpos($contract, 'function storage_lock_name(', $hot_start);
$hot_source = substr($contract, $hot_start, $hot_end - $hot_start);
corsivo_mail_schema_static_check(
	is_string($hot_source)
		&& strpos($hot_source, 'hot_option_record') !== false
		&& stripos($hot_source, 'information_schema') === false
		&& stripos($hot_source, 'schema_snapshot') === false
		&& stripos($hot_source, 'SHOW ') === false,
	'Il gate caldo Mail esegue ancora una verifica fisica.'
);
corsivo_mail_schema_static_check(
	strpos($hot_source, "artifact_has_future_format(\$marker['value'], 'marker_format')") !== false
		&& strpos($hot_source, 'storage_marker_is_valid($marker[\'value\'], $configuration, true)') !== false,
	'Le regole hot per marker corrente, futuro e source mismatch non sono congelate.'
);
corsivo_mail_schema_static_check(
	strpos($contract, 'storage_marker_is_opaque') === false
		&& strpos($contract, "schedule_storage_verification('opaque-marker')") === false,
	'Il contratto storage conserva un reader per marker legacy.'
);

corsivo_mail_schema_static_check(
	strpos($main, "require_once CORSIVO_MAIL_DIR . 'includes/queue/worker.php';") !== false
		&& strpos($main, "add_action('admin_init', __NAMESPACE__ . '\\\\verify_storage_on_admin', 1);") === false
		&& strpos($main, "add_action('corsivo_mail_verify_storage', __NAMESPACE__ . '\\\\verify_storage_event', 10, 1);") !== false
		&& strpos($main, "add_action('upgrader_process_complete'") !== false
		&& strpos($main, 'schedule_storage_verification_after_update') !== false
		&& strpos($main, "Queue\\schedule_storage_verification('update', 1);") !== false,
	'Gli health hook Mail devono evitare verifiche fisiche su ogni admin request.'
);
$prime_start = strpos($main, 'function prime_required_storage_options()');
$ready_start = strpos($main, 'function required_storage_ready(');
$prime_call = strpos($main, 'prime_required_storage_options();', $ready_start);
corsivo_mail_schema_static_check(
	$prime_start !== false
		&& strpos($main, 'wp_prime_option_caches(array_values(array_unique($options)))', $prime_start) !== false
		&& strpos($main, "'corsivo_mail_seeded_core_emails'", $prime_start) !== false
		&& strpos($main, "'corsivo_forms_settings'", $prime_start) !== false
		&& $prime_call !== false
		&& strpos($main, 'prime_required_storage_options();', $prime_call + 1) !== false,
	'Le opzioni del gate storage Mail non vengono caricate in un solo round trip.'
);
corsivo_mail_schema_static_check(
	substr_count($main, 'Queue\\reconcile_cron();') === 3
		&& strpos($main, 'Automation\\seed_core_emails(true);') !== false,
	'Il boot Mail deve lasciare la riconciliazione cron fuori da load_runtime e forzare il seed solo in attivazione.'
);
$seed_preflight = strpos($seed, 'if (!array_diff(array_keys($providers), $seeded))');
$seed_lock = strpos($seed, "add_option('corsivo_mail_seed_lock'");
corsivo_mail_schema_static_check(
	strpos($seed, 'function seed_core_emails($force = false)') !== false
		&& strpos($seed, 'if (!$force && !current_user_can(\'manage_options\'))') !== false
		&& $seed_preflight !== false
		&& $seed_lock !== false
		&& $seed_preflight < $seed_lock,
	'Il seed Mail deve uscire prima del lock quando i template core sono già presenti.'
);
corsivo_mail_schema_static_check(
	strpos($main, "const ADMIN_STORAGE_VERIFY_INTERVAL = 900;") !== false
		&& strpos($main, "function claim_admin_storage_verification()") !== false
		&& strpos($main, "function verify_storage_on_admin(") !== false
		&& strpos($main, "current_user_can('manage_options')") !== false
		&& strpos($main, "release_admin_storage_verification(\$verify_after)") !== false,
	'Il percorso admin Mail non applica il throttle persistente o il gate di capability.'
);
$update_start = strpos($main, 'function schedule_storage_verification_after_update(');
$update_end = strpos($main, 'function storage_unavailable_notice(', $update_start);
$update_source = substr($main, $update_start, $update_end - $update_start);
$update_persist_position = strpos($update_source, 'persist_storage_update_verification()');
$update_schedule_position = strpos($update_source, "schedule_storage_verification('update', 1)");
$bootstrap_start = strpos($main, "add_action('admin_notices'");
$bootstrap_fence_position = strpos($main, 'if (Queue\\storage_update_verification_required())', $bootstrap_start);
$bootstrap_runtime_position = strpos($main, "\nload_runtime();", $bootstrap_fence_position);
corsivo_mail_schema_static_check(
	strpos($contract, "const STORAGE_UPDATE_VERIFY_OPTION = 'corsivo_mail_storage_update_verification';") !== false
		&& strpos($contract, 'function clear_storage_update_verification($expected_token)') !== false
		&& strpos($contract, 'WHERE option_name = %s AND option_value = %s') !== false
		&& $update_persist_position !== false
		&& $update_schedule_position !== false
		&& $update_persist_position < $update_schedule_position
		&& strpos($update_source, 'Queue\\invalidate_storage_gate($configuration)') !== false
		&& $bootstrap_fence_position !== false
		&& $bootstrap_runtime_position !== false
		&& $bootstrap_fence_position < $bootstrap_runtime_position
		&& strpos($main, "add_filter('pre_wp_mail', __NAMESPACE__ . '\\\\fail_closed_wp_mail', PHP_INT_MAX, 2);") !== false
		&& strpos($contract, "'code_generation' => defined('CORSIVO_MAIL_VERSION')") !== false
		&& strpos($uninstall, "'corsivo_mail_storage_update_verification'") !== false,
	'L’update Mail non persiste un fence CAS verificato prima del runtime.'
);
corsivo_mail_schema_static_check(
	strpos($main, 'if ($short_circuit !== null)') !== false
		&& strpos($main, 'if (!governs_global_transport())') !== false
		&& strpos($main, 'if (required_storage_ready())') !== false,
	'Il fail-closed Mail non preserva lo short-circuit o invade trasporti altrui.'
);

$verify_position = strpos($worker, '$verified = verify_storage_before_work();');
$requeue_position = strpos($worker, '$stale_result = requeue_stale');
$claim_position = strpos($worker, '$batch = claim_batch(1);');
corsivo_mail_schema_static_check(
	$verify_position !== false
		&& $requeue_position !== false
		&& $claim_position !== false
		&& $verify_position < $requeue_position
		&& $requeue_position < $claim_position
		&& strpos($worker, "new \\WP_Error('corsivo_mail_queue_requeue_failed'") !== false,
	'Il worker Mail può reclamare righe senza verifica fisica o dopo un requeue fallito.'
);
corsivo_mail_schema_static_check(
	strpos($worker, "add_action('wp_mail_succeeded', \$success_listener, PHP_INT_MAX)") !== false
		&& strpos($worker, '$sent === true && $delivery_confirmed') !== false
		&& strpos($worker, 'mark_delivery_unknown') !== false,
	'Il worker Mail tratta ancora wp_mail true come prova sufficiente.'
);
corsivo_mail_schema_static_check(
	strpos($worker, 'function storage_preflight_request_key()') !== false
		&& strpos($worker, 'static $verified_contexts = array();') !== false
		&& strpos($worker, '$request_key = storage_preflight_request_key();') !== false
		&& strpos($worker, 'if (!empty($verified_contexts[$request_key]) && \\CorsivoMail\\required_storage_ready())') !== false,
	'Il preflight Mail non memoizza in sicurezza il successo nella request.'
);

$schedule_run_start = strpos($schedule, 'function run_schedules(');
$schedule_dispatch_start = strpos($schedule, 'function dispatch_schedules(', $schedule_run_start);
$schedule_run_source = substr($schedule, $schedule_run_start, $schedule_dispatch_start - $schedule_run_start);
$schedule_verify_position = strpos($schedule_run_source, 'Queue\\verify_storage_before_work()');
$schedule_dispatch_position = strpos($schedule_run_source, 'dispatch_schedules()');
$endpoint_verify_position = strpos($cron_endpoint, '$verified = verify_storage_before_work();');
$endpoint_schedule_position = strpos($cron_endpoint, '$schedules = \\CorsivoMail\\Automation\\run_schedules(true);');
$endpoint_process_position = strpos($cron_endpoint, '$result = process();');
corsivo_mail_schema_static_check(
	strpos($schedule, "add_action('corsivo_mail_process_queue', __NAMESPACE__ . '\\\\run_schedules', 5);") !== false
		&& $schedule_verify_position !== false
		&& $schedule_dispatch_position !== false
		&& $schedule_verify_position < $schedule_dispatch_position
		&& $endpoint_verify_position !== false
		&& $endpoint_schedule_position !== false
		&& $endpoint_process_position !== false
		&& $endpoint_verify_position < $endpoint_schedule_position
		&& $endpoint_schedule_position < $endpoint_process_position
		&& strpos($cron_endpoint, "is_wp_error(\$schedules)") !== false
		&& strpos($cron_endpoint, "(\$result['status'] ?? '') === 'unavailable'") !== false,
	'La schedule Mail può lavorare prima del preflight fisico autorevole.'
);

$install_start = strpos($contract, 'function install_current_storage_locked(');
$install_end = strpos($contract, 'function storage_error_can_be_persisted(', $install_start);
$install_source = substr($contract, $install_start, $install_end - $install_start);
$persistable_start = $install_end;
$persistable_end = strpos($contract, 'function ensure_fresh_storage(', $persistable_start);
$persistable_source = substr($contract, $persistable_start, $persistable_end - $persistable_start);
$finalize_start = strpos($contract, 'function finalize_current_storage(');
$finalize_end = strpos($contract, 'function artifact_set_has_future_format(', $finalize_start);
$finalize_source = substr($contract, $finalize_start, $finalize_end - $finalize_start);
$finalize_fence_position = strpos($finalize_source, '$current_records = array(');
$finalize_marker_position = strpos($finalize_source, "persist_option_verified(\$configuration['attestation_option'], \$marker)");
corsivo_mail_schema_static_check(
	strpos($install_source, "storage_intent_is_valid(\$records['intent']['value'], \$configuration)") !== false
		&& strpos($install_source, "storage_failure_is_valid(\$records['failure']['value'], \$configuration)") !== false
		&& strpos($install_source, "return finalize_current_storage(\$configuration, \$records['intent'], \$records['failure']);") !== false
		&& $finalize_fence_position !== false
		&& $finalize_marker_position !== false
		&& $finalize_fence_position < $finalize_marker_position
		&& strpos($finalize_source, 'delete_option_verified_cas($option_name, $expected_record)') !== false
		&& strpos($persistable_source, "'corsivo_mail_install_intent_incompatible'") !== false
		&& strpos($persistable_source, "'corsivo_mail_storage_failure_incompatible'") !== false
		&& strpos($persistable_source, "'corsivo_mail_storage_blocked'") !== false
		&& strpos($persistable_source, "'corsivo_mail_storage_create_unfenced'") !== false
		&& strpos($persistable_source, "'corsivo_mail_storage_create_fence_lost'") !== false
		&& strpos($persistable_source, "'corsivo_mail_storage_finalize_unfenced'") !== false,
	'Il resume Mail su tabella esistente non valida o non propaga il fence dell’intent.'
);

$ensure_start = strpos($contract, 'function ensure_fresh_storage(');
$ensure_source = substr($contract, $ensure_start);
$failure_persistence_position = strpos($ensure_source, 'record_storage_failure(');
$lock_release_position = strpos($ensure_source, 'release_storage_lock(');
corsivo_mail_schema_static_check(
	$ensure_start !== false
		&& $failure_persistence_position !== false
		&& $lock_release_position !== false
		&& $failure_persistence_position < $lock_release_position
		&& strpos(substr($ensure_source, $lock_release_position), 'record_storage_failure(') === false,
	'La failure storage Mail può essere persistita dopo RELEASE_LOCK.'
);

corsivo_mail_schema_static_check(
	strpos($unsubscribe, 'hash_hmac') !== false,
	'L’HMAC applicativo del token unsubscribe è stato rimosso.'
);
corsivo_mail_schema_static_check(
	strpos($unsubscribe, 'wp_salt') === false
		&& strpos($unsubscribe, 'AUTH_KEY') === false
		&& strpos($unsubscribe, 'AUTH_SALT') === false
		&& strpos($unsubscribe, 'Crypto\master_key') !== false
		&& strpos($unsubscribe, "Crypto\derive_key(\$master_key, 'newsletter-unsubscribe-token')") !== false,
	'Il token unsubscribe non usa esclusivamente la master key stabile di Mail.'
);
corsivo_mail_schema_static_check(
	strpos($unsubscribe, 'block_marketing_without_unsubscribe') !== false
		&& strpos($unsubscribe, "9, 2);") !== false
		&& substr_count($queue, 'marketing_unsubscribe_preflight(') >= 1
		&& substr_count($worker, 'marketing_unsubscribe_preflight') >= 1,
	'Il marketing Mail non applica il preflight unsubscribe in enqueue e prima del trasporto.'
);

corsivo_mail_schema_static_check(
	strpos($log, 'wp_salt') === false
		&& strpos($log, 'hash_hmac') === false
		&& substr_count($log, "hash('sha256', strtolower(") === 3,
	'Il digest persistente del destinatario dipende ancora dai salt WordPress.'
);

corsivo_mail_schema_static_check(
	strpos($crypto, 'hash_hkdf') !== false,
	'La derivazione HKDF delle chiavi Mail è stata rimossa.'
);
corsivo_mail_schema_static_check(
	strpos($crypto, 'wp_salt') === false
		&& strpos($crypto, 'AUTH_KEY') === false
		&& strpos($crypto, 'NONCE_SALT') === false,
	'La cifratura Mail è tornata a dipendere dai salt WordPress.'
);

echo "schema current static: OK\n";
