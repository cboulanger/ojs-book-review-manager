{**
 * plugins/generic/bookReviewManager/templates/reservationHistory.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Audit trail of an object for review: reservation events and emails, newest first.
 *}
<div class="ofrHistory">
	<p><em>{$objectDescription|strip_unsafe_html}</em></p>
	{if $history|@count}
		<ul class="ofrHistoryList">
			{foreach from=$history item=entry}
				<li class="ofrHistoryEntry ofrHistory-{$entry.type|escape}">
					<div class="ofrHistoryHead">
						<span class="ofrHistoryDate">{$entry.date|date_format:$datetimeFormatShort}</span>
						<strong>{$entry.label|escape}</strong>
						{if $entry.actor}<span class="ofrHistoryActor">({$entry.actor|escape})</span>{/if}
					</div>
					{if $entry.type == 'email'}
						<details>
							<summary>{translate key="email.to"}: {$entry.recipients|escape} – {$entry.subject|escape}</summary>
							<div class="ofrHistoryBody">{$entry.body|strip_unsafe_html}</div>
						</details>
					{/if}
				</li>
			{/foreach}
		</ul>
	{else}
		<p>{translate key="plugins.generic.bookReviewManager.history.empty"}</p>
	{/if}
</div>
<style>
	.ofrHistoryList { list-style: none; margin: 0; padding: 0; }
	.ofrHistoryEntry { border-bottom: 1px solid #ddd; padding: 0.75rem 0; }
	.ofrHistoryDate { color: #555; margin-right: 0.5rem; }
	.ofrHistoryActor { color: #555; }
	.ofrHistory details { margin-top: 0.5rem; }
	.ofrHistory summary { cursor: pointer; }
	.ofrHistoryBody { border-left: 3px solid #ddd; padding: 0.25rem 1rem; margin-top: 0.5rem; }
</style>
