{**
 * plugins/generic/bookReviewManager/templates/invite.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Invitation page of an object (see InvitationWorkflow): the invitation list,
 * the address book search and the form for adding a new person.
 * Included by management.tpl.
 *}
<p><a href="{$availableUrl|escape}">&larr; {translate key="plugins.generic.bookReviewManager.menu.available"}</a></p>

<div class="ofrInviteHead">
	{include file=$ofrObjectCell row=$object}
	<p class="ofrDeskState">
		{$offerState|escape} · {translate key="plugins.generic.bookReviewManager.invitation.kind.$kind"}
		<span class="ofrInviteHistory">{include file="linkAction/linkAction.tpl" action=$historyAction contextId="ofrInvite"}</span>
	</p>
	{if $round}
		<div class="ofrRound">
			{if $round.mode == 'parallel'}
				<p><strong>{translate key="plugins.generic.bookReviewManager.invitation.round.runningParallel" date=$round.started|date_format:$dateFormatShort name=$round.startedBy|escape}</strong></p>
				<p>{translate key="plugins.generic.bookReviewManager.invitation.round.parallelCounts" invited=$round.invitedCount answered=$round.answeredCount}</p>
				{if $round.next}<p class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.round.newPeople" number=$round.waitingCount}</p>{/if}
				<div class="ofrInlineActions">
					{if $round.next && $canInvite}{include file=$ofrInviteButton do="continueRound" assignmentId=0 labelKey="plugins.generic.bookReviewManager.invitation.round.inviteNew" symbol=""}{/if}
					{include file=$ofrInviteButton do="stopRound" assignmentId=0 labelKey="plugins.generic.bookReviewManager.invitation.round.stop" symbol="" confirmKey="plugins.generic.bookReviewManager.invitation.round.stop.confirmParallel"}
				</div>
			{else}
			<p><strong>{translate key="plugins.generic.bookReviewManager.invitation.round.running" date=$round.started|date_format:$dateFormatShort name=$round.startedBy|escape}</strong></p>
			{if $round.exhausted}
				<p class="ofrWarning">{translate key="plugins.generic.bookReviewManager.invitation.round.exhausted"}</p>
			{elseif $round.current}
				<p>{translate key="plugins.generic.bookReviewManager.invitation.round.current" name=$round.current|escape deadline=$round.currentDeadline|date_format:$dateFormatShort}</p>
			{/if}
			<p class="ofrDetail">
				{if $round.next}{translate key="plugins.generic.bookReviewManager.invitation.round.next" name=$round.next|escape number=$round.waitingCount}{else}{translate key="plugins.generic.bookReviewManager.invitation.round.noneWaiting"}{/if}
			</p>
			<div class="ofrInlineActions">
				{if $round.exhausted && $round.next}{include file=$ofrInviteButton do="continueRound" assignmentId=0 labelKey="plugins.generic.bookReviewManager.invitation.round.continue" symbol=""}{/if}
				{include file=$ofrInviteButton do="stopRound" assignmentId=0 labelKey="plugins.generic.bookReviewManager.invitation.round.stop" symbol="" confirmKey="plugins.generic.bookReviewManager.invitation.round.stop.confirm"}
			</div>
			{/if}
		</div>
	{elseif $canInvite}
		<p class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.manualNote" deadline=$deadline|date_format:$dateFormatShort}</p>
		{if $roundAction}
			<p>{include file="linkAction/linkAction.tpl" action=$roundAction contextId="ofrInvite"} <span class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.round.explain.$kind"}</span></p>
		{/if}
	{elseif $canEdit}
		<p class="ofrWarning">{translate key="plugins.generic.bookReviewManager.invitation.notPossible"}</p>
	{/if}
</div>

<h2>{translate key="plugins.generic.bookReviewManager.invitation.list"}</h2>
{if $rows}
	<form id="ofrReorderForm" method="post" action="{url op="inviteAction"}">
		{csrf}
		<input type="hidden" name="objectId" value="{$objectId|escape}" />
		<input type="hidden" name="do" value="reorder" />
		<input type="hidden" name="order" value="" />
	</form>
	<table class="ofrTable ofrInviteList">
		<thead>
			<tr>
				<th class="ofrNoWrap">#</th>
				<th>{translate key="plugins.generic.bookReviewManager.invitation.person"}</th>
				<th>{translate key="common.status"}</th>
				<th>{translate key="grid.columns.actions"}</th>
			</tr>
		</thead>
		<tbody>
			{foreach from=$rows item=row name=list}
				<tr data-assignment-id="{$row.assignmentId|escape}"{if $canEdit} draggable="true"{/if}>
					<td class="ofrNoWrap">{if $canEdit}<span class="ofrDragHandle" aria-hidden="true" title="{translate key="plugins.generic.bookReviewManager.invitation.drag"}">&#8942;&#8942;</span> {/if}{$smarty.foreach.list.iteration}</td>
					<td>
						<strong>{$row.name|escape}</strong>
						{if $row.affiliation}<div class="ofrDetail">{$row.affiliation|escape}</div>{/if}
						<div class="ofrDetail">{$row.email|escape}</div>
						<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.desk.reviewCount" number=$row.reviewCount}{if $row.reservationCount} · {translate key="plugins.generic.bookReviewManager.invitation.reservationCount" number=$row.reservationCount}{/if}</div>
					</td>
					<td>
						{translate key="plugins.generic.bookReviewManager.status.`$row.status`"}
						{if $row.dateInvited}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.sentOn" date=$row.dateInvited|date_format:$dateFormatShort}</div>{/if}
						{if $row.status == 'invited' && $row.invitationDeadline}<div class="ofrDetail"><strong>{translate key="plugins.generic.bookReviewManager.column.answerBy"}: {$row.invitationDeadline|date_format:$dateFormatShort}</strong></div>{/if}
						{if $row.declineReason}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.desk.declineReason"}: {$row.declineReason|escape|nl2br}</div>{/if}
					</td>
					<td class="ofrActions">
						{if $row.sendAction}{include file="linkAction/linkAction.tpl" action=$row.sendAction contextId="ofrInvite"}{/if}
						{if $canEdit}
							<div class="ofrInlineActions">
								{if $row.canMoveUp}{include file=$ofrInviteButton do="up" assignmentId=$row.assignmentId labelKey="plugins.generic.bookReviewManager.invitation.up" symbol="&uarr;"}{/if}
								{if $row.canMoveDown}{include file=$ofrInviteButton do="down" assignmentId=$row.assignmentId labelKey="plugins.generic.bookReviewManager.invitation.down" symbol="&darr;"}{/if}
								{if $row.canRemove}{include file=$ofrInviteButton do="remove" assignmentId=$row.assignmentId labelKey="plugins.generic.bookReviewManager.invitation.remove" symbol=""}{/if}
							</div>
						{/if}
					</td>
				</tr>
			{/foreach}
		</tbody>
	</table>
{else}
	<p>{translate key="plugins.generic.bookReviewManager.invitation.empty"}</p>
{/if}

{if $canEdit}
	<h2>{translate key="plugins.generic.bookReviewManager.invitation.addressBook"}</h2>
	<form class="ofrFilter" method="get" action="{url op="invite" path=$objectId}">
		<label for="ofrInviteSearch">{translate key="plugins.generic.bookReviewManager.invitation.search"}</label>
		<input type="search" id="ofrInviteSearch" name="q" value="{$query|escape}" />
		<button type="submit" class="pkp_button">{translate key="common.search"}</button>
	</form>
	<p class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.search.hint"}</p>
	{if $searched}
		{if $results}
			<table class="ofrTable">
				<tbody>
					{foreach from=$results item=result}
						<tr>
							<td>
								<strong>{$result.name|escape}</strong>
								{if $result.affiliation}<div class="ofrDetail">{$result.affiliation|escape}</div>{/if}
								<div class="ofrDetail">{$result.email|escape}</div>
							</td>
							<td class="ofrDetail">{translate key="plugins.generic.bookReviewManager.desk.reviewCount" number=$result.reviewCount}{if $result.reservationCount}<br />{translate key="plugins.generic.bookReviewManager.invitation.reservationCount" number=$result.reservationCount}{/if}</td>
							<td class="ofrActions">
								{if $result.canAdd}
									<form method="post" action="{url op="inviteAction"}">
										{csrf}
										<input type="hidden" name="objectId" value="{$objectId|escape}" />
										<input type="hidden" name="do" value="add" />
										<input type="hidden" name="userId" value="{$result.userId|escape}" />
										<input type="hidden" name="q" value="{$query|escape}" />
										<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.invitation.add"}</button>
									</form>
								{else}
									<span class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.cannotAdd"}</span>
								{/if}
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{else}
			<p>{translate key="plugins.generic.bookReviewManager.invitation.noResults"}</p>
		{/if}
	{/if}

	<h2>{translate key="plugins.generic.bookReviewManager.invitation.new"}</h2>
	<p class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.new.description"}</p>
	<form class="ofrNewInvitee" method="post" action="{url op="inviteAction"}">
		{csrf}
		<input type="hidden" name="objectId" value="{$objectId|escape}" />
		<input type="hidden" name="do" value="create" />
		{foreach from=['givenName', 'familyName', 'email', 'affiliation'] item=field}
			<div class="ofrField">
				<label for="ofrNew-{$field}">{translate key="plugins.generic.bookReviewManager.invitation.new.$field"}{if $field != 'affiliation'} <span class="req">*</span>{/if}</label>
				<input type="{if $field == 'email'}email{else}text{/if}" id="ofrNew-{$field}" name="{$field}" value="{$newInvitee.$field|escape}" maxlength="255" />
				{if $newInviteeErrors.$field}<div class="ofrWarning">{$newInviteeErrors.$field|escape}</div>{/if}
			</div>
		{/foreach}
		<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.invitation.new.submit"}</button>
	</form>
{/if}

<script>
	$(function() {ldelim}
		// Reload after an invitation was sent (modal)
		$('.bookReviewManagerManagement').on('dataChanged', function() {ldelim} window.location.reload(); {rdelim});

		// Drag and drop to reorder the list (the arrow buttons do the same without a mouse)
		var $tbody = $('.ofrInviteList tbody'), dragged = null;
		$tbody.on('dragstart', 'tr[draggable]', function(e) {ldelim}
			dragged = this;
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			e.originalEvent.dataTransfer.setData('text/plain', $(this).data('assignmentId'));
			$(this).addClass('ofrDragging');
		{rdelim});
		$tbody.on('dragover', 'tr', function(e) {ldelim}
			if (!dragged || dragged === this) return;
			e.preventDefault();
			var rect = this.getBoundingClientRect();
			if (e.originalEvent.clientY < rect.top + rect.height / 2) {ldelim} $(this).before(dragged); {rdelim} else {ldelim} $(this).after(dragged); {rdelim}
		{rdelim});
		$tbody.on('drop', function(e) {ldelim} e.preventDefault(); {rdelim});
		$tbody.on('dragend', 'tr', function() {ldelim}
			$(this).removeClass('ofrDragging');
			dragged = null;
			var order = $tbody.find('tr').map(function() {ldelim} return $(this).data('assignmentId'); {rdelim}).get().join(',');
			var $form = $('#ofrReorderForm');
			if (order !== $form.data('initialOrder')) {ldelim}
				$form.find('input[name=order]').val(order);
				$form.trigger('submit');
			{rdelim}
		{rdelim});
		$('#ofrReorderForm').data('initialOrder', $tbody.find('tr').map(function() {ldelim} return $(this).data('assignmentId'); {rdelim}).get().join(','));
	{rdelim});
</script>
