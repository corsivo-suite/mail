<?php

if (!defined('ABSPATH')) {
	exit(1);
}

global $wpdb;

$failures = array();
$passes = 0;
$original_settings = get_option(\CorsivoMail\Options\OPTION, null);
$original_accounts = get_option(\CorsivoMail\Accounts\OPTION, null);
$missing_admin_verify_option = new stdClass();
$original_admin_verify_option = get_option(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION, $missing_admin_verify_option);
$original_update_verify_option = get_option(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, $missing_admin_verify_option);
$original_schedule_lock = get_option(\CorsivoMail\Automation\SCHEDULE_LOCK, $missing_admin_verify_option);
$original_cron_option = get_option('cron', $missing_admin_verify_option);
$original_last_worker_run = get_option('corsivo_mail_last_worker_run', $missing_admin_verify_option);
$original_fail_closed_priority = has_filter('pre_wp_mail', 'CorsivoMail\\fail_closed_wp_mail');
$original_user_id = get_current_user_id();
$queue_id = 0;
$claim_queue_id = 0;

function corsivo_mail_transport_check($condition, $label, &$passes, &$failures) {
	if ($condition) {
		$passes++;
		return;
	}
	$failures[] = $label;
}

try {
	$settings = \CorsivoMail\Options\all();
	$settings['use_queue'] = false;
	update_option(\CorsivoMail\Options\OPTION, $settings, false);
	update_option(\CorsivoMail\Accounts\OPTION, array(), false);
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Accounts\OPTION, 'options');

	$foreign_true = true;
	$foreign_error = new WP_Error('foreign_transport');
	$unowned = \CorsivoMail\fail_closed_wp_mail(null, array('to' => 'nobody@example.invalid'));
	corsivo_mail_transport_check(
	\CorsivoMail\fail_closed_wp_mail($foreign_true, array()) === $foreign_true
		&& \CorsivoMail\fail_closed_wp_mail($foreign_error, array()) === $foreign_error
		&& $unowned === null,
		'Il fail-closed Mail sovrascrive short-circuit altrui o blocca un trasporto non posseduto.',
		$passes,
		$failures
	);

	$settings['disable_sending'] = true;
	update_option(\CorsivoMail\Options\OPTION, $settings, false);
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	$unowned_kill_switch = \CorsivoMail\Options\block_sending(null, array('to' => 'nobody@example.invalid'));
	$foreign_kill_switch = \CorsivoMail\Options\block_sending($foreign_error, array());
	$settings['use_queue'] = true;
	update_option(\CorsivoMail\Options\OPTION, $settings, false);
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	$owned_kill_switch = \CorsivoMail\Options\block_sending(null, array('to' => 'nobody@example.invalid'));
	corsivo_mail_transport_check(
		$unowned_kill_switch === null
			&& $foreign_kill_switch === $foreign_error
			&& $owned_kill_switch === true,
		'Il kill switch Mail sovrascrive short-circuit altrui o invade un trasporto non posseduto.',
		$passes,
		$failures
	);
	$settings['disable_sending'] = false;

	$settings['use_queue'] = true;
	update_option(\CorsivoMail\Options\OPTION, $settings, false);
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	$owned_transport_fence = \CorsivoMail\Queue\persist_storage_update_verification();
	$failed_events = 0;
	$continued_events = 0;
	$listener_errors_before = (int) ($GLOBALS['corsivo_mail_wp_mail_failed_listener_errors'] ?? 0);
	$throwing_listener = static function () {
		throw new RuntimeException('fixture listener failure');
	};
	$continued_listener = static function () use (&$continued_events) {
		$continued_events++;
	};
	$failure_listener = static function () use (&$failed_events) {
		$failed_events++;
	};
	add_action('wp_mail_failed', $throwing_listener, 10);
	add_action('wp_mail_failed', $continued_listener, 20);
	add_action('wp_mail_failed', $failure_listener, PHP_INT_MAX);
	$listener_exception = null;
	try {
		$closed = \CorsivoMail\fail_closed_wp_mail(null, array('to' => 'nobody@example.invalid'));
	} catch (Throwable $throwable) {
		$listener_exception = $throwable;
	}
	remove_action('wp_mail_failed', $throwing_listener, 10);
	remove_action('wp_mail_failed', $continued_listener, 20);
	remove_action('wp_mail_failed', $failure_listener, PHP_INT_MAX);
	$listener_errors_after = (int) ($GLOBALS['corsivo_mail_wp_mail_failed_listener_errors'] ?? 0);
	$owned_transport_reopened = \CorsivoMail\verify_storage_with_update_fence();
	corsivo_mail_transport_check(
		is_string($owned_transport_fence)
			&& $listener_exception === null
			&& $closed === false
			&& $continued_events === 1
			&& $failed_events === 1
			&& $listener_errors_after === $listener_errors_before + 1
			&& $owned_transport_reopened === true,
		'Il fail-closed Mail non isola i listener wp_mail_failed o non blocca il trasporto globale.',
		$passes,
		$failures
	);

	$queue_table = \CorsivoMail\Queue\table_name();
	$enqueue_recipient = 'corsivo-mail-enqueue-failure@example.invalid';
	$queue_rows_before = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$queue_table} WHERE recipient = %s",
		$enqueue_recipient
	));
	$enqueue_query_filter = static function ($query) use ($queue_table) {
		$prefix = 'INSERT INTO `' . $queue_table . '`';
		return strpos(ltrim((string) $query), $prefix) === 0
			? 'SELECT * FROM `corsivo_mail_missing_enqueue_fixture`'
			: $query;
	};
	$downstream_short = 'not_called';
	$downstream_filter = static function ($short) use (&$downstream_short) {
		$downstream_short = $short;
		return false;
	};
	$enqueue_failed_events = 0;
	$enqueue_failure_listener = static function ($error) use (&$enqueue_failed_events) {
		if (is_wp_error($error) && $error->get_error_code() === 'corsivo_mail_enqueue_failed') {
			$enqueue_failed_events++;
		}
	};
	$phpmailer_calls = 0;
	$phpmailer_listener = static function () use (&$phpmailer_calls) {
		$phpmailer_calls++;
	};
	$previous_suppress_errors = $wpdb->suppress_errors(true);
	add_filter('query', $enqueue_query_filter, PHP_INT_MAX);
	add_filter('pre_wp_mail', $downstream_filter, PHP_INT_MAX - 1, 1);
	add_action('wp_mail_failed', $enqueue_failure_listener, PHP_INT_MAX);
	add_action('phpmailer_init', $phpmailer_listener, PHP_INT_MAX);
	try {
		$enqueue_failure_result = wp_mail($enqueue_recipient, 'Queue failure fixture', 'Fixture');
	} finally {
		remove_filter('query', $enqueue_query_filter, PHP_INT_MAX);
		remove_filter('pre_wp_mail', $downstream_filter, PHP_INT_MAX - 1);
		remove_action('wp_mail_failed', $enqueue_failure_listener, PHP_INT_MAX);
		remove_action('phpmailer_init', $phpmailer_listener, PHP_INT_MAX);
		$wpdb->suppress_errors($previous_suppress_errors);
	}
	$queue_rows_after = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$queue_table} WHERE recipient = %s",
		$enqueue_recipient
	));
	$enqueue_failure_error = $GLOBALS['corsivo_mail_last_error'] ?? null;
	corsivo_mail_transport_check(
		$enqueue_failure_result === false
			&& $downstream_short === false
			&& $enqueue_failed_events === 1
			&& $phpmailer_calls === 0
			&& $queue_rows_after === $queue_rows_before
			&& is_wp_error($enqueue_failure_error)
			&& $enqueue_failure_error->get_error_code() === 'corsivo_mail_enqueue_failed'
			&& $enqueue_failure_error->get_error_data()['error_code'] === 'corsivo_mail_queue_insert_failed',
		'Un errore di enqueue degrada ancora a invio sincrono o non espone una failure correlata.',
		$passes,
		$failures
	);
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$queue_table} WHERE recipient = %s AND subject = %s",
		$enqueue_recipient,
		'Queue failure fixture'
	));

	$claim_queue_id = \CorsivoMail\Queue\enqueue(array(
		'to' => 'corsivo-mail-claim-read@example.invalid',
		'subject' => 'Claim read fixture',
		'message' => 'Fixture',
		'headers' => array(),
		'attachments' => array(),
		'embeds' => array(),
		'scheduled_at' => '2000-01-01 00:00:00',
		'source_type' => 'test',
	));
	$claim_read_filter = static function ($query) use ($queue_table) {
		$needle = "SELECT * FROM {$queue_table} WHERE lock_token =";
		return strpos((string) $query, $needle) !== false
			? 'SELECT * FROM `corsivo_mail_missing_claim_read_fixture`'
			: $query;
	};
	$previous_suppress_errors = $wpdb->suppress_errors(true);
	add_filter('query', $claim_read_filter, PHP_INT_MAX);
	try {
		$claim_read_result = is_int($claim_queue_id)
			? \CorsivoMail\Queue\claim_batch(1, $claim_queue_id)
			: $claim_queue_id;
	} finally {
		remove_filter('query', $claim_read_filter, PHP_INT_MAX);
		$wpdb->suppress_errors($previous_suppress_errors);
	}
	$claim_row = is_int($claim_queue_id) ? \CorsivoMail\Queue\get_row($claim_queue_id) : null;
	corsivo_mail_transport_check(
		is_wp_error($claim_read_result)
			&& $claim_read_result->get_error_code() === 'corsivo_mail_queue_claim_read_failed'
			&& is_array($claim_row)
			&& $claim_row['status'] === 'pending'
			&& (int) $claim_row['attempts'] === 0
			&& $claim_row['locked_at'] === null
			&& $claim_row['lock_token'] === ''
			&& $claim_row['transport_started_at'] === null,
		'La SELECT post-claim fallita non restituisce errore o lascia una lease/tentativo consumato.',
		$passes,
		$failures
	);

	$claim_update_filter = static function ($query) use ($queue_table) {
		$query = (string) $query;
		return strpos($query, "UPDATE {$queue_table}") !== false && strpos($query, "SET status = 'sending'") !== false
			? 'UPDATE `corsivo_mail_missing_claim_update_fixture` SET id = 1'
			: $query;
	};
	$previous_suppress_errors = $wpdb->suppress_errors(true);
	add_filter('query', $claim_update_filter, PHP_INT_MAX);
	try {
		$worker_claim_result = \CorsivoMail\Queue\process();
	} finally {
		remove_filter('query', $claim_update_filter, PHP_INT_MAX);
		$wpdb->suppress_errors($previous_suppress_errors);
	}
	$failed_worker_run = get_option('corsivo_mail_last_worker_run', array());
	corsivo_mail_transport_check(
		is_wp_error($worker_claim_result)
			&& $worker_claim_result->get_error_code() === 'corsivo_mail_queue_claim_failed'
			&& is_array($failed_worker_run)
			&& ($failed_worker_run['status'] ?? '') === 'failed'
			&& (int) ($failed_worker_run['processed'] ?? -1) === 0
			&& ($failed_worker_run['error_code'] ?? '') === 'corsivo_mail_queue_claim_failed'
			&& !empty($failed_worker_run['created_at']),
		'Un WP_Error del claim viene ancora registrato dal worker come idle/completed.',
		$passes,
		$failures
	);
	$settings['use_queue'] = false;
	update_option(\CorsivoMail\Options\OPTION, $settings, false);
	update_option(\CorsivoMail\Accounts\OPTION, array(array(
		'id' => 'fixture',
		'smtp_host' => 'smtp.example.invalid',
		'is_default' => true,
	)), false);
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Accounts\OPTION, 'options');
	corsivo_mail_transport_check(
		\CorsivoMail\governs_global_transport(),
		'Un account SMTP globale configurato non attiva l’ownership del trasporto.',
		$passes,
		$failures
	);

	$administrator_id = 0;
	foreach (get_users(array('fields' => 'ids')) as $candidate_user_id) {
		if (user_can((int) $candidate_user_id, 'manage_options')) {
			$administrator_id = (int) $candidate_user_id;
			break;
		}
	}
	corsivo_mail_transport_check(
		$administrator_id > 0,
		'Non esiste un utente con manage_options per verificare il throttle admin.',
		$passes,
		$failures
	);

	if ($administrator_id > 0) {
		delete_option(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION);
		wp_cache_delete(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION, 'options');
		$physical_verifications = 0;
		$verification_reasons = array();
		$physical_verification = static function ($reason) use (&$physical_verifications, &$verification_reasons) {
			$physical_verifications++;
			$verification_reasons[] = $reason;
			return true;
		};

		wp_set_current_user(0);
		$denied_verification = \CorsivoMail\verify_storage_on_admin($physical_verification);
		corsivo_mail_transport_check(
			$denied_verification === false
				&& $physical_verifications === 0
				&& get_option(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION, null) === null,
			'L’entrypoint admin dello storage non applica manage_options prima del claim.',
			$passes,
			$failures
		);

		wp_set_current_user($administrator_id);
		global $wp_filter, $wp_actions;
		$had_admin_init_hook = array_key_exists('admin_init', $wp_filter);
		$original_admin_init_hook = $had_admin_init_hook ? $wp_filter['admin_init'] : null;
		$had_admin_init_count = array_key_exists('admin_init', $wp_actions);
		$original_admin_init_count = $had_admin_init_count ? $wp_actions['admin_init'] : null;
		$admin_init_results = array();
		$admin_init_listener = static function () use ($physical_verification, &$admin_init_results) {
			$admin_init_results[] = \CorsivoMail\verify_storage_on_admin($physical_verification);
		};
		unset($wp_filter['admin_init'], $wp_actions['admin_init']);
		try {
			add_action('admin_init', $admin_init_listener, 1);
			do_action('admin_init');
			do_action('admin_init');
		} finally {
			unset($wp_filter['admin_init'], $wp_actions['admin_init']);
			if ($had_admin_init_hook) {
				$wp_filter['admin_init'] = $original_admin_init_hook;
			}
			if ($had_admin_init_count) {
				$wp_actions['admin_init'] = $original_admin_init_count;
			}
		}
		$admin_verify_after = get_option(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION, null);
		corsivo_mail_transport_check(
			$admin_init_results === array(true, false)
				&& $physical_verifications === 1
				&& $verification_reasons === array('admin')
				&& is_scalar($admin_verify_after)
				&& (int) $admin_verify_after > time(),
			'Due admin_init nella finestra eseguono più di una verifica fisica dello storage.',
			$passes,
			$failures
		);
	}

	delete_option(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION);
	delete_option(\CorsivoMail\Automation\SCHEDULE_LOCK);
	wp_cache_delete(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Automation\SCHEDULE_LOCK, 'options');
	$first_update_fence = \CorsivoMail\Queue\persist_storage_update_verification();
	$gate_closed = !\CorsivoMail\required_storage_ready(true);
	$second_update_fence = \CorsivoMail\Queue\persist_storage_update_verification();
	$stale_clear = is_string($first_update_fence)
		? \CorsivoMail\Queue\clear_storage_update_verification($first_update_fence)
		: null;
	$current_update_fence = \CorsivoMail\Queue\storage_update_verification_record();
	$current_clear = is_string($second_update_fence)
		? \CorsivoMail\Queue\clear_storage_update_verification($second_update_fence)
		: null;
	corsivo_mail_transport_check(
		is_string($first_update_fence)
			&& is_string($second_update_fence)
			&& !hash_equals($first_update_fence, $second_update_fence)
			&& $gate_closed
			&& is_wp_error($stale_clear)
			&& $stale_clear->get_error_code() === 'corsivo_mail_storage_update_fence_changed'
			&& $current_update_fence['exists']
			&& hash_equals($second_update_fence, $current_update_fence['value'])
			&& $current_clear === true,
		'Il fence update non chiude il gate o può cancellare un obbligo successore.',
		$passes,
		$failures
	);

	$race_initial_fence = \CorsivoMail\Queue\persist_storage_update_verification();
	$race_delete_injected = false;
	$delete_before_successor_write = null;
	$delete_before_successor_write = static function ($query) use (
		&$race_delete_injected,
		&$delete_before_successor_write,
		$wpdb
	) {
		$is_option_write = preg_match('/^\s*(INSERT|UPDATE)\b/i', $query)
			&& stripos($query, $wpdb->options) !== false
			&& stripos($query, \CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION) !== false;
		if ($race_delete_injected || !$is_option_write) {
			return $query;
		}
		$race_delete_injected = true;
		remove_filter('query', $delete_before_successor_write, PHP_INT_MAX);
		$wpdb->delete(
			$wpdb->options,
			array('option_name' => \CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION),
			array('%s')
		);
		wp_cache_delete(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, 'options');
		wp_cache_delete('alloptions', 'options');
		return $query;
	};
	add_filter('query', $delete_before_successor_write, PHP_INT_MAX);
	try {
		$race_successor_fence = \CorsivoMail\Queue\persist_storage_update_verification();
	} finally {
		remove_filter('query', $delete_before_successor_write, PHP_INT_MAX);
	}
	$race_successor_record = \CorsivoMail\Queue\storage_update_verification_record();
	$race_stale_clear = is_string($race_initial_fence)
		? \CorsivoMail\Queue\clear_storage_update_verification($race_initial_fence)
		: null;
	$race_current_clear = is_string($race_successor_fence)
		? \CorsivoMail\Queue\clear_storage_update_verification($race_successor_fence)
		: null;
	corsivo_mail_transport_check(
		is_string($race_initial_fence)
			&& $race_delete_injected
			&& is_string($race_successor_fence)
			&& !hash_equals($race_initial_fence, $race_successor_fence)
			&& $race_successor_record['exists']
			&& hash_equals($race_successor_fence, $race_successor_record['value'])
			&& is_wp_error($race_stale_clear)
			&& $race_stale_clear->get_error_code() === 'corsivo_mail_storage_update_fence_changed'
			&& $race_current_clear === true,
		'La cancellazione concorrente tra lettura e write perde il fence update successore.',
		$passes,
		$failures
	);

	update_option(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, 'invalid-update-fence', false);
	wp_cache_delete(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, 'options');
	$schedule_preflight = \CorsivoMail\Automation\run_schedules(true);
	$invalid_fence_after = \CorsivoMail\Queue\storage_update_verification_record();
	corsivo_mail_transport_check(
		is_wp_error($schedule_preflight)
			&& $schedule_preflight->get_error_code() !== ''
			&& $invalid_fence_after['exists']
			&& $invalid_fence_after['value'] === 'invalid-update-fence'
			&& get_option(\CorsivoMail\Automation\SCHEDULE_LOCK, null) === null,
		'La schedule acquisisce il lock o lavora prima del preflight fisico.',
		$passes,
		$failures
	);
	delete_option(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION);
	wp_cache_delete(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, 'options');

	$update_fence = \CorsivoMail\schedule_storage_verification_after_update(null, array(
		'action' => 'update',
		'type' => 'plugin',
		'plugins' => array(plugin_basename(CORSIVO_MAIL_FILE)),
	));
	$closed_before_verification = !\CorsivoMail\required_storage_ready(true);
	$fail_closed_during_update = has_filter('pre_wp_mail', 'CorsivoMail\\fail_closed_wp_mail') === PHP_INT_MAX;
	$update_verified = \CorsivoMail\verify_storage_with_update_fence();
	$runtime_reopened = \CorsivoMail\load_runtime();
	$update_fence_after = \CorsivoMail\Queue\storage_update_verification_record();
	$healthy_transport = \CorsivoMail\fail_closed_wp_mail(null, array('to' => 'nobody@example.invalid'));
	corsivo_mail_transport_check(
		is_string($update_fence)
			&& $closed_before_verification
			&& $fail_closed_during_update
			&& $update_verified === true
			&& $runtime_reopened
			&& !$update_fence_after['exists']
			&& has_filter('pre_wp_mail', 'CorsivoMail\\fail_closed_wp_mail') === PHP_INT_MAX
			&& $healthy_transport === null
			&& \CorsivoMail\required_storage_ready(true),
		'L’update non chiude subito il trasporto o il filtro dinamico non riapre dopo verifica.',
		$passes,
		$failures
	);

	$preflight_lock_queries = 0;
	$preflight_query_observer = static function ($query) use (&$preflight_lock_queries) {
		if (stripos($query, 'GET_LOCK(') !== false) {
			$preflight_lock_queries++;
		}
		return $query;
	};
	add_filter('query', $preflight_query_observer, PHP_INT_MAX);
	try {
		$first_preflight = \CorsivoMail\Queue\verify_storage_before_work();
		$first_preflight_lock_queries = $preflight_lock_queries;
		$second_preflight = \CorsivoMail\Queue\verify_storage_before_work();
		$second_preflight_lock_queries = $preflight_lock_queries;
	} finally {
		remove_filter('query', $preflight_query_observer, PHP_INT_MAX);
	}
	corsivo_mail_transport_check(
		$first_preflight === true
			&& $second_preflight === true
			&& $first_preflight_lock_queries > 0
			&& $second_preflight_lock_queries === $first_preflight_lock_queries,
		'Il preflight riuscito ripete i named lock nella stessa request.',
		$passes,
		$failures
	);

	$token = str_repeat('a', 64);
	$headers = \CorsivoMail\Queue\delivery_probe_headers(array('Content-Type' => 'text/html', 'X-Test: value'), $token);
	$event = array('headers' => array(
		'Content-Type' => 'text/html',
		'X-Corsivo-Mail-Delivery' => $token,
	));
	corsivo_mail_transport_check(
		in_array('Content-Type: text/html', $headers, true)
			&& \CorsivoMail\Queue\delivery_event_matches($event, $token)
			&& !\CorsivoMail\Queue\delivery_event_matches($event, str_repeat('b', 64)),
		'Il probe delivery Mail non preserva gli header o non correla il token exact.',
		$passes,
		$failures
	);

	if ($original_settings === null) {
		$runtime_settings = \CorsivoMail\Options\defaults();
	} else {
		$runtime_settings = wp_parse_args($original_settings, \CorsivoMail\Options\defaults());
	}
	$runtime_settings['disable_sending'] = false;
	$runtime_settings['use_queue'] = true;
	update_option(\CorsivoMail\Options\OPTION, $runtime_settings, false);
	update_option(\CorsivoMail\Accounts\OPTION, array(), false);
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Accounts\OPTION, 'options');

	$queue_id = \CorsivoMail\Queue\enqueue(array(
		'to' => 'corsivo-mail-transport-test@example.invalid',
		'subject' => 'Delivery event required',
		'message' => 'Fixture',
		'headers' => array(),
		'attachments' => array(),
		'embeds' => array(),
		'source_type' => 'test',
	));
	$claimed = is_wp_error($queue_id) ? $queue_id : \CorsivoMail\Queue\claim_batch(1, $queue_id);
	$short_circuit = static function ($current) {
		return $current === null ? true : $current;
	};
	add_filter('pre_wp_mail', $short_circuit, PHP_INT_MAX - 1, 1);
	$sent = is_array($claimed) && isset($claimed[0])
		? \CorsivoMail\Queue\send_row($claimed[0])
		: null;
	remove_filter('pre_wp_mail', $short_circuit, PHP_INT_MAX - 1);
	$row = is_int($queue_id) ? \CorsivoMail\Queue\get_row($queue_id) : null;
	corsivo_mail_transport_check(
		is_int($queue_id)
			&& is_array($claimed)
			&& $sent === false
			&& is_array($row)
			&& $row['status'] === 'delivery_unknown'
			&& $row['error_code'] === 'delivery_unknown',
		'wp_mail true senza evento correlato viene ancora marcato sent.',
		$passes,
		$failures
	);
} finally {
	if ($queue_id > 0) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), array('id' => $queue_id), array('%d'));
	}
	if (is_int($claim_queue_id) && $claim_queue_id > 0) {
		$wpdb->delete(\CorsivoMail\Queue\table_name(), array('id' => $claim_queue_id), array('%d'));
	}
	if ($original_settings === null) {
		delete_option(\CorsivoMail\Options\OPTION);
	} else {
		update_option(\CorsivoMail\Options\OPTION, $original_settings, false);
	}
	if ($original_accounts === null) {
		delete_option(\CorsivoMail\Accounts\OPTION);
	} else {
		update_option(\CorsivoMail\Accounts\OPTION, $original_accounts, false);
	}
	wp_cache_delete(\CorsivoMail\Options\OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Accounts\OPTION, 'options');
	delete_option(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION);
	if ($original_admin_verify_option !== $missing_admin_verify_option) {
		add_option(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION, $original_admin_verify_option, '', false);
	}
	delete_option(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION);
	if ($original_update_verify_option !== $missing_admin_verify_option) {
		add_option(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, $original_update_verify_option, '', false);
	}
	delete_option(\CorsivoMail\Automation\SCHEDULE_LOCK);
	if ($original_schedule_lock !== $missing_admin_verify_option) {
		add_option(\CorsivoMail\Automation\SCHEDULE_LOCK, $original_schedule_lock, '', false);
	}
	delete_option('cron');
	if ($original_cron_option !== $missing_admin_verify_option) {
		add_option('cron', $original_cron_option, '', true);
	}
	delete_option('corsivo_mail_last_worker_run');
	if ($original_last_worker_run !== $missing_admin_verify_option) {
		add_option('corsivo_mail_last_worker_run', $original_last_worker_run, '', false);
	}
	remove_filter('pre_wp_mail', 'CorsivoMail\\fail_closed_wp_mail', PHP_INT_MAX);
	if ($original_fail_closed_priority !== false) {
		add_filter('pre_wp_mail', 'CorsivoMail\\fail_closed_wp_mail', $original_fail_closed_priority, 2);
	}
	wp_cache_delete(\CorsivoMail\ADMIN_STORAGE_VERIFY_OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Queue\STORAGE_UPDATE_VERIFY_OPTION, 'options');
	wp_cache_delete(\CorsivoMail\Automation\SCHEDULE_LOCK, 'options');
	wp_cache_delete('cron', 'options');
	wp_cache_delete('alloptions', 'options');
	wp_set_current_user($original_user_id);
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
