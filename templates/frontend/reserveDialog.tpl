{**
 * plugins/generic/bookReviewManager/templates/frontend/reserveDialog.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Reservation dialog: contract, due date and how the reviewer gets the work.
 * Opened by the "reserve" buttons; without JavaScript it is shown via ?reserve=<objectId>.
 * The page has one dialog: for a review essay, whose fields depend on its
 * works, the button loads the page with ?reserve=<objectId> instead.
 *}
{* A review essay asks for the copy per work: its works come from its row *}
{if $reserveOpenObjectId}{assign var=reserveMembers value=$bookReviewManager[$reserveOpenObjectId].members}{/if}
<dialog id="ofrReserveDialog" class="ofrReserveDialog" aria-labelledby="ofrReserveDialogTitle"
	data-open-object-id="{$reserveOpenObjectId|escape}"{if $reserveMembers} data-review-essay="1"{/if}{if $reserveOpenObjectId} open{/if}>
	<form class="ofrReserveForm" method="post" action="{url op="reserveObject"}">
		{csrf}
		<input type="hidden" name="objectId" value="{$reserveOpenObjectId|escape}" />

		<h2 id="ofrReserveDialogTitle">{translate key="plugins.generic.bookReviewManager.reserve.title"}</h2>
		<p class="ofrReserveObject">{if $reserveOpenObjectId}{$bookReviewManager[$reserveOpenObjectId].citation|strip_unsafe_html}{/if}</p>

		{include file=$contractFieldsTemplate}

		<div class="ofrButtons">
			<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.reserve.submit"}</button>
			<a class="pkp_button pkp_button_offset ofrCancel" href="{url op="index"}">{translate key="common.cancel"}</a>
		</div>
	</form>
</dialog>

<style>
	.ofrReserveDialog { max-width: 42rem; width: calc(100% - 2rem); border: 1px solid #ccc; border-radius: 4px; padding: 1.5rem; }
	.ofrReserveDialog::backdrop { background: rgba(0, 0, 0, 0.5); }
	.ofrReserveDialog h2 { margin-top: 0; }
	.ofrReserveObject { font-style: italic; }
	.ofrButtons { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; }
	.ofrDueDate { font-size: 0.875rem; margin: 0 0 0.5rem; }
</style>

<script>
	(function () {ldelim}
		var dialog = document.getElementById('ofrReserveDialog');
		if (!dialog) return;
		var form = dialog.querySelector('form');

		if (typeof dialog.showModal !== 'function') return; // very old browsers: server-rendered fallback

		document.querySelectorAll('.ofrReserveButton').forEach(function (button) {ldelim}
			button.addEventListener('click', function (event) {ldelim}
				// The fields of a review essay (one copy per work) are rendered by the server: follow the link
				if ((button.hasAttribute('data-review-essay') || dialog.hasAttribute('data-review-essay'))
						&& form.elements.objectId.value !== button.getAttribute('data-object-id')) return;
				event.preventDefault();
				if (form.elements.objectId.value !== button.getAttribute('data-object-id')) {ldelim}
					var errors = dialog.querySelector('.ofrErrors');
					if (errors) errors.remove();
				{rdelim}
				form.elements.objectId.value = button.getAttribute('data-object-id');
				dialog.querySelector('.ofrReserveObject').textContent = button.getAttribute('data-description');
				dialog.showModal();
			{rdelim});
		{rdelim});

		dialog.querySelector('.ofrCancel').addEventListener('click', function (event) {ldelim}
			event.preventDefault();
			dialog.close();
		{rdelim});

		if (dialog.getAttribute('data-open-object-id') !== '0') {ldelim}
			dialog.removeAttribute('open');
			dialog.showModal();
		{rdelim}
	{rdelim})();
</script>
