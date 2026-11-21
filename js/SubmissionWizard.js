/**
 * @file plugins/generic/bookReviewManager/js/SubmissionWizard.js
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2000-2016 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionWizard
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Add information about objects for review to the root component of the submission
 *   wizard UI and keep the data in sync as objects are added, edited and removed
 */
(function() {
    if (typeof pkp === 'undefined' || typeof pkp.eventBus === 'undefined') {
        return;
    }

    var root;
    pkp.eventBus.$on('root:mounted', function(id, component) {
        root = component;
    });
    pkp.eventBus.$on('plugin:bookReviewManager:added', function(data) {
        root.bookReviewManager.push(data);
    });
    pkp.eventBus.$on('plugin:bookReviewManager:edited', function(data) {
        root.bookReviewManager = root.bookReviewManager.map(function(objectForReview) {
            if (data.id === objectForReview.id) {
                return data;
            }
            return objectForReview;
        });
    });
    pkp.eventBus.$on('plugin:bookReviewManager:deleted', function(data) {
        root.bookReviewManager = root.bookReviewManager.filter(function(objectForReview) {
            return data.id !== objectForReview.id;
        });
    });
}());