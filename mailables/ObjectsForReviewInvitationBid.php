<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewInvitationBid.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewInvitationBid
 *
 * @brief Invitation to apply for the review of a work (bidding), with a link to apply or decline.
 *  No Sender trait, so that the email can also be sent automatically (from()).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewInvitationBid extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmInvitationBid.name';
    protected static ?string $description = 'emails.brmInvitationBid.description';
    protected static ?string $emailTemplateKey = 'BRM_INVITATION_BID';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
