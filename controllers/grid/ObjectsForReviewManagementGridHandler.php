<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/ObjectsForReviewManagementGridHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewManagementGridHandler
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Handle ObjectsForReview management grid requests.
 */


namespace APP\plugins\generic\bookReviewManager\controllers\grid;

use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\classes\Roles;
use APP\core\Application;
use APP\plugins\generic\bookReviewManager\classes\CrossrefLookup;
use APP\plugins\generic\bookReviewManager\classes\IsbnLookup;

use APP\plugins\generic\bookReviewManager\controllers\grid\form\AvailableObjectsForReviewForm;
use APP\plugins\generic\bookReviewManager\controllers\grid\form\BundleMemberForm;
use APP\plugins\generic\bookReviewManager\controllers\grid\form\ReviewEssayForm;
use APP\plugins\generic\bookReviewManager\controllers\grid\form\ObjectsForReviewForm;

use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewManagementGridRow;
use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewManagementGridCellProvider;

use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;

use APP\facades\Repo;

use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\Notification;
use APP\notification\NotificationManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class ObjectsForReviewManagementGridHandler extends GridHandler {
	public $plugin;

	/**
	 * Constructor
	 */
	function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;
		$this->addRoleAssignment(
			array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR),
			array('fetchGrid', 'fetchRow', 'addAvailableObjectForReview', 'editAvailableObjectForReview', 'updateAvailableObjectForReview', 'deleteAvailableObjectForReview', 'toggleHidden', 'lookupDoi', 'lookupIsbn',
				'addReviewEssay', 'editReviewEssay', 'updateReviewEssay', 'editBundleMember', 'updateBundleMember', 'dissolveReviewEssay', 'removeFromReviewEssay')
		);
	}

	//
	// Getters/Setters
	//

	//
	// Overridden template methods
	//
 	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		if (!parent::authorize($request, $args, $roleAssignments)) {
			return false;
		}
		// Section editors need to be book review editors
		$userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		return Roles::canManage($this->plugin, $request->getContext()->getId(), $request->getUser()?->getId(), $userRoles);
	}

	/**
	 * @copydoc Gridhandler::initialize()
	 */
	function initialize($request, $args = null) {
		parent::initialize($request, $args);
		$context = $request->getContext();

		// Set the grid details.
		$this->setTitle('plugins.generic.bookReviewManager.management.gridTitle');
		$this->setEmptyRowText('plugins.generic.bookReviewManager.management.noneCreated');

		// Get the items and add the data to the grid
		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$bookReviewManager = $objectForReviewDao->getAll($context->getId(), true, true);
		$assignmentsByObject = AssignmentWorkflow::dao()->getByContextId($context->getId());

		// Each review essay is followed by its works. A work is always listed with its essay:
		// syncMembers gives the works the essay's submission, so neither or both are listed.
		$objects = [];
		while ($objectForReview = $bookReviewManager->next()) {
			$objects[$objectForReview->getId()] = $objectForReview;
		}
		$gridData = array();
		foreach ($objects as $objectId => $objectForReview) {
			if (isset($gridData[$objectId])) {
				continue;
			}
			if ($objectForReview->isBundleMember() && BundleWorkflow::getContainer($objectForReview)) {
				continue;
			}
			$gridData[$objectId] = $this->getRowData($objectForReview, $assignmentsByObject, null);
			foreach (BundleWorkflow::membersOf($objectForReview) as $member) {
				$gridData[$member->getId()] = $this->getRowData($member, $assignmentsByObject, $objectForReview);
			}
		}

		$this->setGridDataElements($gridData);

		// Add grid-level actions
		$router = $request->getRouter();
		$this->addAction(
			new LinkAction(
				'addAvailableObjectForReview',
				new AjaxModal(
					$router->url($request, null, null, 'addAvailableObjectForReview', null, array()),
					__('plugins.generic.bookReviewManager.addAvailableObjectForReview'),
					'modal_add_item'
				),
				__('plugins.generic.bookReviewManager.addAvailableObjectForReview'),
				'add_item'
			)
		);
		$this->addAction(
			new LinkAction(
				'addReviewEssay',
				new AjaxModal(
					$router->url($request, null, null, 'addReviewEssay', null, array()),
					__('plugins.generic.bookReviewManager.reviewEssay.create'),
					'modal_add_item'
				),
				__('plugins.generic.bookReviewManager.reviewEssay.create'),
				'add_item'
			)
		);

		// Columns
		$cellProvider = new ObjectsForReviewManagementGridCellProvider();
		// The cell provider escapes the description (strip_unsafe_html) and adds the review essay markup
		$this->addColumn(new GridColumn(
			'description',
			'plugins.generic.bookReviewManager.itemDescription',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider,
			array('anyhtml' => true)
		));
		$this->addColumn(new GridColumn(
			'user',
			'common.status',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider
		));

	}

	/**
	 * Data of a row.
	 * @param array $assignmentsByObject object id => assignments
	 * @param ?ObjectForReview $container The review essay of a work (listed below it)
	 */
	protected function getRowData(ObjectForReview $objectForReview, array $assignmentsByObject, ?ObjectForReview $container): array {
		$assignments = $assignmentsByObject[$objectForReview->getId()] ?? [];
		$state = AssignmentWorkflow::getState($objectForReview, $assignments);
		$userName = __('plugins.generic.bookReviewManager.offerState.' . $state);
		if ($objectForReview->getUserId() && ($user = Repo::user()->get($objectForReview->getUserId(), true))) {
			$userName .= ': ' . $user->getFullName() . ' (' . $user->getEmail() . ')';
		} elseif (!$container && $objectForReview->isBidding() && in_array($state, [AssignmentWorkflow::STATE_OPEN, AssignmentWorkflow::STATE_SELECTION], true)) {
			$userName .= ' (' . __('plugins.generic.bookReviewManager.offerState.bidCount',
				['number' => count(AssignmentWorkflow::filter($assignments, Assignment::BIDDER_STATUSES))]) . ')';
		}
		// A work of an essay is offered through its essay: the essay's row shows bids and whether it is hidden
		if (!$container && $objectForReview->isHidden()) {
			$userName .= ' · ' . __('plugins.generic.bookReviewManager.hidden');
		}

		return array(
			'identifierType' => $objectForReview->getIdentifierType(),
			'identifier' => $objectForReview->getIdentifier(),
			'identifierHtml' => $objectForReview->getIdentifierHtml(),
			'description' => $objectForReview->getCitation(),
			'user' => $userName,
			'hidden' => $objectForReview->isHidden(),
			// container | member | null
			'essayRole' => $objectForReview->isReviewEssay() ? 'container' : ($container ? 'member' : null),
		);
	}

	//
	// Overridden methods from GridHandler
	//
	/**
	 * @copydoc Gridhandler::getRowInstance()
	 */
	function getRowInstance() {
		return new ObjectsForReviewManagementGridRow();
	}

	//
	// Public Grid Actions
	//

	/**
	 * An action to add a new available ObjectForReview item
	 * @param $args array Arguments to the request
	 * @param $request PKPRequest
	 */
	function addAvailableObjectForReview($args, $request) {
		// Calling editObjectForReview with an empty ID will add
		// a new ObjectsForReview item.
		return $this->editAvailableObjectForReview($args, $request);
	}

	/**
	 * An action to edit an available objectForReview
	 * @param $args array Arguments to the request
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function editAvailableObjectForReview($args, $request) {
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();
		if ($objectId && ($object = $this->getObject($request)) && $object->isReviewEssay()) {
			return $this->editReviewEssay($args, $request);
		}

		$this->setupTemplate($request);

        $availableObjectsForReviewForm = new AvailableObjectsForReviewForm($this->plugin, $context->getId(), $objectId);
        $availableObjectsForReviewForm->initData();
        return new JSONMessage(true, $availableObjectsForReviewForm->fetch($request));

	}

	/**
	 * Update a objectForReview
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function updateAvailableObjectForReview($args, $request) {
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();
		if ($objectId && ($object = $this->getObject($request)) && $object->isReviewEssay()) {
			// Review essays are saved by updateReviewEssay
			return new JSONMessage(false);
		}

		$this->setupTemplate($request);

		// Create and populate the form
		$availableObjectsForReviewForm = new AvailableObjectsForReviewForm($this->plugin, $context->getId(), $objectId);
		$availableObjectsForReviewForm->readInputData();
		// Validate
		if ($availableObjectsForReviewForm->validate()) {
			// Save
			$bookReviewManager = $availableObjectsForReviewForm->execute();
 			return DAO::getDataChangedEvent($objectId);
		} else {
			// Present any errors
			return new JSONMessage(true, $availableObjectsForReviewForm->fetch($request));
		}
	}

	/**
	 * Delete a objectForReview
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function deleteAvailableObjectForReview($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$objectForReview = $objectForReviewDao->getById((int) $request->getUserVar('objectId'));
		// Only objects of the current journal
		if (!$objectForReview || $objectForReview->getContextId() != $request->getContext()->getId()) {
			return new JSONMessage(false);
		}
		try {
			if ($objectForReview->isReviewEssay()) {
				// Dissolving deletes the container (refused if it has a reviewer)
				BundleWorkflow::dissolve($objectForReview, $request->getUser());
				return DAO::getDataChangedEvent();
			}
			if ($container = BundleWorkflow::getContainer($objectForReview)) {
				// Refused for the last work of the essay
				BundleWorkflow::removeMember($container, $objectForReview, $request->getUser());
				ReservationMailer::sendEssayChanged($this->plugin, $request->getContext(), $container, $request->getUser());
				$objectForReview = $objectForReviewDao->getById($objectForReview->getId());
			}
		} catch (\InvalidArgumentException $e) {
			return new JSONMessage(false, __($e->getMessage()));
		}
		$objectForReviewDao->deleteObject($objectForReview);
		return DAO::getDataChangedEvent();
	}

	/**
	 * Hide an object from the public list, or show it again.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function toggleHidden($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$object = (new ObjectForReviewDAO())->getById((int) $request->getUserVar('objectId'));
		if (!$object || $object->getContextId() != $request->getContext()->getId()) {
			return new JSONMessage(false);
		}
		AssignmentWorkflow::setHidden($object, !$object->isHidden(), $request->getUser());
		return DAO::getDataChangedEvent($object->getId());
	}

	//
	// Review essays (BundleWorkflow)
	//

	/**
	 * An object of the current journal from a request variable.
	 */
	protected function getObject(PKPRequest $request, string $name = 'objectId'): ?ObjectForReview {
		$object = (new ObjectForReviewDAO())->getById((int) $request->getUserVar($name));
		return $object && $object->getContextId() == $request->getContext()->getId() ? $object : null;
	}

	/**
	 * Create a review essay.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function addReviewEssay($args, $request) {
		return $this->editReviewEssay($args, $request);
	}

	/**
	 * Create a review essay (without objectId) or edit one.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function editReviewEssay($args, $request) {
		$container = null;
		if ($request->getUserVar('objectId')) {
			$container = $this->getObject($request);
			if (!$container || !$container->isReviewEssay()) {
				return new JSONMessage(false);
			}
		}
		$this->setupTemplate($request);
		$form = new ReviewEssayForm($this->plugin, $request->getContext(), $container);
		$form->initData();
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * Save a review essay.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function updateReviewEssay($args, $request) {
		$container = null;
		if ($request->getUserVar('objectId')) {
			$container = $this->getObject($request);
			if (!$container || !$container->isReviewEssay()) {
				return new JSONMessage(false);
			}
		}
		$this->setupTemplate($request);
		$form = new ReviewEssayForm($this->plugin, $request->getContext(), $container);
		$form->readInputData();
		if ($form->validate()) {
			try {
				$saved = $form->execute();
			} catch (\InvalidArgumentException $e) {
				$form->addError('objectIds', __($e->getMessage()));
				return new JSONMessage(true, $form->fetch($request));
			}
			$message = __($container ? 'plugins.generic.bookReviewManager.reviewEssay.saved' : 'plugins.generic.bookReviewManager.reviewEssay.created');
			$this->notify($request, $message, $form->reviewerEmailed);
			return DAO::getDataChangedEvent($saved->getId());
		}
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * Add a work to a review essay: with containerId choose the work, with workId choose the essay.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function editBundleMember($args, $request) {
		$form = $this->getBundleMemberForm($request);
		if (!$form) {
			return new JSONMessage(false);
		}
		$this->setupTemplate($request);
		$form->initData();
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * Save the work added to a review essay.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function updateBundleMember($args, $request) {
		$form = $this->getBundleMemberForm($request);
		if (!$form) {
			return new JSONMessage(false);
		}
		$this->setupTemplate($request);
		$form->readInputData();
		if ($form->validate()) {
			try {
				$form->execute();
			} catch (\InvalidArgumentException $e) {
				$form->addError($form->getMode() === BundleMemberForm::MODE_ADD ? 'workId' : 'containerId', __($e->getMessage()));
				return new JSONMessage(true, $form->fetch($request));
			}
			$this->notify($request, __('plugins.generic.bookReviewManager.reviewEssay.workAdded'), $form->reviewerEmailed);
			return DAO::getDataChangedEvent();
		}
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * The form to add a work to a review essay, from containerId (add mode) or workId (choose mode).
	 */
	protected function getBundleMemberForm(PKPRequest $request): ?BundleMemberForm {
		if ($request->getUserVar('containerId')) {
			$container = $this->getObject($request, 'containerId');
			if (!$container || !$container->isReviewEssay() || $container->getSubmissionId()) {
				return null;
			}
			return new BundleMemberForm($this->plugin, $request->getContext(), $container);
		}
		$work = $this->getObject($request, 'workId');
		if (!$work || $work->isReviewEssay()) {
			return null;
		}
		return new BundleMemberForm($this->plugin, $request->getContext(), null, $work);
	}

	/**
	 * Dissolve a review essay without a reviewer.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function dissolveReviewEssay($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$container = $this->getObject($request);
		if (!$container || !$container->isReviewEssay()) {
			return new JSONMessage(false);
		}
		try {
			BundleWorkflow::dissolve($container, $request->getUser());
		} catch (\InvalidArgumentException $e) {
			return new JSONMessage(false, __($e->getMessage()));
		}
		return DAO::getDataChangedEvent();
	}

	/**
	 * Take a work out of its review essay; the reviewer of the essay, if any, is emailed.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function removeFromReviewEssay($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$work = $this->getObject($request);
		$container = $work ? BundleWorkflow::getContainer($work) : null;
		if (!$container) {
			return new JSONMessage(false);
		}
		try {
			BundleWorkflow::removeMember($container, $work, $request->getUser());
		} catch (\InvalidArgumentException $e) {
			return new JSONMessage(false, __($e->getMessage()));
		}
		$emailed = ReservationMailer::sendEssayChanged($this->plugin, $request->getContext(), $container, $request->getUser());
		$this->notify($request, __('plugins.generic.bookReviewManager.reviewEssay.workRemoved'), $emailed);
		return DAO::getDataChangedEvent();
	}

	/**
	 * Confirm a change to a review essay to the editor (shown after the form or action).
	 */
	protected function notify(PKPRequest $request, string $message, bool $reviewerEmailed): void {
		if ($reviewerEmailed) {
			$message .= ' ' . __('plugins.generic.bookReviewManager.reviewEssay.reviewerEmailed');
		}
		(new NotificationManager())->createTrivialNotification($request->getUser()->getId(),
			Notification::NOTIFICATION_TYPE_SUCCESS, ['contents' => $message]);
	}

	/**
	 * Look up metadata for a DOI at Crossref
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function lookupDoi($args, $request) {
		return CrossrefLookup::handleRequest($request);
	}

	/**
	 * Look up metadata for an ISBN (Open Library, Crossref)
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function lookupIsbn($args, $request) {
		return IsbnLookup::handleRequest($request);
	}

}

?>
