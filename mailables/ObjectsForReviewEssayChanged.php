<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewEssayChanged.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewEssayChanged
 *
 * @brief Notice to the reviewer of a review essay that its list of works has changed
 *  (sent by the book review editor; no Sender trait, so from() can be used).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewEssayChanged extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmReviewEssayChanged.name';
    protected static ?string $description = 'emails.brmReviewEssayChanged.description';
    protected static ?string $emailTemplateKey = 'BRM_REVIEW_ESSAY_CHANGED';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
