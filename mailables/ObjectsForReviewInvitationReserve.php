<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewInvitationReserve.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewInvitationReserve
 *
 * @brief Invitation to review a work (direct reservation), with a link to reserve it or decline.
 *  No Sender trait, so that the email can also be sent automatically (from()).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewInvitationReserve extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmInvitationReserve.name';
    protected static ?string $description = 'emails.brmInvitationReserve.description';
    protected static ?string $emailTemplateKey = 'BRM_INVITATION_RESERVE';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
