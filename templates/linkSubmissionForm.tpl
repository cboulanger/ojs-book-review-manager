{**
 * plugins/generic/bookReviewManager/templates/linkSubmissionForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Link an accepted reservation to the review submission.
 *
 * $objectId, $assignmentId, $objectDescription, $reviewerName
 * $options: submission ID => description of the reviewer's submissions
 *}
<script>
	$(function() {ldelim}
		$('#linkSubmissionForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

{capture assign="actionUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.ReservationActionsHandler" op="linkSubmission" objectId=$objectId assignmentId=$assignmentId escape=false}{/capture}

<form class="pkp_form" id="linkSubmissionForm" method="post" action="{$actionUrl}">
	{csrf}
	{include file="common/formErrors.tpl"}
	{fbvFormArea id="linkSubmissionFormArea"}
		<p><strong>{translate key="plugins.generic.bookReviewManager.linkSubmission.work"}:</strong> {$objectDescription|strip_unsafe_html}</p>
		<p><strong>{translate key="plugins.generic.bookReviewManager.linkSubmission.reviewer"}:</strong> {$reviewerName|escape}</p>

		{if $options}
			{fbvFormSection label="plugins.generic.bookReviewManager.linkSubmission.choose" for="submissionId"}
				<select id="submissionId" name="submissionId">
					<option value="">{translate key="plugins.generic.bookReviewManager.linkSubmission.chooseNone"}</option>
					{html_options options=$options selected=$submissionId|default:''}
				</select>
			{/fbvFormSection}
		{else}
			<p>{translate key="plugins.generic.bookReviewManager.linkSubmission.noSuggestions"}</p>
		{/if}

		{fbvFormSection label="plugins.generic.bookReviewManager.linkSubmission.otherId" description="plugins.generic.bookReviewManager.linkSubmission.otherId.description" for="otherSubmissionId"}
			{fbvElement type="text" id="otherSubmissionId" value=$otherSubmissionId|default:'' size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.linkSubmission.notice"}</p>
	{/fbvFormArea}

	{fbvFormSection class="formButtons"}
		{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
		{fbvElement type="submit" class="submitFormButton" id=$buttonId label="plugins.generic.bookReviewManager.linkSubmission.submit"}
	{/fbvFormSection}
</form>
