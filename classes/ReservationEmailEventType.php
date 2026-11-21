<?php

/**
 * @file plugins/generic/bookReviewManager/classes/ReservationEmailEventType.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Email log event types for emails sent about a reservation.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use PKP\log\core\EmailLogEventType;

enum ReservationEmailEventType: int implements EmailLogEventType
{
	case MESSAGE = 0x1F0F101;
	case CANCELLED_BY_EDITOR = 0x1F0F102;
	case REMINDER = 0x1F0F103;
	case CONTRACT_OFFER = 0x1F0F104;
	case CONTRACT_OFFER_AGAIN = 0x1F0F105;
	case CONTRACT_REMINDER = 0x1F0F106;
	case BID_UNSUCCESSFUL = 0x1F0F107;
	case BID_NEW = 0x1F0F108;
	case CONTRACT_DECLINED = 0x1F0F109;
	case COPY_REQUEST = 0x1F0F10A;
	case COPY_SENT = 0x1F0F10B;
	case RETURN_REQUEST = 0x1F0F10C;
	case RETURN_SENT = 0x1F0F10D;
	case PUBLISHER_NOTIFICATION = 0x1F0F10E;
	case COPY_REQUEST_REMINDER = 0x1F0F10F;
	case CONFIRMATION = 0x1F0F110;
	case INVITATION_RESERVE = 0x1F0F111;
	case INVITATION_BID = 0x1F0F112;
	case INVITATION_DECLINED = 0x1F0F113;
	case INVITATION_REMINDER = 0x1F0F114;
	case INVITATION_EXHAUSTED = 0x1F0F115;
	case ESSAY_CHANGED = 0x1F0F116;

	public function labelKey(): string
	{
		return match ($this) {
			self::MESSAGE => 'plugins.generic.bookReviewManager.history.email.message',
			self::CANCELLED_BY_EDITOR => 'plugins.generic.bookReviewManager.history.email.cancelledByEditor',
			self::REMINDER => 'plugins.generic.bookReviewManager.history.email.reminder',
			self::CONTRACT_OFFER => 'plugins.generic.bookReviewManager.history.email.contractOffer',
			self::CONTRACT_OFFER_AGAIN => 'plugins.generic.bookReviewManager.history.email.contractOfferAgain',
			self::CONTRACT_REMINDER => 'plugins.generic.bookReviewManager.history.email.contractReminder',
			self::BID_UNSUCCESSFUL => 'plugins.generic.bookReviewManager.history.email.bidUnsuccessful',
			self::BID_NEW => 'plugins.generic.bookReviewManager.history.email.bidNew',
			self::CONTRACT_DECLINED => 'plugins.generic.bookReviewManager.history.email.contractDeclined',
			self::COPY_REQUEST => 'plugins.generic.bookReviewManager.history.email.copyRequest',
			self::COPY_SENT => 'plugins.generic.bookReviewManager.history.email.copySent',
			self::RETURN_REQUEST => 'plugins.generic.bookReviewManager.history.email.returnRequest',
			self::RETURN_SENT => 'plugins.generic.bookReviewManager.history.email.returnSent',
			self::PUBLISHER_NOTIFICATION => 'plugins.generic.bookReviewManager.history.email.publisherNotification',
			self::COPY_REQUEST_REMINDER => 'plugins.generic.bookReviewManager.history.email.copyRequestReminder',
			self::CONFIRMATION => 'plugins.generic.bookReviewManager.history.email.confirmation',
			self::INVITATION_RESERVE => 'plugins.generic.bookReviewManager.history.email.invitationReserve',
			self::INVITATION_BID => 'plugins.generic.bookReviewManager.history.email.invitationBid',
			self::INVITATION_DECLINED => 'plugins.generic.bookReviewManager.history.email.invitationDeclined',
			self::INVITATION_REMINDER => 'plugins.generic.bookReviewManager.history.email.invitationReminder',
			self::INVITATION_EXHAUSTED => 'plugins.generic.bookReviewManager.history.email.invitationExhausted',
			self::ESSAY_CHANGED => 'plugins.generic.bookReviewManager.history.email.essayChanged',
		};
	}
}
