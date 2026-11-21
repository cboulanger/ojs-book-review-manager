<?php

/**
 * @file dev/make-review-essay.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Dev helper: creates a review essay "Essay test" with two new works
 *  ("Essay test A" and "Essay test B") in journal 1, for manual checks.
 *
 *  podman exec ojs-ofr-essays php /var/www/html/plugins/generic/bookReviewManager/dev/make-review-essay.php
 *
 *  Prints: container=<id> members=<id>,<id>
 */

// Boot OJS like tests/unit/php/bootstrap.php (without the PHPUnit base class)
chdir('/var/www/html');
define('INDEX_FILE_LOCATION', '/var/www/html/index.php');
require_once './lib/pkp/includes/bootstrap.php';

$application = \APP\core\Application::get();
$router = new \APP\core\PageRouter();
$router->setApplication($application);
$application->getRequest()->setRouter($router);
\PKP\plugins\PluginRegistry::loadCategory('generic');
\PKP\facades\Locale::setLocale('en');

use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;

const CONTEXT_ID = 1;

$context = \APP\core\Application::getContextDAO()->getById(CONTEXT_ID);
$editor = Repo::user()->getByUsername('admin', true);
if (!$context || !$editor) {
	fwrite(STDERR, "Journal 1 or user admin not found\n");
	exit(1);
}

$dao = new ObjectForReviewDAO();
$works = [];
foreach (['A', 'B'] as $letter) {
	$work = $dao->newDataObject();
	$work->setContextId(CONTEXT_ID);
	$work->setIdentifier('978' . random_int(1000000000, 9999999999));
	$work->setIdentifierType('isbn');
	$work->setResourceType('http://purl.org/coar/resource_type/c_2f33');
	$work->setCreator('manager');
	$work->setTitle('Essay test ' . $letter);
	$work->setAuthors('Author ' . $letter);
	$work->setOfferSetting('hidden', true);
	$dao->insertObject($work);
	$works[] = $work;
}

$container = BundleWorkflow::create($context, 'Essay test', null, $works, $editor);
$container->setOfferSetting('hidden', null);
$dao->updateObject($container);

echo 'container=' . $container->getId() . ' members=' . implode(',', array_map(fn ($w) => $w->getId(), $works)) . "\n";
