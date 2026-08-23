<?php

$source = file_get_contents(dirname(__DIR__) . '/assets/inbox.js');
$assertions = 0;

function assert_inbox_contract($condition, $message) {
	global $assertions;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$assertions++;
}

foreach (['/imap-accounts', '/inbox?', '/inbox-sync', '/inbox-body?', '/reply', '/inbox-delete'] as $route) {
	assert_inbox_contract(strpos($source, "'/corsivo/v1/mail{$route}") !== false, "The {$route} route remains in use.");
}

foreach (['accountsRequest', 'listRequest', 'bodyRequest', 'deleteRequest', 'syncRequest'] as $request) {
	assert_inbox_contract(strpos($source, "const {$request} = useRef( 0 )") !== false, "The {$request} race guard exists.");
	assert_inbox_contract(
		strpos($source, "request !== {$request}.current") !== false || strpos($source, "request === {$request}.current") !== false,
		"The {$request} response is correlated."
	);
}

assert_inbox_contract(strpos($source, 'window.confirm') === false, 'Deletion no longer uses window.confirm.');
assert_inbox_contract(strpos($source, 'ConfirmDialog') !== false, 'Deletion uses an accessible confirm dialog.');
assert_inbox_contract(strpos($source, "sandbox: ''") !== false, 'Message HTML remains sandboxed.');
assert_inbox_contract(strpos($source, "referrerPolicy: 'no-referrer'") !== false, 'Message HTML preserves the referrer policy.');
assert_inbox_contract(strpos($source, "config.attachmentBase || ''") !== false, 'Attachments retain the localized base URL.');
assert_inbox_contract(strpos($source, 'style:') === false, 'Structural styles live in the scoped stylesheet.');

echo "Corsivo Mail inbox contract: {$assertions} assertions PASS\n";
