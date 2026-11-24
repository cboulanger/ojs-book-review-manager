{**
 * plugins/generic/bookReviewManager/templates/card.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * One card of a card list (see cardListHeader.tpl). Its parts are HTML, usually made with
 * {capture}; empty parts are left out:
 *
 * $title: what the card is about (e.g. the work), full width at the top
 * $facts: "small" content, laid out side by side and wrapping as needed. Each fact:
 *   <div class="ofrFact"><div class="ofrFactLabel">Label</div>value</div>
 * $details: "big" content (e.g. lists), full width below the facts. Optional heading:
 *   <div class="ofrFactLabel">Label</div>
 * $actions: links/buttons, in a column on the right (below the card on narrow screens)
 * $sortValues: [key => plain-text value] for the keys of the list's header
 * $cardId: (optional) id attribute
 *}
<article class="ofrCard"{if $cardId} id="{$cardId|escape}"{/if}{foreach from=$sortValues key=sortKey item=sortValue} data-sort-{$sortKey|lower|escape}="{$sortValue|strip_tags|escape}"{/foreach}>
	<div class="ofrCardMain">
		{if $title}<div class="ofrCardTitle">{$title}</div>{/if}
		{if $facts}<div class="ofrCardFacts">{$facts}</div>{/if}
		{if $details}<div class="ofrCardDetails">{$details}</div>{/if}
	</div>
	{if $actions}<div class="ofrCardActions ofrActions">{$actions}</div>{/if}
</article>
