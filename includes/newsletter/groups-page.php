<?php

namespace CorsivoMail\Groups;

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_init', __NAMESPACE__ . '\\handle_save');
add_filter('corsivo_mail_settings_ui_config', __NAMESPACE__ . '\\settings_ui_config', 10, 2);

function settings_ui_config($config, $tab) {
	if ($tab !== 'groups') {
		return $config;
	}
	$config['groups'] = [
		'groups' => all(),
		'rules' => rules(),
		'nonce' => wp_create_nonce('corsivo_mail_groups'),
	];
	return $config;
}

function handle_save() {
	if (!isset($_POST['corsivo_mail_groups_action']) || !current_user_can('manage_options')) {
		return;
	}
	check_admin_referer('corsivo_mail_groups');
	$result = save_all(wp_unslash($_POST['groups'] ?? []));
	$status = is_wp_error($result) ? 'failed' : 'updated';
	\CorsivoMail\Log\audit('recipient_groups_updated', is_wp_error($result) ? 'failed' : 'notice', [
		'updated' => is_wp_error($result) ? 0 : count($result),
		'error_code' => is_wp_error($result) ? $result->get_error_code() : '',
	]);
	wp_safe_redirect(add_query_arg(['page' => 'corsivo-mail', 'tab' => 'groups', $status => '1'], admin_url('admin.php')));
	exit;
}

function render_card($index, $group) {
	?>
	<div class="corsivo-mail-group-card corsivo-mail-settings__fallback-group">
		<input type="hidden" name="groups[<?php echo $index; ?>][id]" value="<?php echo esc_attr($group['id'] ?? ''); ?>">
		<input type="text" name="groups[<?php echo $index; ?>][name]" value="<?php echo esc_attr($group['name'] ?? ''); ?>" placeholder="Nome gruppo" class="regular-text">
		<select name="groups[<?php echo $index; ?>][rule]" class="ms-group-rule">
			<?php foreach (rules() as $key => $label) : ?>
				<option value="<?php echo esc_attr($key); ?>" <?php selected($group['rule'] ?? 'all_users', $key); ?>><?php echo esc_html($label); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="text" name="groups[<?php echo $index; ?>][value]" value="<?php echo esc_attr($group['value'] ?? ''); ?>" placeholder="valore (se richiesto)" class="regular-text ms-group-value">
		<button type="button" class="button-link-delete ms-group-remove">Rimuovi</button>
	</div>
	<?php
}

function render_body() {
	$groups = all();
	?>
		<?php if (!empty($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Gruppi salvati.</p></div>
		<?php endif; ?>
		<?php if (!empty($_GET['failed'])) : ?>
			<div class="notice notice-error is-dismissible"><p>Salvataggio dei gruppi non riuscito.</p></div>
		<?php endif; ?>
		<div id="corsivo-mail-groups-app" hidden></div>
		<div id="corsivo-mail-groups-fallback" class="corsivo-mail-settings-fallback">
			<p class="description">Definisci gruppi riutilizzabili da scegliere come destinatari in un'email programmata. "Iscritti newsletter" = utenti che hanno dato il consenso.</p>
			<form method="post">
				<?php wp_nonce_field('corsivo_mail_groups'); ?>
				<input type="hidden" name="corsivo_mail_groups_action" value="1">
				<div id="corsivo-mail-groups">
					<?php foreach ($groups as $i => $group) {
						render_card($i, $group);
					} ?>
				</div>
				<p><button type="button" class="button button-secondary" id="corsivo-mail-add-group">+ Aggiungi gruppo</button></p>
				<template id="corsivo-mail-group-template"><?php render_card('__INDEX__', []); ?></template>
				<?php submit_button('Salva gruppi'); ?>
			</form>
		</div>
	<?php
}
