{**
 * plugins/generic/bookReviewManager/templates/frontend/pages/respond.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Page behind the one-click links and the contract page for selected bidders.
 * Opening it never changes anything; every action needs a confirmation button.
 *
 * $view: contract | leave | confirm | done | invalid | accepted | declined | left
 * $linkAction: the link's action (confirm, done)
 * $key: link key (when reached through an email link)
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.bookReviewManager.frontendTitle"}

<div class="page bookReviewManager ofrResponse">
	{include file="frontend/components/breadcrumbs.tpl" currentTitleKey="plugins.generic.bookReviewManager.frontendTitle"}

	{if $view == 'invalid'}
		<h1>{translate key="plugins.generic.bookReviewManager.link.invalid.title"}</h1>
		<p>{translate key="plugins.generic.bookReviewManager.link.invalid"}</p>

	{elseif $view == 'contract'}
		<h1>{translate key="plugins.generic.bookReviewManager.contract.title"}</h1>
		<p class="ofrReserveObject">{$objectDescription|strip_unsafe_html}</p>
		<p>{translate key="plugins.generic.bookReviewManager.contract.intro" name=$reviewerName|escape deadline=$contractDeadline|date_format:$dateFormatShort}</p>

		<form class="ofrContractForm" method="post" action="{url op="respond"}">
			{csrf}
			<input type="hidden" name="decision" value="accept" />
			{if $key}<input type="hidden" name="key" value="{$key|escape}" />{else}<input type="hidden" name="objectId" value="{$objectId|escape}" />{/if}
			{include file=$contractFieldsTemplate}
			<div class="ofrButtons">
				<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.contract.accept"}</button>
			</div>
		</form>

		<details class="ofrDecline">
			<summary>{translate key="plugins.generic.bookReviewManager.contract.decline"}</summary>
			<form class="ofrDeclineForm" method="post" action="{url op="respond"}">
				{csrf}
				<input type="hidden" name="decision" value="decline" />
				{if $key}<input type="hidden" name="key" value="{$key|escape}" />{else}<input type="hidden" name="objectId" value="{$objectId|escape}" />{/if}
				<div class="ofrField">
					<label for="ofrDeclineReason">{translate key="plugins.generic.bookReviewManager.contract.declineReason"}</label>
					<textarea id="ofrDeclineReason" name="declineReason" rows="3" maxlength="{$maxStatementLength|escape}"></textarea>
				</div>
				<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.contract.decline.submit"}</button>
			</form>
		</details>

	{elseif $view == 'invitation'}
		<h1>{translate key="plugins.generic.bookReviewManager.invitation.page.title.$kind"}</h1>
		<p class="ofrReserveObject">{$objectDescription|strip_unsafe_html}</p>
		<p>{translate key="plugins.generic.bookReviewManager.invitation.page.intro.$kind" name=$reviewerName|escape deadline=$invitationDeadline|date_format:$dateFormatShort}</p>
		{if $editorName}<p class="ofrDetail">{translate key="plugins.generic.bookReviewManager.invitation.page.editor" name=$editorName|escape}</p>{/if}

		<form class="ofrContractForm ofrInvitationForm" method="post" action="{url op="respond"}">
			{csrf}
			<input type="hidden" name="decision" value="accept" />
			<input type="hidden" name="key" value="{$key|escape}" />
			{if $kind == 'bid'}
				<div class="ofrField">
					<label for="ofrStatement">{translate key="plugins.generic.bookReviewManager.bid.statement"}</label>
					<textarea id="ofrStatement" name="statement" rows="5" maxlength="{$maxStatementLength|escape}">{$statement|escape}</textarea>
					<p class="ofrDetail">{translate key="plugins.generic.bookReviewManager.bid.statement.hint"}</p>
				</div>
				<p>{translate key="plugins.generic.bookReviewManager.bid.description"}</p>
				<div class="ofrButtons">
					<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.bid.submit"}</button>
				</div>
			{else}
				{include file=$contractFieldsTemplate}
				<div class="ofrButtons">
					<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.contract.accept"}</button>
				</div>
			{/if}
		</form>

		<details class="ofrDecline">
			<summary>{translate key="plugins.generic.bookReviewManager.invitation.page.decline"}</summary>
			<form class="ofrDeclineForm" method="post" action="{url op="respond"}">
				{csrf}
				<input type="hidden" name="decision" value="decline" />
				<input type="hidden" name="key" value="{$key|escape}" />
				<div class="ofrField">
					<label for="ofrDeclineReason">{translate key="plugins.generic.bookReviewManager.contract.declineReason"}</label>
					<textarea id="ofrDeclineReason" name="declineReason" rows="3" maxlength="{$maxStatementLength|escape}"></textarea>
				</div>
				<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.invitation.page.decline.submit"}</button>
			</form>
		</details>

	{elseif $view == 'leave'}
		<h1>{translate key="plugins.generic.bookReviewManager.leave.title"}</h1>
		<p class="ofrReserveObject">{$objectDescription|strip_unsafe_html}</p>
		<p>{translate key="plugins.generic.bookReviewManager.leave.description" name=$reviewerName|escape}</p>
		<form method="post" action="{url op="respond"}">
			{csrf}
			<input type="hidden" name="decision" value="leave" />
			<input type="hidden" name="key" value="{$key|escape}" />
			<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.leave.submit"}</button>
		</form>

	{elseif $view == 'confirm'}
		<h1>{translate key="plugins.generic.bookReviewManager.link.$linkAction.title"}</h1>
		<p class="ofrReserveObject">{$objectDescription|strip_unsafe_html}</p>
		<p>{translate key="plugins.generic.bookReviewManager.link.$linkAction.description" reviewer=$reviewerName|escape publisher=$publisherName|escape}</p>
		<form method="post" action="{url op="respond"}">
			{csrf}
			<input type="hidden" name="key" value="{$key|escape}" />
			<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.link.$linkAction.submit"}</button>
		</form>

	{elseif $view == 'done'}
		<h1>{translate key="plugins.generic.bookReviewManager.response.done.title"}</h1>
		<p class="ofrReserveObject">{$objectDescription|strip_unsafe_html}</p>
		<p class="ofrResponseMessage">{translate key="plugins.generic.bookReviewManager.link.$linkAction.done"}</p>

	{else}
		<h1>{translate key="plugins.generic.bookReviewManager.response.$view.title"}</h1>
		<p class="ofrReserveObject">{$objectDescription|strip_unsafe_html}</p>
		<p class="ofrResponseMessage">{translate key="plugins.generic.bookReviewManager.response.$view" dueDate=$dueDate|date_format:$dateFormatShort}</p>
	{/if}

	<p><a href="{url op="index"}">{translate key="plugins.generic.bookReviewManager.backToList"}</a></p>
</div>

<style>
	.ofrResponse { max-width: 48rem; }
	.ofrResponse .ofrReserveObject { font-style: italic; }
	.ofrResponse .ofrButtons { margin: 1.5rem 0; }
	.ofrResponse .ofrDecline { margin: 1.5rem 0; border-top: 1px solid #ddd; padding-top: 1rem; }
	.ofrResponse .ofrDecline summary { cursor: pointer; font-weight: bold; }
</style>

{include file="frontend/components/footer.tpl"}
