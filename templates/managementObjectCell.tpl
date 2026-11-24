{**
 * plugins/generic/bookReviewManager/templates/managementObjectCell.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Object description with (linked) identifier, used in the backend tables.
 *
 * $row: objectId, description, identifierType, identifier, identifierUrl, hidden, essayTitle,
 *  historyAction (optional: the description opens the history)
 *}
{if $row.historyAction}
	{include file="linkAction/linkAction.tpl" action=$row.historyAction contextId="ofrObject"}
{else}
	{$row.description|strip_unsafe_html}
{/if}
{if $row.hidden} <span class="ofrBadge">{translate key="plugins.generic.bookReviewManager.hidden"}</span>{/if}
{if $row.essayTitle} <span class="ofrDetail">{translate key="plugins.generic.bookReviewManager.reviewEssay.memberOf" title=$row.essayTitle|escape}</span>{/if}
<div class="ofrIdentifier">
	{$row.identifierType|escape}:
	{if $row.identifierUrl}
		<a href="{$row.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$row.identifier|escape}</a>
	{else}
		{$row.identifier|escape}
	{/if}
</div>
