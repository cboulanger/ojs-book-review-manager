{**
 * plugins/generic/bookReviewManager/templates/reviewEssayForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Create a review essay from works of the "Available works" list, or edit it.
 *
 * $containerId: the review essay being edited (empty: create)
 * $works: id => [citation, hasReviewer] (create)
 *}
<script>
	$(function() {ldelim}
		$('#reviewEssayForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

{capture assign="actionUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.grid.ObjectsForReviewManagementGridHandler" op="updateReviewEssay" escape=false}{/capture}

<form class="pkp_form" id="reviewEssayForm" method="post" action="{$actionUrl}">
	{csrf}
	{include file="common/formErrors.tpl"}
	{if $containerId}
		<input type="hidden" name="objectId" value="{$containerId|escape}" />
	{/if}
	{fbvFormArea id="reviewEssayFormArea" class="border"}
		{fbvFormSection label="plugins.generic.bookReviewManager.reviewEssay.title" for="title" required=true}
			{fbvElement type="text" id="title" value=$title maxlength="255" inline=true multilingual=false size=$fbvStyles.size.LARGE}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.bookReviewManager.itemDescription" for="description"}
			{fbvElement type="textarea" multilingual=false name="description" id="description" value=$description rich=true height=$fbvStyles.height.SHORT}
		{/fbvFormSection}

		{if $containerId}
			<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.reviewEssay.membersHint"}</p>
		{else}
			{fbvFormSection label="plugins.generic.bookReviewManager.reviewEssay.works" description="plugins.generic.bookReviewManager.reviewEssay.works.description" for="objectIds" required=true}
				{if $works}
					<ul class="ofrEssayWorkChoice">
						{foreach from=$works key=workId item=work}
							<li data-has-reviewer="{if $work.hasReviewer}1{else}0{/if}">
								<label>
									<input type="checkbox" name="objectIds[]" value="{$workId|escape}"{if in_array($workId, $selectedIds)} checked="checked"{/if} />
									{$work.citation|strip_unsafe_html}
									{if $work.hasReviewer} <span class="ofrBadge">{translate key="plugins.generic.bookReviewManager.reviewEssay.workHasReviewer"}</span>{/if}
								</label>
								{if !$work.hasReviewer}
									<div class="ofrEssayCopyPreference">
										<label for="copyPreference-{$workId|escape}">{translate key="plugins.generic.bookReviewManager.reviewEssay.copyPreference"}</label>
										<select id="copyPreference-{$workId|escape}" name="copyPreferences[{$workId|escape}]">
											{html_options options=$copyPreferenceOptions selected=$work.copyPreference}
										</select>
									</div>
								{/if}
							</li>
						{/foreach}
					</ul>
					<p class="ofrHint ofrEssayCopyHint">{translate key="plugins.generic.bookReviewManager.reviewEssay.copyPreferences.description"}</p>
				{else}
					<p>{translate key="plugins.generic.bookReviewManager.reviewEssay.noWorks"}</p>
				{/if}
			{/fbvFormSection}
			<script>
				$(function() {ldelim}
					var $form = $('#reviewEssayForm');
					function sync() {ldelim}
						var reviewerSelected = $form.find('li[data-has-reviewer="1"] input[type=checkbox]:checked').length > 0;
						$form.find('.ofrEssayWorkChoice li').each(function() {ldelim}
							$(this).find('.ofrEssayCopyPreference').toggle(reviewerSelected && $(this).find('input[type=checkbox]').is(':checked'));
						{rdelim});
						$form.find('.ofrEssayCopyHint').toggle(reviewerSelected);
					{rdelim}
					$form.on('change', '.ofrEssayWorkChoice input[type=checkbox]', sync);
					sync();
				{rdelim});
			</script>

			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="hidden" value="1" checked=$hidden label="plugins.generic.bookReviewManager.reviewEssay.hidden"}
			{/fbvFormSection}
			<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.reviewEssay.endsBidsNotice"}</p>
			<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.reviewEssay.emailNotice"}</p>
		{/if}
	{/fbvFormArea}

	{include file=$offerSettingsTemplate formId="reviewEssayForm" areaId="reviewEssayOfferArea"}

	{fbvFormSection class="formButtons"}
		{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
		{fbvElement type="submit" class="submitFormButton" id=$buttonId label="common.save"}
	{/fbvFormSection}
</form>
