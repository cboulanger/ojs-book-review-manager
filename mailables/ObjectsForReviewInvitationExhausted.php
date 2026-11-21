<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewInvitationExhausted.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewInvitationExhausted
 *
 * @brief Automatic notice to the responsible book review editor that a sequential invitation round reached the end of the list.
 *  No Sender trait, so that the email can also be sent automatically (from()).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewInvitationExhausted extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmInvitationExhausted.name';
    protected static ?string $description = 'emails.brmInvitationExhausted.description';
    protected static ?string $emailTemplateKey = 'BRM_INVITATION_EXHAUSTED';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
