{**
 * plugins/generic/bookReviewManager/templates/offerSettingsFields.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Offer settings of a work or review essay (assignment mode, bid deadline,
 * responsible editor), included in the forms of the "Available works" list.
 *
 * $formId: id of the enclosing form (shows the bid deadline only for bidding)
 * $areaId: id of the form area
 *}
{fbvFormArea id=$areaId title="plugins.generic.bookReviewManager.offer.title"}
	{fbvFormSection label="plugins.generic.bookReviewManager.offer.mode" description="plugins.generic.bookReviewManager.offer.mode.description" for="assignmentMode"}
		{fbvElement type="select" id="assignmentMode" from=$assignmentModes selected=$assignmentMode translate=false size=$fbvStyles.size.MEDIUM}
	{/fbvFormSection}

	{fbvFormSection label="plugins.generic.bookReviewManager.offer.bidDeadline" description="plugins.generic.bookReviewManager.offer.bidDeadline.description" for="bidDeadline" class="ofrBidDeadlineSection"}
		<input type="date" id="bidDeadline" name="bidDeadline" value="{$bidDeadline|escape}" />
	{/fbvFormSection}

	{fbvFormSection label="plugins.generic.bookReviewManager.offer.responsibleEditor" description="plugins.generic.bookReviewManager.offer.responsibleEditor.description" for="responsibleEditorId"}
		{fbvElement type="select" id="responsibleEditorId" from=$bookReviewEditors selected=$responsibleEditorId translate=false size=$fbvStyles.size.MEDIUM}
	{/fbvFormSection}
	<script>
		$(function() {ldelim}
			var $mode = $('#{$formId|escape:"javascript"} [name="assignmentMode"]');
			function sync() {ldelim} $('#{$formId|escape:"javascript"} .ofrBidDeadlineSection').toggle($mode.val() === 'bidding'); {rdelim}
			$mode.on('change', sync);
			sync();
		{rdelim});
	</script>
{/fbvFormArea}
