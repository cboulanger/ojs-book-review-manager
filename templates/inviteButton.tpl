{**
 * plugins/generic/bookReviewManager/templates/inviteButton.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * A POST button changing the invitation list (up, down, remove); see invite.tpl.
 * $do, $assignmentId, $labelKey, $symbol, $confirmKey (optional: ask before submitting),
 * $objectId, $returnTo (optional: 'desk' to return to the To-do page)
 *}
{if $confirmKey}{capture assign="ofrConfirmText"}{translate key=$confirmKey}{/capture}{/if}
<form class="ofrInlineForm" method="post" action="{url op="inviteAction"}"{if $confirmKey} onsubmit="return confirm({$ofrConfirmText|json_encode|escape});"{/if}>
	{csrf}
	<input type="hidden" name="objectId" value="{$objectId|escape}" />
	<input type="hidden" name="assignmentId" value="{$assignmentId|escape}" />
	<input type="hidden" name="do" value="{$do|escape}" />
	{if $returnTo}<input type="hidden" name="returnTo" value="{$returnTo|escape}" />{/if}
	<button type="submit" class="ofrLinkButton" title="{translate key=$labelKey}" aria-label="{translate key=$labelKey}">{if $symbol}{$symbol}{else}{translate key=$labelKey}{/if}</button>
</form>
