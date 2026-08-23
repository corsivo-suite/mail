<?php

namespace CorsivoMail\Newsletter;

use CorsivoMail\Options;

if (!defined('ABSPATH')) {
	exit;
}

const META = 'corsivo_mail_subscribed';

function is_subscribed($user_id) {
	$value = get_user_meta($user_id, meta_key(), true);
	return $value === '1';
}

function set_subscribed($user_id, $subscribed, $source = 'user') {
	$previous = is_subscribed($user_id);
	update_user_meta($user_id, meta_key(), $subscribed ? '1' : '0');
	if (is_subscribed($user_id) !== (bool) $subscribed) {
		return new \WP_Error('corsivo_mail_subscription_save_failed', 'Salvataggio della preferenza newsletter non riuscito.');
	}
	if ($previous !== (bool) $subscribed && function_exists('CorsivoMail\\Log\\audit')) {
		\CorsivoMail\Log\audit('subscription_changed', 'notice', [
			'actor_user_id' => get_current_user_id(),
			'source_type' => 'user',
			'source_id' => (int) $user_id,
			'previous' => $previous ? 'subscribed' : 'unsubscribed',
			'current' => $subscribed ? 'subscribed' : 'unsubscribed',
			'reason' => $source,
		]);
	}
	return true;
}

function meta_key() {
	return is_multisite() ? META . '_' . get_current_blog_id() : META;
}

function has_explicit_optout($user_id) {
	$value = get_user_meta($user_id, meta_key(), true);
	return $value === '0';
}

function subscribed_users() {
	return get_users(['meta_key' => meta_key(), 'meta_value' => '1']);
}

add_action('show_user_profile', __NAMESPACE__ . '\\profile_field');
add_action('edit_user_profile', __NAMESPACE__ . '\\profile_field');
add_action('personal_options_update', __NAMESPACE__ . '\\save_profile_field');
add_action('edit_user_profile_update', __NAMESPACE__ . '\\save_profile_field');

function profile_field($user) {
	if (!Options\get('module_subscriptions')) {
		return;
	}
	?>
	<h2>Newsletter</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Newsletter</th>
			<td><label><input type="checkbox" name="corsivo_mail_subscribed" value="1" <?php checked(is_subscribed($user->ID)); ?>> Riceve le email della newsletter</label></td>
		</tr>
	</table>
	<?php
}

function save_profile_field($user_id) {
	if (!Options\get('module_subscriptions') || !current_user_can('edit_user', $user_id)) {
		return;
	}
	set_subscribed($user_id, !empty($_POST['corsivo_mail_subscribed']), 'profile');
}

add_action('rest_api_init', function () {
	if (!Options\get('module_subscriptions')) {
		return;
	}
	register_rest_route('corsivo/v1/mail', '/subscription', [
		'methods'             => 'POST',
		'permission_callback' => 'is_user_logged_in',
		'callback'            => __NAMESPACE__ . '\\rest_toggle',
	]);
	register_rest_route('corsivo/v1/mail', '/subscription', [
		'methods'             => 'GET',
		'permission_callback' => 'is_user_logged_in',
		'callback'            => __NAMESPACE__ . '\\rest_status',
	]);
});

function rest_toggle($request) {
	$user_id = get_current_user_id();
	$result = set_subscribed($user_id, !empty($request['subscribed']), 'rest');
	if (is_wp_error($result)) {
		$result->add_data(['status' => 500]);
		return $result;
	}
	return rest_ensure_response(['subscribed' => is_subscribed($user_id)]);
}

function rest_status() {
	return rest_ensure_response(['subscribed' => is_subscribed(get_current_user_id())]);
}
