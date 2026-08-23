<?php

namespace CorsivoMail\Crypto {
	function is_encrypted($value) {
		return str_starts_with((string) $value, 'encrypted:');
	}

	function encrypt($value, $context) {
		return 'encrypted:' . $context . ':' . $value;
	}

	function decrypt($value) {
		return $value;
	}
}

namespace CorsivoMail\Options {
	function get() {
		return true;
	}
}

namespace CorsivoMail\Queue {
	function table_exists() {
		return (bool) ($GLOBALS['settings_queue_exists'] ?? false);
	}

	function active_count_for_account($account_id) {
		return (int) ($GLOBALS['settings_queue_counts'][$account_id] ?? 0);
	}
}

namespace CorsivoMail\Automation {
	const TYPE = 'corsivo_mail_automation';
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('CORSIVO_MAIL_DIR', dirname(__DIR__) . '/');
	define('CORSIVO_MAIL_URL', 'https://example.test/wp-content/plugins/corsivo-mail/');
	define('CORSIVO_MAIL_VERSION', 'test');

	class WP_Error {
		private $code;
		private $message;

		public function __construct($code, $message) {
			$this->code = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class SettingsWpdb {
		public $posts = 'wp_posts';
		public $postmeta = 'wp_postmeta';

		public function prepare($query, ...$arguments) {
			return [$query, $arguments];
		}

		public function get_var($prepared) {
			$account_id = $prepared[1][2] ?? '';
			return (int) ($GLOBALS['settings_automation_counts'][$account_id] ?? 0);
		}
	}

	$GLOBALS['wpdb'] = new SettingsWpdb();
	$GLOBALS['settings_options'] = [];
	$GLOBALS['settings_automation_counts'] = [];
	$GLOBALS['settings_queue_counts'] = [];
	$GLOBALS['settings_queue_exists'] = false;
	$GLOBALS['settings_uuid'] = 0;
	$assertions = 0;

	function add_action() {}
	function apply_filters($hook, $value) {
		return $value;
	}
	function wp_parse_args($arguments, $defaults = []) {
		return array_merge($defaults, is_array($arguments) ? $arguments : []);
	}
	function get_option($key, $default = false) {
		return array_key_exists($key, $GLOBALS['settings_options']) ? $GLOBALS['settings_options'][$key] : $default;
	}
	function update_option($key, $value) {
		$changed = !array_key_exists($key, $GLOBALS['settings_options']) || $GLOBALS['settings_options'][$key] !== $value;
		$GLOBALS['settings_options'][$key] = $value;
		return $changed;
	}
	function wp_unslash($value) {
		return $value;
	}
	function wp_generate_uuid4() {
		$GLOBALS['settings_uuid']++;
		return '00000000-0000-4000-8000-' . str_pad((string) $GLOBALS['settings_uuid'], 12, '0', STR_PAD_LEFT);
	}
	function sanitize_key($value) {
		return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
	}
	function sanitize_text_field($value) {
		return trim(strip_tags((string) $value));
	}
	function sanitize_email($value) {
		return filter_var((string) $value, FILTER_SANITIZE_EMAIL);
	}
	function is_wp_error($value) {
		return $value instanceof WP_Error;
	}

	function account_fixture($id, $label, $is_default = false) {
		return [
			'id' => $id,
			'label' => $label,
			'provider' => 'custom',
			'from_name' => $label . ' Sender',
			'from_email' => strtolower($label) . '@example.test',
			'smtp_host' => 'smtp.example.test',
			'smtp_port' => 587,
			'smtp_secure' => 'tls',
			'smtp_auth' => true,
			'smtp_user' => strtolower($label) . '@example.test',
			'smtp_pass' => 'encrypted:account-smtp-pass:' . strtolower($label),
			'imap_host' => 'imap.example.test',
			'imap_port' => 993,
			'imap_secure' => 'ssl',
			'imap_user' => '',
			'imap_pass' => 'encrypted:account-imap-pass:' . strtolower($label),
			'is_default' => $is_default,
		];
	}

	function posted_account($account) {
		$posted = $account;
		$posted['smtp_pass'] = '';
		$posted['imap_pass'] = '';
		$posted['smtp_auth'] = '1';
		unset($posted['is_default']);
		return $posted;
	}

	function assert_settings_post($condition, $message) {
		global $assertions;
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		$assertions++;
	}

	require_once CORSIVO_MAIL_DIR . 'includes/settings/accounts.php';
	require_once CORSIVO_MAIL_DIR . 'includes/newsletter/groups.php';
	require_once CORSIVO_MAIL_DIR . 'includes/settings/page.php';

	$first = account_fixture('account-a', 'First', true);
	$second = account_fixture('account-b', 'Second');
	$GLOBALS['settings_options'][\CorsivoMail\Accounts\OPTION] = [$first, $second];
	$_POST = [
		'accounts' => [
			posted_account($second),
			array_merge(posted_account($first), ['clear_imap_pass' => '1']),
		],
		'default_account' => '1',
	];

	$result = \CorsivoMail\Settings\save_accounts_post();
	assert_settings_post(!is_wp_error($result), 'The reordered Accounts POST roundtrip succeeds.');
	assert_settings_post(array_column($result, 'id') === ['account-b', 'account-a'], 'POST order is persisted.');
	assert_settings_post($result[0]['smtp_pass'] === $second['smtp_pass'], 'A blank SMTP password preserves the stored encrypted value.');
	assert_settings_post($result[0]['imap_pass'] === $second['imap_pass'], 'A blank IMAP password preserves the stored encrypted value.');
	assert_settings_post($result[1]['smtp_pass'] === $first['smtp_pass'], 'SMTP preservation survives reorder by account ID.');
	assert_settings_post($result[1]['imap_pass'] === '', 'clear_imap_pass removes the dedicated IMAP credential.');
	assert_settings_post(empty($result[0]['is_default']) && !empty($result[1]['is_default']), 'The default account follows the posted index after reorder.');
	assert_settings_post(!array_key_exists('clear_imap_pass', $result[1]), 'The transport-only clear flag is not persisted.');
	assert_settings_post(!array_key_exists('_id', $result[0]), 'Transient Repeater identity never enters the account shape.');

	$blocked = account_fixture('account-blocked', 'Blocked');
	$GLOBALS['settings_options'][\CorsivoMail\Accounts\OPTION] = [$blocked];
	$GLOBALS['settings_automation_counts']['account-blocked'] = 1;
	$_POST = ['accounts' => [], 'default_account' => ''];
	$blocked_result = \CorsivoMail\Settings\save_accounts_post();
	assert_settings_post(is_wp_error($blocked_result), 'Removing a referenced account remains blocked server-side.');
	assert_settings_post($blocked_result->get_error_code() === 'corsivo_mail_account_in_automation', 'The automation removal guard keeps its error contract.');

	$GLOBALS['settings_options'][\CorsivoMail\Groups\OPTION] = [[
		'id' => 'group-a',
		'name' => 'Old',
		'rule' => 'all_users',
		'value' => '',
	]];
	$groups_post = [
		['id' => '', 'name' => 'Manual list', 'rule' => 'manual', 'value' => 'one@example.test, two@example.test'],
		['id' => 'group-a', 'name' => 'Editors', 'rule' => 'role', 'value' => 'editor'],
	];
	$groups_result = \CorsivoMail\Groups\save_all(wp_unslash($groups_post));
	assert_settings_post(!is_wp_error($groups_result), 'The Groups POST roundtrip succeeds.');
	assert_settings_post(count($groups_result) === 2, 'Add and reorder are persisted for Groups.');
	assert_settings_post(str_starts_with($groups_result[0]['id'], 'grp_'), 'A new Group receives a server ID.');
	assert_settings_post($groups_result[1] === [
		'id' => 'group-a',
		'name' => 'Editors',
		'rule' => 'role',
		'value' => 'editor',
	], 'The existing Group keeps the exact public shape.');
	assert_settings_post(!array_key_exists('_id', $groups_result[0]), 'Transient Repeater identity never enters the Group shape.');

	echo "Corsivo Mail settings POST contract: {$assertions} assertions PASS\n";
}
