<?php

/**
 * @file plugins/generic/bookReviewManager/classes/ReservationMailer.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationMailer
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Emails about an object for review (to bidders and reviewers, and
 *  notices to the responsible book review editor), based on editable email
 *  templates and recorded in the object's audit trail.
 *
 *  Values that differ per recipient or only exist once the email is sent (the
 *  one-click link, the recipient's name in a batch) stay as {$variable}
 *  placeholders in the editable text and are filled in by send().
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewBidNew;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewBidUnsuccessful;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewCancelByEditor;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewContractDeclined;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewContractOffer;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewContractOfferAgain;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewContractReminder;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewMessage;
use APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewReminder;
use APP\plugins\generic\bookReviewManager\pages\ObjectsForReviewManagementHandler;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPString;
use PKP\identity\Identity;
use PKP\db\DAORegistry;
use PKP\user\User;

class ReservationMailer {

	/** Plugin setting: true (or not set) = the plugin sends no emails, see emailsSuppressed() */
	const SETTING_SUPPRESS = 'ofrSuppressEmails';

	/**
	 * Whether the plugin's emails are switched off for a journal. They are until a journal
	 * manager switches them on in the settings, so that installing the plugin or importing
	 * data never emails reviewers or publishers. Suppressed emails are still recorded in the
	 * audit trail, marked as not sent.
	 */
	public static function emailsSuppressed(?int $contextId): bool {
		if (!$contextId) {
			return true;
		}
		$value = DAORegistry::getDAO('PluginSettingsDAO')->getSetting($contextId, 'bookreviewmanagerplugin', self::SETTING_SUPPRESS);
		return $value === null || (bool) $value;
	}

	/**
	 * Send a mailable unless the journal's emails are switched off.
	 * @return bool Whether it was sent
	 */
	public static function dispatch($mailable, ?int $contextId): bool {
		if (self::emailsSuppressed($contextId)) {
			return false;
		}
		Mail::send($mailable);
		return true;
	}

	/** Log subject of an email that was not sent */
	protected static function logSubject(bool $sent, string $subject): string {
		return $sent ? $subject : __('plugins.generic.bookReviewManager.email.notSent') . ' ' . $subject;
	}

	const MAILABLES = [
		ReservationEmailEventType::MESSAGE->value => ObjectsForReviewMessage::class,
		ReservationEmailEventType::CANCELLED_BY_EDITOR->value => ObjectsForReviewCancelByEditor::class,
		ReservationEmailEventType::REMINDER->value => ObjectsForReviewReminder::class,
		ReservationEmailEventType::CONTRACT_OFFER->value => ObjectsForReviewContractOffer::class,
		ReservationEmailEventType::CONTRACT_OFFER_AGAIN->value => ObjectsForReviewContractOfferAgain::class,
		ReservationEmailEventType::CONTRACT_REMINDER->value => ObjectsForReviewContractReminder::class,
		ReservationEmailEventType::BID_UNSUCCESSFUL->value => ObjectsForReviewBidUnsuccessful::class,
		ReservationEmailEventType::BID_NEW->value => ObjectsForReviewBidNew::class,
		ReservationEmailEventType::CONTRACT_DECLINED->value => ObjectsForReviewContractDeclined::class,
		ReservationEmailEventType::COPY_REQUEST->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewCopyRequest::class,
		ReservationEmailEventType::COPY_SENT->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewCopySent::class,
		ReservationEmailEventType::RETURN_REQUEST->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewReturnRequest::class,
		ReservationEmailEventType::RETURN_SENT->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewReturnSent::class,
		ReservationEmailEventType::PUBLISHER_NOTIFICATION->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewPublisherNotification::class,
		ReservationEmailEventType::COPY_REQUEST_REMINDER->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewCopyRequestReminder::class,
		ReservationEmailEventType::CONFIRMATION->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewConfirmation::class,
		ReservationEmailEventType::INVITATION_RESERVE->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewInvitationReserve::class,
		ReservationEmailEventType::INVITATION_BID->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewInvitationBid::class,
		ReservationEmailEventType::INVITATION_DECLINED->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewInvitationDeclined::class,
		ReservationEmailEventType::INVITATION_REMINDER->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewInvitationReminder::class,
		ReservationEmailEventType::INVITATION_EXHAUSTED->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewInvitationExhausted::class,
		ReservationEmailEventType::ESSAY_CHANGED->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewEssayChanged::class,
		ReservationEmailEventType::DUE_DATE_CHANGED->value => \APP\plugins\generic\bookReviewManager\mailables\ObjectsForReviewDueDateChanged::class,
	];

	/** Placeholders filled in by send(), per recipient */
	const LATE_VARIABLES = ['contractUrl', 'removeUrl', 'recipientName', 'returnAddress',
		'copySentUrl', 'receivedUrl', 'returnSentUrl', 'returnReceivedUrl', 'retractUrl', 'invitationUrl', 'invitationDeadline'];

	/**
	 * One-click links created by send() for an email type: [link action, variable, label key].
	 * Templates installed before a link existed get the link appended.
	 */
	const LINKS = [
		ReservationEmailEventType::COPY_REQUEST->value => [ActionLink::ACTION_COPY_SENT, 'copySentUrl', 'link.copySent'],
		ReservationEmailEventType::COPY_REQUEST_REMINDER->value => [ActionLink::ACTION_COPY_SENT, 'copySentUrl', 'link.copySent'],
		ReservationEmailEventType::COPY_SENT->value => [ActionLink::ACTION_RECEIVED, 'receivedUrl', 'link.received'],
		ReservationEmailEventType::RETURN_REQUEST->value => [ActionLink::ACTION_RETURN_SENT, 'returnSentUrl', 'link.returnSent'],
		ReservationEmailEventType::RETURN_SENT->value => [ActionLink::ACTION_RETURN_RECEIVED, 'returnReceivedUrl', 'link.returnReceived'],
		ReservationEmailEventType::REMINDER->value => [ActionLink::ACTION_RETRACT, 'retractUrl', 'link.retract'],
		ReservationEmailEventType::CONFIRMATION->value => [ActionLink::ACTION_RETRACT, 'retractUrl', 'link.retract'],
	];

	/**
	 * Install email templates added in later plugin versions (installation only adds them once).
	 */
	public static function ensureTemplates($plugin, int $contextId): void {
		$missing = false;
		foreach (self::MAILABLES as $mailableClass) {
			if (!Repo::emailTemplate()->dao->getByKey($contextId, $mailableClass::getEmailTemplateKey())) {
				$missing = true;
			}
		}
		if ($missing) {
			$locales = Application::get()->getRequest()->getSite()->getInstalledLocales();
			Repo::emailTemplate()->dao->installEmailTemplates($plugin->getInstallEmailTemplatesFile(), $locales, null, true);
		}
	}

	/**
	 * The language of an email: the requested one if the journal supports it, otherwise its primary language.
	 */
	public static function emailLocale($context, ?string $locale = null): string {
		return $locale && in_array($locale, (array) $context->getSupportedLocales(), true) ? $locale : $context->getPrimaryLocale();
	}

	/**
	 * The languages an editor can choose for an email: locale => name.
	 */
	public static function emailLocales($context): array {
		return $context->getSupportedLocaleNames();
	}

	/**
	 * A date in the short format of the email's language (default: the journal's primary language).
	 */
	public static function formatDate($context, ?string $date, ?string $locale = null): string {
		if (!$date) {
			return '';
		}
		$locale = self::emailLocale($context, $locale);
		$format = PKPString::convertStrftimeFormat($context->getLocalizedDateFormatShort($locale));
		// Translated month and day names (date() only knows English), as OJS's date_format modifier does
		return (new \Carbon\Carbon($date))->locale($locale)->translatedFormat($format);
	}

	/**
	 * Values for the template variables.
	 * @param ?User $recipient null: leave {$recipientName} for send() (batch emails)
	 */
	public static function getParams($context, ObjectForReview $object, ?Assignment $assignment, ?User $recipient, ?User $sender,
			string $locale, array $extra = []): array {
		$request = Application::get()->getRequest();
		$formatDate = fn ($date) => self::formatDate($context, $date, $locale);
		$params = [
			'objectDescription' => htmlspecialchars(strip_tags($object->getCitation())),
			'dueDate' => $formatDate($assignment?->getData('dueDate')),
			'contractDeadline' => $formatDate($assignment?->getData('contractDeadline')),
			'contextName' => htmlspecialchars($context->getLocalizedName($locale)),
			'senderName' => htmlspecialchars($sender ? $sender->getFullName() : $context->getData('contactName')),
			// Pages for the recipient open in the email's language
			'forReviewUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), \APP\plugins\generic\bookReviewManager\pages\ObjectsForReviewHandler::PAGE,
				null, null, null, null, false, $locale),
			'deskUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(),
				ObjectsForReviewManagementHandler::PAGE, 'desk'),
			// Where the reviewer submits the review (the submission wizard)
			'submissionUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'submission',
				null, null, null, null, false, $locale),
		];
		if ($recipient) {
			$params['recipientName'] = htmlspecialchars($recipient->getFullName());
		}
		return array_merge($params, $extra);
	}

	/**
	 * Subject and body of a template with the variables filled in.
	 * @param ?string $locale Language of the email (default: the journal's primary language)
	 * @return array [subject, body]
	 */
	public static function compose($context, ReservationEmailEventType $type, ObjectForReview $object, ?Assignment $assignment,
			?User $recipient, ?User $sender, array $extra = [], ?string $locale = null): array {
		$locale = self::emailLocale($context, $locale);
		$template = Repo::emailTemplate()->getByKey($context->getId(), self::MAILABLES[$type->value]::getEmailTemplateKey());
		$params = self::getParams($context, $object, $assignment, $recipient, $sender, $locale, $extra);
		return [
			Mail::compileParams((string) $template?->getLocalizedData('subject', $locale), $params),
			Mail::compileParams((string) $template?->getLocalizedData('body', $locale), $params),
		];
	}

	/**
	 * Send an email to a bidder or reviewer and record it in the audit trail.
	 * @param ?User $sender null for automatic emails (sent from the journal contact)
	 * @param array $late Values for LATE_VARIABLES (e.g. ['contractUrl' => ...])
	 * @param ?Assignment $assignment The assignment the one-click link (LINKS) acts on
	 * @param ?string $locale Language of the email (default: the journal's primary language)
	 */
	public static function send($context, ReservationEmailEventType $type, ObjectForReview $object, User $recipient, ?User $sender,
			string $subject, string $body, array $late = [], ?Assignment $assignment = null, ?string $locale = null): void {
		$late['recipientName'] = htmlspecialchars($recipient->getFullName());
		if ($assignment && isset(self::LINKS[$type->value])) {
			[$action, $variable, $labelKey] = self::LINKS[$type->value];
			$expires = (new \DateTimeImmutable('today'))->modify('+' . ActionLink::DEFAULT_VALIDITY_DAYS . ' days')->format('Y-m-d 23:59:59');
			$url = ActionLink::url($context, ActionLink::create($assignment->getId(), $action, $expires), self::emailLocale($context, $locale));
			$late[$variable] = $url;
			if (!str_contains($body, '{$' . $variable . '}')) {
				$label = __('plugins.generic.bookReviewManager.' . $labelKey, [], self::emailLocale($context, $locale));
				$body .= '<p>' . htmlspecialchars($label) . ':<br /><a href="{$' . $variable . '}">{$' . $variable . '}</a></p>';
			}
		}
		$subject = Mail::compileParams($subject, $late);
		$body = Mail::compileParams($body, $late);

		$mailableClass = self::MAILABLES[$type->value];
		$mailable = new $mailableClass($context, []);
		$from = self::setFrom($mailable, $context, $sender);
		$mailable->recipients([$recipient])->subject($subject)->body($body);
		$sent = self::dispatch($mailable, $context->getId());
		ReservationLog::logEmail($object, $type, $sender, $recipient, $from, self::logSubject($sent, $subject), $body);
	}

	/**
	 * Automatic notice to the responsible book review editor of an offer (or,
	 * without one, to the notification address in the settings or the journal
	 * contact), recorded in the audit trail.
	 */
	public static function notifyEditor($plugin, $context, ReservationEmailEventType $type, ObjectForReview $object,
			?Assignment $assignment, array $extra = []): void {
		self::ensureTemplates($plugin, $context->getId());
		$editor = $object->getResponsibleEditorId() ? Repo::user()->get($object->getResponsibleEditorId()) : null;
		[$subject, $body] = self::compose($context, $type, $object, $assignment, $editor, null, $extra);

		$mailableClass = self::MAILABLES[$type->value];
		$mailable = new $mailableClass($context, []);
		$from = self::setFrom($mailable, $context, null);
		if ($editor) {
			$mailable->recipients([$editor]);
			$recipient = $editor;
		} else {
			$recipient = $plugin->getSetting($context->getId(), 'ofrNotifyEmail') ?: $context->getData('contactEmail');
			// The mailables use the Recipient trait, which only accepts identities, not to()
			$locale = $context->getPrimaryLocale();
			$identity = new Identity();
			$identity->setEmail($recipient);
			$identity->setGivenName((string) $context->getData('contactName'), $locale);
			$mailable->recipients([$identity], $locale);
			$body = Mail::compileParams($body, ['recipientName' => htmlspecialchars($context->getData('contactName'))]);
		}
		$mailable->subject($subject)->body($body);
		$sent = self::dispatch($mailable, $context->getId());
		ReservationLog::logEmail($object, $type, null, $recipient, $from, self::logSubject($sent, $subject), $body);
	}

	/**
	 * Tell the reviewer of a review essay that its list of works has changed (the
	 * template text, unedited), recorded in the essay's audit trail.
	 * @return bool Whether there was a reviewer (with an accepted agreement) to email
	 */
	public static function sendEssayChanged($plugin, $context, ObjectForReview $container, ?User $sender): bool {
		$assignment = BundleWorkflow::getReviewerAssignment($container);
		if (!$assignment || $assignment->getStatus() !== Assignment::STATUS_ACCEPTED) {
			return false;
		}
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		if (!$reviewer) {
			return false;
		}
		self::ensureTemplates($plugin, $context->getId());
		$container = (new ObjectForReviewDAO())->getById($container->getId());
		[$subject, $body] = self::compose($context, ReservationEmailEventType::ESSAY_CHANGED, $container, $assignment, $reviewer, $sender, [
			'reviewerName' => htmlspecialchars($reviewer->getFullName()),
			'journalName' => htmlspecialchars($context->getLocalizedName($context->getPrimaryLocale())),
		]);
		self::send($context, ReservationEmailEventType::ESSAY_CHANGED, $container, $reviewer, $sender, $subject, $body);
		return true;
	}

	/**
	 * Set the sender of a mailable; returns the From header for the log.
	 */
	protected static function setFrom($mailable, $context, ?User $sender): string {
		$hasSenderTrait = in_array(\PKP\mail\traits\Sender::class, class_uses($mailable));
		if ($sender) {
			// Mailables that are also sent automatically have no Sender trait
			$hasSenderTrait ? $mailable->sender($sender) : $mailable->from($sender->getEmail(), $sender->getFullName());
			return '"' . $sender->getFullName() . '" <' . $sender->getEmail() . '>';
		}
		$mailable->from($context->getData('contactEmail'), $context->getData('contactName'));
		return '"' . $context->getData('contactName') . '" <' . $context->getData('contactEmail') . '>';
	}
}
