<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewInvitationDeclined.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewInvitationDeclined
 *
 * @brief Automatic notice to the responsible book review editor that an invitee declined or did not answer in time.
 *  No Sender trait, so that the email can also be sent automatically (from()).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewInvitationDeclined extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmInvitationDeclined.name';
    protected static ?string $description = 'emails.brmInvitationDeclined.description';
    protected static ?string $emailTemplateKey = 'BRM_INVITATION_DECLINED';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
