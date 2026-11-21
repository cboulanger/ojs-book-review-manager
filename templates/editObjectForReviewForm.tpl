{**
 * plugins/generic/bookReviewManager/templates/editFunderForm.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Form for editing a bookReviewManager item
 *}

{if $reservedObjects}
	{foreach from=$reservedObjects item=reservedObject}
		<script>
			$(function() {ldelim}
				// Attach the form handler.
				$('#reservedObjectsForReviewForm-{$reservedObject.objectId}').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
			{rdelim});
		</script>
		<p>

			{capture assign="reserveActionUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler" op="addReservedObjectForReview" submissionId=$submissionId escape=false}{/capture}

			<form class="pkp_form" id="reservedObjectsForReviewForm-{$reservedObject.objectId}" method="post" action="{$reserveActionUrl}">
				{csrf}
				<input type="hidden" name="objectId" value="{$reservedObject.objectId|escape}" />
				{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
				{$reservedObject.description|escape} {fbvElement type="submit" class="submitFormButton" id=$buttonId label="plugins.generic.bookReviewManager.bookReviewManagerData.addReservedObject"}
			</form>
		</p>
	{/foreach}
{else}
	{if $onlyReserved}
		{translate key="plugins.generic.bookReviewManager.noReservedObjects"}
	{/if}
{/if}


{if !$onlyReserved}
	<script>
		$(function() {ldelim}
			// Attach the form handler.
			$('#bookReviewManagerForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		{rdelim});
	</script>

	{capture assign="actionUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler" op="updateObjectForReview" submissionId=$submissionId escape=false}{/capture}

	<form class="pkp_form" id="bookReviewManagerForm" method="post" action="{$actionUrl}">
		{csrf}
		{if $objectId}
			<input type="hidden" name="objectId" value="{$objectId|escape}" />
		{/if}
		{fbvFormArea id="bookReviewManagerFormArea" class="border"}


			{fbvFormSection for="resourceType" label="plugins.generic.bookReviewManager.resourceType"}
				{fbvElement type="select" id="resourceType" from=$resourceTypes selected=$resourceType size=$fbvStyles.size.SMALL}
			{/fbvFormSection}

			{fbvFormSection for="identifierType" label="plugins.generic.bookReviewManager.itemIdentifierType"}
				{fbvElement type="select" id="identifierType" from=$identifierTypes selected=$identifierType translate=false size=$fbvStyles.size.SMALL} 
			{/fbvFormSection}

			{fbvFormSection label="plugins.generic.bookReviewManager.itemIdentifier" for="identifier"}
				{fbvElement type="text" id="identifier" value=$identifier maxlength="255" inline=true multilingual=false size=$fbvStyles.size.MEDIUM}
				{capture assign="doiLookupUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler" op="lookupDoi" submissionId=$submissionId escape=false}{/capture}
				{capture assign="isbnLookupUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewGridHandler" op="lookupIsbn" submissionId=$submissionId escape=false}{/capture}
				{include file=$identifierLookupTemplate doiLookupUrl=$doiLookupUrl isbnLookupUrl=$isbnLookupUrl}
			{/fbvFormSection}

			{fbvFormSection label="plugins.generic.bookReviewManager.itemAuthors" for="authors"}
				{fbvElement type="text" id="authors" value=$authors maxlength="255" inline=true multilingual=false size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{fbvFormSection label="plugins.generic.bookReviewManager.itemTitle" for="title"}
				{fbvElement type="text" id="title" value=$title maxlength="255" inline=true multilingual=false size=$fbvStyles.size.LARGE}
			{/fbvFormSection}

			{fbvFormSection label="plugins.generic.bookReviewManager.itemPublisher" for="publisher"}
				{fbvElement type="text" id="publisher" value=$publisher maxlength="255" inline=true multilingual=false size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{fbvFormSection label="plugins.generic.bookReviewManager.itemYear" for="year"}
				{fbvElement type="text" id="year" value=$year maxlength="255" inline=true multilingual=false size=$fbvStyles.size.SMALL}
			{/fbvFormSection}

		{/fbvFormArea}

		{fbvFormSection class="formButtons"}
			{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
			{fbvElement type="submit" class="submitFormButton" id=$buttonId label="common.save"}
		{/fbvFormSection}
	</form>
{/if}

