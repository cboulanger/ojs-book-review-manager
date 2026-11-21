<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewInvitationReminder.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewInvitationReminder
 *
 * @brief Automatic reminder of an unanswered invitation, with the invitation link.
 *  No Sender trait, so that the email can also be sent automatically (from()).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewInvitationReminder extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmInvitationReminder.name';
    protected static ?string $description = 'emails.brmInvitationReminder.description';
    protected static ?string $emailTemplateKey = 'BRM_INVITATION_REMINDER';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
