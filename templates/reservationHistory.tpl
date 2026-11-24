{**
 * plugins/generic/bookReviewManager/templates/reservationHistory.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Audit trail of an object for review: reservation events, emails and the editors' notes,
 * newest first, with a form to add a note. After a note is added, the whole view is replaced
 * by the refreshed one returned by the addNote operation.
 *
 * $objectId, $objectDescription, $history, $today (Y-m-d), $maxNoteLength
 *}
<div class="ofrHistory" id="ofrHistory-{$objectId|escape}">
	<p><em>{$objectDescription|strip_unsafe_html}</em></p>

	<details class="ofrNote">
		<summary>{translate key="plugins.generic.bookReviewManager.history.note.add"}</summary>
		{capture assign="noteUrl"}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.bookReviewManager.controllers.ReservationActionsHandler" op="addNote" objectId=$objectId escape=false}{/capture}
		<form class="pkp_form ofrNoteForm" method="post" action="{$noteUrl}">
			{csrf}
			<label for="ofrNote-{$objectId|escape}">{translate key="plugins.generic.bookReviewManager.history.note.text"}</label>
			<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.history.note.description"}</p>
			<textarea id="ofrNote-{$objectId|escape}" name="note" rows="5" required maxlength="{$maxNoteLength|escape}"></textarea>
			<label for="ofrNoteDate-{$objectId|escape}">{translate key="plugins.generic.bookReviewManager.history.note.date"}</label>
			<input type="date" id="ofrNoteDate-{$objectId|escape}" name="noteDate" value="{$today|escape}" max="{$today|escape}" required />
			<p class="ofrNoteError" role="alert" hidden></p>
			<div class="ofrNoteButtons">
				<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.history.note.save"}</button>
			</div>
		</form>
	</details>

	{if $history|@count}
		<ul class="ofrHistoryList">
			{foreach from=$history item=entry}
				<li class="ofrHistoryEntry ofrHistory-{$entry.type|escape}">
					<div class="ofrHistoryHead">
						<span class="ofrHistoryDate">{if $entry.dateOnly}{$entry.date|date_format:$dateFormatShort}{else}{$entry.date|date_format:$datetimeFormatShort}{/if}</span>
						<strong>{$entry.label|escape}</strong>
						{if $entry.actor}<span class="ofrHistoryActor">({$entry.actor|escape})</span>{/if}
					</div>
					{if $entry.type == 'email'}
						<details>
							<summary>{translate key="email.to"}: {$entry.recipients|escape} – {$entry.subject|escape}</summary>
							<div class="ofrHistoryBody">{$entry.body|strip_unsafe_html}</div>
						</details>
					{elseif $entry.type == 'note'}
						<div class="ofrHistoryBody">{$entry.body|escape|nl2br}</div>
					{/if}
				</li>
			{/foreach}
		</ul>
	{else}
		<p>{translate key="plugins.generic.bookReviewManager.history.empty"}</p>
	{/if}
	{capture assign="noteFailed"}{translate key="plugins.generic.bookReviewManager.history.note.error.failed"}{/capture}
	<script>
		$(function() {ldelim}
			var $history = $('#ofrHistory-{$objectId|escape:"javascript"}');
			$history.find('.ofrNoteForm').on('submit', function(e) {ldelim}
				e.preventDefault();
				var $form = $(this), $error = $form.find('.ofrNoteError'), $button = $form.find('button[type=submit]');
				$button.prop('disabled', true);
				$.post($form.attr('action'), $form.serialize(), null, 'json')
					.done(function(json) {ldelim}
						if (json && json.status) {ldelim}
							// The refreshed history, with an empty form
							$history.replaceWith(json.content);
						{rdelim} else {ldelim}
							$error.text(json && json.content ? json.content : '').prop('hidden', false);
							$button.prop('disabled', false);
						{rdelim}
					{rdelim})
					.fail(function() {ldelim}
						$error.text({$noteFailed|json_encode}).prop('hidden', false);
						$button.prop('disabled', false);
					{rdelim});
			{rdelim});
		{rdelim});
	</script>
</div>
<style>
	.ofrHistoryList { list-style: none; margin: 0; padding: 0; }
	.ofrHistoryEntry { border-bottom: 1px solid #ddd; padding: 0.75rem 0; }
	.ofrHistoryDate { color: #555; margin-right: 0.5rem; }
	.ofrHistoryActor { color: #555; }
	.ofrHistory details { margin-top: 0.5rem; }
	.ofrHistory summary { cursor: pointer; }
	.ofrHistoryBody { border-left: 3px solid #ddd; padding: 0.25rem 1rem; margin-top: 0.5rem; }
	.ofrHistory-note .ofrHistoryBody { border-left-color: #d9a400; background: #fffbea; }
	.ofrNote { margin-bottom: 1rem; }
	.ofrNote > summary { font-weight: bold; color: #006798; }
	.ofrNoteForm { margin-top: 0.75rem; }
	.ofrNoteForm label { display: block; font-weight: bold; margin-top: 0.5rem; }
	.ofrNoteForm textarea { width: 100%; box-sizing: border-box; }
	.ofrNoteError { color: #d00a0a; }
	.ofrNoteButtons { margin-top: 0.75rem; }
</style>
