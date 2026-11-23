{**
 * plugins/generic/bookReviewManager/templates/reservationEmailForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * A workflow step with a prefilled, editable email (see ReservationEmailForm):
 * $mode message | cancel | contractOffer | unsuccessful | invitation | round | dueDate | copyStep (see CopyStepForm)
 *}
<script>
	$(function() {ldelim}
		$('#ofrReservationEmailForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="ofrReservationEmailForm" method="post" action="{$actionUrl|escape}">
	{csrf}
	<input type="hidden" name="objectId" value="{$objectId|escape}" />
	{foreach from=$assignmentIds item=assignmentId}
		<input type="hidden" name="assignmentIds[]" value="{$assignmentId|escape}" />
	{/foreach}
	{if $step}<input type="hidden" name="step" value="{$step|escape}" />{/if}

	{fbvFormArea id="ofrReservationEmailFormArea"}
		{if $ofrEmailsSuppressed}
			<div class="pkp_notification" role="status"><div class="notifyWarning">{translate key="plugins.generic.bookReviewManager.emailsSuppressed.form"}</div></div>
		{/if}
		<p class="ofrReservationObject"><em>{$objectDescription|strip_unsafe_html}</em></p>
		{if $mode == 'cancel'}
			<p>{if $cancelOffer}{translate key="plugins.generic.bookReviewManager.contractOffer.cancel.description" reviewer=$reviewerName|escape}{else}{translate key="plugins.generic.bookReviewManager.reservations.cancel.description" reviewer=$reviewerName|escape}{/if}</p>
		{elseif $mode == 'contractOffer'}
			<p>{translate key="plugins.generic.bookReviewManager.contractOffer.description" reviewer=$reviewerName|escape}</p>
		{elseif $mode == 'unsuccessful'}
			<p>{translate key="plugins.generic.bookReviewManager.unsuccessful.description"}</p>
		{elseif $stepDescription}
			<p class="ofrStepDescription">{$stepDescription|escape}</p>
		{/if}

		{if $returnTargets}
			{fbvFormSection list=true label="plugins.generic.bookReviewManager.copy.returnTarget"}
				{foreach from=$returnTargets key=target item=address}
					<label class="ofrReturnTarget">
						<input type="radio" name="returnTarget" value="{$target|escape}"{if $target == $returnTarget} checked{/if} />
						<strong>{translate key="plugins.generic.bookReviewManager.copy.to.$target"}</strong>
						<span class="ofrDetail">{$address|escape|nl2br}</span>
					</label>
				{/foreach}
			{/fbvFormSection}
		{/if}

		{if $mode == 'dueDate'}
			{fbvFormSection label="plugins.generic.bookReviewManager.dueDate.new" for="ofrNewDueDate" required=true}
				{* A plain date input is not an fbvElement, so its error is shown here *}
				{if $dueDateError}<span class="error">{$dueDateError|escape}</span>{/if}
				<input type="date" id="ofrNewDueDate" name="dueDate" value="{$dueDate|escape}" min="{$minDueDate|escape}" required />
			{/fbvFormSection}
		{/if}

		{if $hideOfferOption}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="hideOffer" value="1" checked=$hideOffer label="plugins.generic.bookReviewManager.invitation.round.hideOffer"}
			{/fbvFormSection}
		{/if}

		{if !$noEmail}
		{if $optionalEmail}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="sendEmail" value="1" checked=$sendEmail label="plugins.generic.bookReviewManager.reservations.cancel.sendEmail"}
			{/fbvFormSection}
		{/if}

		<div class="ofrEmailFields">
			{fbvFormSection label="email.to"}
				{foreach from=$recipients item=recipient}
					<p class="ofrRecipient">{$recipient.name|escape} &lt;{$recipient.email|escape}&gt;</p>
				{/foreach}
			{/fbvFormSection}
			{fbvFormSection label="email.subject" required=true}
				{fbvElement type="text" id="subject" value=$subject maxlength="255" required=true}
			{/fbvFormSection}
			{fbvFormSection label="email.body" required=true}
				{fbvElement type="textarea" id="body" value=$body rich=true height=$fbvStyles.height.TALL}
				{if $mode == 'contractOffer'}
					<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.contractOffer.linkHint"}</p>
				{elseif $mode == 'unsuccessful'}
					<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.unsuccessful.linkHint"}</p>
				{elseif $bodyHint}
					<p class="ofrHint">{$bodyHint|escape}</p>
				{/if}
			{/fbvFormSection}
		</div>
		{/if}

		{if $submitKey}
			{fbvFormButtons submitText=$submitKey}
		{elseif $mode == 'cancel'}
			{fbvFormButtons submitText="plugins.generic.bookReviewManager.reservations.cancel.submit"}
		{elseif $mode == 'contractOffer'}
			{fbvFormButtons submitText="plugins.generic.bookReviewManager.contractOffer.submit"}
		{elseif $mode == 'unsuccessful'}
			{fbvFormButtons submitText="plugins.generic.bookReviewManager.unsuccessful.submit"}
		{else}
			{fbvFormButtons submitText="plugins.generic.bookReviewManager.reservations.email.submit"}
		{/if}
	{/fbvFormArea}
</form>

{if $optionalEmail}
	<script>
		$(function() {ldelim}
			var $form = $('#ofrReservationEmailForm'),
				$toggle = $form.find('input[name="sendEmail"]');
			function sync() {ldelim}
				$form.find('.ofrEmailFields').toggle($toggle.is(':checked'));
			{rdelim}
			$toggle.on('change', sync);
			sync();
		{rdelim});
	</script>
{/if}
