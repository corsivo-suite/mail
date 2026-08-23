<?php

namespace CorsivoMail\Newsletter;

use CorsivoMail\Crypto;
use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

function unsubscribe_signing_key() {
	$master_key = Crypto\master_key();
	if (is_wp_error($master_key)) {
		return '';
	}

	return Crypto\derive_key($master_key, 'newsletter-unsubscribe-token');
}

function token($user_id, $action = 'unsubscribe') {
	$signing_key = unsubscribe_signing_key();
	if ($signing_key === '') {
		return '';
	}

	return hash_hmac('sha256', 'corsivo-mail-' . get_current_blog_id() . '-' . sanitize_key($action) . '-' . (int) $user_id, $signing_key);
}

function unsubscribe_url($user_id) {
	$key = token($user_id);
	if ($key === '') {
		return '';
	}

	return add_query_arg([
		'corsivo_mail_unsub' => (int) $user_id,
		'k' => $key,
	], home_url('/'));
}

function valid_token($user_id, $key, $action) {
	if (!is_string($key) || preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1) {
		return false;
	}

	$expected = token($user_id, $action);
	return $expected !== '' && hash_equals($expected, $key);
}

function marketing_header_lines($headers) {
	if (is_string($headers)) {
		$headers = preg_split('/\r\n|\r|\n/', $headers, -1, PREG_SPLIT_NO_EMPTY);
	}
	$lines = [];
	foreach ((array) $headers as $name => $value) {
		$line = is_string($name) ? $name . ': ' . (string) $value : (string) $value;
		if (trim($line) !== '') {
			$lines[] = $line;
		}
	}
	return $lines;
}

function marketing_unsubscribe_preflight($to, $headers = [], $require_headers = false) {
	if (!Options\get('module_subscriptions')) {
		return true;
	}
	$recipients = normalize_recipients($to);
	if (count($recipients) !== 1) {
		return new \WP_Error('corsivo_mail_marketing_recipient_invalid', 'Le email marketing richiedono un solo destinatario.');
	}

	$has_unsubscribe = false;
	$has_one_click = false;
	foreach (marketing_header_lines($headers) as $header) {
		$header = trim($header);
		if (preg_match('/^(cc|bcc):/i', $header)) {
			return new \WP_Error('corsivo_mail_marketing_copies_unsupported', 'Cc e Bcc non sono consentiti nelle email marketing.');
		}
		$has_unsubscribe = $has_unsubscribe || stripos($header, 'List-Unsubscribe:') === 0;
		$has_one_click = $has_one_click || stripos($header, 'List-Unsubscribe-Post:') === 0;
	}

	$user = get_user_by('email', $recipients[0]);
	if (!$user || !is_subscribed($user->ID)) {
		return new \WP_Error('corsivo_mail_marketing_consent_missing', 'Il destinatario non ha un consenso marketing attivo.');
	}
	$url = unsubscribe_url($user->ID);
	if ($url === '') {
		return new \WP_Error('corsivo_mail_unsubscribe_unavailable', 'Il link di disiscrizione non è disponibile.');
	}
	if ($require_headers && (!$has_unsubscribe || !$has_one_click)) {
		return new \WP_Error('corsivo_mail_unsubscribe_headers_missing', 'Gli header di disiscrizione non sono disponibili.');
	}
	return ['user' => $user, 'url' => $url];
}

add_filter('pre_wp_mail', __NAMESPACE__ . '\\block_marketing_without_unsubscribe', 9, 2);

function block_marketing_without_unsubscribe($short_circuit, $attributes) {
	if ($short_circuit !== null) {
		return $short_circuit;
	}
	if (($GLOBALS['corsivo_mail_message_type'] ?? 'transactional') !== 'marketing'
		|| !Options\get('module_subscriptions')) {
		return null;
	}
	$attributes = is_array($attributes) ? $attributes : [];
	$preflight = marketing_unsubscribe_preflight(
		$attributes['to'] ?? '',
		$attributes['headers'] ?? [],
		true
	);
	if (!is_wp_error($preflight)) {
		return null;
	}
	$preflight->add_data(['mail_data' => $attributes], $preflight->get_error_code());
	$GLOBALS['corsivo_mail_last_error'] = $preflight;
	\CorsivoMail\Queue\dispatch_action('wp_mail_failed', $preflight);
	return false;
}

add_action('init', __NAMESPACE__ . '\\handle_unsubscribe', 1);

function handle_unsubscribe() {
	if (!isset($_GET['corsivo_mail_unsub'])) {
		return;
	}
	$user_id = (int) $_GET['corsivo_mail_unsub'];

	$key = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	$action = $method === 'POST' ? sanitize_key($_POST['corsivo_mail_subscription_action'] ?? 'unsubscribe') : 'unsubscribe';

	if (!$user_id || !in_array($action, ['unsubscribe', 'resubscribe'], true) || !valid_token($user_id, $key, $action)) {
		render_confirmation_page('Link non valido', '<p>Questo link di gestione preferenze non è valido o è scaduto.</p>', 400);
	}

	if ($method !== 'POST') {
		render_confirmation_page(
			'Conferma disiscrizione',
			'<p>Vuoi smettere di ricevere le email marketing di <strong>' . esc_html(get_bloginfo('name')) . '</strong>?</p>',
			200,
			['action' => 'unsubscribe', 'label' => 'Conferma disiscrizione', 'key' => $key, 'url' => unsubscribe_url($user_id)]
		);
	}

	if ($action === 'resubscribe') {
		$result = set_subscribed($user_id, true, 'resubscribe_link');
		if (is_wp_error($result)) {
			render_confirmation_page('Operazione non riuscita', '<p>Non è stato possibile aggiornare la preferenza. Riprova.</p>', 500);
		}
		render_confirmation_page('Iscrizione riattivata', '<p>Riceverai di nuovo le email marketing di <strong>' . esc_html(get_bloginfo('name')) . '</strong>.</p>');
	}

	$result = set_subscribed($user_id, false, 'one_click_unsubscribe');
	if (is_wp_error($result)) {
		render_confirmation_page('Operazione non riuscita', '<p>Non è stato possibile aggiornare la preferenza. Riprova.</p>', 500);
	}
	if (!empty($_POST['List-Unsubscribe']) && $_POST['List-Unsubscribe'] === 'One-Click') {
		status_header(200);
		exit;
	}

	$resubscribe_url = add_query_arg([
		'corsivo_mail_unsub' => $user_id,
		'k' => token($user_id, 'resubscribe'),
	], home_url('/'));
	render_confirmation_page(
		'Disiscrizione completata',
		'<p>Non riceverai più le email marketing di <strong>' . esc_html(get_bloginfo('name')) . '</strong>.</p>',
		200,
		['action' => 'resubscribe', 'label' => 'Riattiva iscrizione', 'key' => token($user_id, 'resubscribe'), 'url' => $resubscribe_url]
	);
}

function render_confirmation_page($title, $body_html, $status = 200, $form = []) {
	status_header($status);
	nocache_headers();
	header('Content-Type: text/html; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: no-referrer');
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(str_replace('_', '-', get_locale())); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html($title . ' — ' . get_bloginfo('name')); ?></title>
	<style>
		body { margin: 0; padding: 24px; background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; }
		.corsivo-mail-unsub-card { max-width: 480px; margin: 10vh auto 0; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 32px; }
		.corsivo-mail-unsub-card h1 { margin: 0 0 12px; font-size: 22px; }
		.corsivo-mail-unsub-card p { margin: 0 0 12px; line-height: 1.55; }
		.corsivo-mail-unsub-card button { border: 0; border-radius: 3px; padding: 10px 16px; background: #2271b1; color: #fff; cursor: pointer; }
		.corsivo-mail-unsub-site { display: block; margin-top: 20px; font-size: 13px; color: #646970; text-decoration: none; }
	</style>
</head>
<body>
	<div class="corsivo-mail-unsub-card">
		<h1><?php echo esc_html($title); ?></h1>
		<?php echo wp_kses_post($body_html); ?>
		<?php if ($form) : ?>
			<form method="post" action="<?php echo esc_url($form['url'] ?? unsubscribe_url((int) $_GET['corsivo_mail_unsub'])); ?>">
				<input type="hidden" name="corsivo_mail_subscription_action" value="<?php echo esc_attr($form['action']); ?>">
				<button type="submit"><?php echo esc_html($form['label']); ?></button>
			</form>
		<?php endif; ?>
		<a class="corsivo-mail-unsub-site" href="<?php echo esc_url(home_url('/')); ?>">&larr; <?php echo esc_html(get_bloginfo('name')); ?></a>
	</div>
</body>
</html>
	<?php
	exit;
}

add_filter('corsivo_mail_merge_tags', function ($tags, $context) {
	$tags['unsubscribe_url'] = !empty($context['_user_id']) ? unsubscribe_url($context['_user_id']) : '';
	return $tags;
}, 10, 2);

add_filter('wp_mail', __NAMESPACE__ . '\\add_unsubscribe_header', 30);

function add_unsubscribe_header($atts) {
	if (!Options\get('module_subscriptions') || ($GLOBALS['corsivo_mail_message_type'] ?? 'transactional') !== 'marketing') {
		return $atts;
	}
	return add_marketing_headers($atts);
}

function add_marketing_headers($atts) {
	if (!Options\get('module_subscriptions')) {
		return $atts;
	}
	$preflight = marketing_unsubscribe_preflight($atts['to'] ?? '', $atts['headers'] ?? []);
	if (is_wp_error($preflight)) {
		return $atts;
	}
	$headers = marketing_header_lines($atts['headers'] ?? []);
	$has_unsubscribe = false;
	$has_one_click = false;
	foreach ($headers as $header) {
		$has_unsubscribe = $has_unsubscribe || stripos(trim($header), 'List-Unsubscribe:') === 0;
		$has_one_click = $has_one_click || stripos(trim($header), 'List-Unsubscribe-Post:') === 0;
	}
	if (!$has_unsubscribe) {
		$headers[] = 'List-Unsubscribe: <' . $preflight['url'] . '>';
	}
	if (!$has_one_click) {
		$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
	}
	$atts['headers'] = $headers;
	return $atts;
}

function normalize_recipients($to) {
	$items = is_array($to) ? $to : preg_split('/[,;]+/', (string) $to, -1, PREG_SPLIT_NO_EMPTY);
	$out = [];
	foreach ($items as $item) {
		if (preg_match('/<([^>]+)>/', (string) $item, $match)) {
			$item = $match[1];
		}
		$email = sanitize_email(trim((string) $item));
		if ($email) {
			$out[] = $email;
		}
	}
	return array_values(array_unique($out));
}

function can_receive_marketing($to) {
	$recipients = normalize_recipients($to);
	if (count($recipients) !== 1) {
		return false;
	}
	$email = $recipients[0];
	$user = get_user_by('email', $email);
	if (Options\get('module_subscriptions')) {
		$allowed = $user && is_subscribed($user->ID);
	} elseif ($user && has_explicit_optout($user->ID)) {
		$allowed = false;
	} elseif (function_exists('corsivo_newsletter_get_subscriber_by_email')) {
		$subscriber = corsivo_newsletter_get_subscriber_by_email($email);
		$allowed = is_array($subscriber) && ($subscriber['status'] ?? '') === 'confirmed';
	} else {
		$allowed = false;
	}
	return (bool) apply_filters('corsivo_mail_can_receive_marketing', $allowed, $email);
}
