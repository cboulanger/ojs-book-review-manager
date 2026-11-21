{**
 * plugins/generic/bookReviewManager/templates/reviewObjectsForReview.tpl
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The template to review the objects for review data in the submission wizard
 * before completing the submission
 *}
<div class="submissionWizard__reviewPanel">
    <div class="submissionWizard__reviewPanel__header">
        <h3 id="review-plugin-book-review-manager">
            {translate key="plugins.generic.bookReviewManager.submissionWizard.name"}
        </h3>
        <pkp-button
            aria-describedby="review-plugin-book-review-manager"
            class="submissionWizard__reviewPanel__edit"
            @click="openStep('{$step.id}')"
        >
            {translate key="common.edit"}
        </pkp-button>
    </div>
    <div class="submissionWizard__reviewPanel__body">
        <table class="pkpTable" valign="top">
            <thead>
                <tr>
                    <th>
                        {translate key="plugins.generic.bookReviewManager.bookReviewManagerTitle"}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="objectForReview in bookReviewManager"
                    :key="objectForReview.id"
                    class="submissionWizard__reviewPanel__item__value"
                >
                    <td style="vertical-align:top">
                        {{ objectForReview.authors ? objectForReview.authors + ': ' : '' }}{{ objectForReview.title }}{{ objectForReview.publisher ? '. ' + objectForReview.publisher : '' }}{{ objectForReview.year ? '. ' + objectForReview.year + '.' : '' }}
                        <span v-if="objectForReview.identifier"> {{ objectForReview.identifierType }}: {{ objectForReview.identifier }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>