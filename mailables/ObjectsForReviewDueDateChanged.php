<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewDueDateChanged.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewDueDateChanged
 *
 * @brief Notice to the reviewer that the book review editor has changed the due
 *  date of the review, with the link to the submission page.
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class ObjectsForReviewDueDateChanged extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.brmDueDateChanged.name';
    protected static ?string $description = 'emails.brmDueDateChanged.description';
    protected static ?string $emailTemplateKey = 'BRM_DUE_DATE_CHANGED';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
