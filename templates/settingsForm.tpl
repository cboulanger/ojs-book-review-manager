{**
 * plugins/generic/bookReviewManager/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Book Review Manager plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#orSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="orSettingsForm" method="post" action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true standalone=$standalone}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="orSettingsFormNotification"}

	{fbvFormArea id="bookReviewManagerSettingsFormArea"}

			<!--{fbvElement type="select" id="section" from=$sections selected=$section translate=false size=$fbvStyles.size.SMALL}-->

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="onlyReserved" value="1" label="plugins.generic.bookReviewManager.settings.onlyReserved" checked=$onlyReserved}
		{/fbvFormSection}
		{fbvFormSection list=true label="plugins.generic.bookReviewManager.settings.displayOptions"}
			{fbvElement type="checkbox" id="displayAsList" value="1" label="plugins.generic.bookReviewManager.settings.displayAsList" checked=$displayAsList}
		{/fbvFormSection}

		{fbvFormSection list=true label="plugins.generic.bookReviewManager.settings.notifyEmail"}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.notifyEmail.description" name="ofrNotifyEmail" id="ofrNotifyEmail" value=$ofrNotifyEmail maxlength="255" inline=true size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection list=true label="plugins.generic.bookReviewManager.settings.instructions"}
			{fbvElement type="textarea" label="plugins.generic.bookReviewManager.settings.instructions.description" name="ofrInstructions" id="ofrInstructions" value=$ofrInstructions multilingual="true" inline=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.contract"}
			{fbvElement type="textarea" label="plugins.generic.bookReviewManager.settings.contract.description" name="ofrContract" id="ofrContract" value=$ofrContract multilingual=true rich=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.dueMonths" description="plugins.generic.bookReviewManager.settings.dueMonths.description"}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.dueMonthsDefault" id="ofrDueMonthsDefault" value=$ofrDueMonthsDefault maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.dueMonthsMax" id="ofrDueMonthsMax" value=$ofrDueMonthsMax maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.reminderDays" description="plugins.generic.bookReviewManager.settings.reminderDays.description"}
			{fbvElement type="text" id="ofrReminderDays" value=$ofrReminderDays maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.defaultMode" description="plugins.generic.bookReviewManager.settings.defaultMode.description"}
			{fbvElement type="select" id="ofrDefaultMode" from=$assignmentModes selected=$ofrDefaultMode translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.defaultEditor" description="plugins.generic.bookReviewManager.settings.defaultEditor.description"}
			{fbvElement type="select" id="ofrDefaultEditorId" from=$bookReviewEditors selected=$ofrDefaultEditorId translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.contractDays" description="plugins.generic.bookReviewManager.settings.contractDays.description"}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.contractDays.deadline" id="ofrContractDays" value=$ofrContractDays maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.contractDays.reminder" id="ofrContractReminderDays" value=$ofrContractReminderDays maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.publisherReminderDays" description="plugins.generic.bookReviewManager.settings.publisherReminderDays.description"}
			{fbvElement type="text" id="ofrPublisherReminderDays" value=$ofrPublisherReminderDays maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.settings.invitationDays" description="plugins.generic.bookReviewManager.settings.invitationDays.description"}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.contractDays.deadline" id="ofrInvitationDays" value=$ofrInvitationDays maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
			{fbvElement type="text" label="plugins.generic.bookReviewManager.settings.contractDays.reminder" id="ofrInvitationReminderDays" value=$ofrInvitationReminderDays maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
		{/fbvFormSection}

		{if $standalone}
			{fbvFormButtons hideCancel=true submitText="common.save"}
		{else}
			{fbvFormButtons}
		{/if}

	{/fbvFormArea}

	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>

</form>
