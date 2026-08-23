<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$transfer_file = dirname(__DIR__) . '/includes/settings/transfer.php';
if (!function_exists('CorsivoMail\\Transfer\\import_payload')) {
	require_once $transfer_file;
}

function corsivo_mail_transfer_test_check($condition, $message, &$passes, &$failures) {
	if ($condition) {
		$passes++;
		return;
	}
	$failures[] = $message;
}

function corsivo_mail_transfer_test_insert($postarr, &$created_ids) {
	$post_id = wp_insert_post(wp_slash($postarr), true);
	if (is_wp_error($post_id)) {
		throw new RuntimeException($post_id->get_error_message());
	}
	$created_ids[] = (int) $post_id;
	return (int) $post_id;
}

function corsivo_mail_transfer_test_footer_content($attrs) {
	return serialize_blocks([[
		'blockName'    => 'corsivo-mail/footer',
		'attrs'        => $attrs,
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	]]);
}

function corsivo_mail_transfer_test_record($records, $slug) {
	foreach ($records as $record) {
		if (($record['slug'] ?? '') === $slug) {
			return $record;
		}
	}
	return null;
}

$passes = 0;
$failures = [];
$created_ids = [];
$mail_calls = 0;
$suffix = strtolower(wp_generate_password(10, false, false));
$prefix = 'cm-transfer-' . $suffix;
$original_accounts = get_option(\CorsivoMail\Accounts\OPTION, null);
$source_account_id = 'acc_' . $prefix . '_source';
$target_account_id = 'acc_' . $prefix . '_target';
$account_reference = [
	'label'      => 'Transfer account ' . $suffix,
	'provider'   => 'custom',
	'from_email' => $prefix . '@example.com',
];
$mail_filter = function ($return) use (&$mail_calls) {
	$mail_calls++;
	return true;
};
add_filter('pre_wp_mail', $mail_filter, 10, 2);

try {
	$account_rows = is_array($original_accounts) ? $original_accounts : [];
	$account_rows[] = array_merge(\CorsivoMail\Accounts\defaults(), $account_reference, ['id' => $source_account_id]);
	update_option(\CorsivoMail\Accounts\OPTION, $account_rows);

	$footer_slug = $prefix . '-footer';
	$footer_title = 'Transfer Footer ' . $suffix;
	$footer_id = corsivo_mail_transfer_test_insert([
		'post_type'    => \CorsivoMail\Template\FOOTER_TYPE,
		'post_title'   => $footer_title,
		'post_name'    => $footer_slug,
		'post_status'  => 'draft',
		'post_content' => 'Old footer',
	], $created_ids);

	$email_slug = $prefix . '-email';
	$email_title = 'Transfer Email ' . $suffix;
	$core_email = $prefix . '-core';
	$email_id = corsivo_mail_transfer_test_insert([
		'post_type'    => \CorsivoMail\Automation\TYPE,
		'post_title'   => $email_title,
		'post_name'    => $email_slug,
		'post_status'  => 'draft',
		'post_content' => 'Old email',
	], $created_ids);
	update_post_meta($email_id, 'corsivo_mail_core_email', $core_email);
	update_post_meta($email_id, 'corsivo_mail_account_id', $source_account_id);

	$v3_payload = [
		'schema'  => \CorsivoMail\Transfer\SCHEMA,
		'version' => \CorsivoMail\Transfer\VERSION,
		'groups'  => [
			'footers' => [[
				'slug'    => $footer_slug,
				'title'   => $footer_title,
				'status'  => 'publish',
				'content' => 'Updated footer',
				'meta'    => [
					'corsivo_mail_footer_bg'   => '#102030',
					'corsivo_mail_footer_text' => '#ffffff',
				],
			]],
			'emails' => [[
				'slug'    => $email_slug . '-renamed',
				'title'   => $email_title,
				'status'  => 'publish',
				'content' => corsivo_mail_transfer_test_footer_content([
					'footer_ref' => [
						'slug'  => $footer_slug,
						'title' => $footer_title,
					],
				]),
				'meta' => [
					'corsivo_mail_core_email' => $core_email,
					'corsivo_mail_subject'    => 'Updated subject',
				],
			]],
		],
	];

	$v3_report = \CorsivoMail\Transfer\import_payload($v3_payload);
	corsivo_mail_transfer_test_check(!is_wp_error($v3_report), 'Import v3 rifiutato.', $passes, $failures);
	corsivo_mail_transfer_test_check(empty($v3_report['errors']), 'Import v3 con errori.', $passes, $failures);
	corsivo_mail_transfer_test_check(($v3_report['updated']['footers'] ?? 0) === 1, 'Il footer v3 non è stato aggiornato.', $passes, $failures);
	corsivo_mail_transfer_test_check(($v3_report['updated']['emails'] ?? 0) === 1, 'L’email v3 non è stata aggiornata.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post($footer_id)->post_content === 'Updated footer', 'Il footer locale non contiene il nuovo contenuto.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post_meta($footer_id, 'corsivo_mail_footer_bg', true) === '#102030', 'I meta del footer non sono stati aggiornati.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post($email_id)->post_name === $email_slug . '-renamed', 'Il matching per email core non ha aggiornato il post esistente.', $passes, $failures);

	$stored_email_blocks = parse_blocks(get_post($email_id)->post_content);
	$stored_footer_attrs = $stored_email_blocks[0]['attrs'] ?? [];
	corsivo_mail_transfer_test_check((int) ($stored_footer_attrs['footer_id'] ?? 0) === $footer_id, 'Il footer v3 non è stato associato all’ID locale.', $passes, $failures);
	corsivo_mail_transfer_test_check(!isset($stored_footer_attrs['footer_ref']), 'Il riferimento portabile è rimasto nel contenuto salvato.', $passes, $failures);

	$exported = \CorsivoMail\Transfer\export_payload(['emails', 'footers']);
	corsivo_mail_transfer_test_check(!is_wp_error($exported), 'L’export v3 ha restituito un errore.', $passes, $failures);
	corsivo_mail_transfer_test_check(($exported['version'] ?? 0) === 3, 'L’export non usa lo schema v3.', $passes, $failures);
	foreach ((array) ($exported['groups'] ?? []) as $records) {
		foreach ($records as $record) {
			corsivo_mail_transfer_test_check(!array_key_exists('source_id', $record), 'L’export contiene ancora source_id.', $passes, $failures);
		}
	}
	$exported_email = corsivo_mail_transfer_test_record($exported['groups']['emails'] ?? [], $email_slug . '-renamed');
	corsivo_mail_transfer_test_check(is_array($exported_email), 'L’email di test manca dall’export.', $passes, $failures);
	$exported_account = $exported_email['meta']['corsivo_mail_account_id'] ?? null;
	corsivo_mail_transfer_test_check(is_array($exported_account), 'L’account mittente non è stato esportato come riferimento.', $passes, $failures);
	corsivo_mail_transfer_test_check(($exported_account['from_email'] ?? '') === $account_reference['from_email'], 'Il riferimento account non contiene il mittente.', $passes, $failures);
	corsivo_mail_transfer_test_check(!in_array($source_account_id, (array) $exported_account, true), 'L’export contiene ancora l’ID locale dell’account.', $passes, $failures);

	$account_rows[count($account_rows) - 1]['id'] = $target_account_id;
	update_option(\CorsivoMail\Accounts\OPTION, $account_rows);
	$portable_account_report = \CorsivoMail\Transfer\import_payload([
		'schema'  => \CorsivoMail\Transfer\SCHEMA,
		'version' => 3,
		'groups'  => ['emails' => [$exported_email]],
	]);
	corsivo_mail_transfer_test_check(empty($portable_account_report['errors']), 'Il riferimento account portabile non è stato risolto.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post_meta($email_id, 'corsivo_mail_account_id', true) === $target_account_id, 'L’account non è stato associato all’ID locale.', $passes, $failures);

	$protected_content = get_post($email_id)->post_content;
	$unresolved_account_record = $exported_email;
	$unresolved_account_record['content'] = 'Content that must be rolled back';
	$unresolved_account_record['meta']['corsivo_mail_account_id'] = [
		'from_email' => 'missing-' . $account_reference['from_email'],
		'label'      => 'Missing transfer account',
		'provider'   => 'custom',
	];
	$unresolved_account_report = \CorsivoMail\Transfer\import_payload([
		'schema'  => \CorsivoMail\Transfer\SCHEMA,
		'version' => 3,
		'groups'  => ['emails' => [$unresolved_account_record]],
	]);
	corsivo_mail_transfer_test_check(count($unresolved_account_report['errors'] ?? []) === 1, 'Un account non risolvibile non è stato bloccato.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post($email_id)->post_content === $protected_content, 'L’import con account non risolvibile non è stato annullato.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post_meta($email_id, 'corsivo_mail_account_id', true) === $target_account_id, 'L’import fallito ha alterato l’account locale.', $passes, $failures);
	$exported_blocks = parse_blocks((string) ($exported_email['content'] ?? ''));
	$exported_attrs = $exported_blocks[0]['attrs'] ?? [];
	corsivo_mail_transfer_test_check(!isset($exported_attrs['footer_id']), 'L’export contiene ancora l’ID locale del footer.', $passes, $failures);
	corsivo_mail_transfer_test_check(($exported_attrs['footer_ref']['slug'] ?? '') === $footer_slug, 'L’export non contiene il riferimento portabile del footer.', $passes, $failures);

	$layout_title = 'Transfer Layout ' . $suffix;
	$layout_old_slug = $prefix . '-layout-old';
	$layout_new_slug = $prefix . '-layout-new';
	$layout_id = corsivo_mail_transfer_test_insert([
		'post_type'    => \CorsivoMail\Template\TEMPLATE_TYPE,
		'post_title'   => $layout_title,
		'post_name'    => $layout_old_slug,
		'post_status'  => 'draft',
		'post_content' => 'Old layout',
	], $created_ids);
	$title_payload = [
		'schema'  => \CorsivoMail\Transfer\SCHEMA,
		'version' => \CorsivoMail\Transfer\VERSION,
		'groups'  => [
			'layouts' => [[
				'slug'      => $layout_new_slug,
				'title'     => $layout_title,
				'status'    => 'publish',
				'content'   => 'Updated layout',
				'meta'      => [],
			]],
		],
	];
	$title_report = \CorsivoMail\Transfer\import_payload($title_payload);
	corsivo_mail_transfer_test_check(empty($title_report['errors']), 'Il matching di fallback per titolo ha prodotto errori.', $passes, $failures);
	corsivo_mail_transfer_test_check(($title_report['updated']['layouts'] ?? 0) === 1, 'Il layout con stesso titolo non è stato sostituito.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post($layout_id)->post_name === $layout_new_slug, 'Il fallback per titolo ha creato un nuovo layout.', $passes, $failures);

	$blocked_email_slug = $prefix . '-blocked-email';
	$blocked_email_id = corsivo_mail_transfer_test_insert([
		'post_type'    => \CorsivoMail\Automation\TYPE,
		'post_title'   => 'Blocked Email ' . $suffix,
		'post_name'    => $blocked_email_slug,
		'post_status'  => 'draft',
		'post_content' => 'Original protected content',
	], $created_ids);
	$blocked_payload = [
		'schema'  => \CorsivoMail\Transfer\SCHEMA,
		'version' => \CorsivoMail\Transfer\VERSION,
		'groups'  => [
			'emails' => [[
				'slug'      => $blocked_email_slug,
				'title'     => 'Blocked Email ' . $suffix,
				'status'    => 'publish',
				'content'   => corsivo_mail_transfer_test_footer_content([
					'footer_ref' => ['slug' => 'missing-footer', 'title' => 'Missing Footer'],
				]),
				'meta'      => [],
			]],
		],
	];
	$blocked_report = \CorsivoMail\Transfer\import_payload($blocked_payload);
	corsivo_mail_transfer_test_check(count($blocked_report['errors'] ?? []) === 1, 'Un footer non risolvibile non è stato bloccato.', $passes, $failures);
	corsivo_mail_transfer_test_check(get_post($blocked_email_id)->post_content === 'Original protected content', 'Un import non valido ha modificato l’email esistente.', $passes, $failures);
	corsivo_mail_transfer_test_check($mail_calls === 0, 'L’import ha tentato di inviare email.', $passes, $failures);
} catch (Throwable $error) {
	$failures[] = 'Eccezione: ' . $error->getMessage();
} finally {
	remove_filter('pre_wp_mail', $mail_filter, 10);
	if ($original_accounts === null) {
		delete_option(\CorsivoMail\Accounts\OPTION);
	} else {
		update_option(\CorsivoMail\Accounts\OPTION, $original_accounts);
	}
	$cleanup_ids = array_unique($created_ids);
	foreach (array_unique($created_ids) as $post_id) {
		wp_delete_post($post_id, true);
	}
	foreach ([\CorsivoMail\Automation\TYPE, \CorsivoMail\Template\TEMPLATE_TYPE, \CorsivoMail\Template\FOOTER_TYPE] as $post_type) {
		$post_ids = get_posts([
			'post_type'   => $post_type,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		]);
		foreach ($post_ids as $post_id) {
			$post = get_post($post_id);
			if ($post && strpos($post->post_name, $prefix) === 0) {
				$cleanup_ids[] = (int) $post_id;
				wp_delete_post($post_id, true);
			}
		}
	}
	global $wpdb;
	foreach (array_unique($cleanup_ids) as $post_id) {
		$wpdb->delete(\CorsivoMail\Log\table_name(), ['source_id' => (int) $post_id], ['%d']);
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
