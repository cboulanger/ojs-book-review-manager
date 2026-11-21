<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/grid/ObjectsForReviewGridHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewGridHandler
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Handle ObjectsForReview grid requests.
 */

namespace APP\plugins\generic\bookReviewManager\controllers\grid;

use APP\core\Application;

use APP\plugins\generic\bookReviewManager\classes\CrossrefLookup;
use APP\plugins\generic\bookReviewManager\classes\IsbnLookup;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use APP\plugins\generic\bookReviewManager\controllers\grid\form\ObjectsForReviewForm;
use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewGridRow;
use APP\plugins\generic\bookReviewManager\controllers\grid\ObjectsForReviewGridCellProvider;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;
use PKP\core\JSONMessage;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\security\Role;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\stageAssignment\StageAssignment;

class ObjectsForReviewGridHandler extends GridHandler {
	public $plugin;

	/** @var boolean */
	var $_readOnly;

	/**
	 * Constructor
	 */
	function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;

		$this->addRoleAssignment(
			array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_AUTHOR),
			array('fetchGrid', 'fetchRow', 'addObjectForReview', 'editObjectForReview', 'updateObjectForReview', 'deleteObjectForReview','addReservedObjectForReview', 'lookupDoi', 'lookupIsbn')
		);
	}

	//
	// Getters/Setters
	//

	/**
	 * Get the submission associated with this grid.
	 * @return Submission
	 */
	function getSubmission() {
		return $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
	}

	/**
	 * Get whether or not this grid should be 'read only'
	 * @return boolean
	 */
	function getReadOnly() {
		return $this->_readOnly;
	}

	/**
	 * Set the boolean for 'read only' status
	 * @param boolean
	 */
	function setReadOnly($readOnly) {
		$this->_readOnly = $readOnly;
	}

	//
	// Overridden template methods
	//

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * @copydoc Gridhandler::initialize()
	 */
	function initialize($request, $args = null) {
		parent::initialize($request, $args);

		$submission = $this->getSubmission();
		$submissionId = $submission->getId();

		// Set the grid details.
		$this->setTitle('plugins.generic.bookReviewManager.bookReviewManagerTitle');
		$this->setEmptyRowText('plugins.generic.bookReviewManager.noneCreated');

		// Get the items and add the data to the grid
		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$bookReviewManager = $objectForReviewDao->getBySubmissionId($submissionId);

		$gridData = array();
		while ($objectForReview = $bookReviewManager->next()) {
			// A review essay is one row; its citation lists the works
			if ($objectForReview->isBundleMember()) {
				continue;
			}
			$objectId = $objectForReview->getId();
			$gridData[$objectId] = array(
				'identifierType' => $objectForReview->getIdentifierType(),
				'identifier' => $objectForReview->getIdentifier(),
				'description' => $objectForReview->getCitation(),
				'reviewEssay' => $objectForReview->isReviewEssay()
			);
		}

		$this->setGridDataElements($gridData);

		if ($this->canAdminister($request->getUser())) {
			$this->setReadOnly(false);
			// Add grid-level actions
			$router = $request->getRouter();
			$this->addAction(
				new LinkAction(
					'addObjectForReview',
					new AjaxModal(
						$router->url($request, null, null, 'addObjectForReview', null, array('submissionId' => $submissionId)),
						__('plugins.generic.bookReviewManager.addObjectForReview'),
						'modal_add_item'
					),
					__('plugins.generic.bookReviewManager.addObjectForReview'),
					'add_item'
				)
			);
		} else {
			$this->setReadOnly(true);
		}

		// Columns
		$cellProvider = new ObjectsForReviewGridCellProvider();
		$this->addColumn(new GridColumn(
			'description',
			'plugins.generic.bookReviewManager.itemDescription',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider,
			array('html' => true)
		));
		$this->addColumn(new GridColumn(
			'identifierType',
			'plugins.generic.bookReviewManager.itemIdentifierType',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider
		));
		$this->addColumn(new GridColumn(
			'identifier',
			'plugins.generic.bookReviewManager.itemIdentifier',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider
		));

	}

	//
	// Overridden methods from GridHandler
	//
	/**
	 * @copydoc Gridhandler::getRowInstance()
	 */
	function getRowInstance() {
		return new ObjectsForReviewGridRow($this->getReadOnly());
	}

	/**
	 * @copydoc GridHandler::getJSHandler()
	 */
	public function getJSHandler() {
		return '$.pkp.plugins.generic.bookReviewManager.ObjectsForReviewGridHandler';
	}

	//
	// Public Grid Actions
	//

	/**
	 * An action to add a new ObjectForReview item
	 * @param $args array Arguments to the request
	 * @param $request PKPRequest
	 */
	function addObjectForReview($args, $request) {
		// Calling editObjectForReview with an empty ID will add
		// a new ObjectsForReview item.
		return $this->editObjectForReview($args, $request);
	}

	/**
	 * An action to edit a objectForReview
	 * @param $args array Arguments to the request
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function editObjectForReview($args, $request) {
		if (!$this->canAdminister($request->getUser())) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();
		$submission = $this->getSubmission();
		$submissionId = $submission->getId();
		if (!$this->isEditable($objectId, $submissionId)) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}

		$this->setupTemplate($request);

		// Create and present the edit form
		$bookReviewManagerForm = new ObjectsForReviewForm($this->plugin, $context->getId(), $submissionId, $objectId);
		$bookReviewManagerForm->initData();
		return new JSONMessage(true, $bookReviewManagerForm->fetch($request));
	}

	/**
	 * Update an objectForReview
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function updateObjectForReview($args, $request) {
		if (!$this->canAdminister($request->getUser())) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();
		$submission = $this->getSubmission();
		$submissionId = $submission->getId();
		if (!$this->isEditable($objectId, $submissionId)) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}

		$this->setupTemplate($request);

		$bookReviewManagerForm = new ObjectsForReviewForm($this->plugin, $context->getId(), $submissionId, $objectId);
		$bookReviewManagerForm->readInputData();
		if ($bookReviewManagerForm->validate()) {
			$savedObjectId = $bookReviewManagerForm->execute();

			$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
			$objectForReview = $objectForReviewDao->getById($savedObjectId, $submissionId);

			$json = DAO::getDataChangedEvent($submissionId);
			if (!$objectId) {
				$json->setGlobalEvent('plugin:bookReviewManager:added', $this->plugin->getObjectForReviewData($objectForReview));
			} else {
				$json->setGlobalEvent('plugin:bookReviewManager:edited', $this->plugin->getObjectForReviewData($objectForReview));
			}
			return $json;
		} else {
			return new JSONMessage(true, $bookReviewManagerForm->fetch($request));
		}
	}

	/**
	 * Delete a objectForReview
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function deleteObjectForReview($args, $request) {
		$objectId = $request->getUserVar('objectId');
		$submission = $this->getSubmission();
		$submissionId = $submission->getId();

		if (!$request->checkCSRF() || !$this->canAdminister($request->getUser())) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}

		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$objectForReview = $objectForReviewDao->getById($objectId, $submissionId);
		// The works of a review essay are removed with the essay
		if (!$objectForReview || $objectForReview->isBundleMember()) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}

		// If object is created by the manager, do not delete, just remove from submission
		if ($objectForReview->getCreator() == "manager") {
			AssignmentWorkflow::detach($objectForReview);
		} else {
			$objectForReviewDao->deleteObject($objectForReview);
		}

		$json = DAO::getDataChangedEvent($submissionId);
		$json->setGlobalEvent('plugin:bookReviewManager:deleted', ['id' => (int) $objectId]);
		return $json;
	}

	/**
	 * Save form values into the database
	 */
	function addReservedObjectForReview($args, $request) {
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();
		$submission = $this->getSubmission();
		$submissionId = $submission->getId();
		$currentUser = $request->getUser();

		if (!$request->checkCSRF() || !$this->canAdminister($currentUser)) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}

		$objectForReviewDao = DAORegistry::getDAO('BookReviewManagerObjectDAO');
		$objectForReview = $objectForReviewDao->getById((int) $objectId);

		// Only objects reserved by the current user, in this context, not yet attached to a submission
		if (!$objectForReview
			|| $currentUser->getId() != $objectForReview->getUserId()
			|| $objectForReview->getContextId() != $context->getId()
			|| $objectForReview->getSubmissionId()
			|| $objectForReview->isBundleMember()) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($objectForReview->getId(), $currentUser->getId(), [Assignment::STATUS_ACCEPTED]);
		if (!$assignment) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		// Sets the submission, erases shipping address and phone (no longer needed) and logs the event
		AssignmentWorkflow::attach($objectForReview, $assignment, $currentUser, $submissionId);

		$json = DAO::getDataChangedEvent($submissionId);
		$json->setGlobalEvent('plugin:bookReviewManager:added', $this->plugin->getObjectForReviewData($objectForReview));
		return $json;
	}

	/**
	 * Can the object be edited in the form? A new one can; a review essay and its works
	 * cannot (the editor manages them on the plugin's pages).
	 * @param $objectId mixed Object ID (empty for a new object)
	 */
	protected function isEditable($objectId, int $submissionId): bool {
		if (!$objectId) {
			return true;
		}
		$objectForReview = DAORegistry::getDAO('BookReviewManagerObjectDAO')->getById((int) $objectId, $submissionId);
		return $objectForReview && !$objectForReview->isReviewEssay() && !$objectForReview->isBundleMember();
	}

	/**
	 * Determines if there should be add/edit actions on this grid.
	 * @param $user User
	 * @return boolean
	 */
	function canAdminister($user) {
		$submission = $this->getSubmission();

		if (!$user) return false;

		// Incomplete submissions can be edited. (Presumably author.)
		// Since OJS 3.5 dateSubmitted is already set when the submission is created;
		// submissionProgress is emptied only when the submission is actually submitted.
		if ($submission->getData('submissionProgress')) return true;

		// Managers should always have access.
		$userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		if (array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $userRoles)) return true;

		// Sub editors and assistants need to be assigned to the current stage.
		if (StageAssignment::withSubmissionIds([$submission->getId()])
			->withStageIds([$submission->getData('stageId')])
			->withUserId($user->getId())
			->withRoleIds([Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])
			->exists()) {
			return true;
		}

		// Default: Read-only.
		return false;
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
