<?php

namespace CorsivoMail\Settings;

use CorsivoMail\Accounts;
use CorsivoMail\Options;
use CorsivoMail\Queue;
use CorsivoMail\Template;

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_menu', __NAMESPACE__ . '\\register_menu');
add_action('admin_menu', __NAMESPACE__ . '\\reorder_menu', 999);
add_action('admin_init', __NAMESPACE__ . '\\handle_post');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_settings_ui');

const SETTINGS_UI_SCRIPT_DEPENDENCIES = [
	'wp-element',
	'wp-components',
	'wp-i18n',
];

function settings_ui_build_manifest() {
	$build_dir = CORSIVO_MAIL_DIR . 'settings-app/build/';
	$required = ['index.asset.php', 'index.js', 'index.css', 'index-rtl.css'];
	foreach ($required as $file) {
		if (!is_readable($build_dir . $file)) {
			return null;
		}
	}

	$asset = require $build_dir . 'index.asset.php';
	if (!is_array($asset) || !isset($asset['dependencies'], $asset['version']) || !is_array($asset['dependencies'])) {
		return null;
	}

	return [
		'dependencies' => array_values(array_unique(array_merge(SETTINGS_UI_SCRIPT_DEPENDENCIES, $asset['dependencies']))),
		'version' => (string) $asset['version'],
		'url' => CORSIVO_MAIL_URL . 'settings-app/build/',
	];
}

function settings_ui_accounts_config() {
	$accounts = array_map(function ($account) {
		$account['smtp_pass'] = '';
		$account['imap_pass'] = '';
		$account['clear_imap_pass'] = false;
		return $account;
	}, Accounts\all());
	$blank = array_merge(Accounts\defaults(), [
		'id' => '',
		'smtp_pass' => '',
		'imap_pass' => '',
		'clear_imap_pass' => false,
	]);

	return [
		'accounts' => $accounts,
		'blankAccount' => $blank,
		'presets' => Accounts\smtp_presets(),
		'nonce' => wp_create_nonce('corsivo_mail_settings'),
		'restNonce' => wp_create_nonce('wp_rest'),
		'imapTestUrl' => rest_url('corsivo/v1/mail/imap-test'),
		'imapEnabled' => (bool) Options\get('module_imap'),
	];
}

function settings_ui_config($tab) {
	$config = ['tab' => $tab];
	if ($tab === 'accounts') {
		$config['accounts'] = settings_ui_accounts_config();
	}
	return apply_filters('corsivo_mail_settings_ui_config', $config, $tab);
}

function enqueue_settings_ui($hook) {
	$page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
	$tab = current_tab();
	if ($page !== 'corsivo-mail' || strpos((string) $hook, 'corsivo-mail') === false || !in_array($tab, ['accounts', 'groups'], true)) {
		return;
	}

	wp_enqueue_script(
		'corsivo-mail-settings-fallback',
		CORSIVO_MAIL_URL . 'assets/settings.js',
		[],
		CORSIVO_MAIL_VERSION,
		true
	);
	wp_localize_script('corsivo-mail-settings-fallback', 'CorsivoMailSettingsUI', settings_ui_config($tab));

	$build = settings_ui_build_manifest();
	if ($build) {
		wp_enqueue_script(
			'corsivo-mail-settings-ui',
			$build['url'] . 'index.js',
			array_values(array_unique(array_merge(['corsivo-mail-settings-fallback'], $build['dependencies']))),
			$build['version'],
			true
		);
		wp_enqueue_style(
			'corsivo-mail-settings-ui',
			$build['url'] . 'index.css',
			['wp-components'],
			$build['version']
		);
		wp_style_add_data('corsivo-mail-settings-ui', 'rtl', 'replace');
		wp_set_script_translations('corsivo-mail-settings-ui', 'corsivo-mail', CORSIVO_MAIL_DIR . 'languages');
		return;
	}

	wp_enqueue_style(
		'corsivo-mail-settings-ui',
		CORSIVO_MAIL_URL . 'assets/settings.css',
		['wp-components'],
		CORSIVO_MAIL_VERSION
	);
}

function register_menu() {
	add_menu_page(
		'Mail',
		'Mail',
		'manage_options',
		'corsivo-mail',
		__NAMESPACE__ . '\\render_page',
		'dashicons-email-alt',
		26
	);

	add_submenu_page('corsivo-mail', 'Impostazioni', 'Impostazioni', 'manage_options', 'corsivo-mail', __NAMESPACE__ . '\\render_page');
}

function reorder_menu() {
	global $submenu;
	if (empty($submenu['corsivo-mail'])) {
		return;
	}
	$order   = ['edit.php?post_type=corsivo_mail_email', 'corsivo-mail-inbox', 'edit.php?post_type=' . Template\TEMPLATE_TYPE, 'edit.php?post_type=' . Template\FOOTER_TYPE, 'corsivo-mail'];
	$by_slug = [];
	foreach ($submenu['corsivo-mail'] as $item) {
		$by_slug[$item[2]] = $item;
	}
	$reordered = [];
	foreach ($order as $slug) {
		if (isset($by_slug[$slug])) {
			$reordered[] = $by_slug[$slug];
			unset($by_slug[$slug]);
		}
	}
	$reordered = array_merge($reordered, array_values($by_slug));
	if ($reordered) {
		$submenu['corsivo-mail'] = $reordered;
	}
}

function current_tab() {
	$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
	return in_array($tab, ['general', 'style', 'accounts', 'queue', 'log', 'test', 'groups', 'transfer', 'integrations', 'diagnostics'], true) ? $tab : 'general';
}

function handle_post() {
	if (!isset($_POST['corsivo_mail_action']) || !current_user_can('manage_options')) {
		return;
	}

	check_admin_referer('corsivo_mail_settings');
	$action = sanitize_key($_POST['corsivo_mail_action']);

	if ($action === 'save_general') {
		$result = Options\save(wp_unslash($_POST['options'] ?? []));
		if (is_wp_error($result)) {
			\CorsivoMail\Log\audit('settings_updated', 'failed', ['module' => 'general', 'error_code' => $result->get_error_code()]);
			set_transient('corsivo_mail_settings_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
			redirect_with_notice('general', 'save_failed');
		}
		\CorsivoMail\Log\audit('settings_updated', 'notice', ['module' => 'general']);
		redirect_with_notice('general', 'saved');
	}

	if ($action === 'save_style') {
		$result = Options\save_style(wp_unslash($_POST['options'] ?? []));
		if (is_wp_error($result)) {
			\CorsivoMail\Log\audit('settings_updated', 'failed', ['module' => 'style', 'error_code' => $result->get_error_code()]);
			set_transient('corsivo_mail_settings_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
			redirect_with_notice('style', 'save_failed');
		}
		\CorsivoMail\Log\audit('settings_updated', 'notice', ['module' => 'style']);
		redirect_with_notice('style', 'saved');
	}

	if ($action === 'save_accounts') {
		$result = save_accounts_post();
		if (is_wp_error($result)) {
			\CorsivoMail\Log\audit('accounts_updated', 'failed', ['error_code' => $result->get_error_code()]);
			set_transient('corsivo_mail_settings_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
			redirect_with_notice('accounts', 'save_failed');
		}
		\CorsivoMail\Log\audit('accounts_updated', 'notice', ['updated' => count($result)]);
		redirect_with_notice('accounts', 'saved');
	}

	if ($action === 'save_integrations') {
		$result = Options\save_integrations(wp_unslash($_POST['options'] ?? []));
		if (is_wp_error($result)) {
			\CorsivoMail\Log\audit('settings_updated', 'failed', ['module' => 'integrations', 'error_code' => $result->get_error_code()]);
			set_transient('corsivo_mail_settings_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
			redirect_with_notice('integrations', 'save_failed');
		}
		Queue\reconcile_cron();
		\CorsivoMail\Log\audit('settings_updated', 'notice', ['module' => 'integrations']);
		redirect_with_notice('integrations', 'saved');
	}

	if ($action === 'send_test') {
		$to      = sanitize_email(wp_unslash($_POST['test_to'] ?? ''));
		$subject = sanitize_text_field(wp_unslash($_POST['test_subject'] ?? 'Test Mail'));
		$message = wp_kses_post(wp_unslash($_POST['test_message'] ?? ''));

		$error = '';
		$listener = function ($wp_error) use (&$error) {
			$error = $wp_error->get_error_message();
		};
		add_action('wp_mail_failed', $listener);

		$previous_force_sync = $GLOBALS['corsivo_mail_force_sync'] ?? false;
		$GLOBALS['corsivo_mail_force_sync'] = true;
		unset($GLOBALS['corsivo_mail_last_send_suppressed']);
		try {
			$sent = $to ? wp_mail($to, $subject, $message) : false;
		} finally {
			$GLOBALS['corsivo_mail_force_sync'] = $previous_force_sync;
			remove_action('wp_mail_failed', $listener);
		}

		if (!empty($GLOBALS['corsivo_mail_last_send_suppressed'])) {
			redirect_with_notice('test', 'test_suppressed');
		}

		if ($sent) {
			redirect_with_notice('test', 'test_sent');
		}
		set_transient('corsivo_mail_test_error_' . get_current_user_id(), $error ?: 'Invio fallito (destinatario o SMTP non validi).', 60);
		redirect_with_notice('test', 'test_failed');
	}

	if ($action === 'process_queue') {
		$result = Queue\process();
		\CorsivoMail\Log\audit('worker_started_manually', is_wp_error($result) ? 'failed' : 'notice', [
			'processed' => is_array($result) ? (int) ($result['processed'] ?? 0) : 0,
		]);
		if (is_wp_error($result)) {
			redirect_with_notice('queue', 'process_failed');
		}
		$status = $result['status'] ?? 'completed';
		redirect_with_notice('queue', $status === 'paused' ? 'queue_paused' : ($status === 'idle' ? 'queue_idle' : 'processed'));
	}

	if ($action === 'requeue_one') {
		$queue_id = (int) ($_POST['queue_id'] ?? 0);
		$updated = Queue\requeue_one($queue_id);
		\CorsivoMail\Log\audit('requeued', $updated ? 'queued' : 'failed', ['queue_id' => $queue_id]);
		redirect_with_notice('queue', $updated === 1 ? 'requeued' : 'requeue_failed');
	}
}

add_action('admin_post_corsivo_mail_queue_preview', __NAMESPACE__ . '\\queue_preview');
add_action('admin_post_corsivo_mail_rotate_cron_key', __NAMESPACE__ . '\\rotate_cron_key');

function rotate_cron_key() {
	if (!current_user_can('manage_options')) {
		wp_die('Non autorizzato.', 403);
	}
	check_admin_referer('corsivo_mail_rotate_cron_key');
	delete_option('corsivo_mail_cron_key');
	Queue\cron_endpoint_key();
	\CorsivoMail\Log\audit('cron_key_rotated', 'notice');
	redirect_with_notice('general', 'cron_key_rotated');
}

function queue_preview() {
	if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'corsivo_mail_queue_preview')) {
		wp_die('Non autorizzato.');
	}
	if (!Queue\runtime_ready()) {
		wp_die('Coda non disponibile: lo storage corrente non è verificabile.', 503);
	}

	$row = Queue\get_row((int) ($_GET['id'] ?? 0));
	\CorsivoMail\Security\send_isolated_document_headers();
	$unavailable = 'Anteprima non disponibile: il payload è stato eliminato dopo l\'invio.';
	$body = $row ? Queue\decode_body($row['body']) : '';
	echo $body !== '' ? wp_kses_post($body) : '<p>' . esc_html($unavailable) . '</p>';
	exit;
}

function save_accounts_post() {
	$posted   = wp_unslash($_POST['accounts'] ?? []);
	$existing = [];
	foreach (Accounts\all() as $account) {
		$existing[$account['id']] = $account;
	}

	$default = isset($_POST['default_account']) ? (string) $_POST['default_account'] : '';

	$accounts = [];
	foreach ($posted as $index => $row) {
		if (empty($row['label']) && empty($row['smtp_host']) && empty($row['from_email'])) {
			continue;
		}

		if (empty($row['smtp_pass']) && !empty($row['id']) && isset($existing[$row['id']])) {
			$stored = $existing[$row['id']];
			$smtp_changed = trim((string) ($row['smtp_host'] ?? '')) !== (string) $stored['smtp_host'] || trim((string) ($row['smtp_user'] ?? '')) !== (string) $stored['smtp_user'];
			if ($smtp_changed && !empty($row['smtp_auth']) && !empty($stored['smtp_pass'])) {
				return new \WP_Error('corsivo_mail_smtp_password_required', 'Host o username SMTP modificati: reinserisci la password prima di salvare.');
			}
			$row['smtp_pass'] = $smtp_changed ? '' : $stored['smtp_pass'];
		}
		if (empty($row['imap_pass']) && empty($row['clear_imap_pass']) && !empty($row['id']) && isset($existing[$row['id']])) {
			$stored = $existing[$row['id']];
			$current_imap_user = trim((string) ($row['imap_user'] ?? '')) ?: trim((string) ($row['smtp_user'] ?? ''));
			$stored_imap_user = (string) ($stored['imap_user'] ?: $stored['smtp_user']);
			$imap_changed = trim((string) ($row['imap_host'] ?? '')) !== (string) $stored['imap_host'] || $current_imap_user !== $stored_imap_user;
			if ($imap_changed && !empty($row['imap_host'])) {
				return new \WP_Error('corsivo_mail_imap_password_required', 'Host o username IMAP modificati: reinserisci la password IMAP prima di salvare.');
			}
			$row['imap_pass'] = $imap_changed ? '' : $stored['imap_pass'];
		}
		if (!empty($row['clear_imap_pass'])) {
			$row['imap_pass'] = '';
		}
		unset($row['clear_imap_pass']);

		$row['smtp_auth']  = !empty($row['smtp_auth']);
		$row['is_default'] = ((string) $index === $default);
		$accounts[]        = $row;
	}

	$kept_ids = array_filter(array_map(function ($account) {
		return (string) ($account['id'] ?? '');
	}, $accounts));
	foreach ($existing as $account_id => $account) {
		if (in_array((string) $account_id, $kept_ids, true)) {
			continue;
		}
		if (automation_count_for_account($account_id) > 0) {
			return new \WP_Error('corsivo_mail_account_in_automation', 'L\'account "' . $account['label'] . '" è ancora assegnato a una o più automazioni: riassegnale prima di rimuoverlo.');
		}
		if (Queue\table_exists() && Queue\active_count_for_account($account_id) > 0) {
			return new \WP_Error('corsivo_mail_account_in_queue', 'L\'account "' . $account['label'] . '" ha email in attesa, in lavorazione o fallite: completa o riassegna la coda prima di rimuoverlo.');
		}
	}

	return Accounts\save_all($accounts);
}

function automation_count_for_account($account_id) {
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s",
		\CorsivoMail\Automation\TYPE,
		'corsivo_mail_account_id',
		(string) $account_id
	));
}

function redirect_with_notice($tab, $notice) {
	wp_safe_redirect(add_query_arg([
		'page'   => 'corsivo-mail',
		'tab'    => $tab,
		'notice' => $notice,
	], admin_url('admin.php')));
	exit;
}

function render_page() {
	$tab = current_tab();
	?>
	<div class="wrap">
		<h1>Mail</h1>
		<?php render_notice(); ?>
		<h2 class="nav-tab-wrapper">
			<?php
			$tabs = [
				'general'  => ['label' => 'Generali'],
				'style'    => ['label' => 'Stile'],
				'accounts' => ['label' => 'Account SMTP'],
				'queue'    => ['label' => 'Coda'],
				'log'      => ['label' => 'Log invii'],
				'diagnostics' => ['label' => 'Stato'],
				'integrations' => ['label' => 'Moduli'],
				'test'     => ['label' => 'Invia test'],
				'groups'   => ['label' => 'Gruppi destinatari'],
				'transfer' => ['label' => 'Esporta / Importa'],
				'layout'   => ['label' => 'Layout', 'url' => admin_url('edit.php?post_type=' . Template\TEMPLATE_TYPE)],
				'footer'   => ['label' => 'Footer', 'url' => admin_url('edit.php?post_type=' . Template\FOOTER_TYPE)],
			];
			foreach ($tabs as $slug => $meta) {
				$external = isset($meta['url']);
				$url      = $external ? $meta['url'] : admin_url('admin.php?page=corsivo-mail&tab=' . $slug);
				$active   = (!$external && $tab === $slug) ? ' nav-tab-active' : '';
				echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active . '">' . esc_html($meta['label']) . '</a>';
			}
			?>
		</h2>
		<?php
		if ($tab === 'general') {
			render_general_tab();
		} elseif ($tab === 'style') {
			render_style_tab();
		} elseif ($tab === 'accounts') {
			render_accounts_tab();
		} elseif ($tab === 'queue') {
			render_queue_tab();
		} elseif ($tab === 'log') {
			render_log_tab();
		} elseif ($tab === 'diagnostics') {
			render_diagnostics_tab();
		} elseif ($tab === 'integrations') {
			render_integrations_tab();
		} elseif ($tab === 'groups') {
			\CorsivoMail\Groups\render_body();
		} elseif ($tab === 'transfer') {
			\CorsivoMail\Transfer\render_body();
		} else {
			render_test_tab();
		}
		?>
	</div>
	<?php
}

function render_notice() {
	if (empty($_GET['notice'])) {
		return;
	}
	$map = [
		'saved'           => ['updated', 'Impostazioni salvate.'],
		'test_sent'       => ['updated', 'Email di test inviata.'],
		'test_failed'     => ['error', 'Invio di test fallito.'],
		'test_suppressed' => ['warning', 'Invio di test soppresso: l\'invio globale è disattivato.'],
		'save_failed'     => ['error', 'Salvataggio non riuscito.'],
		'processed'       => ['updated', 'Coda elaborata.'],
		'queue_idle'      => ['info', 'Nessuna email pronta per l\'elaborazione.'],
		'queue_paused'    => ['warning', 'Coda non elaborata: l\'invio globale è disattivato.'],
		'process_failed'  => ['error', 'Elaborazione della coda non riuscita.'],
		'cron_key_rotated' => ['updated', 'Chiave del cron esterno rigenerata.'],
		'requeued'        => ['updated', 'Email rimessa in coda.'],
		'requeue_failed'  => ['error', 'Impossibile rimettere in coda l\'email: non è più nello stato fallito.'],
		'transfer_done'   => ['updated', 'Importazione completata.'],
		'transfer_failed' => ['error', 'Importazione non riuscita.'],
	];
	$notice = sanitize_key($_GET['notice']);
	if (!isset($map[$notice])) {
		return;
	}

	$message = $map[$notice][1];
	if ($notice === 'test_failed') {
		$key = 'corsivo_mail_test_error_' . get_current_user_id();
		$detail = get_transient($key);
		delete_transient($key);
		if ($detail) {
			$message .= ' ' . $detail;
		}
	}
	if ($notice === 'save_failed') {
		$key = 'corsivo_mail_settings_error_' . get_current_user_id();
		$detail = get_transient($key);
		delete_transient($key);
		if ($detail) {
			$message .= ' ' . $detail;
		}
	}
	if ($notice === 'transfer_done' || $notice === 'transfer_failed') {
		$detail = \CorsivoMail\Transfer\transfer_result_detail();
		if ($detail) {
			$message .= ' ' . $detail;
		}
	}

	echo '<div class="notice notice-' . esc_attr($map[$notice][0]) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

function render_general_tab() {
	$o         = Options\all();
	$templates = Template\template_options();
	?>
	<form method="post">
		<?php wp_nonce_field('corsivo_mail_settings'); ?>
		<input type="hidden" name="corsivo_mail_action" value="save_general">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Template guscio di default</th>
				<td>
					<select name="options[default_wrapper_id]">
						<option value="0">— Nessuno (solo scaffold) —</option>
						<?php foreach ($templates as $id => $title) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected($o['default_wrapper_id'], $id); ?>><?php echo esc_html($title); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Template (con il blocco "Contenuto email") usato per avvolgere le wp_mail di sistema. Il messaggio entra nello slot.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Disattiva invio email</th>
				<td>
					<label><input type="checkbox" name="options[disable_sending]" value="1" <?php checked($o['disable_sending']); ?>> Blocca <strong>tutte</strong> le email in uscita (debug)</label>
					<p class="description">Nessuna email esce, comprese quelle native di WordPress. Utile in fase di debug.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Wrapping wp_mail</th>
				<td><label><input type="checkbox" name="options[wrap_wp_mail]" value="1" <?php checked($o['wrap_wp_mail']); ?>> Avvolgi ogni wp_mail nel layout di default</label></td>
			</tr>
			<tr>
				<th scope="row">Coda di invio</th>
				<td>
					<label><input type="checkbox" name="options[use_queue]" value="1" <?php checked($o['use_queue']); ?>> Accoda gli invii (asincrono via cron)</label>
					<p class="description">Se disattivata, le mail partono subito in modo sincrono.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">URL cron esterno</th>
				<td>
					<code style="user-select:all;display:inline-block;padding:6px 10px;background:#f0f0f1;border-radius:4px;"><?php echo esc_html(home_url('/?corsivo_mail_cron=' . \CorsivoMail\Queue\cron_endpoint_key())); ?></code>
					<p class="description">Fai chiamare questo URL da un cron esterno (es. Operazioni pianificate del hosting) ogni pochi minuti: processa la coda e sveglia il cron di WordPress anche senza traffico sul sito.</p>
					<p class="description">Se il tuo cron supporta gli header HTTP, preferisci passare la chiave con <code>X-Corsivo-Mail-Key: <?php echo esc_html(\CorsivoMail\Queue\cron_endpoint_key()); ?></code> chiamando <code><?php echo esc_html(home_url('/?corsivo_mail_cron=1')); ?></code>: la chiave non finisce negli access log del server.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Lotto per ciclo</th>
				<td><input type="number" name="options[queue_batch_size]" value="<?php echo (int) $o['queue_batch_size']; ?>" min="1" max="100" class="small-text"> mail per esecuzione del cron</td>
			</tr>
			<tr>
				<th scope="row">Tentativi massimi</th>
				<td><input type="number" name="options[queue_max_retries]" value="<?php echo (int) $o['queue_max_retries']; ?>" min="1" max="10" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row">Pausa tra invii</th>
				<td><input type="number" name="options[queue_sleep_ms]" value="<?php echo (int) $o['queue_sleep_ms']; ?>" min="0" max="10000" step="50" class="small-text"> ms
					<p class="description">Rallenta gli invii per non superare i limiti del provider SMTP. 0 = nessuna pausa.</p></td>
			</tr>
			<tr>
				<th scope="row">Sblocco invii bloccati</th>
				<td><input type="number" name="options[queue_lock_minutes]" value="<?php echo (int) $o['queue_lock_minutes']; ?>" min="3" max="1440" class="small-text"> minuti
					<p class="description">Un invio rimasto appeso (worker interrotto) viene rimesso in coda dopo questo tempo.</p></td>
			</tr>
			<tr>
				<th scope="row">Pulizia automatica</th>
				<td><input type="number" name="options[queue_cleanup_days]" value="<?php echo (int) $o['queue_cleanup_days']; ?>" min="1" max="365" class="small-text"> giorni
					<p class="description">Le righe inviate o fallite più vecchie vengono eliminate (cron giornaliero).</p></td>
			</tr>
			<tr>
				<th scope="row">Conservazione log invii</th>
				<td><input type="number" name="options[log_retention_days]" value="<?php echo (int) $o['log_retention_days']; ?>" min="1" max="3650" class="small-text"> giorni
					<p class="description">Il log storico degli invii (tab "Log invii") viene ripulito oltre questa soglia.</p></td>
			</tr>
		</table>
		<?php submit_button('Salva impostazioni'); ?>
	</form>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<?php wp_nonce_field('corsivo_mail_rotate_cron_key'); ?>
		<input type="hidden" name="action" value="corsivo_mail_rotate_cron_key">
		<?php submit_button('Rigenera chiave cron', 'secondary', 'submit', false); ?>
	</form>
	<?php
}

function render_style_tab() {
	$o = Options\all();
	?>
	<form method="post">
		<?php wp_nonce_field('corsivo_mail_settings'); ?>
		<input type="hidden" name="corsivo_mail_action" value="save_style">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Footer flottante</th>
				<td>
					<label><input type="checkbox" name="options[footer_floating]" value="1" <?php checked($o['footer_floating']); ?>> Restringi il footer alla larghezza del contenuto</label>
					<p class="description">Il footer viene agganciato al contenuto e lascia spazio sotto, invece di occupare tutta la larghezza.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Raggio riquadri</th>
				<td>
					<input type="number" name="options[container_radius]" value="<?php echo (int) $o['container_radius']; ?>" min="0" max="48" class="small-text"> px
					<p class="description">0 = angoli squadrati. Con footer flottante, il contenuto usa solo gli angoli superiori e il footer solo quelli inferiori.</p>
				</td>
			</tr>
		</table>
		<?php submit_button('Salva stile'); ?>
	</form>
	<?php
}

function render_account_card($index, $account, $open = false) {
	$is_new = empty($account['id']);
	$title  = $account['label'] ?: ($account['from_email'] ?: 'Nuovo account');
	?>
	<details class="corsivo-mail-account-card corsivo-mail-settings__fallback-account" <?php echo $open ? 'open' : ''; ?>>
		<summary>
			<span class="ms-card-title"><?php echo esc_html($title); ?></span>
		</summary>
		<div class="corsivo-mail-settings__fallback-account-body">
			<p class="corsivo-mail-settings__fallback-remove"><button type="button" class="button-link-delete ms-remove">Rimuovi account</button></p>
			<input type="hidden" name="accounts[<?php echo $index; ?>][id]" value="<?php echo esc_attr($account['id']); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Provider</th>
					<td>
						<select name="accounts[<?php echo $index; ?>][provider]" class="ms-provider">
							<?php foreach (Accounts\smtp_presets() as $key => $preset) : ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($account['provider'], $key); ?>><?php echo esc_html($preset['label']); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description ms-provider-note"></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Etichetta</th>
					<td><input type="text" name="accounts[<?php echo $index; ?>][label]" value="<?php echo esc_attr($account['label']); ?>" class="regular-text ms-label"></td>
				</tr>
				<tr>
					<th scope="row">Mittente</th>
					<td>
						<input type="text" name="accounts[<?php echo $index; ?>][from_name]" value="<?php echo esc_attr($account['from_name']); ?>" placeholder="Nome" class="regular-text">
						<input type="email" name="accounts[<?php echo $index; ?>][from_email]" value="<?php echo esc_attr($account['from_email']); ?>" placeholder="email@dominio.it" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row">Server SMTP</th>
					<td>
						<input type="text" name="accounts[<?php echo $index; ?>][smtp_host]" value="<?php echo esc_attr($account['smtp_host']); ?>" placeholder="ssl0.ovh.net" class="regular-text ms-host">
						<input type="number" name="accounts[<?php echo $index; ?>][smtp_port]" value="<?php echo (int) $account['smtp_port']; ?>" class="small-text ms-port">
						<select name="accounts[<?php echo $index; ?>][smtp_secure]" class="ms-secure">
							<option value="tls" <?php selected($account['smtp_secure'], 'tls'); ?>>STARTTLS</option>
							<option value="ssl" <?php selected($account['smtp_secure'], 'ssl'); ?>>SSL</option>
							<option value="none" <?php selected($account['smtp_secure'], 'none'); ?>>Nessuna (sconsigliata: credenziali in chiaro sulla rete)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Autenticazione</th>
					<td>
						<label><input type="checkbox" name="accounts[<?php echo $index; ?>][smtp_auth]" value="1" <?php checked($account['smtp_auth']); ?>> Richiesta</label><br><br>
						<input type="text" name="accounts[<?php echo $index; ?>][smtp_user]" value="<?php echo esc_attr($account['smtp_user']); ?>" placeholder="Username" class="regular-text ms-user" autocomplete="off"><br><br>
						<input type="password" name="accounts[<?php echo $index; ?>][smtp_pass]" value="" placeholder="<?php echo $is_new ? 'Password' : '•••••• (lascia vuoto per non cambiare)'; ?>" class="regular-text" autocomplete="new-password">
					</td>
				</tr>
				<tr>
					<th scope="row">IMAP (ricezione)</th>
					<td>
						<input type="text" name="accounts[<?php echo $index; ?>][imap_host]" value="<?php echo esc_attr($account['imap_host']); ?>" placeholder="imaps.dominio.it" class="regular-text ms-imap-host">
						<input type="number" name="accounts[<?php echo $index; ?>][imap_port]" value="<?php echo (int) $account['imap_port']; ?>" class="small-text ms-imap-port">
						<select name="accounts[<?php echo $index; ?>][imap_secure]" class="ms-imap-secure">
							<option value="ssl" <?php selected($account['imap_secure'], 'ssl'); ?>>SSL</option>
							<option value="tls" <?php selected($account['imap_secure'], 'tls'); ?>>STARTTLS</option>
							<option value="none" <?php selected($account['imap_secure'], 'none'); ?>>Nessuna (sconsigliata: credenziali in chiaro sulla rete)</option>
						</select>
						<p class="description">Host vuoto = ricezione disattivata per questo account.</p>
						<input type="text" name="accounts[<?php echo $index; ?>][imap_user]" value="<?php echo esc_attr($account['imap_user']); ?>" placeholder="Username IMAP (vuoto = come SMTP)" class="regular-text ms-imap-user" autocomplete="off"><br><br>
						<input type="password" name="accounts[<?php echo $index; ?>][imap_pass]" value="" placeholder="<?php echo $is_new ? 'Password IMAP (vuoto = come SMTP)' : '•••••• (lascia vuoto per non cambiare)'; ?>" class="regular-text ms-imap-pass" autocomplete="new-password">
						<?php if (!$is_new) : ?>
							<p><label><input type="checkbox" name="accounts[<?php echo $index; ?>][clear_imap_pass]" value="1"> Rimuovi la password IMAP dedicata e usa quella SMTP</label></p>
							<?php if (Options\get('module_imap')) : ?>
								<p class="corsivo-mail-settings__fallback-imap-test"><button type="button" class="button button-secondary ms-imap-test" data-account="<?php echo esc_attr($account['id']); ?>">Testa IMAP</button> <span class="ms-imap-result" role="status" aria-live="polite"></span></p>
							<?php else : ?>
								<p class="description corsivo-mail-settings__fallback-imap-test">Attiva il modulo "Ricezione (IMAP)" nel tab Moduli per testare la connessione.</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Default</th>
					<td><label><input type="radio" name="default_account" value="<?php echo esc_attr($index); ?>" <?php checked($account['is_default']); ?>> Usa come account predefinito</label></td>
				</tr>
			</table>
		</div>
	</details>
	<?php
}

function render_accounts_tab() {
	$accounts = Accounts\all();
	$blank    = array_merge(Accounts\defaults(), ['id' => '']);
	?>
	<div id="corsivo-mail-accounts-app" hidden></div>
	<div id="corsivo-mail-accounts-fallback" class="corsivo-mail-settings-fallback">
		<form method="post">
			<?php wp_nonce_field('corsivo_mail_settings'); ?>
			<input type="hidden" name="corsivo_mail_action" value="save_accounts">
			<p class="description">Ogni account è un'identità mittente (SMTP in uscita) e, opzionalmente, una casella in ricezione (IMAP) per la webmail. Le password sono cifrate nel database. Scegli un provider per precompilare i server.</p>

			<div id="corsivo-mail-accounts">
				<?php foreach ($accounts as $i => $account) {
					render_account_card($i, $account, count($accounts) === 1);
				} ?>
			</div>

			<p><button type="button" class="button button-secondary" id="corsivo-mail-add-account">+ Aggiungi account</button></p>

			<template id="corsivo-mail-account-template">
				<?php render_account_card('__INDEX__', $blank, true); ?>
			</template>

			<?php submit_button('Salva account'); ?>
		</form>
	</div>
	<?php
}

function render_queue_tab() {
	if (!Queue\runtime_ready()) {
		echo '<div class="notice notice-warning inline"><p>Coda non disponibile: lo storage corrente non è verificabile.</p></div>';
		return;
	}
	$counts = Queue\counts();
	$allowed_statuses = ['pending', 'sending', 'transmitting', 'sent', 'failed', 'cancelled', 'delivery_unknown'];
	$status = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses, true) ? $_GET['status'] : '';
	$page_number = max(1, (int) ($_GET['queue_page'] ?? 1));
	$per_page = 50;
	$total = Queue\total($status);
	$recent = Queue\recent($per_page, $status, ($page_number - 1) * $per_page);
	$pages = max(1, (int) ceil($total / $per_page));
	?>
	<p>
		<strong>In attesa:</strong> <?php echo (int) $counts['pending']; ?> &nbsp;|&nbsp;
		<strong>In lavorazione:</strong> <?php echo (int) $counts['sending']; ?> &nbsp;|&nbsp;
		<strong>Trasporto avviato:</strong> <?php echo (int) $counts['transmitting']; ?> &nbsp;|&nbsp;
		<strong>Esito non verificabile:</strong> <?php echo (int) $counts['delivery_unknown']; ?> &nbsp;|&nbsp;
		<strong>Inviate:</strong> <?php echo (int) $counts['sent']; ?> &nbsp;|&nbsp;
		<strong>Fallite:</strong> <?php echo (int) $counts['failed']; ?> &nbsp;|&nbsp;
		<strong>Soppresse:</strong> <?php echo (int) $counts['cancelled']; ?>
	</p>
	<form method="get" style="margin-bottom:12px;">
		<input type="hidden" name="page" value="corsivo-mail">
		<input type="hidden" name="tab" value="queue">
		<select name="status">
			<option value="">Tutti gli stati</option>
			<?php foreach ($allowed_statuses as $status_option) : ?>
				<option value="<?php echo esc_attr($status_option); ?>" <?php selected($status, $status_option); ?>><?php echo esc_html($status_option); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button('Filtra', 'secondary', 'submit', false); ?>
	</form>
	<form method="post" style="margin-bottom:16px;">
		<?php wp_nonce_field('corsivo_mail_settings'); ?>
		<input type="hidden" name="corsivo_mail_action" value="process_queue">
		<?php submit_button('Elabora coda adesso', 'secondary', 'submit', false); ?>
	</form>
	<table class="widefat striped">
		<caption class="screen-reader-text">Coda email</caption>
		<thead><tr><th scope="col">ID</th><th scope="col">Destinatario</th><th scope="col">Oggetto</th><th scope="col">Stato</th><th scope="col">Tentativi</th><th scope="col">Programmata</th><th scope="col">Ultimo tentativo</th><th scope="col">Completata</th><th scope="col">Errore</th><th scope="col">Azioni</th></tr></thead>
		<tbody>
			<?php if (!$recent) : ?>
				<tr><td colspan="10">Nessuna mail in coda.</td></tr>
			<?php else : foreach ($recent as $row) :
				$preview_url = admin_url('admin-post.php?action=corsivo_mail_queue_preview&id=' . (int) $row['id'] . '&_wpnonce=' . wp_create_nonce('corsivo_mail_queue_preview'));
				?>
				<tr>
					<td><?php echo (int) $row['id']; ?></td>
					<td><?php echo esc_html(is_array(json_decode($row['recipient'], true)) ? implode(', ', json_decode($row['recipient'], true)) : $row['recipient']); ?></td>
					<td><?php echo esc_html($row['subject']); ?></td>
					<td><?php echo esc_html($row['status']); ?></td>
					<td><?php echo (int) $row['attempts']; ?>/<?php echo (int) $row['max_attempts']; ?></td>
					<td><?php echo esc_html($row['scheduled_at']); ?></td>
					<td><?php echo esc_html($row['last_attempt_at'] ?: '—'); ?></td>
					<td><?php echo esc_html($row['completed_at'] ?: '—'); ?></td>
					<td style="color:#a00;"><?php echo esc_html($row['error']); ?></td>
					<td>
						<?php if (!empty($row['body'])) : ?><a href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">HTML</a><?php else : ?>—<?php endif; ?>
						<?php if ($row['status'] === 'failed') : ?>
							&nbsp;|&nbsp;
							<form method="post" style="display:inline;">
								<?php wp_nonce_field('corsivo_mail_settings'); ?>
								<input type="hidden" name="corsivo_mail_action" value="requeue_one">
								<input type="hidden" name="queue_id" value="<?php echo (int) $row['id']; ?>">
								<button type="submit" class="button-link">Reinvia</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>
	<?php if ($pages > 1) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php echo wp_kses_post(paginate_links([
				'base' => add_query_arg('queue_page', '%#%'),
				'format' => '',
				'current' => $page_number,
				'total' => $pages,
			])); ?>
		</div></div>
	<?php endif; ?>
	<?php
}

function render_log_tab() {
	$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
	$allowed_statuses = ['sent', 'failed', 'suppressed', 'queued', 'notice'];
	$status = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses, true) ? $_GET['status'] : '';
	$event = isset($_GET['event']) ? sanitize_key($_GET['event']) : '';
	$page_number = max(1, (int) ($_GET['paged'] ?? 1));
	$per_page = 50;
	$total = \CorsivoMail\Log\total($search, $status, $event);
	$counts = \CorsivoMail\Log\counts($search, $status, $event);
	$rows = \CorsivoMail\Log\recent($per_page, $search, $status, $event, ($page_number - 1) * $per_page);
	$pages = max(1, (int) ceil($total / $per_page));
	?>
	<p>
		<strong>Accettate dal trasporto:</strong> <?php echo (int) $counts['sent']; ?> &nbsp;|&nbsp;
		<strong>Fallite:</strong> <?php echo (int) $counts['failed']; ?> &nbsp;|&nbsp;
		<strong>Accodate:</strong> <?php echo (int) $counts['queued']; ?> &nbsp;|&nbsp;
		<strong>Soppresse:</strong> <?php echo (int) $counts['suppressed']; ?>
	</p>
	<form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">
		<input type="hidden" name="page" value="corsivo-mail">
		<input type="hidden" name="tab" value="log">
		<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Cerca destinatario o oggetto" class="regular-text">
		<select name="status">
			<option value="">Tutti gli stati</option>
			<option value="sent" <?php selected($status, 'sent'); ?>>Accettate</option>
			<option value="failed" <?php selected($status, 'failed'); ?>>Fallite</option>
			<option value="queued" <?php selected($status, 'queued'); ?>>Accodate</option>
			<option value="suppressed" <?php selected($status, 'suppressed'); ?>>Soppresse</option>
			<option value="notice" <?php selected($status, 'notice'); ?>>Azioni</option>
		</select>
		<select name="event">
			<option value="">Tutti gli eventi</option>
			<?php foreach (\CorsivoMail\Log\events() as $event_name) : ?>
				<option value="<?php echo esc_attr($event_name); ?>" <?php selected($event, $event_name); ?>><?php echo esc_html($event_name); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button('Filtra', 'secondary', 'submit', false); ?>
	</form>
	<table class="widefat striped">
		<caption class="screen-reader-text">Storico operativo Corsivo Mail</caption>
		<thead><tr><th scope="col">ID</th><th scope="col">Data</th><th scope="col">Evento</th><th scope="col">Attore</th><th scope="col">Origine</th><th scope="col">Coda</th><th scope="col">Account</th><th scope="col">Destinatario</th><th scope="col">Oggetto</th><th scope="col">Stato</th><th scope="col">Dettagli</th><th scope="col">Errore</th></tr></thead>
		<tbody>
			<?php if (!$rows) : ?>
				<tr><td colspan="12">Nessun evento registrato per questi filtri.</td></tr>
			<?php else : foreach ($rows as $row) :
				$actor = $row['actor_user_id'] ? get_userdata((int) $row['actor_user_id']) : null;
				$metadata = json_decode((string) $row['metadata'], true);
				$details = [];
				if ($row['error_code']) {
					$details[] = 'code=' . $row['error_code'];
				}
				foreach ((array) $metadata as $key => $value) {
					$details[] = $key . '=' . (is_scalar($value) ? $value : wp_json_encode($value));
				}
				?>
				<tr>
					<td><?php echo (int) $row['id']; ?></td>
					<td><?php echo esc_html($row['created_at']); ?></td>
					<td><code><?php echo esc_html($row['event']); ?></code></td>
					<td><?php echo $actor ? esc_html($actor->user_login) : ($row['actor_user_id'] ? '#' . (int) $row['actor_user_id'] : '—'); ?></td>
					<td><?php echo $row['source_type'] ? '<code>' . esc_html($row['source_type']) . '</code>' . ($row['source_id'] ? ' #' . (int) $row['source_id'] : '') : '—'; ?></td>
					<td><?php echo $row['queue_id'] ? '#' . (int) $row['queue_id'] : '—'; ?></td>
					<td><?php echo esc_html($row['account_id']); ?></td>
					<td><?php echo esc_html(\CorsivoMail\Log\mask_recipient($row['recipient'])); ?></td>
					<td><?php echo esc_html($row['subject']); ?></td>
					<td><?php echo esc_html($row['status']); ?></td>
					<td><?php echo esc_html(implode(' · ', $details)); ?></td>
					<td style="color:#a00;"><?php echo esc_html($row['error']); ?></td>
				</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>
	<?php if ($pages > 1) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php echo wp_kses_post(paginate_links([
				'base' => add_query_arg('paged', '%#%'),
				'format' => '',
				'current' => $page_number,
				'total' => $pages,
			])); ?>
		</div></div>
	<?php endif; ?>
	<p class="description">Journal con esiti del trasporto e azioni amministrative. Non registra body, header, allegati, password o token; la retention si imposta in Generali.</p>
	<?php
}

function render_integrations_tab() {
	$options = Options\all();
	$meta_keys = implode("\n", (array) $options['user_meta_keys']);
	$states = [
		'integration_corsivo_theme' => function_exists('corsivo_theme_get_settings') ? 'rilevato' : 'non rilevato',
		'integration_wpml' => defined('ICL_SITEPRESS_VERSION') ? 'rilevato' : 'non rilevato',
		'integration_woocommerce' => class_exists('WooCommerce') ? 'rilevato' : 'non rilevato',
	];
	$external_newsletter = Options\external_newsletter_active();
	?>
	<form method="post">
		<?php wp_nonce_field('corsivo_mail_settings'); ?>
		<input type="hidden" name="corsivo_mail_action" value="save_integrations">
		<table class="form-table" role="presentation">
			<tr><th scope="row">Moduli interni</th><td>
				<label><input type="checkbox" name="options[module_imap]" value="1" <?php checked($options['module_imap']); ?>> Webmail IMAP</label><br>
				<label><input type="checkbox" name="options[module_subscriptions]" value="1" <?php checked($options['module_subscriptions']); ?>> Preferenze newsletter degli utenti registrati</label> <?php if ($external_newsletter) : ?><code>Corsivo Newsletter rilevato</code><?php endif; ?>
				<p class="description">Disattivarli nasconde le interfacce senza cancellare dati.</p>
				<?php if ($external_newsletter) : ?><p class="description">Corsivo Newsletter mantiene già consenso, double opt-in e audience: lascia questo modulo disattivato per avere una sola fonte di verità.</p><?php endif; ?>
			</td></tr>
			<tr><th scope="row">Corsivo Theme</th><td><label><input type="checkbox" name="options[integration_corsivo_theme]" value="1" <?php checked($options['integration_corsivo_theme']); ?>> Eredita token e font dal design system</label> <code><?php echo esc_html($states['integration_corsivo_theme']); ?></code></td></tr>
			<tr><th scope="row">WPML</th><td><label><input type="checkbox" name="options[integration_wpml]" value="1" <?php checked($options['integration_wpml']); ?>> Risolvi email, layout e footer nella lingua del destinatario</label> <code><?php echo esc_html($states['integration_wpml']); ?></code></td></tr>
			<tr><th scope="row">WooCommerce</th><td><label><input type="checkbox" name="options[integration_woocommerce]" value="1" <?php checked($options['integration_woocommerce']); ?>> Lascia rendering e coda delle email native a WooCommerce</label> <code><?php echo esc_html($states['integration_woocommerce']); ?></code></td></tr>
			<tr><th scope="row"><label for="corsivo-mail-user-meta-keys">Meta utente ammessi</label></th><td>
				<textarea id="corsivo-mail-user-meta-keys" name="options[user_meta_keys]" rows="6" class="large-text code"><?php echo esc_textarea($meta_keys); ?></textarea>
				<p class="description">Una chiave per riga. Solo queste chiavi possono essere usate come <code>{{meta:chiave}}</code>.</p>
			</td></tr>
		</table>
		<?php submit_button('Salva moduli'); ?>
	</form>
	<?php
}

function render_diagnostics_tab() {
	$queue_ready = Queue\runtime_ready();
	$log_ready = \CorsivoMail\Log\schema_ready();
	$counts = $queue_ready ? Queue\counts() : ['pending' => 0, 'sending' => 0, 'transmitting' => 0, 'failed' => 0, 'delivery_unknown' => 0];
	$account = Accounts\get_default();
	$next_cron = wp_next_scheduled('corsivo_mail_process_queue');
	$last_run = get_option('corsivo_mail_last_worker_run', []);
	$openssl_ready = function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
	$encryption_key = \CorsivoMail\Crypto\master_key();
	$encryption_key_ready = !is_wp_error($encryption_key);
	$encryption_key_source = defined(\CorsivoMail\Crypto\KEY_CONSTANT) ? 'wp-config.php' : 'database';
	$consent_provider = (bool) Options\get('module_subscriptions') || function_exists('corsivo_newsletter_get_subscriber_by_email');
	$checks = [
		['Schema coda', $queue_ready, $queue_ready ? 'pronto' : 'tabella o colonne mancanti'],
		['Schema log', $log_ready, $log_ready ? 'pronto' : 'tabella o colonne mancanti'],
		['Account SMTP predefinito', !empty($account['smtp_host']), !empty($account['smtp_host']) ? $account['smtp_host'] : 'non configurato'],
		['Cron worker', (bool) $next_cron, $next_cron ? wp_date('d/m/Y H:i:s', $next_cron) : 'non pianificato'],
		['OpenSSL AES-GCM', $openssl_ready, $openssl_ready ? 'disponibile' : 'non disponibile'],
		['Chiave cifratura credenziali', $encryption_key_ready, $encryption_key_ready ? $encryption_key_source : 'non disponibile'],
		['Provider consensi marketing', $consent_provider, $consent_provider ? 'attivo' : 'assente: le email marketing vengono soppresse finché non attivi il modulo iscrizioni o Corsivo Newsletter'],
	];
	?>
	<table class="widefat striped" style="max-width:900px;margin-top:16px;">
		<caption class="screen-reader-text">Stato tecnico Corsivo Mail</caption>
		<thead><tr><th scope="col">Controllo</th><th scope="col">Esito</th><th scope="col">Dettaglio</th></tr></thead>
		<tbody><?php foreach ($checks as $check) : ?><tr><td><?php echo esc_html($check[0]); ?></td><td><?php echo $check[1] ? '<span style="color:#1a7f37;">OK</span>' : '<span style="color:#b32d2e;">Errore</span>'; ?></td><td><?php echo esc_html($check[2]); ?></td></tr><?php endforeach; ?></tbody>
	</table>
	<p><strong>Coda:</strong> <?php echo (int) $counts['pending']; ?> in attesa, <?php echo (int) ($counts['sending'] + $counts['transmitting']); ?> in lavorazione, <?php echo (int) $counts['failed']; ?> fallite, <?php echo (int) $counts['delivery_unknown']; ?> con esito non verificabile.</p>
	<p><strong>Ultimo worker:</strong> <?php echo esc_html(($last_run['created_at'] ?? 'mai') . (isset($last_run['status']) ? ' — ' . $last_run['status'] : '')); ?></p>
	<?php
}

function render_test_tab() {
	?>
	<form method="post">
		<?php wp_nonce_field('corsivo_mail_settings'); ?>
		<input type="hidden" name="corsivo_mail_action" value="send_test">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Destinatario</th>
				<td><input type="email" name="test_to" value="<?php echo esc_attr(get_bloginfo('admin_email')); ?>" class="regular-text" required></td>
			</tr>
			<tr>
				<th scope="row">Oggetto</th>
				<td><input type="text" name="test_subject" value="Test Mail" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row">Messaggio</th>
				<td><textarea name="test_message" rows="5" class="large-text">Questa è una mail di prova inviata con Mail. Se la vedi stilizzata col layout, il wrapping funziona.</textarea></td>
			</tr>
		</table>
		<p class="description">Passa attraverso wp_mail e il layout di default, ma bypassa la coda per restituire subito l'esito SMTP.</p>
		<?php submit_button('Invia test'); ?>
	</form>
	<?php
}
