<?php

/**
 * @file plugins/generic/bookReviewManager/classes/InvitationWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InvitationWorkflow
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Inviting particular people to review an object (issue #2). Each
 *  person on an object's invitation list is an assignment:
 *
 *  listed --(invitation sent)--> invited --(reserves)--> accepted   (direct reservation)
 *                                        --(applies)---> bid        (bidding)
 *                                        --(declines)--> invitationDeclined
 *                                        --(deadline)--> invitationExpired
 *
 *  Declined and expired invitations can be sent again (manually). Once an
 *  invitee reserves or applies, the normal workflow applies (AssignmentWorkflow).
 *
 *  Invitations are sent one by one by the editor (manual), or in a round whose
 *  text is edited once:
 *  - sequential (direct reservation): the first person on the list is invited,
 *    and each decline or passed deadline invites the next one, until someone
 *    reserves, the list is exhausted or the editor stops;
 *  - all at once (bidding): everyone waiting is invited to apply at the same
 *    time; the round ends when all have answered, the editor selects a bidder
 *    or stops it.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\pages\ObjectsForReviewManagementHandler;
use PKP\core\Core;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\user\User;
use PKP\userGroup\relationships\UserUserGroup;
use PKP\userGroup\UserGroup;

class InvitationWorkflow {

	const MODE_SEQUENTIAL = 'sequential';
	const MODE_PARALLEL = 'parallel';

	const DEFAULT_INVITATION_DAYS = 7;
	const MAX_INVITATION_DAYS = 90;

	/** Statuses from which an invitation can be sent (again) */
	const SENDABLE_STATUSES = [Assignment::STATUS_LISTED, Assignment::STATUS_INVITED,
		Assignment::STATUS_INVITATION_DECLINED, Assignment::STATUS_INVITATION_EXPIRED, Assignment::STATUS_INVITATION_WITHDRAWN];

	/** Statuses of people who can't be added to the list (again) */
	const BLOCKING_STATUSES = [Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_OFFERED,
		Assignment::STATUS_ACCEPTED, Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED,
		Assignment::STATUS_LISTED, Assignment::STATUS_INVITED, Assignment::STATUS_INVITATION_DECLINED, Assignment::STATUS_INVITATION_EXPIRED,
		Assignment::STATUS_INVITATION_WITHDRAWN];

	/**
	 * The invitation list of an object, in order: everyone added to it, also
	 * those who have since reserved or applied (they keep their position).
	 * @return Assignment[]
	 */
	public static function getList(ObjectForReview $object): array {
		$list = array_values(array_filter(AssignmentWorkflow::dao()->getByObjectId($object->getId()),
			fn (Assignment $a) => $a->isInvitee() || $a->getData('position') !== null));
		usort($list, fn ($a, $b) => ((int) $a->getData('position')) <=> ((int) $b->getData('position')) ?: $a->getId() <=> $b->getId());
		return $list;
	}

	/**
	 * What the invitees are asked to do: reserve (direct reservation) or apply (bidding).
	 */
	public static function getKind(ObjectForReview $object): string {
		return $object->isBidding() ? 'bid' : 'reserve';
	}

	public static function getEmailType(ObjectForReview $object): ReservationEmailEventType {
		return $object->isBidding() ? ReservationEmailEventType::INVITATION_BID : ReservationEmailEventType::INVITATION_RESERVE;
	}

	/**
	 * Can invitations be sent (and answered) now? The offer must be open; hiding
	 * it from the public list doesn't matter.
	 */
	public static function canInvite(ObjectForReview $object, ?array $assignments = null): bool {
		if ($object->isBundleMember()) {
			return false;
		}
		$assignments ??= AssignmentWorkflow::dao()->getByObjectId($object->getId());
		return $object->isBidding()
			? AssignmentWorkflow::isBiddingOpen($object, $assignments)
			: AssignmentWorkflow::isReservable($object, $assignments);
	}

	/**
	 * Can the user be added to the object's list?
	 */
	public static function canAdd(ObjectForReview $object, int $userId): bool {
		return !$object->isBundleMember() && !$object->getData('offerClosed') && !$object->getSubmissionId()
			&& !AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $userId, self::BLOCKING_STATUSES);
	}

	/**
	 * Days invitees have to answer (setting ofrInvitationDays).
	 */
	public static function getInvitationDays($plugin, int $contextId): int {
		$days = $plugin->getSetting($contextId, 'ofrInvitationDays');
		return $days === null || $days === '' ? self::DEFAULT_INVITATION_DAYS : max(1, (int) $days);
	}

	/**
	 * Days after the invitation on which invitees are reminded (setting ofrInvitationReminderDays; 0: never).
	 */
	public static function getReminderDays($plugin, int $contextId): int {
		return max(0, (int) $plugin->getSetting($contextId, 'ofrInvitationReminderDays'));
	}

	/**
	 * Answer deadline of an invitation sent today (Y-m-d). In bidding mode, the
	 * bid deadline if there is one.
	 */
	public static function getDeadline($plugin, ObjectForReview $object): string {
		if ($object->isBidding() && $object->getData('bidDeadline')) {
			return (string) $object->getData('bidDeadline');
		}
		$days = self::getInvitationDays($plugin, (int) $object->getContextId());
		return (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
	}

	public static function add(ObjectForReview $object, User $user, User $editor): ?Assignment {
		if (!self::canAdd($object, $user->getId())) {
			return null;
		}
		$list = self::getList($object);
		$assignment = AssignmentWorkflow::dao()->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId((int) $object->getContextId());
		$assignment->setUserId($user->getId());
		$assignment->setStatus(Assignment::STATUS_LISTED);
		$assignment->setData('position', $list ? max(array_map(fn ($a) => (int) $a->getData('position'), $list)) + 1 : 1);
		AssignmentWorkflow::dao()->insertObject($assignment);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITEE_ADDED, $editor, $user);
		return $assignment;
	}

	/**
	 * Create an account for a new person (in the journal's Author group, so that
	 * they can submit the review) and add them to the list. No email is sent.
	 * An existing account with the same email address is added to the journal instead.
	 * @param array $data givenName, familyName, email, affiliation
	 */
	public static function createAndAdd($context, ObjectForReview $object, array $data, User $editor): ?Assignment {
		$locale = $context->getPrimaryLocale();
		$user = Repo::user()->getByEmail($data['email'], true);
		$created = false;
		if (!$user) {
			$user = Repo::user()->newDataObject();
			$user->setUsername(self::uniqueUsername($data['email']));
			$user->setEmail($data['email']);
			$user->setPassword(Validation::encryptCredentials($user->getUsername(), Validation::generatePassword()));
			$user->setGivenName($data['givenName'], $locale);
			$user->setFamilyName($data['familyName'], $locale);
			if ($data['affiliation'] !== '') {
				$user->setAffiliation($data['affiliation'], $locale);
			}
			$user->setDateRegistered(Core::getCurrentDate());
			$user->setMustChangePassword(true);
			Repo::user()->add($user);
			$user = Repo::user()->get($user->getId(), true);
			$created = true;
		}
		$authorGroup = UserGroup::withContextIds([$context->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])->isDefault(true)->first();
		if ($authorGroup && !UserUserGroup::withUserId($user->getId())->withUserGroupIds([$authorGroup->userGroupId])->exists()) {
			UserUserGroup::create(['userId' => $user->getId(), 'userGroupId' => $authorGroup->userGroupId,
				'dateStart' => Core::getCurrentDate(), 'dateEnd' => null, 'masthead' => null]);
		}
		if ($created) {
			ReservationLog::logEvent($object, ReservationLog::EVENT_INVITEE_CREATED, $editor, $user);
		}
		return self::add($object, $user, $editor);
	}

	protected static function uniqueUsername(string $email): string {
		$base = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($email, '@', true) ?: 'reviewer')) ?: 'reviewer';
		$base = substr($base, 0, 24);
		$username = $base;
		for ($i = 2; Repo::user()->getByUsername($username, true); $i++) {
			$username = $base . $i;
		}
		return $username;
	}

	/**
	 * Remove someone who hasn't been invited yet.
	 */
	public static function remove(ObjectForReview $object, Assignment $assignment, User $editor): bool {
		if ($assignment->getStatus() !== Assignment::STATUS_LISTED) {
			return false;
		}
		$user = Repo::user()->get($assignment->getUserId(), true);
		AssignmentWorkflow::dao()->deleteById($assignment->getId());
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITEE_REMOVED, $editor, $user);
		return true;
	}

	/**
	 * Put the list in a new order.
	 * @param int[] $assignmentIds All assignment IDs of the list, in the new order
	 */
	public static function reorder(ObjectForReview $object, array $assignmentIds, User $editor): bool {
		$list = self::getList($object);
		$byId = [];
		foreach ($list as $assignment) {
			$byId[$assignment->getId()] = $assignment;
		}
		$assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));
		if (count($assignmentIds) !== count($byId) || array_diff($assignmentIds, array_keys($byId))) {
			return false;
		}
		if ($assignmentIds === array_keys($byId)) {
			return true;
		}
		foreach ($assignmentIds as $index => $id) {
			$byId[$id]->setData('position', $index + 1);
			AssignmentWorkflow::dao()->updateObject($byId[$id]);
		}
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_LIST_REORDERED, $editor, null);
		return true;
	}

	/**
	 * Move an entry one place up (-1) or down (+1).
	 */
	public static function move(ObjectForReview $object, int $assignmentId, int $direction, User $editor): bool {
		$ids = array_map(fn ($a) => $a->getId(), self::getList($object));
		$index = array_search($assignmentId, $ids, true);
		$target = $index === false ? false : $index + ($direction < 0 ? -1 : 1);
		if ($target === false || $target < 0 || $target >= count($ids)) {
			return false;
		}
		[$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
		return self::reorder($object, $ids, $editor);
	}

	/**
	 * Send (or send again) an invitation: the assignment becomes "invited".
	 * @param ?string $locale Language of the invitation, also used for its reminder (null: the journal's primary language)
	 * @return string Key of the one-click link (valid until the deadline)
	 */
	public static function invite(ObjectForReview $object, Assignment $assignment, ?User $editor, string $deadline, ?string $locale = null): string {
		if (!in_array($assignment->getStatus(), self::SENDABLE_STATUSES, true)) {
			throw new \InvalidArgumentException('Assignment ' . $assignment->getId() . ' has status ' . $assignment->getStatus());
		}
		ActionLink::invalidate($assignment->getId());
		$assignment->setData('invitedBy', $editor?->getId() ?? $object->getData('invitationStartedBy'));
		$assignment->setData('dateInvited', Core::getCurrentDate());
		$assignment->setData('invitationDeadline', $deadline);
		$locale ? $assignment->setData('invitationLocale', $locale) : $assignment->unsetData('invitationLocale');
		$assignment->unsetData('invitationDeclineReason');
		$assignment->unsetData('invitationReminderSent');
		$assignment->setStatus(Assignment::STATUS_INVITED);
		$assignment->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_SENT, $editor, $invitee, ['reviewDueDate' => $deadline]);
		return ActionLink::create($assignment->getId(), ActionLink::ACTION_INVITATION, $deadline . ' 23:59:59');
	}

	/**
	 * Can the invitee still answer?
	 */
	public static function isAnswerable(ObjectForReview $object, Assignment $assignment): bool {
		return $assignment->getStatus() === Assignment::STATUS_INVITED
			&& (string) $assignment->getData('invitationDeadline') >= date('Y-m-d')
			&& self::canInvite($object);
	}

	/**
	 * The invitee reserves the object (direct reservation).
	 */
	public static function reserve($plugin, $context, ObjectForReview $object, Assignment $assignment, array $data): void {
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		AssignmentWorkflow::reserve($plugin, $context, $object, $invitee, $data, $assignment);
	}

	/**
	 * The invitee applies (bidding).
	 */
	public static function bid($plugin, $context, ObjectForReview $object, Assignment $assignment, string $statement): void {
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		AssignmentWorkflow::bid($plugin, $context, $object, $invitee, $statement, $assignment);
		self::onAnswered($plugin, $context, $object, true);
	}

	/**
	 * The invitee declines; the responsible editor is notified.
	 */
	public static function decline($plugin, $context, ObjectForReview $object, Assignment $assignment, string $reason): void {
		if ($assignment->getStatus() !== Assignment::STATUS_INVITED) {
			throw new \InvalidArgumentException('Assignment ' . $assignment->getId() . ' has status ' . $assignment->getStatus());
		}
		if ($reason !== '') {
			$assignment->setData('invitationDeclineReason', $reason);
		}
		$assignment->setStatus(Assignment::STATUS_INVITATION_DECLINED);
		$assignment->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		ActionLink::invalidate($assignment->getId());
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_DECLINED, $invitee, $invitee, ReservationLog::viaLink($invitee));
		if (self::isRoundActive($object)) {
			// In a round, declines are only recorded (sequential: the next person is invited)
			self::onAnswered($plugin, $context, $object);
			return;
		}
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::INVITATION_DECLINED, $object, $assignment, [
			'inviteeName' => htmlspecialchars($invitee?->getFullName() ?? ''),
			'invitationListUrl' => self::listUrl($context, $object),
			'declineReason' => $reason !== '' ? nl2br(htmlspecialchars($reason))
				: __('plugins.generic.bookReviewManager.contract.declined.noReason', [], $context->getPrimaryLocale()),
		]);
	}

	/**
	 * The deadline passed without an answer (daily task); the responsible editor is notified.
	 */
	public static function expire($plugin, $context, ObjectForReview $object, Assignment $assignment): void {
		if ($assignment->getStatus() !== Assignment::STATUS_INVITED) {
			return;
		}
		$assignment->setStatus(Assignment::STATUS_INVITATION_EXPIRED);
		$assignment->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		ActionLink::invalidate($assignment->getId());
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_EXPIRED, null, $invitee);
		if (self::isRoundActive($object)) {
			self::onAnswered($plugin, $context, $object);
			return;
		}
		if (!self::canInvite($object)) {
			// The work was taken or bidding has closed: nothing to decide
			return;
		}
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::INVITATION_DECLINED, $object, $assignment, [
			'inviteeName' => htmlspecialchars($invitee?->getFullName() ?? ''),
			'invitationListUrl' => self::listUrl($context, $object),
			'declineReason' => __('plugins.generic.bookReviewManager.invitation.expired', [], $context->getPrimaryLocale()),
		]);
	}

	/**
	 * URL of the object's invitation page.
	 */
	public static function listUrl($context, ObjectForReview $object): string {
		$request = Application::get()->getRequest();
		return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(),
			ObjectsForReviewManagementHandler::PAGE, 'invite', [$object->getId()]);
	}

	/**
	 * The offer was closed or the review published: open invitations end, their links stop working.
	 */
	public static function endAll(ObjectForReview $object, ?User $editor = null): void {
		$open = AssignmentWorkflow::dao()->getByObjectId($object->getId(), Assignment::OPEN_INVITATION_STATUSES);
		foreach ($open as $assignment) {
			ActionLink::invalidate($assignment->getId());
			$assignment->setStatus(Assignment::STATUS_DISCARDED);
			$assignment->setDateStatus(Core::getCurrentDate());
			AssignmentWorkflow::dao()->updateObject($assignment);
		}
		if ($open) {
			ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATIONS_ENDED, $editor, null, ['recipientCount' => count($open)]);
		}
		self::clearRound($object);
	}

	//
	// Sequential rounds
	//

	public static function isRoundActive(ObjectForReview $object): bool {
		return in_array($object->getData('invitationMode'), [self::MODE_SEQUENTIAL, self::MODE_PARALLEL], true);
	}

	/**
	 * Mode of the running round, or null.
	 */
	public static function getRoundMode(ObjectForReview $object): ?string {
		return self::isRoundActive($object) ? $object->getData('invitationMode') : null;
	}

	/**
	 * The kind of round that fits the offer: sequential (direct reservation) or all at once (bidding).
	 */
	public static function getRoundModeFor(ObjectForReview $object): string {
		return $object->isBidding() ? self::MODE_PARALLEL : self::MODE_SEQUENTIAL;
	}

	/**
	 * The round reached the end of the list (and waits for more people or for the editor).
	 */
	public static function isExhausted(ObjectForReview $object): bool {
		return self::isRoundActive($object) && (bool) $object->getData('invitationExhausted');
	}

	/**
	 * People on the list who haven't been invited yet, in order.
	 * @return Assignment[]
	 */
	public static function getWaiting(ObjectForReview $object): array {
		return array_values(array_filter(self::getList($object), fn ($a) => $a->getStatus() === Assignment::STATUS_LISTED));
	}

	/**
	 * The person currently invited in the round, if any.
	 */
	public static function getCurrent(ObjectForReview $object): ?Assignment {
		foreach (self::getList($object) as $assignment) {
			if ($assignment->getStatus() === Assignment::STATUS_INVITED) {
				return $assignment;
			}
		}
		return null;
	}

	/**
	 * Can a round be started (sequential for direct reservation, all at once for bidding)?
	 * A sequential round needs nobody else to be invited at the moment.
	 */
	public static function canStartRound(ObjectForReview $object, ?array $assignments = null): bool {
		return !self::isRoundActive($object) && self::canInvite($object, $assignments) && self::getWaiting($object)
			&& ($object->isBidding() || !self::getCurrent($object));
	}

	/**
	 * Invited people of a round (all at once): [invited now, answered or ended].
	 */
	public static function getRoundCounts(ObjectForReview $object): array {
		$invited = $answered = 0;
		foreach (self::getList($object) as $assignment) {
			if (!$assignment->getData('dateInvited')) {
				continue;
			}
			$assignment->getStatus() === Assignment::STATUS_INVITED ? $invited++ : $answered++;
		}
		return [$invited, $answered];
	}

	/**
	 * Start a round: store the text and invite the first person waiting (sequential)
	 * or everyone waiting (all at once).
	 * The subject and body keep {$recipientName}, {$invitationDeadline} and
	 * {$invitationUrl}, which are filled in for each invitee.
	 */
	public static function startRound($plugin, $context, ObjectForReview $object, User $editor, string $subject, string $body, bool $hide,
			?string $locale = null): void {
		if (!self::canStartRound($object)) {
			throw new \InvalidArgumentException('A sequential round cannot be started for object ' . $object->getId());
		}
		$mode = self::getRoundModeFor($object);
		$object->setOfferSetting('invitationMode', $mode);
		$object->setOfferSetting('invitationSubject', $subject);
		$object->setOfferSetting('invitationBody', $body);
		$object->setOfferSetting('invitationLocale', $locale);
		$object->setOfferSetting('invitationStarted', Core::getCurrentDate());
		$object->setOfferSetting('invitationStartedBy', $editor->getId());
		$object->setOfferSetting('invitationExhausted', null);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, $mode === self::MODE_PARALLEL ? ReservationLog::EVENT_ROUND_STARTED_PARALLEL : ReservationLog::EVENT_ROUND_STARTED,
			$editor, null);
		if ($hide) {
			AssignmentWorkflow::setHidden($object, true, $editor);
		}
		$mode === self::MODE_PARALLEL
			? self::inviteAllWaiting($plugin, $context, $object, $editor)
			: self::advance($plugin, $context, $object, $editor);
	}

	/**
	 * All at once: invite everyone waiting on the list.
	 */
	protected static function inviteAllWaiting($plugin, $context, ObjectForReview $object, ?User $actor): void {
		foreach (self::getWaiting($object) as $assignment) {
			self::sendRoundInvitation($plugin, $context, $object, $assignment, $actor);
		}
	}

	/**
	 * Someone answered (applied, declined) or didn't (expired). Sequential: invite
	 * the next person (not after an application). All at once: end the round
	 * when nobody is left to answer.
	 */
	public static function onAnswered($plugin, $context, ObjectForReview $object, bool $applied = false): void {
		if (self::getRoundMode($object) === self::MODE_SEQUENTIAL && !$applied) {
			self::advance($plugin, $context, $object);
		} elseif (self::getRoundMode($object) === self::MODE_PARALLEL && !self::getCurrent($object)) {
			self::clearRound($object);
			ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_FINISHED, null, null);
		}
	}

	/**
	 * Invite the next person waiting, or end the round if the list is exhausted.
	 * @param ?User $actor The editor (when starting or continuing), null when automatic
	 */
	public static function advance($plugin, $context, ObjectForReview $object, ?User $actor = null): void {
		if (self::getRoundMode($object) !== self::MODE_SEQUENTIAL) {
			return;
		}
		// The offer changed (e.g. bidding, closed, reserved): the round ends
		if ($object->isBidding() || !self::canInvite($object)) {
			self::clearRound($object);
			ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_STOPPED, $actor, null);
			return;
		}
		$next = self::getWaiting($object)[0] ?? null;
		if (!$next) {
			$object->setOfferSetting('invitationExhausted', Core::getCurrentDate());
			(new ObjectForReviewDAO())->updateObject($object);
			ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_EXHAUSTED, null, null);
			ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::INVITATION_EXHAUSTED, $object, null, [
				'invitationListUrl' => self::listUrl($context, $object),
			]);
			return;
		}
		self::sendRoundInvitation($plugin, $context, $object, $next, $actor);
	}

	/**
	 * Send the round's invitation to one person.
	 */
	protected static function sendRoundInvitation($plugin, $context, ObjectForReview $object, Assignment $assignment, ?User $actor): void {
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		$sender = Repo::user()->get((int) $object->getData('invitationStartedBy'), true);
		if (!$invitee) {
			return;
		}
		$deadline = self::getDeadline($plugin, $object);
		$locale = $object->getData('invitationLocale') ?: null;
		$key = self::invite($object, $assignment, $actor, $deadline, $locale);
		ReservationMailer::send($context, self::getEmailType($object), $object, $invitee, $sender,
			(string) $object->getData('invitationSubject'), (string) $object->getData('invitationBody'), [
				'invitationUrl' => ActionLink::url($context, $key, $locale),
				'invitationDeadline' => self::formatDate($context, $deadline, $locale),
			], null, $locale);
	}

	/**
	 * Continue a round after more people were added: an exhausted sequential
	 * round goes on; all at once, the new people are invited.
	 */
	public static function continueRound($plugin, $context, ObjectForReview $object, User $editor): bool {
		if (self::getRoundMode($object) === self::MODE_PARALLEL && self::getWaiting($object) && self::canInvite($object)) {
			ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_CONTINUED, $editor, null);
			self::inviteAllWaiting($plugin, $context, $object, $editor);
			return true;
		}
		if (!self::isExhausted($object) || !self::getWaiting($object)) {
			return false;
		}
		$object->setOfferSetting('invitationExhausted', null);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_CONTINUED, $editor, null);
		self::advance($plugin, $context, $object, $editor);
		return true;
	}

	/**
	 * The editor stops the round: the pending invitation is withdrawn.
	 */
	public static function stopRound(ObjectForReview $object, User $editor): bool {
		if (!self::isRoundActive($object)) {
			return false;
		}
		self::withdrawOpen($object, null);
		self::clearRound($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_STOPPED, $editor, null);
		return true;
	}

	/**
	 * The editor selected a bidder (bidding closed) or changed the assignment
	 * mode: pending invitations that can no longer be answered are withdrawn,
	 * and a round that no longer fits ends.
	 */
	public static function onOfferChanged(ObjectForReview $object, ?User $editor): void {
		$roundMode = self::getRoundMode($object);
		if ($roundMode && $roundMode !== self::getRoundModeFor($object)) {
			self::withdrawOpen($object, null);
			self::clearRound($object);
			ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_STOPPED, $editor, null);
		} elseif (!self::canInvite($object)) {
			self::withdrawOpen($object, null);
			if ($roundMode) {
				self::clearRound($object);
				ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_STOPPED, $editor, null);
			}
		}
	}

	/**
	 * Someone reserved the object: other pending invitations are withdrawn and a round ends.
	 */
	public static function onReserved(ObjectForReview $object, Assignment $reservation): void {
		self::withdrawOpen($object, $reservation->getId());
		if (self::isRoundActive($object)) {
			self::clearRound($object);
			ReservationLog::logEvent($object, ReservationLog::EVENT_ROUND_COMPLETED, null, Repo::user()->get($reservation->getUserId(), true));
		}
	}

	/**
	 * Withdraw pending invitations (their links stop working).
	 */
	protected static function withdrawOpen(ObjectForReview $object, ?int $exceptAssignmentId): void {
		foreach (AssignmentWorkflow::dao()->getByObjectId($object->getId(), [Assignment::STATUS_INVITED]) as $assignment) {
			if ($assignment->getId() === $exceptAssignmentId) {
				continue;
			}
			ActionLink::invalidate($assignment->getId());
			$assignment->setStatus(Assignment::STATUS_INVITATION_WITHDRAWN);
			$assignment->setDateStatus(Core::getCurrentDate());
			AssignmentWorkflow::dao()->updateObject($assignment);
			ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_WITHDRAWN, null, Repo::user()->get($assignment->getUserId(), true));
		}
	}

	protected static function clearRound(ObjectForReview $object): void {
		if (!$object->getData('invitationMode')) {
			return;
		}
		foreach (['invitationMode', 'invitationSubject', 'invitationBody', 'invitationLocale', 'invitationStarted', 'invitationStartedBy', 'invitationExhausted'] as $name) {
			$object->setOfferSetting($name, null);
		}
		(new ObjectForReviewDAO())->updateObject($object);
	}

	/**
	 * Remind an invitee who hasn't answered yet (daily task).
	 */
	public static function sendReminder($plugin, $context, ObjectForReview $object, Assignment $assignment): bool {
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		$deadline = (string) $assignment->getData('invitationDeadline');
		if (!$invitee || !self::isAnswerable($object, $assignment)) {
			return false;
		}
		$key = ActionLink::create($assignment->getId(), ActionLink::ACTION_INVITATION, $deadline . ' 23:59:59');
		// In the language of the invitation
		$locale = $assignment->getData('invitationLocale') ?: null;
		$late = ['invitationUrl' => ActionLink::url($context, $key, $locale), 'invitationDeadline' => self::formatDate($context, $deadline, $locale)];
		[$subject, $body] = ReservationMailer::compose($context, ReservationEmailEventType::INVITATION_REMINDER, $object, $assignment, $invitee, null,
			$late, $locale);
		ReservationMailer::send($context, ReservationEmailEventType::INVITATION_REMINDER, $object, $invitee, null, $subject, $body, $late, null, $locale);
		$assignment->setData('invitationReminderSent', Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		return true;
	}

	/**
	 * @param ?string $locale Language of the email (default: the journal's primary language)
	 */
	public static function formatDate($context, string $date, ?string $locale = null): string {
		return ReservationMailer::formatDate($context, $date, $locale);
	}
}
