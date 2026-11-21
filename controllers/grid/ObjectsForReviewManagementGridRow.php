<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/ObjectsForReviewManagementGridRow.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewManagementGridRow
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Handle ObjectsForReview management grid row requests.
 */

namespace APP\plugins\generic\bookReviewManager\controllers\grid;

use PKP\controllers\grid\GridRow;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\linkAction\request\RemoteActionConfirmationModal;

class ObjectsForReviewManagementGridRow extends GridRow {

	/**
	 * Constructor
	 */
	function __construct($readOnly = false) {
		parent::__construct();
	}

	//
	// Overridden template methods
	//
	/**
	 * @copydoc GridRow::initialize()
	 */
	function initialize($request, $template = null) {
		parent::initialize($request, $template);
		$objectId = $this->getId();

		if (!empty($objectId)) {
			$router = $request->getRouter();
			$data = $this->getData();
			// container (review essay) | member (work of a review essay) | null
			$essayRole = $data['essayRole'] ?? null;

			// Create the "edit" action (a review essay has its own form)
			$this->addAction(
				new LinkAction(
					'edit',
					new AjaxModal(
						$router->url($request, null, null, $essayRole === 'container' ? 'editReviewEssay' : 'editAvailableObjectForReview', null, array('objectId' => $objectId)),
						__('grid.action.edit'),
						'modal_edit',
						true),
					__('grid.action.edit'),
					'edit'
				)
			);

			if ($essayRole === 'container') {
				// Add a work to the review essay
				$this->addAction(
					new LinkAction(
						'addWork',
						new AjaxModal(
							$router->url($request, null, null, 'editBundleMember', null, ['containerId' => $objectId]),
							__('plugins.generic.bookReviewManager.reviewEssay.addWork'),
							'modal_add_item'),
						__('plugins.generic.bookReviewManager.reviewEssay.addWork'),
						'add_item'
					)
				);
				// Dissolve the review essay (BundleWorkflow refuses it with a reviewer)
				$this->addAction(
					new LinkAction(
						'dissolve',
						new RemoteActionConfirmationModal(
							$request->getSession(),
							__('plugins.generic.bookReviewManager.reviewEssay.dissolve.confirm'),
							__('plugins.generic.bookReviewManager.reviewEssay.dissolve'),
							$router->url($request, null, null, 'dissolveReviewEssay', null, ['objectId' => $objectId]), 'modal_delete'
						),
						__('plugins.generic.bookReviewManager.reviewEssay.dissolve'),
						'delete'
					)
				);
			} elseif ($essayRole === 'member') {
				$this->addAction(
					new LinkAction(
						'removeFromReviewEssay',
						new RemoteActionConfirmationModal(
							$request->getSession(),
							__('plugins.generic.bookReviewManager.reviewEssay.remove.confirm'),
							__('plugins.generic.bookReviewManager.reviewEssay.remove'),
							$router->url($request, null, null, 'removeFromReviewEssay', null, ['objectId' => $objectId]), 'modal_delete'
						),
						__('plugins.generic.bookReviewManager.reviewEssay.remove'),
						'delete'
					)
				);
			} else {
				// Add the work to a review essay
				$this->addAction(
					new LinkAction(
						'addToReviewEssay',
						new AjaxModal(
							$router->url($request, null, null, 'editBundleMember', null, ['workId' => $objectId]),
							__('plugins.generic.bookReviewManager.reviewEssay.addToEssay'),
							'modal_add_item'),
						__('plugins.generic.bookReviewManager.reviewEssay.addToEssay'),
						'add_item'
					)
				);
			}

			if ($essayRole !== 'member') {
				// Invitation page (InvitationWorkflow)
				$this->addAction(
					new LinkAction(
						'invite',
						new RedirectAction($request->getDispatcher()->url($request, \APP\core\Application::ROUTE_PAGE, null,
							\APP\plugins\generic\bookReviewManager\pages\ObjectsForReviewManagementHandler::PAGE, 'invite', [$objectId])),
						__('plugins.generic.bookReviewManager.invitation.inviteReviewers'),
						'invite'
					)
				);

				// Hide from the public list, or show again
				$hidden = !empty($data['hidden']);
				$label = __($hidden ? 'plugins.generic.bookReviewManager.show' : 'plugins.generic.bookReviewManager.hide');
				$this->addAction(
					new LinkAction(
						'toggleHidden',
						new AjaxAction(
							$router->url($request, null, null, 'toggleHidden', null, ['objectId' => $objectId]),
							AjaxAction::AJAX_REQUEST_TYPE_POST,
							['csrfToken' => $request->getSession()->token()]
						),
						$label,
						$hidden ? 'unhide' : 'hide'
					)
				);
			}

			// Audit trail of the object's reservations
			$this->addAction(
				new LinkAction(
					'history',
					new AjaxModal(
						$request->getDispatcher()->url($request, \APP\core\Application::ROUTE_COMPONENT, null,
							\APP\plugins\generic\bookReviewManager\controllers\ReservationActionsHandler::COMPONENT, 'history', null, ['objectId' => $objectId]),
						__('plugins.generic.bookReviewManager.history'),
						'modal_information'),
					__('plugins.generic.bookReviewManager.history'),
					'history'
				)
			);

			// Create the "delete" action (a review essay is dissolved first, a work is first removed from its essay)
			$confirmKey = match ($essayRole) {
				'container' => 'plugins.generic.bookReviewManager.reviewEssay.delete.confirmEssay',
				'member' => 'plugins.generic.bookReviewManager.reviewEssay.delete.confirmWork',
				default => 'common.confirmDelete',
			};
			$this->addAction(
				new LinkAction(
					'delete',
					new RemoteActionConfirmationModal(
						$request->getSession(),
						__($confirmKey),
						__('grid.action.delete'),
						$router->url($request, null, null, 'deleteAvailableObjectForReview', null, array('objectId' => $objectId)), 'modal_delete'
					),
					__('grid.action.delete'),
					'delete'
				)
			);
		}
	}
}

?>
