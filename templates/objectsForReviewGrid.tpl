{**
 * templates/objectsForReviewGrid.tpl
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Objects for review plugin -- ofr grid.
 *}
<div id="bookReviewManager">
	{capture assign="bookReviewManagerGridUrl"}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT  component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler" op="fetchGrid" submissionId=$submission->getId() escape=false}{/capture}
	{load_url_in_div id="bookReviewManagerGridContainer"|uniqid url=$bookReviewManagerGridUrl inVueEl=true}
</div>