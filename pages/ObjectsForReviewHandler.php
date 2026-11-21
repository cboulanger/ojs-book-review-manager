<?php

/**
 * @file plugins/generic/bookReviewManager/pages/ObjectsForReviewHandler.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewHandler
 * @ingroup plugins_generic_bookReviewManager
 * @brief Reader-facing pages: the list of offers (reserve, bid, withdraw,
 *  cancel), the contract page for selected bidders and the one-click links.
 */

namespace APP\plugins\generic\bookReviewManager\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\bookReviewManager\classes\ActionLink;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\CopyWorkflow;
use APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow;
use APP\plugins\generic\bookReviewManager\classes\MyReviews;
use APP\plugins\generic\bookReviewManager\classes\Reservation;
use APP\plugins\generic\bookReviewManager\classes\ReservationEmailEventType;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class ObjectsForReviewHandler extends Handler {

	/** Page path of the public page */
	const PAGE = 'books-for-review';

	/** Page path used before the plugin was renamed (links in emails sent by the objectsForReview fork) */
	const LEGACY_PAGE = 'for-review';

	const CONTRACT_INPUT = ['acceptTerms', 'dueDate', 'copyPreference', 'copyPreferences', 'shippingAddress', 'phone', 'copyNote'];
	const MAX_STATEMENT_LENGTH = 2000;

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextRequiredPolicy($request));
		return parent::authorize($request, $args, $roleAssignments);
	}

	protected function plugin() {
		return PluginRegistry::getPlugin('generic', 'BookReviewManagerPlugin');
	}

	/**
	 * View objects for review
	 *
	 * Request var "reserve" (object ID) opens the reservation dialog for that object.
	 *
	 * @param $reserveState array Reservation form state after a failed submission (objectId, errors, input)
	 */
	public function index($args, $request, array $reserveState = []) {
		$context = $request->getContext();
		$contextId = $context->getId();
		$plugin = $this->plugin();
		$templateMgr = TemplateManager::getManager($request);
		$currentUser = $request->getUser();

		$assignmentsByObject = AssignmentWorkflow::dao()->getByContextId($contextId);
		$bookReviewManager = DAORegistry::getDAO('BookReviewManagerObjectDAO')->getAll($contextId, true, true);
		$gridData = [];
		while ($objectForReview = $bookReviewManager->next()) {
			// The works of a review essay are listed under the essay
			if ($objectForReview->getData('offerClosed') || $objectForReview->isBundleMember()) {
				continue;
			}
			$objectId = $objectForReview->getId();
			$assignments = $assignmentsByObject[$objectId] ?? [];
			$mine = null;
			if ($currentUser) {
				foreach (array_reverse($assignments) as $assignment) {
					if ($assignment->getUserId() == $currentUser->getId()) {
						$mine = $assignment;
						break;
					}
				}
			}
			$myStatus = $mine?->getStatus();
			$myActiveStatus = in_array($myStatus, [Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED], true) ? $myStatus : null;
			// Hidden offers are only listed for the people who have a bid, offer or reservation
			$hidden = $objectForReview->isHidden();
			if ($hidden && !$myActiveStatus) {
				continue;
			}
			$members = $this->memberRows($objectForReview, $currentUser);
			// A review essay without works is not offered
			if ($objectForReview->isReviewEssay() && !$members) {
				continue;
			}
			$gridData[$objectId] = [
				'objectId' => $objectId,
				'identifierType' => $objectForReview->getIdentifierType(),
				'identifier' => $objectForReview->getIdentifier(),
				'identifierUrl' => $objectForReview->getIdentifierUrl(),
				'authors' => $objectForReview->getAuthors(),
				'title' => $objectForReview->getTitle(),
				'publisher' => $objectForReview->getPublisher(),
				'year' => $objectForReview->getYear(),
				'citation' => $objectForReview->getCitation(),
				'description' => $objectForReview->getDescription(),
				'reviewEssay' => $objectForReview->isReviewEssay(),
				'state' => AssignmentWorkflow::getState($objectForReview, $assignments),
				'bidding' => $objectForReview->isBidding(),
				'biddingOpen' => !$hidden && AssignmentWorkflow::isBiddingOpen($objectForReview, $assignments),
				'bidDeadline' => $objectForReview->getData('bidDeadline'),
				'reservable' => !$hidden && AssignmentWorkflow::isReservable($objectForReview, $assignments),
				// Only shown to the user concerned
				'myStatus' => $myActiveStatus,
				'myDate' => $mine?->getDateCreated(),
				'dueDate' => $myStatus === Assignment::STATUS_ACCEPTED ? $mine->getData('dueDate') : null,
				'contractDeadline' => $myStatus === Assignment::STATUS_OFFERED ? $mine->getData('contractDeadline') : null,
				// For a review essay, the copy status is that of each work (members)
				'copyStatus' => $myStatus === Assignment::STATUS_ACCEPTED && !$objectForReview->isReviewEssay() ? CopyWorkflow::getStatus($mine) : null,
				// The works of a review essay (the reservation dialog asks for a copy preference per work),
				// with the current user's review copy status of each work
				'members' => $members,
			];
		}

		$ofrInstructions = (array) $plugin->getSetting($contextId, 'ofrInstructions');
		$instructions = ($ofrInstructions[Locale::getLocale()] ?? null) ?: ($ofrInstructions[$context->getPrimaryLocale()] ?? '');

		// Reservation dialog: opened after a failed submission or via ?reserve=<objectId>
		$openObjectId = (int) ($reserveState['objectId'] ?? $request->getUserVar('reserve'));
		if ($openObjectId && (!isset($gridData[$openObjectId]) || !$gridData[$openObjectId]['reservable'] || !$currentUser)) {
			$openObjectId = 0;
		}
		$templateMgr->assign($this->contractTemplateData($request, $reserveState) + [
			'bookReviewManager' => $gridData,
			'currentUser' => $currentUser,
			// "Your book reviews": open invitations, contract offers and reservations
			'myReviews' => $currentUser ? MyReviews::rows($contextId, $currentUser->getId(), MyReviews::PUBLIC_STATUSES) : [],
			'instructions' => $instructions,
			'reserveDialogTemplate' => $plugin->getTemplateResource('frontend/reserveDialog.tpl'),
			'reserveOpenObjectId' => $openObjectId,
			'maxStatementLength' => self::MAX_STATEMENT_LENGTH,
		]);
		return $templateMgr->display($plugin->getTemplateResource('frontend/pages/forReview.tpl'));
	}

	/**
	 * Reserve an object (direct reservation mode; submission of the reservation dialog).
	 */
	function reserveObject($args, $request) {
		[$object, $user, $context] = $this->requirePost($request);
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		if ($object->isHidden() || !AssignmentWorkflow::isReservable($object, $assignments)) {
			$request->redirect(null, self::PAGE);
		}
		$input = $this->contractInput($request);
		[$errors, $data] = Reservation::validate($input, Reservation::getDueDateRange($this->plugin(), $context->getId()),
			BundleWorkflow::membersOf($object));
		if ($errors) {
			return $this->index($args, $request, ['objectId' => $object->getId(), 'errors' => $errors, 'input' => $input]);
		}
		AssignmentWorkflow::reserve($this->plugin(), $context, $object, $user, $data);
		$request->redirect(null, self::PAGE);
	}

	/**
	 * The reviewer cancels their reservation (retracts).
	 */
	function cancelObject($args, $request) {
		[$object, $user] = $this->requirePost($request);
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(), [Assignment::STATUS_ACCEPTED]);
		if ($assignment) {
			AssignmentWorkflow::retract($this->plugin(), $object, $assignment, $user);
		}
		$this->redirectBack($request);
	}

	/**
	 * The reviewer confirms that the review copy has arrived.
	 */
	function confirmReceipt($args, $request) {
		// Also on a work of a review essay: the assignment is the reviewer's mirror on the work
		[$object, $user, $context] = $this->requirePost($request, true);
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(), [Assignment::STATUS_ACCEPTED]);
		if ($assignment && CopyWorkflow::getStatus($assignment) === CopyWorkflow::SENT) {
			CopyWorkflow::confirmReceipt($this->plugin(), $context, $object, $assignment, $user);
		}
		$this->redirectBack($request);
	}

	/**
	 * Bid for an object (bidding mode), with an optional statement.
	 */
	function bid($args, $request) {
		[$object, $user, $context] = $this->requirePost($request);
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		$hasBid = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(),
			array_merge(Assignment::BIDDER_STATUSES, [Assignment::STATUS_OFFERED], Assignment::REVIEWER_STATUSES));
		if (!$object->isHidden() && AssignmentWorkflow::isBiddingOpen($object, $assignments) && !$hasBid) {
			$statement = mb_substr(trim((string) $request->getUserVar('statement')), 0, self::MAX_STATEMENT_LENGTH);
			AssignmentWorkflow::bid($this->plugin(), $context, $object, $user, $statement);
		}
		$request->redirect(null, self::PAGE);
	}

	/**
	 * Withdraw one's bid (also from the list of unsuccessful bidders).
	 */
	function withdrawBid($args, $request) {
		[$object, $user] = $this->requirePost($request);
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(), Assignment::BIDDER_STATUSES);
		if ($assignment) {
			AssignmentWorkflow::withdrawBid($object, $assignment, $user);
		}
		$request->redirect(null, self::PAGE);
	}

	/**
	 * Contract page for a logged-in bidder who has been selected (alternative to the email link).
	 */
	function contract($args, $request) {
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
		$assignment = $object ? AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $request->getUser()->getId(), [Assignment::STATUS_OFFERED]) : null;
		return $this->showResponsePage($request, $assignment ? 'contract' : 'invalid', $object, $assignment);
	}

	/**
	 * The invitation page for a logged-in invitee (from "Your book reviews"),
	 * the same as behind the invitation email's link.
	 */
	function invitation($args, $request) {
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
		$assignment = $object ? AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $request->getUser()->getId(), [Assignment::STATUS_INVITED]) : null;
		if (!$assignment) {
			return $this->showResponsePage($request, 'invalid');
		}
		return $this->showInvitationPage($request, $object, $assignment, '');
	}

	/**
	 * One-click link from an email: shows what will happen and a confirmation button.
	 * Opening the link never changes anything.
	 */
	function link($args, $request) {
		[$link, $assignment, $object] = $this->resolveLink($request, (string) ($args[0] ?? ''));
		if (!$link) {
			return $this->showResponsePage($request, 'invalid');
		}
		if ($link->action === ActionLink::ACTION_INVITATION) {
			return $this->showInvitationPage($request, $object, $assignment, (string) $args[0]);
		}
		$view = match ($link->action) {
			ActionLink::ACTION_CONTRACT => 'contract',
			ActionLink::ACTION_LEAVE => 'leave',
			default => 'confirm',
		};
		return $this->showResponsePage($request, $view, $object, $assignment, ['key' => $args[0], 'linkAction' => $link->action]);
	}

	/**
	 * Answer to a contract offer (from the link page or the logged-in contract page).
	 */
	function respond($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			$request->redirect(null, self::PAGE);
		}
		$context = $request->getContext();
		$key = (string) $request->getUserVar('key');
		$link = null;
		if ($key !== '') {
			[$link, $assignment, $object] = $this->resolveLink($request, $key);
			if (!$link) {
				return $this->showResponsePage($request, 'invalid');
			}
		} else {
			if (!$request->getUser()) {
				Validation::redirectLogin();
			}
			$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
			$assignment = $object ? AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $request->getUser()->getId(),
				[Assignment::STATUS_OFFERED, Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_INVITED]) : null;
		}
		if (!$assignment) {
			return $this->showResponsePage($request, 'invalid');
		}
		$extra = $key !== '' ? ['key' => $key] : [];
		$decision = (string) $request->getUserVar('decision');

		// From the invitation email's link, or from the logged-in invitation page
		if ($link?->action === ActionLink::ACTION_INVITATION || (!$link && $assignment->getStatus() === Assignment::STATUS_INVITED)) {
			return $this->respondToInvitation($request, $object, $assignment, $key, $decision);
		}
		if ($link && in_array($link->action, array_keys(self::COPY_LINK_STEPS), true) || $link?->action === ActionLink::ACTION_RETRACT) {
			return $this->confirmLink($request, $link, $object, $assignment);
		}
		if ($decision === 'leave' && $assignment->isBidder()) {
			AssignmentWorkflow::withdrawBid($object, $assignment, Repo::user()->get($assignment->getUserId(), true));
			$link && ActionLink::markUsed((int) $link->link_id);
			return $this->showResponsePage($request, 'left', $object, $assignment);
		}
		if ($assignment->getStatus() !== Assignment::STATUS_OFFERED) {
			return $this->showResponsePage($request, 'invalid');
		}
		if ($decision === 'decline') {
			$reason = mb_substr(trim((string) $request->getUserVar('declineReason')), 0, self::MAX_STATEMENT_LENGTH);
			AssignmentWorkflow::declineContract($this->plugin(), $context, $object, $assignment, $reason);
			return $this->showResponsePage($request, 'declined', $object, $assignment);
		}
		$input = $this->contractInput($request);
		[$errors, $data] = Reservation::validate($input, Reservation::getDueDateRange($this->plugin(), $context->getId()),
			BundleWorkflow::membersOf($object));
		if ($errors) {
			return $this->showResponsePage($request, 'contract', $object, $assignment, $extra, ['errors' => $errors, 'input' => $input]);
		}
		AssignmentWorkflow::acceptContract($this->plugin(), $context, $object, $assignment, $data);
		return $this->showResponsePage($request, 'accepted', $object, $assignment);
	}

	/**
	 * The invitation page: reserve (direct reservation) or apply (bidding), or decline.
	 */
	protected function showInvitationPage($request, $object, Assignment $assignment, string $key, array $formState = []) {
		if (!InvitationWorkflow::isAnswerable($object, $assignment)) {
			return $this->showResponsePage($request, 'invitationClosed', $object, $assignment);
		}
		$editor = $assignment->getData('invitedBy') ? Repo::user()->get((int) $assignment->getData('invitedBy'), true) : null;
		return $this->showResponsePage($request, 'invitation', $object, $assignment, [
			'key' => $key,
			'kind' => InvitationWorkflow::getKind($object),
			'invitationDeadline' => $assignment->getData('invitationDeadline'),
			'editorName' => $editor?->getFullName(),
			'statement' => $formState['statement'] ?? '',
		], $formState);
	}

	/**
	 * Answer to an invitation (POST from the invitation page).
	 */
	protected function respondToInvitation($request, $object, Assignment $assignment, string $key, string $decision) {
		$plugin = $this->plugin();
		$context = $request->getContext();
		if (!InvitationWorkflow::isAnswerable($object, $assignment)) {
			return $this->showResponsePage($request, 'invitationClosed', $object, $assignment);
		}
		if ($decision === 'decline') {
			$reason = mb_substr(trim((string) $request->getUserVar('declineReason')), 0, self::MAX_STATEMENT_LENGTH);
			InvitationWorkflow::decline($plugin, $context, $object, $assignment, $reason);
			return $this->showResponsePage($request, 'invitationDeclined', $object, $assignment);
		}
		if ($object->isBidding()) {
			$statement = mb_substr(trim((string) $request->getUserVar('statement')), 0, self::MAX_STATEMENT_LENGTH);
			InvitationWorkflow::bid($plugin, $context, $object, $assignment, $statement);
			return $this->showResponsePage($request, 'applied', $object, $assignment);
		}
		$input = $this->contractInput($request);
		[$errors, $data] = Reservation::validate($input, Reservation::getDueDateRange($plugin, $context->getId()),
			BundleWorkflow::membersOf($object));
		if ($errors) {
			return $this->showInvitationPage($request, $object, $assignment, $key, ['errors' => $errors, 'input' => $input]);
		}
		InvitationWorkflow::reserve($plugin, $context, $object, $assignment, $data);
		return $this->showResponsePage($request, 'accepted', $object, $assignment);
	}

	/** Copy steps taken through one-click links: link action => [copy step, who uses the link] */
	const COPY_LINK_STEPS = [
		ActionLink::ACTION_COPY_SENT => ['publisherSent', 'publisher'],
		ActionLink::ACTION_RECEIVED => ['markReceived', 'reviewer'],
		ActionLink::ACTION_RETURN_SENT => ['returnSent', 'reviewer'],
		ActionLink::ACTION_RETURN_RECEIVED => ['returned', 'publisher'],
	];

	/**
	 * Confirmation of a copy step or retraction through a one-click link.
	 */
	protected function confirmLink($request, object $link, $object, Assignment $assignment) {
		$plugin = $this->plugin();
		$context = $request->getContext();
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ActionLink::markUsed((int) $link->link_id);
		if ($link->action === ActionLink::ACTION_RETRACT) {
			AssignmentWorkflow::retract($plugin, $object, $assignment, $reviewer, true);
			return $this->showResponsePage($request, 'done', $object, $assignment, ['linkAction' => $link->action]);
		}
		[$step, $who] = self::COPY_LINK_STEPS[$link->action];
		$actor = $who === 'publisher' ? CopyWorkflow::getPublisherContact($object) : $reviewer;
		CopyWorkflow::apply($plugin, $context, $object, $assignment, $step, $actor, [], true);
		// Tell the next person, with their one-click link
		if ($step === 'publisherSent' && $reviewer) {
			CopyWorkflow::sendAutomatic($plugin, $context, $object, $assignment, ReservationEmailEventType::COPY_SENT, $reviewer, $step);
		}
		if ($step === 'returnSent' && $assignment->getData('returnTarget') === CopyWorkflow::BY_PUBLISHER
				&& ($contact = CopyWorkflow::getPublisherContact($object))) {
			CopyWorkflow::sendAutomatic($plugin, $context, $object, $assignment, ReservationEmailEventType::RETURN_SENT, $contact, $step);
		}
		return $this->showResponsePage($request, 'done', $object, $assignment, ['linkAction' => $link->action]);
	}

	//
	// Helpers
	//

	/**
	 * @return array [link row, Assignment, ObjectForReview] or [null, null, null]
	 */
	protected function resolveLink($request, string $key): array {
		$link = ActionLink::find($key);
		$assignment = $link ? AssignmentWorkflow::dao()->getById((int) $link->assignment_id) : null;
		$object = $assignment ? $this->getObject($request, $assignment->getObjectId()) : null;
		if (!$object) {
			return [null, null, null];
		}
		$copyStatus = CopyWorkflow::getStatus($assignment);
		$valid = match ($link->action) {
			ActionLink::ACTION_CONTRACT => $assignment->getStatus() === Assignment::STATUS_OFFERED,
			ActionLink::ACTION_LEAVE => $assignment->isBidder(),
			ActionLink::ACTION_RETRACT => $assignment->getStatus() === Assignment::STATUS_ACCEPTED,
			// Whether it can still be answered is shown on the page (showInvitationPage)
			ActionLink::ACTION_INVITATION => $assignment->getStatus() === Assignment::STATUS_INVITED,
			ActionLink::ACTION_COPY_SENT => $copyStatus === CopyWorkflow::REQUESTED && $object->getPublisherContactId(),
			ActionLink::ACTION_RECEIVED => $copyStatus === CopyWorkflow::SENT,
			ActionLink::ACTION_RETURN_SENT => $copyStatus === CopyWorkflow::RETURN_REQUESTED,
			ActionLink::ACTION_RETURN_RECEIVED => in_array($copyStatus, [CopyWorkflow::RETURN_REQUESTED, CopyWorkflow::RETURN_SENT], true)
				&& $assignment->getData('returnTarget') === CopyWorkflow::BY_PUBLISHER && $object->getPublisherContactId(),
			default => false,
		};
		return $valid ? [$link, $assignment, $object] : [null, null, null];
	}

	/**
	 * @param string $view contract | leave | confirm | done | invalid | accepted | declined | left
	 */
	protected function showResponsePage($request, string $view, $object = null, ?Assignment $assignment = null,
			array $extra = [], array $formState = []) {
		$plugin = $this->plugin();
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign($this->contractTemplateData($request, $formState, $object) + $extra + [
			'view' => $view,
			'objectId' => $object?->getId(),
			'objectDescription' => $object?->getCitation(),
			'reviewerName' => $assignment ? Repo::user()->get($assignment->getUserId(), true)?->getFullName() : null,
			'publisherName' => $object ? CopyWorkflow::getPublisherContact($object)?->getFullName() : null,
			'contractDeadline' => $assignment?->getData('contractDeadline'),
			'dueDate' => $assignment?->getData('dueDate'),
			'contractFieldsTemplate' => $plugin->getTemplateResource('frontend/contractFields.tpl'),
			'maxStatementLength' => self::MAX_STATEMENT_LENGTH,
		]);
		return $templateMgr->display($plugin->getTemplateResource('frontend/pages/respond.tpl'));
	}

	/**
	 * Template data for the contract fields (reservation dialog and contract page).
	 * @param ?ObjectForReview $object The object of the contract: for a review
	 *  essay, the copy preference is asked per work (on the list page, the
	 *  dialog takes the works from the object's row instead)
	 */
	protected function contractTemplateData($request, array $formState, $object = null): array {
		$plugin = $this->plugin();
		$context = $request->getContext();
		$dueDateRange = Reservation::getDueDateRange($plugin, $context->getId());
		return [
			'reserveContract' => Reservation::getContract($plugin, $context),
			'reserveDueDate' => $dueDateRange,
			'reserveErrors' => $formState['errors'] ?? [],
			'reserveInput' => ($formState['input'] ?? []) + ['dueDate' => $dueDateRange['default'], 'copyPreference' => '', 'copyPreferences' => []],
			'reserveMembers' => $object ? $this->memberRows($object) : [],
			'contractFieldsTemplate' => $plugin->getTemplateResource('frontend/contractFields.tpl'),
		];
	}

	/**
	 * The works of a review essay for the list and the contract fields ([] for any other object).
	 * @param ?\PKP\user\User $user With a user who has an accepted assignment (the mirror) on a work: copyStatus is that work's copy status
	 * @return array [['id' => ..., 'citation' => ..., 'identifierUrl' => ..., 'copyStatus' => ...], ...]
	 */
	protected function memberRows($object, $user = null): array {
		return array_map(function ($member) use ($user) {
			$mirror = $user ? AssignmentWorkflow::dao()->getByObjectAndUser($member->getId(), $user->getId(), [Assignment::STATUS_ACCEPTED]) : null;
			return [
				'id' => $member->getId(),
				'citation' => $member->getCitation(),
				'identifierUrl' => $member->getIdentifierUrl(),
				'copyStatus' => $mirror ? CopyWorkflow::getStatus($mirror) : null,
			];
		}, BundleWorkflow::membersOf($object));
	}

	protected function contractInput($request): array {
		$input = [];
		foreach (self::CONTRACT_INPUT as $name) {
			$input[$name] = $request->getUserVar($name);
		}
		$input['copyPreferences'] = is_array($input['copyPreferences']) ? $input['copyPreferences'] : [];
		return $input;
	}

	/**
	 * An object of the current journal.
	 */
	protected function getObject($request, int $objectId) {
		$object = $objectId ? DAORegistry::getDAO('BookReviewManagerObjectDAO')->getById($objectId) : null;
		return $object && $object->getContextId() == $request->getContext()->getId() ? $object : null;
	}

	/**
	 * After a POST action of the list page: back to the list, or to the
	 * backend page "My book reviews" when the action was taken there.
	 */
	protected function redirectBack($request): void {
		if ($request->getUserVar('returnTo') === MyBookReviewsHandler::PAGE) {
			$request->redirect(null, MyBookReviewsHandler::PAGE);
		}
		$request->redirect(null, self::PAGE);
	}

	/**
	 * Checks for the POST actions of logged-in users on the list page.
	 * @param bool $allowMember Allow a work of a review essay (otherwise only the essay can be acted on)
	 * @return array [ObjectForReview, User, Context]
	 */
	protected function requirePost($request, bool $allowMember = false): array {
		if (!$request->checkCSRF()) fatalError('CSRF check failed, please try again!');
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
		// The offer of a work of a review essay is that of its essay
		$offer = $object && $object->isBundleMember() ? (BundleWorkflow::getContainer($object) ?? $object) : $object;
		if (!$object || $object->getSubmissionId() || $offer->getData('offerClosed') || (!$allowMember && $object->isBundleMember())) {
			$request->redirect(null, self::PAGE);
		}
		return [$object, $request->getUser(), $request->getContext()];
	}
}
