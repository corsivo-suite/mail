<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function corsivo_mail_uninstall_site() {
	global $wpdb;

	foreach (array('corsivo_mail_queue', 'corsivo_mail_messages', 'corsivo_mail_log') as $suffix) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
	}

	$options = array(
		'corsivo_mail_settings',
		'corsivo_mail_accounts',
		'corsivo_mail_encryption_master_key',
		'corsivo_mail_groups',
		'corsivo_mail_cron_key',
		'corsivo_mail_queue_install_intent',
		'corsivo_mail_queue_schema_attestation',
		'corsivo_mail_queue_schema_failure',
		'corsivo_mail_imap_install_intent',
		'corsivo_mail_imap_schema_attestation',
		'corsivo_mail_imap_schema_failure',
		'corsivo_mail_log_install_intent',
		'corsivo_mail_log_schema_attestation',
		'corsivo_mail_log_schema_failure',
		'corsivo_mail_seeded_core_emails',
		'corsivo_mail_schedule_worker_lock',
		'corsivo_mail_seed_lock',
		'corsivo_mail_storage_admin_verify_after',
		'corsivo_mail_storage_update_verification',
		'corsivo_mail_last_worker_run',
	);
	foreach ($options as $option) {
		delete_option($option);
	}

	$transient_like = $wpdb->esc_like('_transient_corsivo_mail_') . '%';
	$timeout_like = $wpdb->esc_like('_transient_timeout_corsivo_mail_') . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$timeout_like
		)
	);

	wp_clear_scheduled_hook('corsivo_mail_process_queue');
	wp_clear_scheduled_hook('corsivo_mail_cleanup_queue');
	wp_clear_scheduled_hook('corsivo_mail_process_automations');
	wp_clear_scheduled_hook('corsivo_mail_verify_storage');

	$post_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		WHERE post_type IN ('corsivo_mail_email', 'corsivo_mail_layout', 'corsivo_mail_footer')"
	);
	foreach ($post_ids as $post_id) {
		wp_delete_post((int) $post_id, true);
	}

	$subscription_meta = is_multisite()
		? 'corsivo_mail_subscribed_' . get_current_blog_id()
		: 'corsivo_mail_subscribed';
	delete_metadata('user', 0, $subscription_meta, '', true);
}

if (is_multisite()) {
	$site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
	foreach ($site_ids as $site_id) {
		$switched = (int) $site_id !== get_current_blog_id();
		if ($switched) {
			switch_to_blog((int) $site_id);
		}
		corsivo_mail_uninstall_site();
		if ($switched) {
			restore_current_blog();
		}
	}
} else {
	corsivo_mail_uninstall_site();
}
