{**
 * plugins/generic/bookReviewManager/templates/myBookReviews.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Backend page "My book reviews" (dashboard menu): the current user's
 * reservations. The buttons post to the public page, which returns here.
 *
 * $rows: see MyReviews::rows()
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	<div class="app__contentPanel bookReviewManagerManagement ofrMyBookReviews">
		{if !$rows}
			<p>{translate key="plugins.generic.bookReviewManager.myReviews.empty"}</p>
		{else}
			<p>{translate key="plugins.generic.bookReviewManager.myReviews.submitHint"}</p>
			<table class="ofrTable">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.bookReviewManager.column.object"}</th>
						<th>{translate key="plugins.generic.bookReviewManager.reserve.dueDate"}</th>
						<th>{translate key="plugins.generic.bookReviewManager.myReviews.copy"}</th>
						<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$rows item=row}
						<tr>
							<td>
								{$row.citation|strip_unsafe_html}
								{if $row.identifierUrl}<div class="ofrIdentifier"><a href="{$row.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$row.identifierUrl|escape}</a></div>{/if}
							</td>
							<td class="ofrNoWrap">
								{if $row.dueDate}
									{if $row.overdue}<strong class="ofrWarning">{$row.dueDate|date_format:$dateFormatShort}</strong>{else}{$row.dueDate|date_format:$dateFormatShort}{/if}
								{/if}
							</td>
							<td>
								{if $row.reviewEssay}
									<ul class="ofrEssayWorks">
										{foreach from=$row.members item=member}
											<li>
												{$member.citation|strip_unsafe_html}
												{if $member.copyStatus && $member.copyStatus != 'notNeeded' && $member.copyStatus != 'received'}
													<div class="ofrDetail">{translate key="plugins.generic.bookReviewManager.copy.reviewerStatus.`$member.copyStatus`"}</div>
												{/if}
												{if $member.copyStatus == 'sent'}
													{include file=$receiptTemplate objectId=$member.id}
												{/if}
											</li>
										{/foreach}
									</ul>
								{else}
									{if $row.copyStatus && $row.copyStatus != 'notNeeded' && $row.copyStatus != 'received'}
										{translate key="plugins.generic.bookReviewManager.copy.reviewerStatus.`$row.copyStatus`"}
									{/if}
									{if $row.copyStatus == 'sent'}
										{include file=$receiptTemplate objectId=$row.objectId}
									{/if}
								{/if}
							</td>
							<td class="ofrActions">
								<a href="{url page="submission"}" class="pkp_button">{translate key="plugins.generic.bookReviewManager.myReviews.submit"}</a>
								<form method="post" action="{url page=$publicPage op="cancelObject"}" onsubmit="return confirm('{translate|escape:"javascript"|escape key="plugins.generic.bookReviewManager.cancel.confirm"}');">
									{csrf}
									<input type="hidden" name="objectId" value="{$row.objectId|escape}" />
									<input type="hidden" name="returnTo" value="myBookReviews" />
									<button type="submit" class="pkp_button pkp_button_offset">{translate key="plugins.generic.bookReviewManager.cancel"}</button>
								</form>
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}
		<p><a href="{url page=$publicPage}" target="_blank" rel="noopener">{translate key="plugins.generic.bookReviewManager.myReviews.publicPage"}</a></p>
	</div>
	<style>
		.ofrMyBookReviews .ofrActions form { margin-top: 0.5rem; }
		.ofrMyBookReviews .ofrEssayWorks { margin: 0; padding-left: 1.25rem; }
		.ofrMyBookReviews .ofrWarning { color: #d00a0a; }
	</style>
{/block}
