<?php

/**
 * Print an API key (JWT) for a user of the dev container, for E2E tests.
 * Reuses the user's existing key, so that parallel test processes get the same one;
 * creates and enables a key if there is none.
 *
 * Run from the OJS folder: php plugins/generic/bookReviewManager/tests/lib/php/api-key.php <username>
 */

use APP\facades\Repo;
use Firebase\JWT\JWT;
use PKP\config\Config;

chdir('/var/www/html');
define('INDEX_FILE_LOCATION', '/var/www/html/index.php');
require_once './lib/pkp/includes/bootstrap.php';

$username = $argv[1] ?? '';
$user = Repo::user()->getByUsername($username, true);
if (!$user) {
	fwrite(STDERR, "No user {$username}\n");
	exit(1);
}
$secret = Config::getVar('security', 'api_key_secret', '');
if ($secret === '') {
	fwrite(STDERR, "api_key_secret is not set in config.inc.php\n");
	exit(1);
}
if (!$user->getData('apiKey') || !$user->getData('apiKeyEnabled')) {
	$user->setData('apiKey', $user->getData('apiKey') ?: bin2hex(random_bytes(32)));
	$user->setData('apiKeyEnabled', true);
	Repo::user()->edit($user);
}
echo JWT::encode([$user->getData('apiKey')], $secret, 'HS256'), "\n";
