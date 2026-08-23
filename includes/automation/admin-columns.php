<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

add_filter('manage_' . TYPE . '_posts_columns', __NAMESPACE__ . '\\columns');
add_action('manage_' . TYPE . '_posts_custom_column', __NAMESPACE__ . '\\render_column', 10, 2);

function columns($columns) {
	$out = [];
	foreach ($columns as $key => $label) {
		$out[$key] = $label;
		if ($key === 'title') {
			$out['corsivo_mail_trigger']    = 'Trigger';
			$out['corsivo_mail_switches']   = 'Stato';
			$out['corsivo_mail_recipients'] = 'Destinatari';
			$out['corsivo_mail_sent']       = 'Ultimo run';
		}
	}
	return $out;
}

function render_column($column, $post_id) {
	$config = config($post_id);

	if ($column === 'corsivo_mail_switches') {
		$switches = [
			['corsivo_mail_send_enabled', $config['send_enabled'], 'red', 'Invio', 'Spento = questa email non parte mai, nemmeno quella standard di WordPress.'],
			['corsivo_mail_filter_enabled', $config['filter_enabled'], 'blue', 'Filtro', 'Acceso = questa versione sostituisce l\'email di WordPress / attiva l\'automazione.'],
		];
		foreach ($switches as $switch) {
			list($key, $on, $tone, $label, $help) = $switch;
			printf(
				'<label class="corsivo-mail-switch corsivo-mail-switch--%s" title="%s"><input type="checkbox" data-post="%d" data-key="%s"%s><span class="corsivo-mail-switch__track"></span><span class="corsivo-mail-switch__label">%s</span></label>',
				esc_attr($tone),
				esc_attr($help),
				(int) $post_id,
				esc_attr($key),
				checked($on, true, false),
				esc_html($label)
			);
		}
		return;
	}

	if ($column === 'corsivo_mail_trigger') {
		$labels = [
			'manual'     => 'Manuale',
			'core_email' => 'Override WP',
			'event'      => 'Evento',
			'schedule'   => 'Programmata',
		];
		echo esc_html($labels[$config['trigger_type']] ?? $config['trigger_type']);
		return;
	}

	if ($column === 'corsivo_mail_recipients') {
		if (in_array($config['trigger_type'], ['manual', 'schedule'], true)) {
			$count = recipient_count($config);
			echo $count === null ? 'Dinamici' : ($count > 0 ? '<strong>' . (int) $count . '</strong> in target' : '—');
		} else {
			echo '—';
		}
		return;
	}

	if ($column === 'corsivo_mail_sent') {
		$stored_total = get_post_meta($post_id, 'corsivo_mail_queued_total', true);
		$total = $stored_total === '' ? (int) get_post_meta($post_id, 'corsivo_mail_sent_total', true) : (int) $stored_total;
		$last  = get_post_meta($post_id, 'corsivo_mail_last_run_at', true);
		if ($total) {
			echo (int) $total . ' accodate';
			if ($last) {
				echo '<br><span style="color:#646970;font-size:11px;">ultimo: ' . esc_html($last) . '</span>';
			}
		} else {
			echo '—';
		}
		return;
	}
}

add_action('wp_ajax_corsivo_mail_toggle_flag', __NAMESPACE__ . '\ajax_toggle_flag');
function ajax_toggle_flag() {
	check_ajax_referer('corsivo_mail_toggle_flag');

	$post_id = (int) ($_POST['post'] ?? 0);
	$key     = sanitize_key($_POST['key'] ?? '');
	$value   = !empty($_POST['value']);

	if (!in_array($key, ['corsivo_mail_send_enabled', 'corsivo_mail_filter_enabled'], true)) {
		wp_send_json_error('Chiave non valida.', 400);
	}
	if (!$post_id || get_post_type($post_id) !== TYPE || !current_user_can('edit_post', $post_id)) {
		wp_send_json_error('Non autorizzato.', 403);
	}

	$previous = (bool) get_post_meta($post_id, $key, true);
	$updated = update_post_meta($post_id, $key, $value);
	if ($updated === false && (bool) get_post_meta($post_id, $key, true) !== $value) {
		\CorsivoMail\Log\audit('automation_flag_updated', 'failed', [
			'source_type' => 'automation',
			'source_id' => $post_id,
			'field' => $key,
			'previous' => $previous,
			'current' => $value,
			'error_code' => 'database_update_failed',
		]);
		wp_send_json_error('Salvataggio non riuscito.', 500);
	}
	\CorsivoMail\Log\audit('automation_flag_updated', 'notice', [
		'source_type' => 'automation',
		'source_id' => $post_id,
		'field' => $key,
		'previous' => $previous,
		'current' => $value,
	]);
	wp_send_json_success(['post' => $post_id, 'key' => $key, 'value' => $value]);
}

add_action('admin_footer-edit.php', __NAMESPACE__ . '\switches_assets');
function switches_assets() {
	$screen = get_current_screen();
	if (!$screen || $screen->post_type !== TYPE) {
		return;
	}
	$nonce = wp_create_nonce('corsivo_mail_toggle_flag');
	?>
	<style>
	.corsivo-mail-switch { display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 2px 0; user-select: none; }
	.corsivo-mail-switch input { position: absolute; width: 1px; height: 1px; opacity: 0; }
	.corsivo-mail-switch__track { width: 32px; height: 18px; border-radius: 999px; background: #c3c4c7; position: relative; transition: background .15s ease; flex-shrink: 0; }
	.corsivo-mail-switch input:focus-visible + .corsivo-mail-switch__track { outline: 2px solid #2271b1; outline-offset: 2px; }
	.corsivo-mail-switch__track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: left .15s ease; box-shadow: 0 1px 2px rgba(0,0,0,.25); }
	.corsivo-mail-switch input:checked + .corsivo-mail-switch__track::after { left: 16px; }
	.corsivo-mail-switch--red input:checked + .corsivo-mail-switch__track { background: #d63638; }
	.corsivo-mail-switch--blue input:checked + .corsivo-mail-switch__track { background: #2271b1; }
	.corsivo-mail-switch__label { font-size: 12px; color: #50575e; }
	.corsivo-mail-switch.is-busy { opacity: .5; pointer-events: none; }
	</style>
	<script>
	(function () {
		document.addEventListener('change', function (event) {
			const input = event.target;
			if (!input.matches('.corsivo-mail-switch input')) return;
			const wrap = input.closest('.corsivo-mail-switch');
			const requestedValue = input.checked;
			wrap.classList.add('is-busy');
			input.disabled = true;
			const body = new URLSearchParams({
				action: 'corsivo_mail_toggle_flag',
				_ajax_nonce: '<?php echo esc_js($nonce); ?>',
				post: input.dataset.post,
				key: input.dataset.key,
				value: requestedValue ? '1' : ''
			});
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
				.then(function (response) { if (!response.ok) throw new Error(); return response.json(); })
				.then(function (data) {
					if (!data || !data.success) throw new Error();
					input.checked = typeof data.data === 'object' && data.data !== null && 'value' in data.data ? !!data.data.value : requestedValue;
				})
				.catch(function () { input.checked = !requestedValue; alert('Salvataggio non riuscito, riprova.'); })
				.finally(function () { wrap.classList.remove('is-busy'); input.disabled = false; });
		});
	})();
	</script>
	<?php
}
