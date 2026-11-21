<?php

/**
 * @file plugins/generic/bookReviewManager/classes/ReservationReminder.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationReminder
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Daily task:
 *  - remind reviewers a configurable number of days before their review is due,
 *  - remind selected bidders who have not answered a contract offer,
 *  - expire unanswered contract offers (the book review editor is notified),
 *  - remind publisher contacts of unanswered review copy requests, every N days.
 *  Emails are recorded in the audit trail.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class ReservationReminder extends ScheduledTask {

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	public function getName(): string {
		return __('plugins.generic.bookReviewManager.reminder.taskName');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	protected function executeActions(): bool {
		$plugin = PluginRegistry::getPlugin('generic', 'BookReviewManagerPlugin');
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');

		$contexts = Application::getContextDAO()->getAll(true);
		while ($context = $contexts->next()) {
			if (!$pluginSettingsDao->getSetting($context->getId(), 'bookreviewmanagerplugin', 'enabled')) {
				continue;
			}
			ReservationMailer::ensureTemplates($plugin, $context->getId());
			$counts = [
				'reminders' => $this->sendDueDateReminders($plugin, $context),
				'publisherReminders' => $this->sendPublisherReminders($plugin, $context),
			] + $this->processInvitations($plugin, $context) + $this->processContractOffers($plugin, $context);
			if (array_sum($counts)) {
				$this->addExecutionLogEntry(__('plugins.generic.bookReviewManager.reminder.sent', $counts + ['context' => $context->getLocalizedName()]));
			}
		}
		return true;
	}

	/**
	 * Remind reviewers before their review is due.
	 * @return int Number of reminders sent
	 */
	protected function sendDueDateReminders($plugin, $context): int {
		$days = Reservation::getReminderDays($plugin, $context->getId());
		if ($days <= 0) {
			return 0;
		}
		$today = date('Y-m-d');
		$latestDueDate = (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
		$sent = 0;
		foreach ($this->assignments($context, [Assignment::STATUS_ACCEPTED]) as [$object, $assignment]) {
			// The reviewer of a review essay is reminded once, through the container assignment
			if ($assignment->isMirror()) {
				continue;
			}
			$dueDate = $assignment->getData('dueDate');
			if (!$dueDate || $assignment->getData('reminderSent') || $dueDate < $today || $dueDate > $latestDueDate) {
				continue;
			}
			$reviewer = Repo::user()->get($assignment->getUserId(), true);
			if (!$reviewer) {
				continue;
			}
			[$subject, $body] = ReservationMailer::compose($context, ReservationEmailEventType::REMINDER, $object, $assignment, $reviewer, null);
			ReservationMailer::send($context, ReservationEmailEventType::REMINDER, $object, $reviewer, null, $subject, $body, [], $assignment);
			$assignment->setData('reminderSent', Core::getCurrentDate());
			AssignmentWorkflow::dao()->updateObject($assignment);
			$sent++;
		}
		return $sent;
	}

	/**
	 * Remind publisher contacts of copy requests that are still open after N days (and every N days after that).
	 * @return int Number of reminders sent
	 */
	protected function sendPublisherReminders($plugin, $context): int {
		$days = CopyWorkflow::getPublisherReminderDays($plugin, $context->getId());
		if ($days <= 0) {
			return 0;
		}
		$cutoff = (new \DateTimeImmutable('today'))->modify("-{$days} days")->format('Y-m-d');
		$sent = 0;
		foreach ($this->assignments($context, [Assignment::STATUS_ACCEPTED]) as [$object, $assignment]) {
			if (CopyWorkflow::getStatus($assignment) !== CopyWorkflow::REQUESTED) {
				continue;
			}
			$last = substr((string) ($assignment->getData('copyReminderSent') ?: $assignment->getData('copyStatusDate')), 0, 10);
			$contact = CopyWorkflow::getPublisherContact($object);
			if (!$last || $last > $cutoff || !$contact) {
				continue;
			}
			CopyWorkflow::sendAutomatic($plugin, $context, $object, $assignment, ReservationEmailEventType::COPY_REQUEST_REMINDER, $contact);
			$assignment->setData('copyReminderSent', Core::getCurrentDate());
			$assignment->setData('copyReminderCount', (int) $assignment->getData('copyReminderCount') + 1);
			AssignmentWorkflow::dao()->updateObject($assignment);
			$sent++;
		}
		return $sent;
	}

	/**
	 * Remind selected bidders who have not answered, and expire offers past their deadline.
	 * @return array ['contractReminders' => int, 'expired' => int]
	 */
	protected function processContractOffers($plugin, $context): array {
		[, $reminderDays] = AssignmentWorkflow::getContractDays($plugin, $context->getId());
		$today = date('Y-m-d');
		$counts = ['contractReminders' => 0, 'expired' => 0];
		foreach ($this->assignments($context, [Assignment::STATUS_OFFERED]) as [$object, $assignment]) {
			$deadline = $assignment->getData('contractDeadline');
			if ($deadline && $deadline < $today) {
				AssignmentWorkflow::expireContract($plugin, $context, $object, $assignment);
				$counts['expired']++;
				continue;
			}
			$offered = substr((string) $assignment->getData('dateOffered'), 0, 10);
			$remindOn = $offered ? (new \DateTimeImmutable($offered))->modify("+{$reminderDays} days")->format('Y-m-d') : null;
			if ($reminderDays <= 0 || !$remindOn || $remindOn > $today || $assignment->getData('contractReminderSent')) {
				continue;
			}
			$reviewer = Repo::user()->get($assignment->getUserId(), true);
			if (!$reviewer) {
				continue;
			}
			$key = ActionLink::create($assignment->getId(), ActionLink::ACTION_CONTRACT, $deadline . ' 23:59:59');
			[$subject, $body] = ReservationMailer::compose($context, ReservationEmailEventType::CONTRACT_REMINDER, $object, $assignment, $reviewer, null);
			ReservationMailer::send($context, ReservationEmailEventType::CONTRACT_REMINDER, $object, $reviewer, null, $subject, $body,
				['contractUrl' => ActionLink::url($context, $key)]);
			$assignment->setData('contractReminderSent', Core::getCurrentDate());
			AssignmentWorkflow::dao()->updateObject($assignment);
			$counts['contractReminders']++;
		}
		return $counts;
	}

	/**
	 * Invitations (InvitationWorkflow): expire those not answered by their
	 * deadline (in a sequential round, the next person is invited), and remind
	 * invitees who haven't answered yet.
	 * @return array ['expiredInvitations' => int, 'invitationReminders' => int]
	 */
	protected function processInvitations($plugin, $context): array {
		$today = date('Y-m-d');
		$reminderDays = InvitationWorkflow::getReminderDays($plugin, $context->getId());
		$counts = ['expiredInvitations' => 0, 'invitationReminders' => 0];
		foreach ($this->assignments($context, [Assignment::STATUS_INVITED]) as [$object, $assignment]) {
			// Reload: an earlier step in this run (advancing a round) may have changed the object
			$object = (new ObjectForReviewDAO())->getById($object->getId());
			$assignment = AssignmentWorkflow::dao()->getById($assignment->getId());
			if (!$object || !$assignment || $assignment->getStatus() !== Assignment::STATUS_INVITED) {
				continue;
			}
			$deadline = (string) $assignment->getData('invitationDeadline');
			if ($deadline !== '' && $deadline < $today) {
				InvitationWorkflow::expire($plugin, $context, $object, $assignment);
				$counts['expiredInvitations']++;
				continue;
			}
			$invited = substr((string) $assignment->getData('dateInvited'), 0, 10);
			if ($reminderDays <= 0 || $invited === '' || $assignment->getData('invitationReminderSent')
					|| (new \DateTimeImmutable($invited))->modify("+{$reminderDays} days")->format('Y-m-d') > $today) {
				continue;
			}
			if (InvitationWorkflow::sendReminder($plugin, $context, $object, $assignment)) {
				$counts['invitationReminders']++;
			}
		}
		return $counts;
	}

	/**
	 * Assignments of a journal with their objects.
	 * @return array[] [ObjectForReview, Assignment]
	 */
	protected function assignments($context, array $statuses): array {
		$objectDao = new ObjectForReviewDAO();
		$result = [];
		foreach (AssignmentWorkflow::dao()->getByContextId($context->getId(), $statuses) as $objectId => $assignments) {
			$object = $objectDao->getById($objectId);
			if (!$object || $object->getData('offerClosed')) {
				continue;
			}
			foreach ($assignments as $assignment) {
				$result[] = [$object, $assignment];
			}
		}
		return $result;
	}
}
