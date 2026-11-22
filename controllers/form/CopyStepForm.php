<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/form/CopyStepForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CopyStepForm
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief A review copy step recorded by the book review editor (see
 *  CopyWorkflow::STEPS), or the notification of the publisher after
 *  publication, with a prefilled, editable email where the step has one.
 */

namespace APP\plugins\generic\bookReviewManager\controllers\form;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\CopyWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\ReservationEmailEventType;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\controllers\ReservationActionsHandler;
use APP\template\TemplateManager;
use PKP\core\Core;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class CopyStepForm extends \PKP\form\Form {

	/** Not a copy step: notify the publisher of the published review */
	const NOTIFY_PUBLISHER = 'notifyPublisher';

	protected $plugin;
	protected $context;
	protected $object;
	protected Assignment $assignment;
	protected string $step;
	protected $reviewer;
	/** @var ?\PKP\user\User Recipient of the email, if the step has one */
	protected $recipient = null;
	protected ?ReservationEmailEventType $emailType = null;
	protected bool $emailRequired = false;
	/** @var array target => address, for requestReturn */
	protected array $returnTargets = [];

	public function __construct($plugin, $context, $object, Assignment $assignment, string $step) {
		parent::__construct($plugin->getTemplateResource('reservationEmailForm.tpl'));
		$this->plugin = $plugin;
		$this->context = $context;
		$this->object = $object;
		$this->assignment = $assignment;
		$this->step = $step;
		$this->reviewer = Repo::user()->get($assignment->getUserId(), true);

		if ($step === self::NOTIFY_PUBLISHER) {
			$this->emailType = ReservationEmailEventType::PUBLISHER_NOTIFICATION;
			$this->recipient = CopyWorkflow::getPublisherContact($object);
			$this->emailRequired = true;
		} else {
			$definition = CopyWorkflow::STEPS[$step];
			$this->emailType = $definition['email'];
			$this->emailRequired = $definition['emailRequired'] ?? false;
			if ($this->emailType) {
				$this->recipient = ($definition['recipient'] ?? '') === 'publisher' ? CopyWorkflow::getPublisherContact($object) : $this->reviewer;
			}
			// The return notice only goes to the publisher if the copy goes back to them
			if ($step === 'returnSent' && $assignment->getData('returnTarget') !== CopyWorkflow::BY_PUBLISHER) {
				$this->recipient = null;
			}
			if (!$this->recipient) {
				$this->emailType = null;
			}
			if ($step === 'requestReturn') {
				$this->returnTargets = array_filter(CopyWorkflow::getReturnTargets($plugin, $object));
				$this->addCheck(new FormValidatorCustom($this, 'returnTarget', 'required', 'plugins.generic.bookReviewManager.copy.returnTargetRequired',
					fn ($target) => isset($this->returnTargets[$target])));
			}
		}

		$needsEmail = fn () => $this->emailType && ($this->emailRequired || $this->getData('sendEmail'));
		$this->addCheck(new FormValidatorCustom($this, 'subject', 'required', 'plugins.generic.bookReviewManager.reservations.subjectRequired',
			fn ($subject) => !$needsEmail() || trim((string) $subject) !== ''));
		$this->addCheck(new FormValidatorCustom($this, 'body', 'required', 'plugins.generic.bookReviewManager.reservations.bodyRequired',
			fn ($body) => !$needsEmail() || trim(strip_tags((string) $body)) !== ''));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	public function initData() {
		$this->setData('sendEmail', true);
		if ($this->step === 'requestReturn') {
			$preferred = $this->assignment->getData('copySentBy') ?: CopyWorkflow::BY_JOURNAL;
			$this->setData('returnTarget', isset($this->returnTargets[$preferred]) ? $preferred : array_key_first($this->returnTargets));
		}
		if (!$this->emailType) {
			return;
		}
		$sender = Application::get()->getRequest()->getUser();
		[$subject, $body] = ReservationMailer::compose($this->context, $this->emailType, $this->object, $this->assignment,
			$this->recipient, $sender, $this->emailParams());
		$this->setData('subject', $subject);
		$this->setData('body', $body);
	}

	/**
	 * Template variables of the copy emails.
	 */
	protected function emailParams(): array {
		$params = CopyWorkflow::getEmailParams($this->context, $this->object, $this->assignment,
			$this->step === self::NOTIFY_PUBLISHER ? null : $this->step);
		if ($this->step === self::NOTIFY_PUBLISHER) {
			$params['reviewUrl'] = $this->getReviewUrl();
		}
		return $params;
	}

	/**
	 * URL of the published review.
	 */
	protected function getReviewUrl(): string {
		$request = Application::get()->getRequest();
		$submission = $this->object->getSubmissionId() ? Repo::submission()->get((int) $this->object->getSubmissionId()) : null;
		if (!$submission) {
			return '';
		}
		return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $this->context->getPath(), 'article', 'view',
			[$submission->getBestId()]);
	}

	public function readInputData() {
		$this->readUserVars(['subject', 'body', 'sendEmail', 'returnTarget']);
	}

	public function fetch($request, $template = null, $display = false) {
		$recipients = $this->recipient ? [['name' => $this->recipient->getFullName(), 'email' => $this->recipient->getEmail()]] : [];
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'ofrEmailsSuppressed' => \APP\plugins\generic\bookReviewManager\classes\ReservationMailer::emailsSuppressed($request->getContext()?->getId()),
			'mode' => 'copyStep',
			'step' => $this->step,
			'objectId' => $this->object->getId(),
			'assignmentIds' => [$this->assignment->getId()],
			'objectDescription' => $this->object->getCitation(),
			'stepDescription' => __('plugins.generic.bookReviewManager.copy.step.' . $this->step . '.description', [
				'reviewer' => $this->reviewer?->getFullName() ?? '',
				'publisher' => CopyWorkflow::getPublisherContact($this->object)?->getFullName() ?? '',
			]),
			'recipients' => $recipients,
			'noEmail' => !$this->emailType,
			'optionalEmail' => $this->emailType && !$this->emailRequired,
			'returnTargets' => $this->returnTargets,
			'returnTarget' => $this->getData('returnTarget'),
			'bodyHint' => $this->step === 'requestReturn' ? __('plugins.generic.bookReviewManager.copy.returnAddressHint') : null,
			'submitKey' => 'plugins.generic.bookReviewManager.copy.step.' . $this->step,
			'actionUrl' => $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
				ReservationActionsHandler::COMPONENT, 'copyStep'),
		]);
		return parent::fetch($request, $template, $display);
	}

	public function execute(...$functionArgs) {
		$sender = Application::get()->getRequest()->getUser();
		if ($this->step === self::NOTIFY_PUBLISHER) {
			$this->object->setOfferSetting('publisherNotified', Core::getCurrentDate());
			(new ObjectForReviewDAO())->updateObject($this->object);
			ReservationLog::logEvent($this->object, ReservationLog::EVENT_PUBLISHER_NOTIFIED, $sender, $this->reviewer);
		} else {
			CopyWorkflow::apply($this->plugin, $this->context, $this->object, $this->assignment, $this->step, $sender,
				['returnTarget' => $this->getData('returnTarget')]);
		}
		if ($this->emailType && ($this->emailRequired || $this->getData('sendEmail'))) {
			$late = [];
			if ($this->step === 'requestReturn') {
				$late['returnAddress'] = nl2br(htmlspecialchars($this->returnTargets[$this->getData('returnTarget')] ?? ''));
			}
			ReservationMailer::send($this->context, $this->emailType, $this->object, $this->recipient, $sender,
				trim((string) $this->getData('subject')), (string) $this->getData('body'), $late, $this->assignment);
		}
		parent::execute(...$functionArgs);
	}
}
