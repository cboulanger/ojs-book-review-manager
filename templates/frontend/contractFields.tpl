{**
 * plugins/generic/bookReviewManager/templates/frontend/contractFields.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Contract fields: terms, due date and how the reviewer gets the work.
 * Used by the reservation dialog (direct reservation) and the contract page
 * (selected bidders). Expects $reserveContract, $reserveDueDate,
 * $reserveErrors and $reserveInput, and for a review essay $reserveMembers
 * (the works: [{id, citation}, ...]), which asks for the copy per work.
 *}
{assign var=input value=$reserveInput}
<div class="ofrContractFields">
	{if $reserveErrors}
		<div class="ofrErrors" role="alert">
			<p>{translate key="plugins.generic.bookReviewManager.reserve.error.summary"}</p>
			<ul>
				{foreach from=$reserveErrors item=errorKey}
					<li>{translate key=$errorKey}</li>
				{/foreach}
			</ul>
		</div>
	{/if}

	<section class="ofrContract">
		<h3>{translate key="plugins.generic.bookReviewManager.reserve.contract"}</h3>
		<div class="ofrContractText" tabindex="0">{$reserveContract|strip_unsafe_html}</div>
		<label class="ofrCheckbox">
			<input type="checkbox" name="acceptTerms" value="1" required{if $input.acceptTerms} checked{/if} />
			{translate key="plugins.generic.bookReviewManager.reserve.acceptTerms"}
		</label>
	</section>

	<div class="ofrField">
		<label for="ofrDueDate">{translate key="plugins.generic.bookReviewManager.reserve.dueDate"} <span class="ofrRequired">*</span></label>
		<input type="date" id="ofrDueDate" name="dueDate" required
			min="{$reserveDueDate.min|escape}" max="{$reserveDueDate.max|escape}" value="{$input.dueDate|escape}" />
		<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.reserve.dueDate.hint" maxDate=$reserveDueDate.max|date_format:$dateFormatShort}</p>
	</div>

	<fieldset class="ofrCopy">
		{if $reserveMembers}
			{* Review essay: one copy preference per work *}
			<legend>{translate key="plugins.generic.bookReviewManager.reserve.copyPerWork"} <span class="ofrRequired">*</span></legend>
			{foreach from=$reserveMembers item=member}
				<fieldset class="ofrCopyWork">
					<legend>{$member.citation|strip_unsafe_html}</legend>
					{foreach from=['have', 'physical', 'electronic', 'other'] item=preference}
						<label class="ofrRadio">
							<input type="radio" name="copyPreferences[{$member.id|escape}]" value="{$preference}" required{if $input.copyPreferences[$member.id] == $preference} checked{/if} />
							{translate key="plugins.generic.bookReviewManager.reserve.copy.$preference"}
						</label>
					{/foreach}
				</fieldset>
			{/foreach}
		{else}
			<legend>{translate key="plugins.generic.bookReviewManager.reserve.copy"} <span class="ofrRequired">*</span></legend>
			{foreach from=['have', 'physical', 'electronic', 'other'] item=preference}
				<label class="ofrRadio">
					<input type="radio" name="copyPreference" value="{$preference}" required{if $input.copyPreference == $preference} checked{/if} />
					{translate key="plugins.generic.bookReviewManager.reserve.copy.$preference"}
				</label>
			{/foreach}
		{/if}

		<div class="ofrConditional" data-show-for="physical">
			<div class="ofrField">
				<label for="ofrShippingAddress">{translate key="plugins.generic.bookReviewManager.reserve.shippingAddress"} <span class="ofrRequired">*</span></label>
				<textarea id="ofrShippingAddress" name="shippingAddress" rows="4">{$input.shippingAddress|escape}</textarea>
			</div>
			<div class="ofrField">
				<label for="ofrPhone">{translate key="plugins.generic.bookReviewManager.reserve.phone"} <span class="ofrRequired">*</span></label>
				<input type="tel" id="ofrPhone" name="phone" value="{$input.phone|escape}" pattern="\+?[0-9 ()\/.\-]{ldelim}6,30{rdelim}" autocomplete="tel" />
				<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.reserve.phone.hint"}</p>
			</div>
		</div>

		<div class="ofrConditional" data-show-for="other">
			<div class="ofrField">
				<label for="ofrCopyNote">{translate key="plugins.generic.bookReviewManager.reserve.copyNote"} <span class="ofrRequired">*</span></label>
				<textarea id="ofrCopyNote" name="copyNote" rows="3">{$input.copyNote|escape}</textarea>
			</div>
		</div>
	</fieldset>
</div>

<style>
	.ofrContractText { max-height: 14rem; overflow-y: auto; border: 1px solid #ddd; padding: 0.75rem; margin-bottom: 0.75rem; background: #fafafa; }
	.ofrContractFields .ofrField, .ofrResponse .ofrField { margin: 1rem 0; }
	.ofrContractFields .ofrField label, .ofrResponse .ofrField label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
	.ofrContractFields textarea, .ofrContractFields input[type=tel], .ofrResponse textarea { width: 100%; box-sizing: border-box; }
	.ofrContractFields fieldset { border: 1px solid #ddd; padding: 0.75rem 1rem; margin: 1rem 0; }
	.ofrContractFields legend { font-weight: bold; padding: 0 0.25rem; }
	.ofrContractFields .ofrCopyWork { margin: 0.5rem 0; }
	.ofrContractFields .ofrCopyWork legend { font-weight: normal; font-style: italic; }
	.ofrRadio, .ofrCheckbox { display: block; margin: 0.35rem 0; }
	.ofrHint { font-size: 0.875rem; color: #555; margin: 0.25rem 0 0; }
	.ofrRequired { color: #b00; }
	.ofrErrors { border-left: 4px solid #b00; background: #fdf0f0; padding: 0.5rem 1rem; margin-bottom: 1rem; }
</style>

<script>
	// Show the fields for the selected delivery options (one per work in a review essay); only visible fields are required
	document.querySelectorAll('.ofrContractFields').forEach(function (box) {ldelim}
		function sync() {ldelim}
			var values = [];
			box.querySelectorAll('input[name^="copyPreference"]:checked').forEach(function (checked) {ldelim}
				values.push(checked.value);
			{rdelim});
			box.querySelectorAll('[data-show-for]').forEach(function (part) {ldelim}
				var active = values.indexOf(part.getAttribute('data-show-for')) !== -1;
				part.hidden = !active;
				part.querySelectorAll('input, textarea').forEach(function (field) {ldelim}
					field.disabled = !active;
					field.required = active;
				{rdelim});
			{rdelim});
		{rdelim}
		box.addEventListener('change', sync);
		sync();
	{rdelim});
</script>
