<?php

namespace CorsivoMail\Transfer;

use CorsivoMail\Accounts;
use CorsivoMail\Automation;
use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

const SCHEMA  = 'corsivo-mail.emails';
const VERSION = 3;

add_action('admin_post_corsivo_mail_export_emails', __NAMESPACE__ . '\\handle_export');
add_action('admin_post_corsivo_mail_import_emails', __NAMESPACE__ . '\\handle_import');

function groups() {
	return [
		'emails' => [
			'label'     => 'Email',
			'post_type' => Automation\TYPE,
			'meta_keys' => email_meta_keys(),
		],
		'layouts' => [
			'label'     => 'Layout',
			'post_type' => Template\TEMPLATE_TYPE,
			'meta_keys' => [],
		],
		'footers' => [
			'label'     => 'Footer',
			'post_type' => Template\FOOTER_TYPE,
			'meta_keys' => ['corsivo_mail_footer_bg', 'corsivo_mail_footer_text'],
		],
	];
}

function email_meta_keys() {
	return [
		'corsivo_mail_trigger_type',
		'corsivo_mail_send_enabled',
		'corsivo_mail_filter_enabled',
		'corsivo_mail_core_email',
		'corsivo_mail_event_id',
		'corsivo_mail_subject',
		'corsivo_mail_audience_type',
		'corsivo_mail_audience_emails',
		'corsivo_mail_audience_role',
		'corsivo_mail_schedule_freq',
		'corsivo_mail_schedule_time',
		'corsivo_mail_schedule_day',
		'corsivo_mail_schedule_date',
		'corsivo_mail_custom_bg',
		'corsivo_mail_outer_bg',
		'corsivo_mail_content_bg',
		'corsivo_mail_message_type',
		'corsivo_mail_account_id',
		'corsivo_mail_custom_tags',
	];
}

function account_reference($account_id) {
	$account_id = sanitize_text_field((string) $account_id);
	if ($account_id === '') {
		return null;
	}

	$account = Accounts\get($account_id);
	if (!$account) {
		return new \WP_Error(
			'corsivo_mail_missing_export_account',
			'L\'account mittente associato non esiste più.'
		);
	}

	return [
		'from_email' => sanitize_email((string) $account['from_email']),
		'label'      => sanitize_text_field((string) $account['label']),
		'provider'   => sanitize_key((string) $account['provider']),
	];
}

function resolve_account_reference($reference) {
	if ($reference === null || $reference === '') {
		return null;
	}

	if (is_string($reference)) {
		$account_id = sanitize_text_field($reference);
		return Accounts\get($account_id)
			? $account_id
			: new \WP_Error('corsivo_mail_missing_account', 'L\'account mittente indicato nel file non esiste su questo sito.');
	}

	if (!is_array($reference)) {
		return new \WP_Error('corsivo_mail_invalid_account_reference', 'Il riferimento all\'account mittente non è valido.');
	}

	$from_email = strtolower(sanitize_email((string) ($reference['from_email'] ?? '')));
	$label = strtolower(sanitize_text_field((string) ($reference['label'] ?? '')));
	$provider = sanitize_key((string) ($reference['provider'] ?? ''));
	if ($from_email === '' && $label === '') {
		return new \WP_Error('corsivo_mail_invalid_account_reference', 'Il riferimento all\'account mittente non contiene email o etichetta.');
	}

	$accounts = Accounts\all();
	if ($from_email !== '') {
		$email_matches = array_values(array_filter($accounts, function ($account) use ($from_email) {
			return strtolower((string) $account['from_email']) === $from_email;
		}));
		if (count($email_matches) === 1) {
			return (string) $email_matches[0]['id'];
		}
		if (count($email_matches) > 1) {
			$email_matches = array_values(array_filter($email_matches, function ($account) use ($label, $provider) {
				$label_matches = $label === '' || strtolower((string) $account['label']) === $label;
				$provider_matches = $provider === '' || (string) $account['provider'] === $provider;
				return $label_matches && $provider_matches;
			}));
			if (count($email_matches) === 1) {
				return (string) $email_matches[0]['id'];
			}
			return new \WP_Error('corsivo_mail_ambiguous_account', 'Più account locali corrispondono al mittente esportato.');
		}
	}

	if ($label !== '') {
		$label_matches = array_values(array_filter($accounts, function ($account) use ($label, $provider) {
			if (strtolower((string) $account['label']) !== $label) {
				return false;
			}
			return $provider === '' || (string) $account['provider'] === $provider;
		}));
		if (count($label_matches) === 1) {
			return (string) $label_matches[0]['id'];
		}
		if (count($label_matches) > 1) {
			return new \WP_Error('corsivo_mail_ambiguous_account', 'Più account locali corrispondono al mittente esportato.');
		}
	}

	return new \WP_Error('corsivo_mail_missing_account', 'Nessun account locale corrisponde al mittente esportato.');
}

function post_reference($post_id, $post_type) {
	$post = $post_id ? get_post((int) $post_id) : null;
	if (!$post || $post->post_type !== $post_type) {
		return null;
	}
	return [
		'slug'  => (string) $post->post_name,
		'title' => (string) $post->post_title,
	];
}

function export_blocks($blocks) {
	foreach ($blocks as &$block) {
		if (!empty($block['innerBlocks'])) {
			$block['innerBlocks'] = export_blocks($block['innerBlocks']);
		}
		if (($block['blockName'] ?? '') !== 'corsivo-mail/footer') {
			continue;
		}
		$block['attrs'] = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
		$footer_id = absint($block['attrs']['footer_id'] ?? 0);
		unset($block['attrs']['footer_id'], $block['attrs']['footer_ref']);
		$reference = post_reference($footer_id, Template\FOOTER_TYPE);
		if ($reference) {
			$block['attrs']['footer_ref'] = $reference;
		}
	}
	unset($block);
	return $blocks;
}

function export_content($content) {
	$content = (string) $content;
	if (strpos($content, 'wp:corsivo-mail/footer') === false) {
		return $content;
	}
	return serialize_blocks(export_blocks(parse_blocks($content)));
}

function post_records($post_type, $meta_keys) {
	$posts = get_posts([
		'post_type'   => $post_type,
		'post_status' => ['publish', 'draft', 'pending', 'private'],
		'numberposts' => -1,
		'orderby'     => 'ID',
		'order'       => 'ASC',
	]);

	$records = [];
	foreach ($posts as $post) {
		$meta = [];
		foreach ($meta_keys as $key) {
			$value = metadata_exists('post', $post->ID, $key)
				? get_post_meta($post->ID, $key, true)
				: null;
			if ($key === 'corsivo_mail_account_id') {
				$value = account_reference($value);
				if (is_wp_error($value)) {
					return new \WP_Error(
						$value->get_error_code(),
						'Email "' . $post->post_title . '": ' . $value->get_error_message()
					);
				}
			}
			$meta[$key] = $value;
		}
		$records[] = [
			'slug'    => (string) $post->post_name,
			'title'   => (string) $post->post_title,
			'status'  => (string) $post->post_status,
			'content' => export_content($post->post_content),
			'meta'    => $meta,
		];
	}
	return $records;
}

function export_payload($selected) {
	$payload = [
		'schema'      => SCHEMA,
		'version'     => VERSION,
		'exported_at' => gmdate('c'),
		'site_url'    => home_url('/'),
		'groups'      => [],
	];

	foreach (groups() as $key => $group) {
		if (in_array($key, $selected, true)) {
			$records = post_records($group['post_type'], $group['meta_keys']);
			if (is_wp_error($records)) {
				return $records;
			}
			$payload['groups'][$key] = $records;
		}
	}
	return $payload;
}

function handle_export() {
	if (!current_user_can('manage_options')) {
		wp_die('Non autorizzato.', 403);
	}
	check_admin_referer('corsivo_mail_export_emails');

	$selected = array_map('sanitize_key', (array) ($_POST['groups'] ?? []));
	$payload  = export_payload($selected);
	if (is_wp_error($payload)) {
		wp_die(esc_html($payload->get_error_message()), 'Esportazione non riuscita', ['response' => 422]);
	}
	if (!$payload['groups']) {
		wp_die('Seleziona almeno un gruppo da esportare.', 400);
	}
	$record_count = array_sum(array_map('count', $payload['groups']));
	\CorsivoMail\Log\audit('templates_exported', 'notice', [
		'groups'  => count($payload['groups']),
		'records' => $record_count,
	]);

	nocache_headers();
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	header('Content-Disposition: attachment; filename="corsivo-mail-' . sanitize_file_name(implode('-', array_keys($payload['groups']))) . '-' . gmdate('Ymd-His') . '.json"');
	echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function sanitize_meta_value($key, $value) {
	if ($value === null) {
		return null;
	}
	if ($key === 'corsivo_mail_account_id') {
		return resolve_account_reference($value);
	}
	if (in_array($key, ['corsivo_mail_send_enabled', 'corsivo_mail_filter_enabled', 'corsivo_mail_custom_bg'], true)) {
		return !empty($value);
	}
	if ($key === 'corsivo_mail_schedule_day') {
		return absint($value);
	}
	if ($key === 'corsivo_mail_custom_tags') {
		return array_values(array_filter(array_map('sanitize_text_field', (array) $value)));
	}
	if (in_array($key, ['corsivo_mail_trigger_type', 'corsivo_mail_core_email', 'corsivo_mail_event_id', 'corsivo_mail_audience_type', 'corsivo_mail_audience_role', 'corsivo_mail_schedule_freq', 'corsivo_mail_message_type'], true)) {
		return sanitize_key((string) $value);
	}
	if ($key === 'corsivo_mail_audience_emails') {
		return sanitize_textarea_field((string) $value);
	}
	return sanitize_text_field((string) $value);
}

function meta_value_matches($stored, $expected) {
	if (is_bool($expected)) {
		return (bool) $stored === $expected;
	}
	if (is_int($expected)) {
		return (int) $stored === $expected;
	}
	return $stored === $expected;
}

function find_referenced_post($reference, $post_type) {
	$slug = sanitize_title((string) ($reference['slug'] ?? ''));
	if ($slug) {
		$post = get_page_by_path($slug, OBJECT, $post_type);
		if ($post) {
			return (int) $post->ID;
		}
	}

	$title = sanitize_text_field((string) ($reference['title'] ?? ''));
	if (!$title) {
		return 0;
	}

	$existing = get_posts([
		'post_type'   => $post_type,
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'title'       => $title,
	]);
	return $existing ? (int) $existing[0] : 0;
}

function find_post($record, $post_type) {
	if ($post_type === Automation\TYPE) {
		$core_email = sanitize_key((string) (((array) ($record['meta'] ?? []))['corsivo_mail_core_email'] ?? ''));
		if ($core_email) {
			$existing = get_posts([
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => 'corsivo_mail_core_email',
				'meta_value'  => $core_email,
			]);
			if ($existing) {
				return (int) $existing[0];
			}
		}
	}

	return find_referenced_post($record, $post_type);
}

function import_blocks($blocks) {
	foreach ($blocks as &$block) {
		if (!empty($block['innerBlocks'])) {
			$inner_blocks = import_blocks($block['innerBlocks']);
			if (is_wp_error($inner_blocks)) {
				return $inner_blocks;
			}
			$block['innerBlocks'] = $inner_blocks;
		}
		if (($block['blockName'] ?? '') !== 'corsivo-mail/footer') {
			continue;
		}

		$block['attrs'] = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
		$reference = is_array($block['attrs']['footer_ref'] ?? null)
			? $block['attrs']['footer_ref']
			: null;
		if (!$reference) {
			if (absint($block['attrs']['footer_id'] ?? 0)) {
				return new \WP_Error(
					'corsivo_mail_invalid_footer_reference',
					'Il riferimento al footer non è portabile.'
				);
			}
			unset($block['attrs']['footer_id'], $block['attrs']['footer_ref']);
			continue;
		}

		$footer_id = find_referenced_post($reference, Template\FOOTER_TYPE);
		if (!$footer_id) {
			$footer_label = sanitize_text_field((string) ($reference['title'] ?? $reference['slug'] ?? ''));
			return new \WP_Error(
				'corsivo_mail_missing_footer',
				'Footer "' . $footer_label . '" non trovato. Importa anche il gruppo Footer.'
			);
		}
		$block['attrs']['footer_id'] = $footer_id;
		unset($block['attrs']['footer_ref']);
	}
	unset($block);
	return $blocks;
}

function import_content($content) {
	$content = (string) $content;
	if (strpos($content, 'wp:corsivo-mail/footer') === false) {
		return $content;
	}

	$blocks = import_blocks(parse_blocks($content));
	return is_wp_error($blocks) ? $blocks : serialize_blocks($blocks);
}

function upsert_record($record, $group_key, $group, &$report) {
	global $wpdb;
	$label = is_array($record) ? (string) ($record['title'] ?? $record['slug'] ?? '') : '';
	if (!is_array($record) || $label === '') {
		$report['errors'][] = $group['label'] . ': record senza titolo.';
		return;
	}

	$status = sanitize_key((string) ($record['status'] ?? 'draft'));
	if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
		$status = 'draft';
	}

	$content = import_content($record['content'] ?? '');
	if (is_wp_error($content)) {
		$report['errors'][] = $group['label'] . ' "' . $label . '": ' . $content->get_error_message();
		return;
	}

	$meta = (array) ($record['meta'] ?? []);
	$sanitized_meta = [];
	foreach ($group['meta_keys'] as $key) {
		if (!array_key_exists($key, $meta)) {
			continue;
		}
		$value = sanitize_meta_value($key, $meta[$key]);
		if (is_wp_error($value)) {
			$report['errors'][] = $group['label'] . ' "' . $label . '": ' . $value->get_error_message();
			return;
		}
		$sanitized_meta[$key] = $value;
	}

	$post_id = find_post($record, $group['post_type']);
	$postarr = [
		'post_type'    => $group['post_type'],
		'post_title'   => sanitize_text_field((string) ($record['title'] ?? '')),
		'post_status'  => $status,
		'post_content' => current_user_can('unfiltered_html') ? (string) $content : wp_kses_post($content),
	];
	$slug = sanitize_title((string) ($record['slug'] ?? ''));
	if ($slug) {
		$postarr['post_name'] = $slug;
	}
	if ($post_id) {
		$postarr['ID'] = $post_id;
	}

	if ($wpdb->query('START TRANSACTION') === false) {
		$report['errors'][] = $group['label'] . ' "' . $label . '": impossibile iniziare la transazione.';
		return;
	}
	$result = $post_id ? wp_update_post(wp_slash($postarr), true) : wp_insert_post(wp_slash($postarr), true);
	if (is_wp_error($result)) {
		$wpdb->query('ROLLBACK');
		$report['errors'][] = $group['label'] . ' "' . $label . '": ' . $result->get_error_message();
		return;
	}

	foreach ($sanitized_meta as $key => $value) {
		if ($value === null) {
			delete_post_meta((int) $result, $key);
			$stored_correctly = !metadata_exists('post', (int) $result, $key);
		} else {
			update_post_meta((int) $result, $key, $value);
			$stored_correctly = metadata_exists('post', (int) $result, $key) && meta_value_matches(get_post_meta((int) $result, $key, true), $value);
		}
		if (!$stored_correctly) {
			$wpdb->query('ROLLBACK');
			if (!$post_id) {
				wp_delete_post((int) $result, true);
			}
			clean_post_cache((int) $result);
			$report['errors'][] = $group['label'] . ' "' . $label . '": salvataggio del metadato ' . $key . ' non riuscito.';
			return;
		}
	}
	if ($wpdb->query('COMMIT') === false) {
		$wpdb->query('ROLLBACK');
		clean_post_cache((int) $result);
		$report['errors'][] = $group['label'] . ' "' . $label . '": commit del record non riuscito.';
		return;
	}
	clean_post_cache((int) $result);

	$report[$post_id ? 'updated' : 'created'][$group_key]++;
}

function import_payload($payload) {
	if (!is_array($payload) || ($payload['schema'] ?? '') !== SCHEMA || (int) ($payload['version'] ?? 0) !== VERSION) {
		return new \WP_Error('corsivo_mail_bad_schema', 'Schema JSON non riconosciuto.');
	}
	$incoming = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
	if (!$incoming) {
		return new \WP_Error('corsivo_mail_empty_transfer', 'Il file non contiene gruppi importabili.');
	}

	$report = ['created' => [], 'updated' => [], 'errors' => []];
	$available_groups = groups();
	foreach (['footers', 'layouts', 'emails'] as $key) {
		if (!isset($incoming[$key])) {
			continue;
		}
		$group = $available_groups[$key];
		$report['created'][$key] = 0;
		$report['updated'][$key] = 0;
		foreach ((array) $incoming[$key] as $record) {
			upsert_record($record, $key, $group, $report);
		}
	}
	return $report;
}

function redirect_with_result($notice, $detail) {
	set_transient('corsivo_mail_transfer_result_' . get_current_user_id(), $detail, MINUTE_IN_SECONDS);
	\CorsivoMail\Settings\redirect_with_notice('transfer', $notice);
}

function handle_import() {
	if (!current_user_can('manage_options')) {
		wp_die('Non autorizzato.', 403);
	}
	check_admin_referer('corsivo_mail_import_emails');

	$file = $_FILES['corsivo_mail_import_file'] ?? null;
	if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
		redirect_with_result('transfer_failed', 'Caricamento del file non riuscito.');
	}
	if ((int) ($file['size'] ?? 0) > 10 * MB_IN_BYTES) {
		redirect_with_result('transfer_failed', 'Il file supera 10 MB.');
	}
	if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
		redirect_with_result('transfer_failed', 'Il file non proviene da un caricamento valido.');
	}
	if (strtolower(pathinfo(sanitize_file_name((string) ($file['name'] ?? '')), PATHINFO_EXTENSION)) !== 'json') {
		redirect_with_result('transfer_failed', 'Carica un file JSON.');
	}

	$contents = file_get_contents((string) $file['tmp_name']);
	if ($contents === false) {
		redirect_with_result('transfer_failed', 'Impossibile leggere il file.');
	}
	if (strlen($contents) > 10 * MB_IN_BYTES) {
		redirect_with_result('transfer_failed', 'Il contenuto supera 10 MB.');
	}

	try {
		$payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
	} catch (\JsonException $error) {
		redirect_with_result('transfer_failed', 'JSON non valido: ' . $error->getMessage());
	}

	$report = import_payload($payload);
	if (is_wp_error($report)) {
		redirect_with_result('transfer_failed', $report->get_error_message());
	}

	$parts = [];
	$created_total = 0;
	$updated_total = 0;
	foreach (groups() as $key => $group) {
		if (!isset($report['created'][$key])) {
			continue;
		}
		$parts[] = $group['label'] . ': ' . $report['created'][$key] . ' creati, ' . $report['updated'][$key] . ' aggiornati';
		$created_total += (int) $report['created'][$key];
		$updated_total += (int) $report['updated'][$key];
	}
	$detail = implode(' — ', $parts);
	if ($report['errors']) {
		\CorsivoMail\Log\audit('templates_imported', 'failed', ['created' => $created_total, 'updated' => $updated_total]);
		$detail .= ' — Errori: ' . implode('; ', array_slice($report['errors'], 0, 10));
		redirect_with_result('transfer_failed', $detail);
	}
	\CorsivoMail\Log\audit('templates_imported', 'notice', ['created' => $created_total, 'updated' => $updated_total]);
	redirect_with_result('transfer_done', $detail);
}

function transfer_result_detail() {
	$key    = 'corsivo_mail_transfer_result_' . get_current_user_id();
	$detail = get_transient($key);
	delete_transient($key);
	return is_string($detail) ? $detail : '';
}

function post_count($post_type) {
	$counts = wp_count_posts($post_type);
	return array_sum(array_map(fn($status) => (int) ($counts->$status ?? 0), ['publish', 'draft', 'pending', 'private']));
}

function render_body() {
	?>
	<h2>Esporta</h2>
	<p class="description">Scarica un JSON portabile con le email formattate e, se vuoi, i layout e i footer che le vestono. Gli ID WordPress non vengono esportati; gli account mittente sono identificati da email, etichetta e provider.</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<?php wp_nonce_field('corsivo_mail_export_emails'); ?>
		<input type="hidden" name="action" value="corsivo_mail_export_emails">
		<table class="widefat striped" style="max-width:560px;">
			<thead><tr><th style="width:40px;"></th><th>Gruppo</th><th style="width:100px;">Record</th></tr></thead>
			<tbody>
				<?php foreach (groups() as $key => $group) : ?>
					<tr>
						<td><input type="checkbox" name="groups[]" value="<?php echo esc_attr($key); ?>" <?php checked($key, 'emails'); ?>></td>
						<td><?php echo esc_html($group['label']); ?></td>
						<td><?php echo (int) post_count($group['post_type']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button('Esporta JSON', 'primary', 'submit', true); ?>
	</form>
	<hr>
	<h2>Importa</h2>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Importare le email dal file? I record corrispondenti vengono aggiornati.');">
		<?php wp_nonce_field('corsivo_mail_import_emails'); ?>
		<input type="hidden" name="action" value="corsivo_mail_import_emails">
		<input type="file" name="corsivo_mail_import_file" accept="application/json,.json" required>
		<p class="description">Il matching avviene per email core, poi slug e infine titolo. I footer vengono riagganciati allo slug locale; se non esistono, includi anche il gruppo Footer nell'esportazione. Gli account mittente devono avere una corrispondenza locale per email oppure per etichetta e provider. Nessun invio parte durante l'importazione.</p>
		<?php submit_button('Importa JSON', 'secondary', 'submit', true); ?>
	</form>
	<?php
}
