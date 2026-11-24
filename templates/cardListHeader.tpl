{**
 * plugins/generic/bookReviewManager/templates/cardListHeader.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The header row of a card list (used instead of a table where cells can have a lot of
 * content): buttons that sort the cards. Sorting is done by js/CardList.js.
 *
 * Usage:
 *   <div class="ofrCardList" id="...">
 *     {include file=$ofrCardListHeader sorts=$sorts sortedBy="deadline"}
 *     {foreach ...}{include file=$ofrCard ...}{/foreach}
 *   </div>
 *
 * $sorts: [key => label] in display order; each card has a sort value per key (see card.tpl).
 *   Values sort as text in natural order (so ISO dates YYYY-MM-DD sort chronologically);
 *   cards without a value come last.
 * $sortedBy: (optional) the key the cards are already sorted by on the server (ascending)
 *}
<div class="ofrCardListHeader" role="toolbar" aria-label="{translate key="plugins.generic.bookReviewManager.cards.sortBy"}">
	<span class="ofrCardListHeaderLabel">{translate key="plugins.generic.bookReviewManager.cards.sortBy"}</span>
	{foreach from=$sorts key=sortKey item=sortLabel}
		<button type="button" class="ofrCardSort" data-sort-key="{$sortKey|escape}"{if $sortKey == $sortedBy} aria-sort="ascending"{/if}>{$sortLabel|escape}</button>
	{/foreach}
</div>
