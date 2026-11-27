{**
 * plugins/generic/bookReviewManager/templates/frontend/workAccess.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The publisher's page of a work and its open access status (only if known).
 * $work: ['publisherUrl' => ?string (entered by the editor), 'openAccess' => ?string]
 *}
{if $work.publisherUrl || $work.openAccess == 'open' || $work.openAccess == 'partial'}
	<span class="ofrWorkAccess">
		{if $work.publisherUrl}<a href="{$work.publisherUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.bookReviewManager.forReview.workUrl"}</a>{/if}
		{if $work.openAccess == 'open'}<span class="ofrOpenAccess">{translate key="plugins.generic.bookReviewManager.forReview.openAccess"}</span>
		{elseif $work.openAccess == 'partial'}<span class="ofrOpenAccess ofrOpenAccessPartial">{translate key="plugins.generic.bookReviewManager.forReview.openAccessPartial"}</span>{/if}
	</span>
{/if}
