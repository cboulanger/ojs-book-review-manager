{**
 * plugins/generic/bookReviewManager/templates/frontend/pages/forReview.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * The template used for listing available objects for review
 *}

{include file="frontend/components/header.tpl" pageTitle="plugins.generic.bookReviewManager.frontendTitle"}

<script type="text/javascript">
  window.addEventListener('DOMContentLoaded', function(){ldelim}
    new Tablesort(document.getElementById('book-review-manager-table'));
  {rdelim});
</script>

<div class="page bookReviewManager">
  {include file="frontend/components/breadcrumbs.tpl" currentTitleKey="plugins.generic.bookReviewManager.frontendTitle"}

  <p>{$instructions|strip_unsafe_html}</p>

  {if !$currentUser}
    <p>{translate key='plugins.generic.bookReviewManager.logInToReserve'}</p>
  {/if}

		<table id="book-review-manager-table" style="width:100%">
      <tr data-sort-method='none'>
        <th>{translate key="plugins.generic.bookReviewManager.itemTitle"}</th>
        <th>{translate key="plugins.generic.bookReviewManager.itemAuthors"}</th>
        <th>{translate key="plugins.generic.bookReviewManager.itemPublisher"}</th>
        <th>{translate key="plugins.generic.bookReviewManager.itemYearShort"}</th>
        <th></th> 
      </tr>
			{foreach from=$bookReviewManager item=objectForReview}
      <tr>
				<td data-sort="{$objectForReview.title|escape}">
            {$objectForReview.title|escape}
            {if $objectForReview.reviewEssay}
              {* A review essay: its works, with the current reviewer's review copy status of each *}
              <ul class="ofrEssayWorks">
                {foreach from=$objectForReview.members item=member}
                  <li>
                    {if $member.identifierUrl}
                      <a href="{$member.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$member.citation|escape}</a>
                    {else}
                      {$member.citation|escape}
                    {/if}
                    {if $member.copyStatus && $member.copyStatus != 'notNeeded' && $member.copyStatus != 'received'}
                      <span class="ofrMyStatus ofrCopyStatus">{translate key="plugins.generic.bookReviewManager.copy.reviewerStatus.`$member.copyStatus`"}</span>
                    {/if}
                    {if $member.copyStatus == 'sent'}
                      <form class="pkp_form" method="post" action="{url op="confirmReceipt"}">
                        {csrf}
                        <input type="hidden" name="objectId" value="{$member.id|escape}" />
                        <button type="submit" class="pkp_button ofrReceivedButton">{translate key='plugins.generic.bookReviewManager.copy.confirmReceipt'}</button>
                      </form>
                    {/if}
                  </li>
                {/foreach}
              </ul>
            {else}
              <br />
              {$objectForReview.identifierType|escape}:
              {if $objectForReview.identifierUrl}
                <a href="{$objectForReview.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$objectForReview.identifier|escape}</a>
              {else}
                {$objectForReview.identifier|escape}
              {/if}
            {/if}
            {if $objectForReview.description}
              <div class="ofrObjectDescription">{$objectForReview.description|strip_unsafe_html}</div>
            {/if}
				</td>
        <td>
            {$objectForReview.authors}
        </td>
        <td>
            {$objectForReview.publisher}
        </td>
        <td>
            {$objectForReview.year}
        </td>
        <td class="ofrStatusCell">
          {assign var=o value=$objectForReview}
          {if $o.myStatus == 'accepted'}
            {* The current user's reservation: due date and cancel button *}
            {if $o.dueDate}
              <p class="ofrDueDate">{translate key="plugins.generic.bookReviewManager.reserve.yourDueDate" dueDate=$o.dueDate|date_format:$dateFormatShort}</p>
            {/if}
            {if $o.copyStatus && $o.copyStatus != 'notNeeded' && $o.copyStatus != 'received'}
              <p class="ofrMyStatus ofrCopyStatus">{translate key="plugins.generic.bookReviewManager.copy.reviewerStatus.`$o.copyStatus`"}</p>
            {/if}
            {if $o.copyStatus == 'sent'}
              <form class="pkp_form" method="post" action="{url op="confirmReceipt"}">
                {csrf}
                <input type="hidden" name="objectId" value="{$o.objectId|escape}" />
                <button type="submit" class="pkp_button ofrReceivedButton">{translate key='plugins.generic.bookReviewManager.copy.confirmReceipt'}</button>
              </form>
            {/if}
            <form class="pkp_form" id="cancelObjectForm-{$o.objectId|escape}" method="post" action="{url op="cancelObject"}" onsubmit="return confirm('{translate|escape:"javascript"|escape key="plugins.generic.bookReviewManager.cancel.confirm"}');">
              {csrf}
              <input type="hidden" name="objectId" value="{$o.objectId|escape}" />
              <button type="submit" id="cancelObject-{$o.objectId|escape}" class="pkp_button pkp_button_offset">{translate key='plugins.generic.bookReviewManager.cancel'}</button>
            </form>
          {elseif $o.myStatus == 'offered'}
            {* Selected bidder: respond to the contract offer *}
            <p class="ofrMyStatus">{translate key="plugins.generic.bookReviewManager.bid.selected" deadline=$o.contractDeadline|date_format:$dateFormatShort}</p>
            <a class="pkp_button ofrRespondButton" href="{url op="contract" objectId=$o.objectId}">{translate key="plugins.generic.bookReviewManager.bid.respond"}</a>
          {elseif $o.myStatus == 'bid' || $o.myStatus == 'unsuccessful'}
            {* The current user's bid *}
            <p class="ofrMyStatus">{if $o.myStatus == 'bid'}{translate key="plugins.generic.bookReviewManager.bid.yours" date=$o.myDate|date_format:$dateFormatShort}{else}{translate key="plugins.generic.bookReviewManager.bid.unsuccessful"}{/if}</p>
            <form class="pkp_form" method="post" action="{url op="withdrawBid"}" onsubmit="return confirm('{translate|escape:"javascript"|escape key="plugins.generic.bookReviewManager.bid.withdraw.confirm"}');">
              {csrf}
              <input type="hidden" name="objectId" value="{$o.objectId|escape}" />
              <button type="submit" class="pkp_button pkp_button_offset ofrWithdrawButton">{translate key='plugins.generic.bookReviewManager.bid.withdraw'}</button>
            </form>
          {elseif $o.reservable}
            {if $currentUser}
              <a class="pkp_button ofrReserveButton" id="reserveObject-{$o.objectId|escape}" href="{url op="index" reserve=$o.objectId}" data-object-id="{$o.objectId|escape}" data-description="{$o.citation|strip_tags|escape}"{if $o.members} data-review-essay="1"{/if}>{translate key='plugins.generic.bookReviewManager.reserve'}</a>
            {else}
              {translate key='plugins.generic.bookReviewManager.objectAvailable'}
            {/if}
          {elseif $o.biddingOpen}
            {if $o.bidDeadline}<p class="ofrBidDeadline">{translate key="plugins.generic.bookReviewManager.bid.deadline" deadline=$o.bidDeadline|date_format:$dateFormatShort}</p>{/if}
            {if $currentUser}
              <button type="button" class="pkp_button ofrBidButton" data-object-id="{$o.objectId|escape}" data-description="{$o.citation|strip_tags|escape}">{translate key='plugins.generic.bookReviewManager.bid'}</button>
            {else}
              {translate key='plugins.generic.bookReviewManager.bid.open'}
            {/if}
          {elseif $o.state == 'selection' || $o.state == 'contractOffered'}
            {translate key='plugins.generic.bookReviewManager.bid.closed'}
          {else}
            {translate key='plugins.generic.bookReviewManager.objectReserved'}
          {/if}
        </td>
        </tr>
			{/foreach}
		</table>
		{if $currentUser}
			{include file=$reserveDialogTemplate}
			<dialog id="ofrBidDialog" class="ofrReserveDialog" aria-labelledby="ofrBidDialogTitle">
				<form method="post" action="{url op="bid"}">
					{csrf}
					<input type="hidden" name="objectId" value="" />
					<h2 id="ofrBidDialogTitle">{translate key="plugins.generic.bookReviewManager.bid.title"}</h2>
					<p class="ofrReserveObject"></p>
					<p>{translate key="plugins.generic.bookReviewManager.bid.description"}</p>
					<div class="ofrField">
						<label for="ofrStatement">{translate key="plugins.generic.bookReviewManager.bid.statement"}</label>
						<textarea id="ofrStatement" name="statement" rows="5" maxlength="{$maxStatementLength|escape}"></textarea>
						<p class="ofrHint">{translate key="plugins.generic.bookReviewManager.bid.statement.hint"}</p>
					</div>
					<div class="ofrButtons">
						<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.bid.submit"}</button>
						<button type="button" class="pkp_button pkp_button_offset ofrCancel">{translate key="common.cancel"}</button>
					</div>
				</form>
			</dialog>
			<style>
				#ofrBidDialog .ofrField label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
				#ofrBidDialog textarea { width: 100%; box-sizing: border-box; }
				#ofrBidDialog .ofrHint { font-size: 0.875rem; color: #555; }
				.ofrMyStatus, .ofrBidDeadline { font-size: 0.875rem; margin: 0 0 0.5rem; }
				.ofrObjectDescription { font-size: 0.875rem; margin-top: 0.5rem; }
				.ofrObjectDescription p { margin: 0 0 0.25rem; }
				.ofrEssayWorks { margin: 0.25rem 0 0; padding-left: 1.25rem; }
				.ofrEssayWorks li { margin-bottom: 0.25rem; }
				.ofrEssayWorks .ofrCopyStatus { display: block; }
			</style>
			<script>
				(function () {ldelim}
					var dialog = document.getElementById('ofrBidDialog');
					if (!dialog || typeof dialog.showModal !== 'function') return;
					var form = dialog.querySelector('form');
					document.querySelectorAll('.ofrBidButton').forEach(function (button) {ldelim}
						button.addEventListener('click', function () {ldelim}
							form.elements.objectId.value = button.getAttribute('data-object-id');
							dialog.querySelector('.ofrReserveObject').textContent = button.getAttribute('data-description');
							dialog.showModal();
						{rdelim});
					{rdelim});
					dialog.querySelector('.ofrCancel').addEventListener('click', function () {ldelim} dialog.close(); {rdelim});
				{rdelim})();
			</script>
		{/if}
</div>
{include file="frontend/components/footer.tpl"}
