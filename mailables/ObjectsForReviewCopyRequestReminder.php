<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewCopyRequestReminder.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewCopyRequestReminder
 *
 * @brief Automatic reminder to the publisher contact about an unanswered review copy request.
 *  Sent from the journal contact (no Sender trait, so from() can be used).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewCopyRequestReminder extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmCopyRequestReminder.name';
    protected static ?string $description = 'emails.brmCopyRequestReminder.description';
    protected static ?string $emailTemplateKey = 'BRM_COPY_REQUEST_REMINDER';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_READER];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
