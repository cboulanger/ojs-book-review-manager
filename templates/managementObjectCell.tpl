{**
 * plugins/generic/bookReviewManager/templates/managementObjectCell.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Object description with (linked) identifier, used in the backend tables.
 *
 * $row: objectId, description, identifierType, identifier, identifierUrl, identifierHtml, publisherUrl, openAccess, hidden, essayTitle
 *}
{$row.description|strip_unsafe_html}
{if $row.hidden} <span class="ofrBadge">{translate key="plugins.generic.bookReviewManager.hidden"}</span>{/if}
{if $row.essayTitle} <span class="ofrDetail">{translate key="plugins.generic.bookReviewManager.reviewEssay.memberOf" title=$row.essayTitle|escape}</span>{/if}
{* identifierHtml is escaped: "type: identifier", linked where possible (IdentifierLinks) *}
{if $row.identifierHtml}<div class="ofrIdentifier">{$row.identifierHtml}</div>{/if}
{if $row.publisherUrl || $row.openAccess}
	<div class="ofrDetail">
		{if $row.publisherUrl}<a href="{$row.publisherUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.bookReviewManager.itemPublisherUrl"}</a>{/if}
		{if $row.openAccess}{if $row.publisherUrl} · {/if}{translate key="plugins.generic.bookReviewManager.openAccess.`$row.openAccess`"}{/if}
	</div>
{/if}
