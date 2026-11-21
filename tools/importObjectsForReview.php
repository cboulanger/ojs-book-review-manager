<?php

/**
 * @file plugins/generic/bookReviewManager/tools/importObjectsForReview.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Copy the objects, journal settings and customised email templates of the upstream
 * Objects for Review plugin (ajnyga/objectsForReview) into Book Review Manager.
 * The upstream data stays unchanged; objects imported before are skipped.
 *
 * Usage (from the OJS directory):
 *   php plugins/generic/bookReviewManager/tools/importObjectsForReview.php [--dry-run] [journalPath ...]
 */

require(dirname(__FILE__, 5) . '/tools/bootstrap.php');

use APP\core\Application;
use APP\plugins\generic\bookReviewManager\classes\UpstreamImport;
use PKP\cliTool\CommandLineTool;
use PKP\plugins\PluginRegistry;

class ImportObjectsForReviewTool extends CommandLineTool
{
	public function usage()
	{
		echo "Copy the data of the Objects for Review plugin into Book Review Manager.\n\n"
			. "Usage: php {$this->scriptName} [--dry-run] [journalPath ...]\n"
			. "  --dry-run     Only show what would be imported\n"
			. "  journalPath   Only import these journals (default: all)\n";
	}

	public function execute()
	{
		$dryRun = in_array('--dry-run', $this->argv, true);
		$paths = array_values(array_filter($this->argv, fn ($arg) => $arg !== '--dry-run'));

		// Registering the plugin creates or upgrades its tables
		PluginRegistry::loadCategory('generic');
		if (!PluginRegistry::getPlugin('generic', 'BookReviewManagerPlugin')) {
			fwrite(STDERR, "The Book Review Manager plugin is not installed.\n");
			exit(1);
		}
		if (!UpstreamImport::available()) {
			fwrite(STDERR, "No data of the Objects for Review plugin found.\n");
			exit(1);
		}

		$contextIds = null;
		if ($paths) {
			$contextIds = [];
			foreach ($paths as $path) {
				$context = Application::getContextDAO()->getByPath($path);
				if (!$context) {
					fwrite(STDERR, "Unknown journal: {$path}\n");
					exit(1);
				}
				$contextIds[] = (int) $context->getId();
			}
		}

		$result = UpstreamImport::run($contextIds, $dryRun);
		$verb = $dryRun ? 'Would import' : 'Imported';
		echo "{$verb} {$result['objects']} object(s) ({$result['skipped']} imported before), "
			. "{$result['settings']} journal setting(s) and {$result['templates']} customised email template(s).\n";
	}
}

$tool = new ImportObjectsForReviewTool($argv ?? []);
$tool->execute();
