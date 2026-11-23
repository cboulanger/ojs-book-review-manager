<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/ReservationActionsHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationActionsHandler
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Modals and actions of the book review editor's pages: email a
 *  reviewer, change the due date of a review, cancel a contract offer or reservation, select a bidder (contract
 *  offer), notify unsuccessful bidders, reopen bidding, switch to direct
 *  reservation, close or reopen an offer, and show the audit trail.
 */

namespace APP\plugins\generic\bookReviewManager\controllers;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\classes\Roles;
use APP\plugins\generic\bookReviewManager\classes\CopyWorkflow;
use APP\plugins\generic\bookReviewManager\controllers\form\CopyStepForm;
use APP\plugins\generic\bookReviewManager\controllers\form\ReservationEmailForm;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class ReservationActionsHandler extends Handler {

	/** Component name used in URLs */
	const COMPONENT = 'plugins.generic.bookReviewManager.controllers.ReservationActionsHandler';

	/** Form modes: [show op, save op, allowed assignment statuses] */
	const FORM_OPS = [
		'emailForm' => ReservationEmailForm::MODE_MESSAGE,
		'sendEmail' => ReservationEmailForm::MODE_MESSAGE,
		'cancelForm' => ReservationEmailForm::MODE_CANCEL,
		'cancel' => ReservationEmailForm::MODE_CANCEL,
		'contractOfferForm' => ReservationEmailForm::MODE_CONTRACT_OFFER,
		'offerContract' => ReservationEmailForm::MODE_CONTRACT_OFFER,
		'unsuccessfulForm' => ReservationEmailForm::MODE_UNSUCCESSFUL,
		'notifyUnsuccessful' => ReservationEmailForm::MODE_UNSUCCESSFUL,
		'invitationForm' => ReservationEmailForm::MODE_INVITATION,
		'sendInvitation' => ReservationEmailForm::MODE_INVITATION,
		'roundForm' => ReservationEmailForm::MODE_ROUND,
		'startRound' => ReservationEmailForm::MODE_ROUND,
		'dueDateForm' => ReservationEmailForm::MODE_DUE_DATE,
		'changeDueDate' => ReservationEmailForm::MODE_DUE_DATE,
	];

	const OFFER_ACTIONS = ['reopenBidding', 'switchToDirect', 'closeOffer', 'reopenOffer'];

	/** @var \APP\plugins\generic\bookReviewManager\BookReviewManagerPlugin */
	public $plugin;

	public function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;
		$this->addRoleAssignment(
			[Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR],
			array_merge(array_keys(self::FORM_OPS), self::OFFER_ACTIONS, ['history', 'copyStepForm', 'copyStep'])
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 * Section editors need to be book review editors.
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		if (!parent::authorize($request, $args, $roleAssignments)) {
			return false;
		}
		$userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		return Roles::canManage($this->plugin, $request->getContext()->getId(), $request->getUser()?->getId(), $userRoles);
	}

	public function emailForm($args, $request) { return $this->showForm($request, 'emailForm'); }
	public function sendEmail($args, $request) { return $this->saveForm($request, 'sendEmail'); }
	public function cancelForm($args, $request) { return $this->showForm($request, 'cancelForm'); }
	public function cancel($args, $request) { return $this->saveForm($request, 'cancel'); }
	public function contractOfferForm($args, $request) { return $this->showForm($request, 'contractOfferForm'); }
	public function offerContract($args, $request) { return $this->saveForm($request, 'offerContract'); }
	public function unsuccessfulForm($args, $request) { return $this->showForm($request, 'unsuccessfulForm'); }
	public function notifyUnsuccessful($args, $request) { return $this->saveForm($request, 'notifyUnsuccessful'); }
	public function invitationForm($args, $request) { return $this->showForm($request, 'invitationForm'); }
	public function sendInvitation($args, $request) { return $this->saveForm($request, 'sendInvitation'); }
	public function roundForm($args, $request) { return $this->showForm($request, 'roundForm'); }
	public function startRound($args, $request) { return $this->saveForm($request, 'startRound'); }
	public function dueDateForm($args, $request) { return $this->showForm($request, 'dueDateForm'); }
	public function changeDueDate($args, $request) { return $this->saveForm($request, 'changeDueDate'); }

	public function reopenBidding($args, $request) { return $this->offerAction($request, 'reopenBidding'); }
	public function switchToDirect($args, $request) { return $this->offerAction($request, 'switchToDirect'); }
	public function closeOffer($args, $request) { return $this->offerAction($request, 'closeOffer'); }
	public function reopenOffer($args, $request) { return $this->offerAction($request, 'reopenOffer'); }

	/**
	 * A review copy step (or the publisher notification), see CopyStepForm.
	 */
	public function copyStepForm($args, $request) {
		[$object, $assignment, $step] = $this->getCopyStepTarget($request);
		if (!$object) {
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
		}
		ReservationMailer::ensureTemplates($this->plugin, $request->getContext()->getId());
		$form = new CopyStepForm($this->plugin, $request->getContext(), $object, $assignment, $step);
		$form->initData();
		return new JSONMessage(true, $form->fetch($request));
	}

	public function copyStep($args, $request) {
		[$object, $assignment, $step] = $this->getCopyStepTarget($request);
		if (!$object || !$request->checkCSRF()) {
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
		}
		$form = new CopyStepForm($this->plugin, $request->getContext(), $object, $assignment, $step);
		$form->readInputData();
		if (!$form->validate()) {
			return new JSONMessage(true, $form->fetch($request));
		}
		$form->execute();
		return DAO::getDataChangedEvent($object->getId());
	}

	/**
	 * @return array [ObjectForReview|null, Assignment|null, step]
	 */
	protected function getCopyStepTarget($request): array {
		$object = $this->getObject($request);
		$step = (string) $request->getUserVar('step');
		$assignmentId = (int) ($request->getUserVar('assignmentId') ?: ((array) $request->getUserVar('assignmentIds'))[0] ?? 0);
		$assignment = $object ? AssignmentWorkflow::dao()->getById($assignmentId) : null;
		if (!$assignment || $assignment->getObjectId() !== $object->getId()) {
			return [null, null, $step];
		}
		$allowed = $step === CopyStepForm::NOTIFY_PUBLISHER
			? self::canNotifyPublisher($object, $assignment)
			: in_array($step, CopyWorkflow::getAllowedSteps($this->plugin, $object, $assignment), true);
		return $allowed ? [$object, $assignment, $step] : [null, null, $step];
	}

	/**
	 * The review was published and the publisher wants to know.
	 */
	public static function canNotifyPublisher($object, Assignment $assignment): bool {
		return $assignment->getStatus() === Assignment::STATUS_COMPLETED
			&& $object->getData('notifyPublisher') && $object->getPublisherContactId() && !$object->getData('publisherNotified');
	}

	/**
	 * Audit trail of an object (all bidders and reviewers, past and current).
	 */
	public function history($args, $request) {
		$object = $this->getObject($request);
		if (!$object) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'objectDescription' => $object->getCitation(),
			'history' => ReservationLog::getHistory($object),
		]);
		return new JSONMessage(true, $templateMgr->fetch($this->plugin->getTemplateResource('reservationHistory.tpl')));
	}

	protected function showForm($request, string $op) {
		[$object, $assignments] = $this->getTarget($request, self::FORM_OPS[$op]);
		if (!$object) {
			return new JSONMessage(false, __(in_array(self::FORM_OPS[$op], [ReservationEmailForm::MODE_INVITATION, ReservationEmailForm::MODE_ROUND], true)
				? 'plugins.generic.bookReviewManager.desk.notPossible' : 'plugins.generic.bookReviewManager.reservations.notReserved'));
		}
		ReservationMailer::ensureTemplates($this->plugin, $request->getContext()->getId());
		$form = new ReservationEmailForm($this->plugin, $request->getContext(), $object, $assignments, self::FORM_OPS[$op]);
		$form->initData();
		return new JSONMessage(true, $form->fetch($request));
	}

	protected function saveForm($request, string $op) {
		[$object, $assignments] = $this->getTarget($request, self::FORM_OPS[$op]);
		if (!$object || !$request->checkCSRF()) {
			return new JSONMessage(false, __(in_array(self::FORM_OPS[$op], [ReservationEmailForm::MODE_INVITATION, ReservationEmailForm::MODE_ROUND], true)
				? 'plugins.generic.bookReviewManager.desk.notPossible' : 'plugins.generic.bookReviewManager.reservations.notReserved'));
		}
		$form = new ReservationEmailForm($this->plugin, $request->getContext(), $object, $assignments, self::FORM_OPS[$op]);
		$form->readInputData();
		if (!$form->validate()) {
			return new JSONMessage(true, $form->fetch($request));
		}
		$form->execute();
		return DAO::getDataChangedEvent($object->getId());
	}

	/**
	 * Offer-level actions confirmed in a dialog (RemoteActionConfirmationModal).
	 */
	protected function offerAction($request, string $action) {
		$object = $this->getObject($request);
		if (!$object || !$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		if ($object->isBundleMember()) {
			// A work of a review essay is offered through its essay
			return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
		}
		$user = $request->getUser();
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		$state = AssignmentWorkflow::getState($object, $assignments);
		$selecting = in_array($state, [AssignmentWorkflow::STATE_OPEN, AssignmentWorkflow::STATE_SELECTION], true);
		switch ($action) {
			case 'reopenBidding':
				if (!$selecting) {
					return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
				}
				$deadline = $object->getData('bidDeadline');
				AssignmentWorkflow::reopenBidding($object, $user, $deadline && $deadline >= date('Y-m-d') ? $deadline : null);
				break;
			case 'switchToDirect':
				if (!$selecting) {
					return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
				}
				AssignmentWorkflow::switchToDirect($object, $user);
				break;
			case 'closeOffer':
				if (!$selecting) {
					return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
				}
				AssignmentWorkflow::closeOffer($object, $user);
				break;
			case 'reopenOffer':
				if ($state !== AssignmentWorkflow::STATE_CLOSED) {
					return new JSONMessage(false, __('plugins.generic.bookReviewManager.desk.notPossible'));
				}
				AssignmentWorkflow::reopenOffer($object, $user);
				break;
		}
		return DAO::getDataChangedEvent($object->getId());
	}

	/**
	 * The object and the assignments a form acts on, if the step is allowed.
	 * @return array [ObjectForReview|null, Assignment[]]
	 */
	protected function getTarget($request, string $mode): array {
		$object = $this->getObject($request);
		if (!$object) {
			return [null, []];
		}
		$all = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		$state = AssignmentWorkflow::getState($object, $all);

		if ($mode === ReservationEmailForm::MODE_ROUND) {
			// Everyone waiting on the list, in order (the first is invited now)
			return InvitationWorkflow::canStartRound($object, $all) ? [$object, InvitationWorkflow::getWaiting($object)] : [null, []];
		}

		if ($mode === ReservationEmailForm::MODE_UNSUCCESSFUL) {
			// Remaining bidders, once someone has the review
			$bidders = AssignmentWorkflow::filter($all, [Assignment::STATUS_BID]);
			$requested = array_map('intval', (array) $request->getUserVar('assignmentIds'));
			if ($requested) {
				$bidders = array_values(array_filter($bidders, fn ($a) => in_array($a->getId(), $requested, true)));
			}
			$hasReviewer = in_array($state, [AssignmentWorkflow::STATE_ASSIGNED, AssignmentWorkflow::STATE_SUBMITTED], true);
			return $bidders && $hasReviewer ? [$object, $bidders] : [null, []];
		}

		$assignmentId = (int) ($request->getUserVar('assignmentId') ?: ((array) $request->getUserVar('assignmentIds'))[0] ?? 0);
		$assignment = null;
		foreach ($all as $candidate) {
			if ($candidate->getId() === $assignmentId) {
				$assignment = $candidate;
			}
		}
		if (!$assignment || $assignment->isMirror()) {
			// A mirror follows the essay's reviewer assignment and is not acted on by itself
			return [null, []];
		}
		$allowed = match ($mode) {
			ReservationEmailForm::MODE_CONTRACT_OFFER => $assignment->isBidder()
				&& in_array($state, [AssignmentWorkflow::STATE_OPEN, AssignmentWorkflow::STATE_SELECTION], true),
			ReservationEmailForm::MODE_INVITATION => in_array($assignment->getStatus(), InvitationWorkflow::SENDABLE_STATUSES, true)
				&& InvitationWorkflow::canInvite($object, $all) && !InvitationWorkflow::isRoundActive($object),
			ReservationEmailForm::MODE_DUE_DATE => $assignment->getStatus() === Assignment::STATUS_ACCEPTED,
			default => in_array($assignment->getStatus(), [Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED], true),
		};
		return $allowed ? [$object, [$assignment]] : [null, []];
	}

	/**
	 * The requested object of the current journal.
	 */
	protected function getObject($request) {
		$object = DAORegistry::getDAO('BookReviewManagerObjectDAO')->getById((int) $request->getUserVar('objectId'));
		return $object && $object->getContextId() == $request->getContext()->getId() ? $object : null;
	}
}
