{**
 * plugins/generic/bookReviewManager/templates/editAvailableObjectsForReviewForm.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Form for editing an available bookReviewManager item
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#availableObjectsForReviewForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

{capture assign="actionUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewManagementGridHandler" op="updateAvailableObjectForReview" escape=false}{/capture}

<form class="pkp_form" id="availableObjectsForReviewForm" method="post" action="{$actionUrl}">
	{csrf}
	{if $objectId}
		<input type="hidden" name="objectId" value="{$objectId|escape}" />
	{/if}
	{fbvFormArea id="availableObjectsForReviewFormArea" class="border"}


		{fbvFormSection for="resourceType" label="plugins.generic.bookReviewManager.resourceType"}
			{fbvElement type="select" id="resourceType" from=$resourceTypes selected=$resourceType size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection for="identifierType" label="plugins.generic.bookReviewManager.itemIdentifierType"}
			{fbvElement type="select" id="identifierType" from=$identifierTypes selected=$identifierType translate=false size=$fbvStyles.size.SMALL} 
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemIdentifier" for="identifier"}
			{fbvElement type="text" id="identifier" value=$identifier maxlength="255" inline=true multilingual=false size=$fbvStyles.size.MEDIUM}
			{capture assign="doiLookupUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewManagementGridHandler" op="lookupDoi" escape=false}{/capture}
			{capture assign="isbnLookupUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewManagementGridHandler" op="lookupIsbn" escape=false}{/capture}
			{include file=$identifierLookupTemplate doiLookupUrl=$doiLookupUrl isbnLookupUrl=$isbnLookupUrl}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemAuthors" for="authors"}
			{fbvElement type="text" id="authors" value=$authors maxlength="255" inline=true multilingual=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemTitle" for="title"}
			{fbvElement type="text" id="title" value=$title maxlength="255" inline=true multilingual=false size=$fbvStyles.size.LARGE}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemDescription" description="plugins.generic.bookReviewManager.itemDescription.description" for="description"}
			{fbvElement type="textarea" multilingual=false name="description" id="description" value=$description rich=true height=$fbvStyles.height.SHORT}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemPublisher" for="publisher"}
			{fbvElement type="text" id="publisher" value=$publisher maxlength="255" inline=true multilingual=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemYear" for="year"}
			{fbvElement type="text" id="year" value=$year maxlength="255" inline=true multilingual=false size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemPublisherUrl" description="plugins.generic.bookReviewManager.itemPublisherUrl.description" for="publisherUrl"}
			{fbvElement type="url" id="publisherUrl" value=$publisherUrl maxlength="2048" inline=true multilingual=false size=$fbvStyles.size.LARGE}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.openAccess" description="plugins.generic.bookReviewManager.openAccess.description" for="openAccess"}
			{fbvElement type="select" id="openAccess" from=$openAccessStatuses selected=$openAccess translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{include file=$offerSettingsTemplate formId="availableObjectsForReviewForm" areaId="availableObjectsForReviewOfferArea"}

	{fbvFormArea id="availableObjectsForReviewCopyArea" title="plugins.generic.bookReviewManager.offer.copyTitle"}
		{fbvFormSection label="plugins.generic.bookReviewManager.offer.publisherContact" description="plugins.generic.bookReviewManager.offer.publisherContact.description" for="publisherContactId"}
			{fbvElement type="select" id="publisherContactId" from=$publisherContacts selected=$publisherContactId translate=false size=$fbvStyles.size.MEDIUM}
			{if !$hasPublisherContacts}<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.offer.noPublisherContacts"}</p>{/if}
		{/fbvFormSection}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="journalHoldsCopy" value="1" checked=$journalHoldsCopy label="plugins.generic.bookReviewManager.offer.journalHoldsCopy"}
			{fbvElement type="checkbox" id="returnRequired" value="1" checked=$returnRequired label="plugins.generic.bookReviewManager.offer.returnRequired"}
			{fbvElement type="checkbox" id="notifyPublisher" value="1" checked=$notifyPublisher label="plugins.generic.bookReviewManager.offer.notifyPublisher"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormSection class="formButtons"}
		{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
		{fbvElement type="submit" class="submitFormButton" id=$buttonId label="common.save"}
	{/fbvFormSection}
</form>
