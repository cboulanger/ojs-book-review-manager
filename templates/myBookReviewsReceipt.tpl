{**
 * plugins/generic/bookReviewManager/templates/myBookReviewsReceipt.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * "Copy received" button on the backend page "My book reviews".
 *
 * $objectId: the object (or the work of a review essay) whose copy arrived
 *}
<form method="post" action="{url page=$publicPage op="confirmReceipt"}">
	{csrf}
	<input type="hidden" name="objectId" value="{$objectId|escape}" />
	<input type="hidden" name="returnTo" value="myBookReviews" />
	<button type="submit" class="pkp_button">{translate key="plugins.generic.bookReviewManager.copy.confirmReceipt"}</button>
</form>
