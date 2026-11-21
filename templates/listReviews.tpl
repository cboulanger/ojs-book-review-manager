{**
 * plugins/generic/bookReviewManager/templates/listReviews.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * The included template that is hooked into Templates::Article::Main and Templates::Catalog::Book::Main.
 *}
<div class="item bookReviewManager">
	<div class="value">
    {if $bookReviewManager|@count == 1}
  		<h3>{translate key="plugins.generic.bookReviewManager.bookReviewManagerData.singular"}</h3>
    {else}
      <h3>{translate key="plugins.generic.bookReviewManager.bookReviewManagerData.plural"}</h3>
    {/if}
		<ul>
			{foreach from=$bookReviewManager item=objectForReview}
				<li>
          {$objectForReview.description|strip_unsafe_html}
          {$objectForReview.identifierType|escape}:
          {if $objectForReview.identifierUrl}
            <a href="{$objectForReview.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$objectForReview.identifier|escape}</a>
          {else}
            {$objectForReview.identifier|escape}
          {/if}
				</li>
			{/foreach}
		</ul>
	</div>
</div>
