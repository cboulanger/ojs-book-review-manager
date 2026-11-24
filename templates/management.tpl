{**
 * plugins/generic/bookReviewManager/templates/management.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Backend pages of the "Book Review Manager" menu section.
 *
 * $view: available | desk | assignments | reviewedWorks | settings | invite
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	{if $ofrEmailsSuppressed}
		<div class="pkp_notification" role="status">
			<div class="notifyWarning">{translate key="plugins.generic.bookReviewManager.emailsSuppressed.notice"}</div>
		</div>
	{/if}

	<div class="app__contentPanel bookReviewManagerManagement">
		{if $view == 'available'}
			{load_url_in_div id="bookReviewManagerGridContainer" url=$gridUrl}

		{elseif $view == 'settings'}
			{load_url_in_div id="bookReviewManagerSettingsContainer" url=$settingsUrl}

		{elseif $view == 'desk'}
			{if !$selection && !$offers && !$unsuccessful && !$copies && !$notifications && !$overdue && !$unlinked && !$invitations}
				<p class="ofrDeskEmpty">{translate key="plugins.generic.bookReviewManager.desk.empty"}</p>
			{/if}

			{if $selection}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.selection"}</h2>
				{foreach from=$selection item=offer}
					<section class="ofrDeskItem" id="ofrSelection-{$offer.objectId|escape}">
						<div class="ofrDeskHead">
							{include file=$ofrObjectCell row=$offer}
							<p class="ofrDeskState">
								{if $offer.biddingOpen}
									{translate key="plugins.generic.bookReviewManager.desk.biddingOpen"}{if $offer.bidDeadline} ({translate key="plugins.generic.bookReviewManager.bid.deadline" deadline=$offer.bidDeadline|date_format:$dateFormatShort}){/if}
								{elseif $offer.deadlinePassed && !$offer.dropout}
									{translate key="plugins.generic.bookReviewManager.desk.deadlinePassed" deadline=$offer.bidDeadline|date_format:$dateFormatShort}
								{else}
									{translate key="plugins.generic.bookReviewManager.desk.biddingClosed"}
								{/if}
								{if $offer.dropout}
									<br /><strong>{translate key="plugins.generic.bookReviewManager.desk.dropout.`$offer.dropout.status`" name=$offer.dropout.name|escape date=$offer.dropout.date|date_format:$dateFormatShort}</strong>
									{if $offer.dropout.reason}<br /><span class="ofrDetail">{translate key="plugins.generic.bookReviewManager.desk.declineReason"}: {$offer.dropout.reason|escape|nl2br}</span>{/if}
								{/if}
							</p>
						</div>
						{if $offer.bidders}
							<table class="ofrTable ofrBidders">
								<thead>
									<tr>
										<th>{translate key="plugins.generic.bookReviewManager.column.bidder"}</th>
										<th>{translate key="plugins.generic.bookReviewManager.bid.statement"}</th>
										<th>{translate key="plugins.generic.bookReviewManager.column.bidDate"}</th>
										<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
									</tr>
								</thead>
								<tbody>
									{foreach from=$offer.bidders item=bidder}
										<tr>
											<td>
												<strong>{$bidder.name|escape}</strong>
												{if $bidder.affiliation}<div class="ofrDetail">{$bidder.affiliation|escape}</div>{/if}
												<div class="ofrDetail">{$bidder.email|escape}</div>
												<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.desk.reviewCount" number=$bidder.reviewCount}</div>
												{if $bidder.status == 'unsuccessful'}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.desk.onList"}</div>{/if}
											</td>
											<td>{if $bidder.statement}{$bidder.statement|escape|nl2br}{else}–{/if}</td>
											<td class="ofrNoWrap">{$bidder.date|date_format:$dateFormatShort}</td>
											<td class="ofrActions">{include file="linkAction/linkAction.tpl" action=$bidder.selectAction contextId="ofrDesk"}</td>
										</tr>
									{/foreach}
								</tbody>
							</table>
						{else}
							<p>{translate key="plugins.generic.bookReviewManager.desk.noBidders"}</p>
						{/if}
						<div class="ofrOfferActions">
							{foreach from=$offer.offerActions item=action}
								{include file="linkAction/linkAction.tpl" action=$action contextId="ofrDesk"}
							{/foreach}
							{include file="linkAction/linkAction.tpl" action=$offer.historyAction contextId="ofrDesk"}
						</div>
					</section>
				{/foreach}
			{/if}

			{if $invitations}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.invitations"}</h2>
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.invitation.mode"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.status.invited"}</th>
							<th>{translate key="grid.columns.actions"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$invitations item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{translate key="plugins.generic.bookReviewManager.invitation.mode.`$row.mode`"}</td>
								<td>
									{if $row.exhausted}
										<span class="ofrWarning">{translate key="plugins.generic.bookReviewManager.invitation.round.exhaustedShort"}</span>
									{else}
										{foreach from=$row.invited item=name name=invited}{$name|escape}{if !$smarty.foreach.invited.last}<br />{/if}{/foreach}
										{if $row.deadline}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.column.answerBy"}: {$row.deadline|date_format:$dateFormatShort}</div>{/if}
									{/if}
									{if $row.mode == 'sequential' && $row.next}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.round.next" name=$row.next|escape number=$row.waitingCount}</div>{/if}
									{if $row.mode == 'parallel' && $row.waitingCount}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.round.newPeople" number=$row.waitingCount}</div>{/if}
								</td>
								<td class="ofrActions">
									<a href="{$row.inviteUrl|escape}">{if $row.exhausted}{translate key="plugins.generic.bookReviewManager.invitation.inviteMore"}{else}{translate key="plugins.generic.bookReviewManager.invitation.openPage"}{/if}</a>
									{if $row.roundActive}
										{include file=$ofrInviteButton do="stopRound" objectId=$row.objectId assignmentId=0 returnTo="desk" labelKey="plugins.generic.bookReviewManager.invitation.round.stop" symbol="" confirmKey="plugins.generic.bookReviewManager.invitation.round.stop.confirm"}
									{/if}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $offers}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.offers"}</h2>
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.selectedBidder"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.dates"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$offers item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.user|escape}</td>
								<td class="ofrDates">
									{translate key="plugins.generic.bookReviewManager.column.offeredOn"}: <span class="ofrNoWrap">{$row.dateOffered|date_format:$dateFormatShort}</span><br />
									<strong>{translate key="plugins.generic.bookReviewManager.column.answerBy"}: <span class="ofrNoWrap">{$row.contractDeadline|date_format:$dateFormatShort}</span></strong>
									{if $row.contractReminderSent}<br />{translate key="plugins.generic.bookReviewManager.column.reminderSent"}: <span class="ofrNoWrap">{$row.contractReminderSent|date_format:$dateFormatShort}</span>{/if}
								</td>
								<td class="ofrActions">
									{foreach from=$row.actions item=action}
										{include file="linkAction/linkAction.tpl" action=$action contextId="ofrDesk"}
									{/foreach}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $unsuccessful}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.unsuccessful"}</h2>
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.reservedBy"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.remainingBidders"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$unsuccessful item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.reviewer|escape}</td>
								<td>{foreach from=$row.names item=name}<div>{$name|escape}</div>{/foreach}</td>
								<td class="ofrActions">{include file="linkAction/linkAction.tpl" action=$row.action contextId="ofrDesk"}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $overdue}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.overdue"}</h2>
				<table class="ofrTable ofrOverdue">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.reviewer"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.dates"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$overdue item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.reviewer|escape}{if $row.copyStatusLabel}<div class="ofrDetail">{$row.copyStatusLabel|escape}</div>{/if}</td>
								<td class="ofrDates">
									<strong class="ofrWarning">{translate key="plugins.generic.bookReviewManager.reserve.dueDate"}: <span class="ofrNoWrap">{$row.dueDate|date_format:$dateFormatShort}</span></strong>
									{if $row.reminderSent}<br />{translate key="plugins.generic.bookReviewManager.column.reminderSent"}: <span class="ofrNoWrap">{$row.reminderSent|date_format:$dateFormatShort}</span>{/if}
								</td>
								<td class="ofrActions">
									{foreach from=$row.actions item=action}
										{include file="linkAction/linkAction.tpl" action=$action contextId="ofrDesk"}
									{/foreach}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $unlinked}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.unlinked"}</h2>
				<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.desk.unlinked.description"}</p>
				<table class="ofrTable ofrUnlinked">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.reviewer"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.dates"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$unlinked item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.reviewer|escape}</td>
								<td class="ofrDates">{translate key="plugins.generic.bookReviewManager.desk.unlinked.submitted"}: <span class="ofrNoWrap">{$row.dateStatus|date_format:$dateFormatShort}</span></td>
								<td class="ofrActions">
									{foreach from=$row.actions item=action}
										{include file="linkAction/linkAction.tpl" action=$action contextId="ofrDesk"}
									{/foreach}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $copies}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.copies"}</h2>
				<table class="ofrTable ofrCopies">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.person"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.copy"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.copySource"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$copies item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>
									{$row.reviewer|escape}
									{if $row.assignmentStatus != 'accepted'}<div class="ofrDetail ofrWarning">{translate key="plugins.generic.bookReviewManager.status.`$row.assignmentStatus`"}</div>{/if}
								</td>
								<td>
									{$row.copyPreferenceLabel|escape|nl2br}<br />
									<strong>{$row.copyStatusLabel|escape}</strong>
									<div class="ofrDetail">{$row.copyStatusDate|date_format:$dateFormatShort}</div>
									{if $row.copyReminderCount}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.copy.reminders" number=$row.copyReminderCount date=$row.copyReminderSent|date_format:$dateFormatShort}</div>{/if}
								</td>
								<td>
									{if $row.journalHoldsCopy}<div>{translate key="plugins.generic.bookReviewManager.copy.journalHoldsCopy"}</div>{/if}
									{if $row.publisherContact}<div>{$row.publisherContact|escape}</div>{elseif !$row.journalHoldsCopy}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.copy.noSource"}</div>{/if}
								</td>
								<td class="ofrActions">
									{foreach from=$row.copyActions item=action}
										{include file="linkAction/linkAction.tpl" action=$action contextId="ofrDesk"}
									{/foreach}
									{include file="linkAction/linkAction.tpl" action=$row.historyAction contextId="ofrDesk"}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $notifications}
				<h2>{translate key="plugins.generic.bookReviewManager.desk.notifications"}</h2>
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.reviewer"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.offer.publisherContact"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$notifications item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.reviewer|escape}</td>
								<td>{$row.publisherContact|escape}</td>
								<td class="ofrActions">{include file="linkAction/linkAction.tpl" action=$row.action contextId="ofrDesk"}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}

			{if $closed}
				<details class="ofrClosed">
					<summary>{translate key="plugins.generic.bookReviewManager.desk.closed"} ({$closed|@count})</summary>
					<table class="ofrTable">
						<tbody>
							{foreach from=$closed item=row}
								<tr>
									<td>{include file=$ofrObjectCell row=$row}</td>
									<td class="ofrActions">
										{foreach from=$row.offerActions item=action}
											{include file="linkAction/linkAction.tpl" action=$action contextId="ofrDesk"}
										{/foreach}
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</details>
			{/if}
			<script>
				$(function() {ldelim}
					$('.bookReviewManagerManagement').on('dataChanged', function() {ldelim} window.location.reload(); {rdelim});
				{rdelim});
			</script>

		{elseif $view == 'assignments'}
			<form class="ofrFilter" method="get" action="{url op="assignments"}">
				<label for="ofrFilterStatus">{translate key="common.status"}</label>
				<select id="ofrFilterStatus" name="status" onchange="this.form.submit()">
					{foreach from=$statusOptions item=option}
						<option value="{$option|escape}"{if $option == $statusFilter} selected{/if}>{translate key="plugins.generic.bookReviewManager.status.$option"}</option>
					{/foreach}
				</select>
				{if $users|@count > 1}
					<label for="ofrFilterUser">{translate key="plugins.generic.bookReviewManager.column.person"}</label>
					<select id="ofrFilterUser" name="userId" onchange="this.form.submit()">
						<option value="">{translate key="plugins.generic.bookReviewManager.filter.allUsers"}</option>
						{foreach from=$users key=userId item=userName}
							<option value="{$userId|escape}"{if $userId == $filterUserId} selected{/if}>{$userName|escape}</option>
						{/foreach}
					</select>
				{/if}
				<noscript><button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.filter.apply"}</button></noscript>
			</form>
			{if $rows|@count}
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.person"}</th>
							<th>{translate key="common.status"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.dates"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.reserve.copy"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$rows item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.user|escape}</td>
								<td class="ofrNoWrap">{translate key="plugins.generic.bookReviewManager.status.`$row.status`"}<br /><span class="ofrDetail">{$row.dateStatus|date_format:$dateFormatShort}</span></td>
								<td class="ofrDates">
									{if $row.dateReserved}{translate key="plugins.generic.bookReviewManager.column.reservedOn"}: <span class="ofrNoWrap">{$row.dateReserved|date_format:$dateFormatShort}</span><br />{/if}
									{if $row.dueDate}<strong>{translate key="plugins.generic.bookReviewManager.reserve.dueDate"}: <span class="ofrNoWrap">{$row.dueDate|date_format:$dateFormatShort}</span></strong>{/if}
									{if $row.contractDeadline}<strong>{translate key="plugins.generic.bookReviewManager.column.answerBy"}: <span class="ofrNoWrap">{$row.contractDeadline|date_format:$dateFormatShort}</span></strong>{/if}
									{if $row.reminderSent}<br />{translate key="plugins.generic.bookReviewManager.column.reminderSent"}: <span class="ofrNoWrap">{$row.reminderSent|date_format:$dateFormatShort}</span>{/if}
								</td>
								<td>
									{$row.copyPreferenceLabel|escape|nl2br}
									{if $row.copyStatusLabel}<div><strong>{$row.copyStatusLabel|escape}</strong></div>{/if}
									{if $row.members}
										<ul class="ofrDetail">
											{foreach from=$row.members item=member}
												<li>{$member.description|strip_unsafe_html}{if $member.copyStatusLabel}: <strong>{$member.copyStatusLabel|escape}</strong>{/if}
													{foreach from=$member.copyActions item=action}
														{include file="linkAction/linkAction.tpl" action=$action contextId="ofrAssignments"}
													{/foreach}
												</li>
											{/foreach}
										</ul>
									{/if}
									{if $row.shippingAddress}<div class="ofrDetail">{$row.shippingAddress|escape|nl2br}</div>{/if}
									{if $row.phone}<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.reserve.phone"}: {$row.phone|escape}</div>{/if}
									{if $row.copyNote}<div class="ofrDetail">{$row.copyNote|escape|nl2br}</div>{/if}
								</td>
								<td class="ofrActions">
									{foreach from=$row.copyActions item=action}
										{include file="linkAction/linkAction.tpl" action=$action contextId="ofrAssignments"}
									{/foreach}
									{foreach from=$row.actions item=action}
										{include file="linkAction/linkAction.tpl" action=$action contextId="ofrAssignments"}
									{/foreach}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{translate key="plugins.generic.bookReviewManager.assignments.none"}</p>
			{/if}
			<script>
				// Reload after an assignment was emailed or cancelled in a modal
				$(function() {ldelim}
					$('.bookReviewManagerManagement').on('dataChanged', function() {ldelim} window.location.reload(); {rdelim});
				{rdelim});
			</script>

		{elseif $view == 'reviewedWorks'}
			{if $rows|@count}
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
							<th>{translate key="plugins.generic.bookReviewManager.column.submission"}</th>
							<th>{translate key="common.status"}</th>
							<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$rows item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>
									{if $row.submissionUrl}
										<a href="{$row.submissionUrl|escape}">#{$row.submissionId|escape} {$row.submissionTitle|strip_tags|escape}</a>
									{else}
										#{$row.submissionId|escape}
									{/if}
								</td>
								<td>{$row.submissionStatus|escape}</td>
								<td class="ofrActions">{include file="linkAction/linkAction.tpl" action=$row.historyAction contextId="ofrReviewedWorks"}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{translate key="plugins.generic.bookReviewManager.reviewedWorks.none"}</p>
			{/if}

		{elseif $view == 'invite'}
			{include file=$ofrInviteTemplate}
		{/if}
	</div>
{/block}
