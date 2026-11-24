<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/ObjectsForReviewGridCellProvider.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewGridCellProvider
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Class for a cell provider to display information about bookReviewManager items
 */

namespace APP\plugins\generic\bookReviewManager\controllers\grid;


use PKP\controllers\grid\GridCellProvider;
use PKP\core\PKPString;

class ObjectsForReviewGridCellProvider extends GridCellProvider {

	//
	// Template methods from GridCellProvider
	//

	/**
	 * Extracts variables for a given column from a data element
	 * so that they may be assigned to template before rendering.
	 *
	 * @copydoc GridCellProvider::getTemplateVarsFromRowColumn()
	 */
	function getTemplateVarsFromRowColumn($row, $column) {
		$bookReviewManagerItem = $row->getData();
		switch ($column->getId()) {
			case 'description':
				// The citation (column flag anyhtml, so sanitized here) with "type: identifier" below it,
				// linked to the resource where possible (escaped HTML, IdentifierLinks)
				$description = PKPString::stripUnsafeHtml((string) $bookReviewManagerItem['description']);
				if (!empty($bookReviewManagerItem['identifierHtml'])) {
					$description .= '<div class="ofrIdentifier">' . $bookReviewManagerItem['identifierHtml'] . '</div>';
				}
				return array('label' => $description);
		}
	}
}

?>
