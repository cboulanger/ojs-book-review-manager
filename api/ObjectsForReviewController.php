<?php

/**
 * @file plugins/generic/bookReviewManager/api/ObjectsForReviewController.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewController
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief REST API of the plugin, for importing data from scripts (see api/SKILL.md):
 *  works offered for review (with DOI/ISBN lookup), reviewers and publisher
 *  contacts, review processes that are already under way, and review essays
 *  (one review of several works; see BundleWorkflow).
 *
 *  Served at /index.php/{journal}/api/v1/bookReviewManager/... and authenticated
 *  like the OJS API (Authorization: Bearer <API key>). Open to journal managers,
 *  site admins and book review editors. Imports never send emails.
 */

namespace APP\plugins\generic\bookReviewManager\api;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\CopyWorkflow;
use APP\plugins\generic\bookReviewManager\classes\CrossrefLookup;
use APP\plugins\generic\bookReviewManager\classes\IsbnLookup;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\Reservation;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use APP\plugins\generic\bookReviewManager\classes\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PKP\core\Core;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\UserRolesRequiredPolicy;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\user\User;
use PKP\userGroup\relationships\UserUserGroup;
use PKP\userGroup\UserGroup;

class ObjectsForReviewController extends PKPBaseController {

	/** Roles a user can be given through the API */
	const ROLE_AUTHOR = 'author';
	const USER_ROLES = [self::ROLE_AUTHOR, Roles::PUBLISHER, Roles::EDITOR];

	/** Assignment statuses that can be imported */
	const IMPORT_STATUSES = [Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_OFFERED,
		Assignment::STATUS_ACCEPTED, Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED];

	const COPY_STATUSES = [CopyWorkflow::NOT_NEEDED, CopyWorkflow::PENDING, CopyWorkflow::REQUESTED, CopyWorkflow::SENT,
		CopyWorkflow::RECEIVED, CopyWorkflow::RETURN_REQUESTED, CopyWorkflow::RETURN_SENT, CopyWorkflow::RETURNED];

	/** COAR resource types (as in AvailableObjectsForReviewForm) */
	const RESOURCE_TYPES = [
		'http://purl.org/coar/resource_type/c_2f33', // book
		'http://purl.org/coar/resource_type/c_3248', // book part
		'http://purl.org/coar/resource_type/c_6501', // journal article
		'http://purl.org/coar/resource_type/c_5794', // conference paper
		'http://purl.org/coar/resource_type/c_46ec', // thesis
		'http://purl.org/coar/resource_type/c_816b', // preprint
		'http://purl.org/coar/resource_type/c_7ad9', // website
		'http://purl.org/coar/resource_type/c_ddb1', // dataset, software
		'http://purl.org/coar/resource_type/c_12cc', // cartographic material
		'http://purl.org/coar/resource_type/c_18cc', // sound
	];

	/** Offer settings that can be given when a review essay is created (the rest come from its first work) */
	const BUNDLE_OFFER_FIELDS = ['assignmentMode', 'bidDeadline', 'responsibleEditorId', 'responsibleEditorEmail', 'hidden'];

	/** Locale key prefix of the review essay errors of the API */
	const BUNDLE_ERROR_PREFIX = 'plugins.generic.bookReviewManager.reviewEssay.api.';

	const IDENTIFIER_TYPES = ['doi', 'issn', 'isbn', 'uri', 'urn', 'pmid', 'pmcid', 'purl', 'arxiv', 'ark', 'handle', 'uuid', 'ecli', 'accession', 'other'];

	public function __construct(protected $plugin) {
	}

	public function getHandlerPath(): string {
		return 'bookReviewManager';
	}

	public function getRouteGroupMiddleware(): array {
		return [
			'has.user',
			'has.context',
			self::roleAuthorizer([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR]),
		];
	}

	public function getGroupRoutes(): void {
		Route::get('lookup', $this->lookup(...))->name('bookReviewManager.lookup');

		Route::get('objects', $this->getObjects(...))->name('bookReviewManager.getObjects');
		Route::post('objects', $this->addObject(...))->name('bookReviewManager.addObject');
		Route::get('objects/{objectId}', $this->getObject(...))->name('bookReviewManager.getObject')->whereNumber('objectId');
		Route::put('objects/{objectId}', $this->editObject(...))->name('bookReviewManager.editObject')->whereNumber('objectId');

		Route::get('objects/{objectId}/assignments', $this->getAssignments(...))->name('bookReviewManager.getAssignments')->whereNumber('objectId');
		Route::post('objects/{objectId}/assignments', $this->addAssignment(...))->name('bookReviewManager.addAssignment')->whereNumber('objectId');

		Route::post('bundles', $this->addBundle(...))->name('bookReviewManager.addBundle');
		Route::post('bundles/{bundleId}/members', $this->addBundleMember(...))->name('bookReviewManager.addBundleMember')->whereNumber('bundleId');
		Route::delete('bundles/{bundleId}/members/{objectId}', $this->removeBundleMember(...))->name('bookReviewManager.removeBundleMember')
			->whereNumber('bundleId')->whereNumber('objectId');

		Route::get('users', $this->getUsers(...))->name('bookReviewManager.getUsers');
		Route::post('users', $this->addUser(...))->name('bookReviewManager.addUser');
	}

	public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool {
		$this->addPolicy(new UserRolesRequiredPolicy($request), true);
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	//
	// Lookup
	//

	/**
	 * GET lookup?doi=... or lookup?isbn=...: metadata of a work, nothing is saved.
	 */
	public function lookup(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		try {
			[$data] = $this->lookupMetadata($illuminateRequest->query('doi'), $illuminateRequest->query('isbn'));
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($data, Response::HTTP_OK);
	}

	//
	// Objects
	//

	/**
	 * GET objects[?identifier=...&identifierType=...]
	 */
	public function getObjects(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$identifier = $illuminateRequest->query('identifier');
		if ($identifier !== null) {
			$object = $this->findByIdentifier((string) $illuminateRequest->query('identifierType', ''), (string) $identifier);
			$objects = $object ? [$object] : [];
		} else {
			$objects = (new ObjectForReviewDAO())->getAll($this->contextId())->toArray();
		}
		return response()->json([
			'itemsMax' => count($objects),
			'items' => array_map(fn ($object) => $this->objectToArray($object), $objects),
		], Response::HTTP_OK);
	}

	public function getObject(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$object = $this->getContextObject((int) $illuminateRequest->route('objectId'));
		if (!$object) {
			return $this->error(Response::HTTP_NOT_FOUND, 'objectNotFound', 'The object for review was not found.');
		}
		return response()->json($this->objectToArray($object, true), Response::HTTP_OK);
	}

	/**
	 * POST objects: create a work offered for review.
	 * With "doi" or "isbn", the metadata is looked up; fields in the request take precedence.
	 * "onDuplicate" (error|skip|update) decides what happens if the identifier is already there.
	 */
	public function addObject(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$input = (array) $illuminateRequest->input();
		try {
			$input = $this->withLookup($input);
			$onDuplicate = $input['onDuplicate'] ?? 'error';
			if (!in_array($onDuplicate, ['error', 'skip', 'update'], true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidOnDuplicate', 'onDuplicate must be error, skip or update.');
			}
			$existing = !empty($input['identifier']) ? $this->findByIdentifier((string) ($input['identifierType'] ?? ''), (string) $input['identifier']) : null;
			if ($existing) {
				if ($onDuplicate === 'skip') {
					return response()->json($this->objectToArray($existing, true) + ['_existing' => true], Response::HTTP_OK);
				}
				if ($onDuplicate === 'error') {
					throw new ApiError(Response::HTTP_CONFLICT, 'duplicateIdentifier',
						"An object with this identifier exists already (id {$existing->getId()}).", ['objectId' => $existing->getId()]);
				}
				$this->applyObjectInput($existing, $input, false);
				(new ObjectForReviewDAO())->updateObject($existing);
				return response()->json($this->objectToArray($existing, true) + ['_existing' => true], Response::HTTP_OK);
			}

			$dao = new ObjectForReviewDAO();
			$object = $dao->newDataObject();
			$object->setContextId($this->contextId());
			$object->setCreator('manager');
			$this->applyObjectInput($object, $input, true);
			$dao->insertObject($object);
			if (!empty($input['dateCreated'])) {
				$object->setDateCreated($this->validDateTime($input['dateCreated'], 'dateCreated'));
				$dao->updateObject($object);
			}
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->objectToArray($dao->getById($object->getId()), true), Response::HTTP_OK);
	}

	/**
	 * PUT objects/{objectId}: change fields of a work (only those given).
	 */
	public function editObject(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$object = $this->getContextObject((int) $illuminateRequest->route('objectId'));
		if (!$object) {
			return $this->error(Response::HTTP_NOT_FOUND, 'objectNotFound', 'The object for review was not found.');
		}
		try {
			$this->applyObjectInput($object, (array) $illuminateRequest->input(), false);
			(new ObjectForReviewDAO())->updateObject($object);
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->objectToArray($object, true), Response::HTTP_OK);
	}

	//
	// Assignments
	//

	public function getAssignments(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$object = $this->getContextObject((int) $illuminateRequest->route('objectId'));
		if (!$object) {
			return $this->error(Response::HTTP_NOT_FOUND, 'objectNotFound', 'The object for review was not found.');
		}
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		return response()->json(array_map(fn ($a) => $this->assignmentToArray($a), $assignments), Response::HTTP_OK);
	}

	/**
	 * POST objects/{objectId}/assignments: import a bidder or reviewer whose review
	 * process is already under way (e.g. agreed by email before the plugin was used).
	 * No emails are sent and no one-click links are created.
	 */
	public function addAssignment(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$object = $this->getContextObject((int) $illuminateRequest->route('objectId'));
		if (!$object) {
			return $this->error(Response::HTTP_NOT_FOUND, 'objectNotFound', 'The object for review was not found.');
		}
		$input = (array) $illuminateRequest->input();
		try {
			$assignment = $this->importAssignment($object, $input);
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->assignmentToArray($assignment), Response::HTTP_OK);
	}

	protected function importAssignment(ObjectForReview $object, array $input): Assignment {
		$status = (string) ($input['status'] ?? '');
		if (!in_array($status, self::IMPORT_STATUSES, true)) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidStatus', 'status must be one of: ' . implode(', ', self::IMPORT_STATUSES) . '.');
		}
		// The works of a review essay are reviewed through the essay (their mirrors follow its reviewer)
		if ($object->isBundleMember()) {
			throw new ApiError(Response::HTTP_CONFLICT, 'member_of_review_essay',
				__(self::BUNDLE_ERROR_PREFIX . 'memberOfReviewEssay', ['bundleId' => $object->getBundleId()]), ['bundleId' => $object->getBundleId()]);
		}
		// "copies" lists the works of a review essay; any other work has copyPreference instead
		if (!$object->isReviewEssay() && ($input['copies'] ?? null) !== null) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopies', __(self::BUNDLE_ERROR_PREFIX . 'invalidCopies'));
		}
		$copies = $object->isReviewEssay() ? $this->bundleCopies($object, $input) : null;

		// The reviewer: by id, or by email (created as an author if givenName is given)
		if (!empty($input['userId'])) {
			$user = Repo::user()->get((int) $input['userId'], true);
			if (!$user) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'userNotFound', 'No user with id ' . (int) $input['userId'] . '.');
			}
			$this->addToGroup($user, self::ROLE_AUTHOR);
		} elseif (!empty($input['email'])) {
			[$user] = $this->ensureUser($input + ['roles' => [self::ROLE_AUTHOR]]);
		} else {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'userRequired', 'userId or email is required.');
		}

		$dao = AssignmentWorkflow::dao();
		$assignments = $dao->getByObjectId($object->getId());
		$active = [...Assignment::BIDDER_STATUSES, Assignment::STATUS_OFFERED, ...Assignment::REVIEWER_STATUSES];
		foreach ($assignments as $other) {
			if ($other->getUserId() === $user->getId() && in_array($other->getStatus(), $active, true)) {
				throw new ApiError(Response::HTTP_CONFLICT, 'duplicateAssignment',
					"The user has an assignment for this object already (id {$other->getId()}, status {$other->getStatus()}).",
					['assignmentId' => $other->getId()]);
			}
		}
		if (in_array($status, Assignment::REVIEWER_STATUSES, true) && AssignmentWorkflow::filter($assignments, Assignment::REVIEWER_STATUSES)) {
			throw new ApiError(Response::HTTP_CONFLICT, 'reviewerExists', 'The object has a reviewer already.');
		}
		if ($status === Assignment::STATUS_OFFERED && AssignmentWorkflow::filter($assignments, [Assignment::STATUS_OFFERED])) {
			throw new ApiError(Response::HTTP_CONFLICT, 'offerExists', 'A contract for this object is offered to someone else already.');
		}
		if (in_array($status, [...Assignment::BIDDER_STATUSES, Assignment::STATUS_OFFERED], true) && !$object->isBidding()) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'notBidding', "Status {$status} needs an object in bidding mode (assignmentMode: bidding).");
		}
		if (in_array($status, [Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED], true) && $object->getSubmissionId()
				&& !empty($input['submissionId']) && (int) $input['submissionId'] !== (int) $object->getSubmissionId()) {
			throw new ApiError(Response::HTTP_CONFLICT, 'submissionExists', 'The object is attached to another submission already.');
		}

		$now = Core::getCurrentDate();
		$assignment = $dao->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId($this->contextId());
		$assignment->setUserId($user->getId());
		$assignment->setStatus($status);
		$assignment->setDateCreated(!empty($input['dateCreated']) ? $this->validDateTime($input['dateCreated'], 'dateCreated') : $now);
		$assignment->setDateStatus(!empty($input['dateStatus']) ? $this->validDateTime($input['dateStatus'], 'dateStatus') : $now);

		if (in_array($status, Assignment::BIDDER_STATUSES, true) || $status === Assignment::STATUS_OFFERED) {
			if (trim((string) ($input['statement'] ?? '')) !== '') {
				$assignment->setData('statement', trim((string) $input['statement']));
			}
		}

		if ($status === Assignment::STATUS_OFFERED) {
			$deadline = $input['contractDeadline'] ?? null;
			if (!$deadline) {
				[$days] = AssignmentWorkflow::getContractDays($this->plugin, $this->contextId());
				$deadline = (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
			}
			$assignment->setData('contractDeadline', $this->validDate($deadline, 'contractDeadline'));
			$assignment->setData('dateOffered', !empty($input['dateOffered']) ? $this->validDateTime($input['dateOffered'], 'dateOffered') : $now);
		}

		if (in_array($status, Assignment::REVIEWER_STATUSES, true)) {
			if ($copies !== null) {
				$this->applyBundleReservation($assignment, $input, $status, $copies);
			} else {
				$this->applyReservation($assignment, $input, $status);
			}
		}

		if (in_array($status, [Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED], true) && !empty($input['submissionId'])) {
			$submission = Repo::submission()->get((int) $input['submissionId']);
			if (!$submission || (int) $submission->getData('contextId') !== $this->contextId()) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'submissionNotFound', 'No submission with id ' . (int) $input['submissionId'] . ' in this journal.');
			}
			$object->setSubmissionId($submission->getId());
		}

		// All or nothing: a review essay is not left half imported
		DB::transaction(function () use ($dao, $assignment, $object, $status, $copies, $user) {
			$dao->insertObject($assignment);

			// Bidding is over once someone has been chosen
			if ($object->isBidding() && ($status === Assignment::STATUS_OFFERED || in_array($status, Assignment::REVIEWER_STATUSES, true))) {
				$object->setOfferSetting('biddingClosed', true);
			}
			(new ObjectForReviewDAO())->updateObject($object);
			AssignmentWorkflow::syncReviewer($object);
			if ($copies !== null) {
				// The members get their mirrors, then the copy status of each work
				BundleWorkflow::syncMembers($object);
				$this->applyMirrorCopyStatus($assignment, $copies);
			}

			ReservationLog::logEvent($object, ReservationLog::EVENT_IMPORTED, Application::get()->getRequest()->getUser(), $user);
		});
		return $dao->getById($assignment->getId());
	}

	/**
	 * The due date of a reviewer (required for status accepted).
	 */
	protected function reservationDueDate(array $input, string $status): ?string {
		if (!empty($input['dueDate'])) {
			return $this->validDate($input['dueDate'], 'dueDate');
		}
		if ($status === Assignment::STATUS_ACCEPTED) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'dueDateRequired', 'dueDate (Y-m-d) is required for status accepted.');
		}
		return null;
	}

	/**
	 * Reservation fields of a reviewer: due date, copy preference and the state of the review copy.
	 */
	protected function applyReservation(Assignment $assignment, array $input, string $status): void {
		$data = array_fill_keys(Reservation::FIELDS, null);
		$data['dateReserved'] = $assignment->getDateCreated();
		$data['dueDate'] = $this->reservationDueDate($input, $status);

		$preference = $this->validCopyPreference($input['copyPreference'] ?? Reservation::COPY_HAVE);
		$data['copyPreference'] = $preference;
		if ($preference === Reservation::COPY_OTHER) {
			$data['copyNote'] = trim((string) ($input['copyNote'] ?? ''));
		}
		$copyStatus = $this->validCopyStatus($input['copyStatus'] ?? null);
		if ($status === Assignment::STATUS_ACCEPTED) {
			$this->applyDeliveryDetails($data, $input, [['copyPreference' => $preference, 'copyStatus' => $copyStatus]]);
		}

		Reservation::apply($assignment, $data);
		if ($copyStatus === null) {
			if ($status === Assignment::STATUS_ACCEPTED) {
				CopyWorkflow::init($assignment);
				return;
			}
			$copyStatus = CopyWorkflow::NOT_NEEDED;
		}
		$assignment->setData('copyStatus', $copyStatus);
		$assignment->setData('copyStatusDate', !empty($input['copyStatusDate']) ? $this->validDateTime($input['copyStatusDate'], 'copyStatusDate') : Core::getCurrentDate());
		if (!empty($input['returnTarget'])) {
			if (!in_array($input['returnTarget'], [CopyWorkflow::BY_JOURNAL, CopyWorkflow::BY_PUBLISHER], true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidReturnTarget', 'returnTarget must be journal or publisher.');
			}
			$assignment->setData('returnTarget', $input['returnTarget']);
		}
		if (!empty($input['copySentBy'])) {
			if (!in_array($input['copySentBy'], [CopyWorkflow::BY_JOURNAL, CopyWorkflow::BY_PUBLISHER], true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopySentBy', 'copySentBy must be journal or publisher.');
			}
			$assignment->setData('copySentBy', $input['copySentBy']);
		}
	}

	//
	// Review essays
	//

	/**
	 * POST bundles: combine works into a review essay (see BundleWorkflow). A reviewer who
	 * already has an agreement for one of the works becomes the reviewer of the essay; no
	 * email is sent. Offer settings not given are those of the first work.
	 */
	public function addBundle(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$input = (array) $illuminateRequest->input();
		try {
			$title = trim((string) ($input['title'] ?? ''));
			if ($title === '') {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'titleRequired', __('plugins.generic.bookReviewManager.reviewEssay.titleRequired'));
			}
			$description = trim((string) ($input['description'] ?? ''));
			$description = trim(strip_tags($description)) === '' ? null : $description;
			$ids = $input['objectIds'] ?? null;
			if (!is_array($ids) || !$ids) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'worksRequired', __('plugins.generic.bookReviewManager.reviewEssay.worksRequired'));
			}
			// Unknown ids (or works of another journal) are refused by canCombine as not available
			$works = array_map(fn ($id) => is_numeric($id) ? $this->getContextObject((int) $id) : null, array_values($ids));
			$copyPreferences = [];
			$objectIds = array_map(fn ($id) => is_numeric($id) ? (int) $id : null, array_values($ids));
			foreach ((array) ($input['copyPreferences'] ?? []) as $id => $preference) {
				if (!is_numeric($id) || !in_array((int) $id, $objectIds, true)) {
					throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopies', __(self::BUNDLE_ERROR_PREFIX . 'invalidCopies'));
				}
				$copyPreferences[(int) $id] = $this->validCopyPreference($preference);
			}
			if ($reason = BundleWorkflow::canCombine($works)) {
				throw $this->bundleRuleError('cannot_combine', $reason);
			}

			$offer = array_intersect_key($input, array_flip(self::BUNDLE_OFFER_FIELDS));
			$editor = $this->getRequest()->getUser();
			// Invalid offer settings undo the essay
			$container = DB::transaction(function () use ($title, $description, $works, $editor, $copyPreferences, $offer) {
				try {
					$container = BundleWorkflow::create($this->getRequest()->getContext(), $title, $description, $works, $editor, $copyPreferences);
				} catch (\InvalidArgumentException $e) {
					throw $this->bundleRuleError('cannot_combine', $e);
				}
				if ($offer) {
					$this->applyObjectInput($container, $offer, false);
					(new ObjectForReviewDAO())->updateObject($container);
				}
				return $container;
			});
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->objectToArray((new ObjectForReviewDAO())->getById($container->getId()), true), Response::HTTP_CREATED);
	}

	/**
	 * POST bundles/{bundleId}/members: add a work to a review essay that is not submitted yet.
	 * copyPreference is the reviewer's copy preference for the work, if the essay has a reviewer (default: have).
	 */
	public function addBundleMember(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$input = (array) $illuminateRequest->input();
		try {
			$container = $this->getBundle((int) $illuminateRequest->route('bundleId'));
			$work = $this->getBundleWork($input['objectId'] ?? null);
			$copyPreference = isset($input['copyPreference']) ? $this->validCopyPreference($input['copyPreference']) : null;
			try {
				BundleWorkflow::addMember($container, $work, $this->getRequest()->getUser(), $copyPreference);
			} catch (\InvalidArgumentException $e) {
				throw $this->bundleRuleError('review_essay_rule', $e);
			}
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->objectToArray((new ObjectForReviewDAO())->getById($container->getId()), true), Response::HTTP_OK);
	}

	/**
	 * DELETE bundles/{bundleId}/members/{objectId}: take a work out of a review essay that is not
	 * submitted yet. It is offered on its own again, hidden at first.
	 */
	public function removeBundleMember(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		try {
			$container = $this->getBundle((int) $illuminateRequest->route('bundleId'));
			$work = $this->getBundleWork($illuminateRequest->route('objectId'));
			try {
				BundleWorkflow::removeMember($container, $work, $this->getRequest()->getUser());
			} catch (\InvalidArgumentException $e) {
				throw $this->bundleRuleError('review_essay_rule', $e);
			}
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->objectToArray((new ObjectForReviewDAO())->getById($container->getId()), true), Response::HTTP_OK);
	}

	/**
	 * The review essay container with this id in this journal.
	 */
	protected function getBundle(int $id): ObjectForReview {
		$container = $this->getContextObject($id);
		if (!$container || !$container->isReviewEssay()) {
			throw new ApiError(Response::HTTP_NOT_FOUND, 'bundleNotFound', __(self::BUNDLE_ERROR_PREFIX . 'notFound'));
		}
		return $container;
	}

	protected function getBundleWork($id): ObjectForReview {
		$work = is_numeric($id) ? $this->getContextObject((int) $id) : null;
		if (!$work) {
			throw new ApiError(Response::HTTP_NOT_FOUND, 'objectNotFound', __(self::BUNDLE_ERROR_PREFIX . 'workNotFound'));
		}
		return $work;
	}

	/**
	 * A 409 for a review essay rule: the reason is the locale key of BundleWorkflow, the message its translation.
	 * @param \InvalidArgumentException|string $reason An exception of BundleWorkflow (others are passed on) or a locale key
	 */
	protected function bundleRuleError(string $code, \InvalidArgumentException|string $reason): ApiError {
		if ($reason instanceof \InvalidArgumentException) {
			if (!str_starts_with($reason->getMessage(), BundleWorkflow::ERROR_PREFIX)) {
				throw $reason;
			}
			$reason = $reason->getMessage();
		}
		return new ApiError(Response::HTTP_CONFLICT, $code, __($reason), ['reason' => $reason]);
	}

	protected function validCopyPreference($preference): string {
		if (!is_string($preference) || !in_array($preference, Reservation::COPY_PREFERENCES, true)) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopyPreference',
				__(self::BUNDLE_ERROR_PREFIX . 'invalidCopyPreference', ['values' => implode(', ', Reservation::COPY_PREFERENCES)]));
		}
		return $preference;
	}

	/**
	 * The "copies" of an assignment imported for a review essay, one per work (default: have).
	 * @return array Work id => [copyPreference, copyStatus (or null), copyStatusDate (or null)]
	 */
	protected function bundleCopies(ObjectForReview $container, array $input): array {
		$copies = [];
		foreach (BundleWorkflow::membersOf($container) as $member) {
			$copies[(int) $member->getId()] = ['copyPreference' => Reservation::COPY_HAVE, 'copyStatus' => null, 'copyStatusDate' => null];
		}
		$given = $input['copies'] ?? [];
		if (!is_array($given) || !array_is_list($given)) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopies', __(self::BUNDLE_ERROR_PREFIX . 'invalidCopies'));
		}
		$seen = [];
		foreach ($given as $entry) {
			$id = is_array($entry) && is_numeric($entry['objectId'] ?? null) ? (int) $entry['objectId'] : null;
			if ($id === null || !isset($copies[$id]) || isset($seen[$id])) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopies', __(self::BUNDLE_ERROR_PREFIX . 'invalidCopies'));
			}
			$seen[$id] = true;
			$copyStatus = $this->validCopyStatus($entry['copyStatus'] ?? null);
			$copies[$id] = [
				'copyPreference' => $this->validCopyPreference($entry['copyPreference'] ?? Reservation::COPY_HAVE),
				'copyStatus' => $copyStatus,
				'copyStatusDate' => $copyStatus !== null && !empty($entry['copyStatusDate'])
					? $this->validDateTime($entry['copyStatusDate'], 'copyStatusDate') : null,
			];
		}
		return $copies;
	}

	/**
	 * Reservation fields of the reviewer of a review essay: the copy preferences per work
	 * (BundleWorkflow::syncMembers() moves them and the delivery details to the mirrors).
	 */
	protected function applyBundleReservation(Assignment $assignment, array $input, string $status, array $copies): void {
		$data = array_fill_keys(Reservation::FIELDS, null);
		$data['dateReserved'] = $assignment->getDateCreated();
		$data['dueDate'] = $this->reservationDueDate($input, $status);
		$data['copyPreferences'] = array_map(fn ($copy) => $copy['copyPreference'], $copies);
		$preferences = array_values($data['copyPreferences']);
		if (in_array(Reservation::COPY_OTHER, $preferences, true)) {
			$data['copyNote'] = trim((string) ($input['copyNote'] ?? ''));
		}
		if ($status === Assignment::STATUS_ACCEPTED) {
			$this->applyDeliveryDetails($data, $input, $copies);
		}
		Reservation::apply($assignment, $data);
	}

	/**
	 * Delivery details of an accepted reviewer who gets a physical copy. They are kept only while
	 * a copy is on its way (see Reservation::erasePersonalData), and required while one is still to be sent.
	 * @param array $copies [copyPreference, copyStatus (or null)] of each work
	 */
	protected function applyDeliveryDetails(array &$data, array $input, array $copies): void {
		$physical = array_filter($copies, fn ($copy) => $copy['copyPreference'] === Reservation::COPY_PHYSICAL);
		if (!$physical) {
			return;
		}
		$data['shippingAddress'] = trim((string) ($input['shippingAddress'] ?? ''));
		$data['phone'] = trim((string) ($input['phone'] ?? ''));
		$toShip = array_filter($physical,
			fn ($copy) => in_array($copy['copyStatus'] ?? CopyWorkflow::PENDING, [CopyWorkflow::PENDING, CopyWorkflow::REQUESTED], true));
		if ($toShip && $data['shippingAddress'] === '') {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'shippingAddressRequired', __(self::BUNDLE_ERROR_PREFIX . 'shippingAddressRequired'));
		}
	}

	protected function validCopyStatus($copyStatus): ?string {
		if ($copyStatus !== null && !in_array($copyStatus, self::COPY_STATUSES, true)) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidCopyStatus',
				__(self::BUNDLE_ERROR_PREFIX . 'invalidCopyStatus', ['values' => implode(', ', self::COPY_STATUSES)]));
		}
		return $copyStatus;
	}

	/**
	 * The review copy statuses given in "copies", set on the mirrors of the members.
	 */
	protected function applyMirrorCopyStatus(Assignment $containerAssignment, array $copies): void {
		$dao = AssignmentWorkflow::dao();
		foreach ($dao->getMirrors($containerAssignment->getId()) as $mirror) {
			$copy = $copies[$mirror->getObjectId()] ?? null;
			if (!$copy || $copy['copyStatus'] === null) {
				continue;
			}
			$mirror->setData('copyStatus', $copy['copyStatus']);
			$mirror->setData('copyStatusDate', $copy['copyStatusDate'] ?? Core::getCurrentDate());
			if (!in_array($copy['copyStatus'], [CopyWorkflow::PENDING, CopyWorkflow::REQUESTED, CopyWorkflow::SENT], true)) {
				// The copy has arrived (or none is needed): the delivery details copied onto the mirror are no longer needed
				Reservation::erasePersonalData($mirror);
			}
			$dao->updateObject($mirror);
		}
	}

	//
	// Users
	//

	/**
	 * GET users[?role=author|publisherContact|bookReviewEditor][&email=...]
	 */
	public function getUsers(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		$email = $illuminateRequest->query('email');
		$role = $illuminateRequest->query('role');
		if ($role !== null && !in_array($role, self::USER_ROLES, true)) {
			return $this->error(Response::HTTP_BAD_REQUEST, 'invalidRole', 'role must be one of: ' . implode(', ', self::USER_ROLES) . '.');
		}
		if ($email !== null) {
			$user = Repo::user()->getByEmail((string) $email, true);
			$users = $user && (!$role || $this->hasRole($user, $role)) ? [$user] : [];
		} else {
			$collector = Repo::user()->getCollector()->filterByContextIds([$this->contextId()]);
			if ($role) {
				$collector->filterByUserGroupIds([$this->groupId($role)]);
			}
			$users = $collector->getMany()->all();
		}
		return response()->json([
			'itemsMax' => count($users),
			'items' => array_values(array_map(fn ($user) => $this->userToArray($user), $users)),
		], Response::HTTP_OK);
	}

	/**
	 * POST users: find a user by email or create one (no email is sent; the
	 * account has a random password), and give them roles in the journal.
	 */
	public function addUser(Request $illuminateRequest): JsonResponse {
		if ($denied = $this->denyUnlessManager()) {
			return $denied;
		}
		try {
			[$user, $created, $rolesAdded] = $this->ensureUser((array) $illuminateRequest->input());
		} catch (ApiError $e) {
			return $e->response();
		}
		return response()->json($this->userToArray($user) + ['_created' => $created, '_rolesAdded' => $rolesAdded], Response::HTTP_OK);
	}

	/**
	 * @return array [User, bool created, string[] roles added]
	 */
	protected function ensureUser(array $input): array {
		$email = trim((string) ($input['email'] ?? ''));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidEmail', 'A valid email is required.');
		}
		$roles = (array) ($input['roles'] ?? ($input['role'] ?? [self::ROLE_AUTHOR]));
		foreach ($roles as $role) {
			if (!in_array($role, self::USER_ROLES, true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidRole', 'roles must be some of: ' . implode(', ', self::USER_ROLES) . '.');
			}
			if ($role === Roles::EDITOR && !Roles::isManager($this->userRoles())) {
				throw new ApiError(Response::HTTP_FORBIDDEN, 'managerOnly', 'Only journal managers can make someone a book review editor.');
			}
		}

		$locale = $this->getRequest()->getContext()->getPrimaryLocale();
		$user = Repo::user()->getByEmail($email, true);
		$created = false;
		$profile = ['givenName' => 'setGivenName', 'familyName' => 'setFamilyName', 'affiliation' => 'setAffiliation'];
		if (!$user) {
			if (trim((string) ($input['givenName'] ?? '')) === '') {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'givenNameRequired', "No user with email {$email}; givenName is required to create one.");
			}
			$user = Repo::user()->newDataObject();
			$user->setUsername($this->uniqueUsername($input['username'] ?? $email));
			$user->setEmail($email);
			$user->setPassword(Validation::encryptCredentials($user->getUsername(), Validation::generatePassword()));
			$user->setDateRegistered(Core::getCurrentDate());
			$user->setMustChangePassword(true);
			$created = true;
		}

		// Profile: a new account gets everything given; an existing one only what is missing, unless updateProfile is set
		$overwrite = $created || !empty($input['updateProfile']);
		$changed = false;
		foreach ($profile as $field => $setter) {
			$value = trim((string) ($input[$field] ?? ''));
			if ($value !== '' && ($overwrite || trim((string) $user->getLocalizedData($field, $locale)) === '')) {
				$user->$setter($value, $locale);
				$changed = true;
			}
		}
		foreach (['mailingAddress' => 'setMailingAddress', 'phone' => 'setPhone', 'country' => 'setCountry'] as $field => $setter) {
			$value = trim((string) ($input[$field] ?? ''));
			if ($value !== '' && ($overwrite || trim((string) $user->getData($field)) === '')) {
				$user->$setter($value);
				$changed = true;
			}
		}
		if ($created) {
			Repo::user()->add($user);
			$user = Repo::user()->get($user->getId(), true);
		} elseif ($changed) {
			Repo::user()->edit($user);
			$user = Repo::user()->get($user->getId(), true);
		}

		$rolesAdded = [];
		foreach ($roles as $role) {
			if ($this->addToGroup($user, $role)) {
				$rolesAdded[] = $role;
			}
		}
		return [$user, $created, $rolesAdded];
	}

	protected function uniqueUsername(string $source): string {
		$base = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($source, '@', true) ?: $source)) ?: 'user';
		$base = substr($base, 0, 24);
		$username = $base;
		for ($i = 2; Repo::user()->getByUsername($username, true); $i++) {
			$username = $base . $i;
		}
		return $username;
	}

	/**
	 * @return bool Whether the user was added (false: had the role already)
	 */
	protected function addToGroup(User $user, string $role): bool {
		$groupId = $this->groupId($role);
		if (UserUserGroup::withUserId($user->getId())->withUserGroupIds([$groupId])->withActive()->exists()) {
			return false;
		}
		UserUserGroup::create(['userId' => $user->getId(), 'userGroupId' => $groupId,
			'dateStart' => Core::getCurrentDate(), 'dateEnd' => null, 'masthead' => null]);
		return true;
	}

	protected function hasRole(User $user, string $role): bool {
		return UserUserGroup::withUserId($user->getId())->withUserGroupIds([$this->groupId($role)])->withActive()->exists();
	}

	protected function groupId(string $role): int {
		if ($role === self::ROLE_AUTHOR) {
			$group = UserGroup::withContextIds([$this->contextId()])->withRoleIds([Role::ROLE_ID_AUTHOR])->isDefault(true)->first();
			if (!$group) {
				throw new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, 'noAuthorGroup', 'The journal has no default author group.');
			}
			return (int) $group->userGroupId;
		}
		return Roles::getGroupId($this->plugin, $this->contextId(), $role);
	}

	//
	// Objects: input
	//

	/**
	 * "doi" or "isbn" in the input: normalize, set identifier and type, and fill in missing fields from the lookup.
	 */
	protected function withLookup(array $input): array {
		if (empty($input['doi']) && empty($input['isbn'])) {
			return $input;
		}
		if (array_key_exists('lookup', $input) && !$input['lookup']) {
			if (!empty($input['doi'])) {
				$doi = CrossrefLookup::normalizeDoi($input['doi']) ?? throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidDoi', 'Not a valid DOI.');
				return ['identifier' => $doi, 'identifierType' => 'doi'] + $input;
			}
			$isbn = IsbnLookup::normalizeIsbn($input['isbn']) ?? throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidIsbn', 'Not a valid ISBN.');
			// The identifier is the normalized one; the resource type only defaults to book
			return ['identifier' => $isbn, 'identifierType' => 'isbn'] + $input + ['resourceType' => IsbnLookup::BOOK_TYPE];
		}
		[$data, $type] = $this->lookupMetadata($input['doi'] ?? null, $input['isbn'] ?? null);
		unset($data['source']);
		// Values in the request win over the lookup
		$given = array_filter($input, fn ($value) => $value !== null && $value !== '');
		return array_merge(['identifierType' => $type], array_filter($data, fn ($value) => $value !== null && $value !== ''), $given,
			['identifier' => $data['identifier'], 'identifierType' => $type]);
	}

	/**
	 * @return array [metadata, identifier type]
	 */
	protected function lookupMetadata(?string $doi, ?string $isbn): array {
		if ($doi) {
			$normalized = CrossrefLookup::normalizeDoi($doi);
			if (!$normalized) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidDoi', 'Not a valid DOI.');
			}
			try {
				return [CrossrefLookup::lookup($normalized) + ['identifierType' => 'doi', 'source' => 'Crossref'], 'doi'];
			} catch (\Exception $e) {
				$notFound = $e->getMessage() === 'plugins.generic.bookReviewManager.doiLookup.notFound';
				throw new ApiError($notFound ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_GATEWAY,
					$notFound ? 'doiNotFound' : 'lookupFailed', $notFound ? "DOI {$normalized} not found at Crossref." : 'The Crossref lookup failed.');
			}
		}
		if ($isbn) {
			$normalized = IsbnLookup::normalizeIsbn($isbn);
			if (!$normalized) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidIsbn', 'Not a valid ISBN.');
			}
			try {
				$data = IsbnLookup::lookup($normalized);
			} catch (\Exception $e) {
				throw new ApiError(Response::HTTP_BAD_GATEWAY, 'lookupFailed', 'The ISBN lookup failed (Crossref and Open Library).');
			}
			if (!$data) {
				throw new ApiError(Response::HTTP_NOT_FOUND, 'isbnNotFound', "ISBN {$normalized} not found at Crossref or Open Library.");
			}
			return [$data + ['identifierType' => 'isbn'], 'isbn'];
		}
		throw new ApiError(Response::HTTP_BAD_REQUEST, 'identifierRequired', 'doi or isbn is required.');
	}

	/**
	 * Set the fields given in the input, with the checks of AvailableObjectsForReviewForm.
	 */
	protected function applyObjectInput(ObjectForReview $object, array $input, bool $isNew): void {
		$has = fn (string $key) => array_key_exists($key, $input);

		if ($has('identifierType') || $isNew) {
			$type = strtolower((string) ($input['identifierType'] ?? ''));
			if (!in_array($type, self::IDENTIFIER_TYPES, true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidIdentifierType', 'identifierType must be one of: ' . implode(', ', self::IDENTIFIER_TYPES) . '.');
			}
			$object->setIdentifierType($type);
		}
		if ($has('identifier') || $isNew) {
			$identifier = trim((string) ($input['identifier'] ?? ''));
			if ($identifier === '') {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'identifierRequired', 'identifier (or doi/isbn) is required.');
			}
			$object->setIdentifier($identifier);
		}
		if ($has('resourceType') || $isNew) {
			$resourceType = (string) ($input['resourceType'] ?? IsbnLookup::BOOK_TYPE);
			// A review essay is made with POST bundles and stays one until it is dissolved
			if ($object->isReviewEssay()) {
				if ($resourceType !== ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY) {
					throw $this->bundleRuleError('review_essay_rule', BundleWorkflow::ERROR_PREFIX . 'resourceType');
				}
			} elseif ($resourceType === ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidResourceType', 'A work cannot become a review essay: create one with POST bundles.');
			} elseif (!in_array($resourceType, self::RESOURCE_TYPES, true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidResourceType', 'resourceType must be a COAR resource type URI: ' . implode(', ', self::RESOURCE_TYPES) . '.');
			}
			$object->setResourceType($resourceType);
		}
		if ($has('title') || $isNew) {
			$title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($input['title'] ?? ''))));
			if ($title === '') {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'titleRequired', 'title is required (the lookup found none).');
			}
			$object->setTitle($title);
		}
		foreach (['authors' => 'setAuthors', 'publisher' => 'setPublisher', 'year' => 'setYear'] as $field => $setter) {
			if ($has($field)) {
				$object->$setter(trim((string) $input[$field]));
			}
		}
		if ($has('description')) {
			$description = trim((string) $input['description']);
			$object->setDescription(trim(strip_tags($description)) === '' ? null : $description);
		}

		if ($has('assignmentMode') || $isNew) {
			$mode = $input['assignmentMode'] ?? ($this->plugin->getSetting($this->contextId(), 'ofrDefaultMode') ?: ObjectForReview::MODE_DIRECT);
			if (!in_array($mode, ObjectForReview::MODES, true)) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidAssignmentMode', 'assignmentMode must be direct or bidding.');
			}
			$object->setOfferSetting('assignmentMode', $mode === ObjectForReview::MODE_BIDDING ? ObjectForReview::MODE_BIDDING : null);
			if ($mode !== ObjectForReview::MODE_BIDDING) {
				$object->setOfferSetting('bidDeadline', null);
			}
		}
		if ($has('bidDeadline')) {
			$object->setOfferSetting('bidDeadline', $object->isBidding() && $input['bidDeadline'] ? $this->validDate($input['bidDeadline'], 'bidDeadline') : null);
		}

		if ($has('responsibleEditorId') || $has('responsibleEditorEmail')) {
			$object->setOfferSetting('responsibleEditorId', $this->memberId(Roles::EDITOR, $input['responsibleEditorId'] ?? null, $input['responsibleEditorEmail'] ?? null, 'responsibleEditor'));
		} elseif ($isNew) {
			$editorId = (int) $this->plugin->getSetting($this->contextId(), 'ofrDefaultEditorId');
			$userId = $this->getRequest()->getUser()->getId();
			if (!$editorId && Roles::isBookReviewEditor($this->plugin, $this->contextId(), $userId)) {
				$editorId = $userId;
			}
			$object->setOfferSetting('responsibleEditorId', $editorId ?: null);
		}
		if ($has('publisherContactId') || $has('publisherContactEmail')) {
			$object->setOfferSetting('publisherContactId', $this->memberId(Roles::PUBLISHER, $input['publisherContactId'] ?? null, $input['publisherContactEmail'] ?? null, 'publisherContact'));
		}
		if ($has('journalHoldsCopy')) {
			$object->setOfferSetting('journalHoldsCopy', $this->bool($input['journalHoldsCopy']));
		}
		if ($has('returnRequired') || $isNew) {
			$object->setData('returnRequired', $this->bool($input['returnRequired'] ?? true));
		}
		if ($has('notifyPublisher')) {
			$object->setOfferSetting('notifyPublisher', $this->bool($input['notifyPublisher']));
		}
		if ($has('hidden')) {
			$object->setOfferSetting('hidden', $this->bool($input['hidden']));
		}

		// Physical copies are returned to the responsible editor (journal stock) or the publisher contact
		if ($object->isReturnRequired()) {
			if ($object->getData('journalHoldsCopy') && !$this->hasMailingAddress($object->getResponsibleEditorId())) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'editorAddressRequired',
					'journalHoldsCopy with returnRequired needs a responsible editor with a mailing address.');
			}
			if ($object->getPublisherContactId() && !$this->hasMailingAddress($object->getPublisherContactId())) {
				throw new ApiError(Response::HTTP_BAD_REQUEST, 'publisherAddressRequired',
					'returnRequired needs a publisher contact with a mailing address (or set returnRequired: false).');
			}
		}
		if ($object->getData('notifyPublisher') && !$object->getPublisherContactId()) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'notifyNeedsContact', 'notifyPublisher needs a publisher contact.');
		}
	}

	/**
	 * Id of a member of a plugin group, given by id or email (null/empty: none).
	 */
	protected function memberId(string $group, $id, $email, string $field): ?int {
		if (!$id && !$email) {
			return null;
		}
		$user = $id ? Repo::user()->get((int) $id, true) : Repo::user()->getByEmail((string) $email, true);
		if (!$user || !$this->hasRole($user, $group)) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, "invalid" . ucfirst($field),
				"{$field}: no user " . ($id ?: $email) . " with the role {$group} (add them with POST users first).");
		}
		return $user->getId();
	}

	protected function hasMailingAddress(?int $userId): bool {
		$user = $userId ? Repo::user()->get($userId, true) : null;
		return $user && trim((string) $user->getMailingAddress()) !== '';
	}

	/**
	 * Find an object of this journal by identifier (DOIs and ISBNs compared normalized).
	 */
	protected function findByIdentifier(string $type, string $identifier): ?ObjectForReview {
		$type = strtolower($type);
		$normalize = match ($type) {
			'doi' => fn ($value) => strtolower((string) (CrossrefLookup::normalizeDoi($value) ?? trim($value))),
			'isbn' => fn ($value) => self::isbn13(IsbnLookup::normalizeIsbn($value)) ?? trim($value),
			default => fn ($value) => trim((string) $value),
		};
		$wanted = $normalize($identifier);
		$objects = (new ObjectForReviewDAO())->getAll($this->contextId());
		while ($object = $objects->next()) {
			if (($type === '' || strtolower((string) $object->getIdentifierType()) === $type) && $normalize((string) $object->getIdentifier()) === $wanted) {
				return $object;
			}
		}
		return null;
	}

	/**
	 * ISBN-13 of a normalized ISBN (an ISBN-10 and its ISBN-13 are the same book).
	 */
	protected static function isbn13(?string $isbn): ?string {
		if ($isbn === null || strlen($isbn) !== 10) {
			return $isbn;
		}
		$digits = '978' . substr($isbn, 0, 9);
		$sum = 0;
		for ($i = 0; $i < 12; $i++) {
			$sum += (int) $digits[$i] * ($i % 2 ? 3 : 1);
		}
		return $digits . ((10 - $sum % 10) % 10);
	}

	//
	// Output
	//

	protected function objectToArray(ObjectForReview $object, bool $withAssignments = false): array {
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		$data = [
			'id' => $object->getId(),
			'identifier' => $object->getIdentifier(),
			'identifierType' => $object->getIdentifierType(),
			'resourceType' => $object->getResourceType(),
			'title' => $object->getTitle(),
			'authors' => $object->getAuthors(),
			'publisher' => $object->getPublisher(),
			'year' => $object->getYear(),
			'description' => $object->getDescription(),
			'creator' => $object->getCreator(),
			'dateCreated' => $object->getDateCreated(),
			'submissionId' => $object->getSubmissionId() ? (int) $object->getSubmissionId() : null,
			'reviewerId' => $object->getUserId() ? (int) $object->getUserId() : null,
			'state' => AssignmentWorkflow::getState($object, $assignments),
			'assignmentMode' => $object->getAssignmentMode(),
			'returnRequired' => $object->isReturnRequired(),
			'isReviewEssay' => $object->isReviewEssay(),
			'bundleId' => $object->getBundleId(),
		];
		foreach (ObjectForReview::OFFER_FIELDS as $field) {
			if (!array_key_exists($field, $data) && !in_array($field, ['invitationSubject', 'invitationBody'], true)) {
				$data[$field] = $object->getData($field);
			}
		}
		if ($withAssignments) {
			$data['assignments'] = array_map(fn ($a) => $this->assignmentToArray($a), $assignments);
		}
		if ($object->isReviewEssay()) {
			// The works of the essay (with their mirror assignments, if assignments are asked for)
			$data['members'] = array_map(fn ($member) => $this->objectToArray($member, $withAssignments), BundleWorkflow::membersOf($object));
		}
		return $data;
	}

	protected function assignmentToArray(Assignment $assignment): array {
		$user = Repo::user()->get($assignment->getUserId(), true);
		$data = [
			'id' => (int) $assignment->getId(),
			'objectId' => $assignment->getObjectId(),
			'userId' => $assignment->getUserId(),
			'userName' => $user?->getFullName(),
			'userEmail' => $user?->getEmail(),
			'status' => $assignment->getStatus(),
			'dateCreated' => $assignment->getDateCreated(),
			'dateStatus' => $assignment->getDateStatus(),
		];
		foreach (array_merge(Reservation::FIELDS, Assignment::FIELDS) as $field) {
			$data[$field] = $assignment->getData($field);
		}
		return $data;
	}

	protected function userToArray(User $user): array {
		return [
			'id' => $user->getId(),
			'username' => $user->getUsername(),
			'email' => $user->getEmail(),
			'givenName' => $user->getLocalizedGivenName(),
			'familyName' => $user->getLocalizedFamilyName(),
			'fullName' => $user->getFullName(),
			'affiliation' => $user->getLocalizedData('affiliation'),
			'mailingAddress' => $user->getMailingAddress(),
			'phone' => $user->getPhone(),
			'country' => $user->getCountry(),
			'roles' => array_values(array_filter(self::USER_ROLES, fn ($role) => $this->hasRole($user, $role))),
		];
	}

	//
	// Helpers
	//

	protected function contextId(): int {
		return (int) $this->getRequest()->getContext()->getId();
	}

	protected function userRoles(): array {
		return (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
	}

	protected function getContextObject(int $objectId): ?ObjectForReview {
		$object = (new ObjectForReviewDAO())->getById($objectId);
		return $object && (int) $object->getContextId() === $this->contextId() ? $object : null;
	}

	/**
	 * Section editors get through the route middleware; only book review editors among them may use the API.
	 */
	protected function denyUnlessManager(): ?JsonResponse {
		$user = $this->getRequest()->getUser();
		if (Roles::canManage($this->plugin, $this->contextId(), $user?->getId(), $this->userRoles())) {
			return null;
		}
		return $this->error(Response::HTTP_FORBIDDEN, 'forbidden', 'Only journal managers and book review editors can use this API.');
	}

	protected function validDate($value, string $field): string {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
		if (!$date || $date->format('Y-m-d') !== $value) {
			throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidDate', "{$field} must be a date (Y-m-d).");
		}
		return $value;
	}

	protected function validDateTime($value, string $field): string {
		foreach (['!Y-m-d H:i:s', '!Y-m-d'] as $format) {
			$date = \DateTimeImmutable::createFromFormat($format, (string) $value);
			if ($date && $date->format(ltrim($format, '!')) === $value) {
				return $date->format('Y-m-d H:i:s');
			}
		}
		throw new ApiError(Response::HTTP_BAD_REQUEST, 'invalidDate', "{$field} must be a date (Y-m-d or Y-m-d H:i:s).");
	}

	protected function bool($value): bool {
		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
	}

	protected function error(int $status, string $error, string $message, array $extra = []): JsonResponse {
		return response()->json(['error' => $error, 'errorMessage' => $message] + $extra, $status);
	}
}

