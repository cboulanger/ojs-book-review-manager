{**
 * plugins/generic/bookReviewManager/templates/bundleMemberForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Add a work to a review essay.
 *
 * $mode: add (choose the work; $containerId, $essayCitation) or choose (choose the review essay; $workId, $workCitation)
 * $options: id => citation of the works or review essays to choose from
 * $reviewerEssayIds: review essays with a reviewer (the copy preference is asked for these)
 *}
<script>
	$(function() {ldelim}
		$('#bundleMemberForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

{capture assign="actionUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewManagementGridHandler" op="updateBundleMember" escape=false}{/capture}

<form class="pkp_form" id="bundleMemberForm" method="post" action="{$actionUrl}">
	{csrf}
	{include file="common/formErrors.tpl"}
	{if $mode == 'add'}
		<input type="hidden" name="containerId" value="{$containerId|escape}" />
	{else}
		<input type="hidden" name="workId" value="{$workId|escape}" />
	{/if}
	{fbvFormArea id="bundleMemberFormArea"}
		{if $mode == 'add'}
			<p><strong>{translate key="plugins.generic.bookReviewManager.reviewEssay.essay"}:</strong> {$essayCitation|strip_unsafe_html}</p>
			{if $options}
				{fbvFormSection label="plugins.generic.bookReviewManager.reviewEssay.work" for="workId" required=true}
					<select id="workId" name="workId" class="ofrEssayChoice">
						{html_options options=$options selected=$workId|default:''}
					</select>
				{/fbvFormSection}
			{else}
				<p>{translate key="plugins.generic.bookReviewManager.reviewEssay.noWorksToAdd"}</p>
			{/if}
		{else}
			<p><strong>{translate key="plugins.generic.bookReviewManager.reviewEssay.work"}:</strong> {$workCitation|strip_unsafe_html}</p>
			{if $options}
				{fbvFormSection label="plugins.generic.bookReviewManager.reviewEssay.essay" for="containerId" required=true}
					<select id="containerId" name="containerId" class="ofrEssayChoice">
						{html_options options=$options selected=$containerId|default:''}
					</select>
				{/fbvFormSection}
			{else}
				<p>{translate key="plugins.generic.bookReviewManager.reviewEssay.noEssays"}</p>
			{/if}
		{/if}

		{if $options && $reviewerEssayIds}
			{fbvFormSection label="plugins.generic.bookReviewManager.reviewEssay.copyPreference" description="plugins.generic.bookReviewManager.reviewEssay.copyPreference.description" for="copyPreference" class="ofrEssayCopyPreference"}
				<select id="copyPreference" name="copyPreference">
					{html_options options=$copyPreferenceOptions selected=$copyPreference|default:'have'}
				</select>
			{/fbvFormSection}
			{if $mode == 'choose'}
				<script>
					$(function() {ldelim}
						var reviewerEssayIds = {$reviewerEssayIdsJson};
						var $form = $('#bundleMemberForm');
						function sync() {ldelim}
							$form.find('.ofrEssayCopyPreference').toggle(reviewerEssayIds.indexOf(parseInt($form.find('[name="containerId"]').val(), 10)) !== -1);
						{rdelim}
						$form.on('change', '.ofrEssayChoice', sync);
						sync();
					{rdelim});
				</script>
			{/if}
		{/if}
		<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.reviewEssay.emailNotice"}</p>
	{/fbvFormArea}

	{if $options}
		{fbvFormSection class="formButtons"}
			{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
			{fbvElement type="submit" class="submitFormButton" id=$buttonId label="common.save"}
		{/fbvFormSection}
	{/if}
</form>
